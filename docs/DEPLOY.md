# Развертывание на сервере

## Требования

- Docker + Docker Compose plugin
- Git
- домен или tunnel до сервера
- reverse proxy перед Laravel-контейнером: Nginx, Caddy или Traefik

## 1. Клонирование

```bash
cd /opt
git clone <REPO_URL> sheetcollab
cd /opt/sheetcollab
```

## 2. Подготовка `vendor` перед первым запуском

`compose.yaml` собирает контейнер из `./vendor/laravel/sail/runtimes/8.5`, поэтому сначала нужно получить PHP-зависимости.

Если на сервере нет локального Composer, используйте контейнер:

```bash
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v /opt/sheetcollab:/app \
  -w /app \
  composer:2 \
  composer install --no-dev --optimize-autoloader
```

## 3. Production `.env`

```bash
cp .env.example .env
```

Заполнить минимум:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example.com
APP_PORT=8080

DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=sheetcollab
DB_USERNAME=sheetcollab
DB_PASSWORD=strong-password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

ACTIVECOLLAB_BASE_URL=https://your-activecollab.example.com/api/v1
ACTIVECOLLAB_PROJECT_ID=10
ACTIVECOLLAB_API_TOKEN=...
GOOGLE_SHEETS_INTEGRATION_TOKEN=...
```

Рекомендуется не публиковать MariaDB наружу. Если нужны host ports, привязать к localhost:

```env
FORWARD_DB_PORT=127.0.0.1:3306
```

## 4. Первый запуск

```bash
docker compose up -d --build
docker compose exec laravel.test php artisan key:generate --force
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test npm install --ignore-scripts
docker compose exec laravel.test npm run build
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache
```

Если нужен администратор Orchid:

```bash
docker compose exec laravel.test php artisan orchid:admin
```

## 5. Reverse proxy

Reverse proxy должен проксировать публичный HTTPS-домен на `127.0.0.1:${APP_PORT}`.

В `INTEGRATION_SERVICE_URL` для Google Apps Script нужно указывать **публичный HTTPS URL сервиса**, без `/admin` и без `/api/...`.

## 6. Smoke check после деплоя

```bash
docker compose ps
docker compose logs --tail=200
docker compose exec laravel.test php artisan about
curl -I http://127.0.0.1:${APP_PORT}
```

В браузере:

- `/admin/login` открывается
- можно войти в Orchid
- раздел `Apps Script` доступен
- раздел `Интеграции` доступен

## 7. Обновление уже развернутого сервиса

```bash
cd /opt/sheetcollab
git pull
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v /opt/sheetcollab:/app \
  -w /app \
  composer:2 \
  composer install --no-dev --optimize-autoloader
docker compose up -d --build
docker compose exec laravel.test php artisan migrate --force
docker compose exec laravel.test npm install --ignore-scripts
docker compose exec laravel.test npm run build
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan route:cache
docker compose exec laravel.test php artisan view:cache
```

## 8. Что проверить, если не работает

- `APP_URL` совпадает с реальным публичным доменом
- `GOOGLE_SHEETS_INTEGRATION_TOKEN` задан и совпадает со значением в Apps Script
- `ACTIVECOLLAB_*` заполнены корректно
- migrations выполнены
- reverse proxy ведет на правильный `APP_PORT`
- контейнеры `laravel.test`, `mariadb` в статусе `healthy/running`

## 9. Не делать

- не оставлять `APP_DEBUG=true` на сервере
- не публиковать MariaDB в интернет
- не указывать `http://localhost` в `INTEGRATION_SERVICE_URL`


## Переход на резервирование операций (шаг 1.2)

Не запускать старых и новых writers одновременно. Перед релизом остановить приём синхронизаций и дождаться завершения прежних запросов. Снять backup БД и конфигурации расшифровки; проверить восстановление на отдельной БД.

1. Установить код с additive-миграциями `2026_10_29_000001` и `2026_10_29_000002`, применить миграции в штатном окружении.
2. Выполнить `make artisan CMD='integrations:backfill-sync-state --dry-run'`, сохранить отчёт. `review` содержит export IDs для ручной сверки, без payload и секретов. Orphan integration/project не перепривязывать по догадке.
3. Выполнить `make artisan CMD='integrations:backfill-sync-state'`, сверить counts, убедиться, что повторный запуск не классифицирует записи заново. Legacy pending/failed могут стать unknown; synced_at не восстанавливается из updated_at, старые attempts не создаются.
4. Задать системные параметры: `ACTIVECOLLAB_CONNECT_TIMEOUT=5`, `ACTIVECOLLAB_TIMEOUT=30`, `ACTIVECOLLAB_SYNC_LEASE_SECONDS=120`. Lease должен превышать полный HTTP timeout. Очистить/пересобрать config cache штатной процедурой деплоя.
5. Проверить ответы API и журнал на тестовых данных, затем возобновить приём запросов только новой версией.

При unknown отправка блокируется. Административное разрешение с аудитом доступно на шаге 1.3 после выдачи соответствующих прав; не удалять экспорт, не очищать operation ID и не менять unknown на idle вручную ради повтора.

При проблеме остановить запись и сохранить additive-схему/историю. До нового трафика возможен возврат с проверенным backup. После появления новых attempts откат только к старому коду небезопасен: он игнорирует reservation/unknown. Использовать исправление вперёд либо отдельное восстановление backup со сверкой внешних результатов. Down-миграции удаляют новые структуры и предназначены для пустой тестовой схемы, не для отката рабочей истории.


## Включение административного восстановления (шаг 1.3)

1. Остановить sync и старые отправляющие workers, снять backup БД/конфигурации. Если 1.2 ещё не установлен — сначала выполнить его миграции и backfill. Применить новую audit-миграцию `2026_10_30_000001`; никаких legacy данных она не меняет.
2. В существующем Users/Roles выдать администратору `platform.activecollab.recovery`; дополнительно требуются оба `platform.systems.users` и `platform.systems.roles`. Не выдавать recovery менеджерам и не запускать production DatabaseSeeder ради нового права. Seeder меняет только локального admin@example.test.
3. Настроить один штатный scheduler runner (либо cron `* * * * *` для `php artisan schedule:run` в контейнере). Schedule содержит expiry-команду каждую минуту. `make artisan CMD='integrations:expire-sync-operations --dry-run'` показывает expired count и review IDs без HTTP; без флага переводит только всё ещё просроченные running в unknown. Повторяемо, Redis не требуется.
4. Проверить права, журнал и recovery на тестовой записи; убедиться, что ordinary token не открывает экран, GET не изменяет запись и повтор формы не отправляет второй HTTP. Возобновить обработку только новой версией.

Перед confirm absence или replacement остановить старые mutating процессы и проверить AC вручную: lease/404 не доказывают отсутствия результата. Не удалять экспорт и не сбрасывать unknown через SQL. Если результат старого create неизвестен, сначала попытаться подтвердить существующую задачу в правильном проекте. После замены обновить ссылку в Sheets перед следующим update.

При проблеме отключить recovery permission/route и остановить запись, сохранив audit/history. Решение о смене связи или внешняя задача не отменяются возвратом старого кода. Использовать forward fix либо отдельное восстановление со сверкой AC. Не выполнять down audit-миграции на рабочей истории.

## Переход на UUID (шаг 1.4)

Этот релиз сохраняет старые POST/PUT. Новый sync endpoint и индивидуальные токены будут следующим этапом; общий token не обеспечивает изоляцию независимых пользователей.

### Развёртывание серверной части

1. Сохранить backup БД и DB-версии Apps Script; остановить mutating workers/ручную синхронизацию. Проверить running: завершить живые операции, expired обработать штатным sweep без автоматического повтора AC-запросов.
2. Разместить код 1.4 при остановленных writers. Применить `2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations.php`, затем `2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports.php` штатным `make migrate`. После снятия unique старый row-number writer запускать нельзя. Миграции не назначают UUID.
3. Проверить composite unique и неуникальный external_id index, поднять только новый код. Непереведённые интеграции без UUID продолжают принимать legacy-запросы без UUID. Не запускать production seeder.

### Перевод одной таблицы

1. Остановить triggers, ручную синхронизацию и редактирование/сортировку таблицы; отключить интеграцию (`active=false`). Сохранить копию таблицы и текущего скрипта. Пауза редактирования — обязанность оператора: сервер/LockService не блокирует Google UI.
2. Экспортировать содержимое `script_assets` через существующий DB-редактор, сравнить с `resources/apps-script/activecollab_sync.gs`, сохранить полезные custom-изменения после review. Внести проверенный UUID-шаблон в DB-редактор, скачать и установить его в bound Apps Script данной таблицы. Обновление файла в Git само по себе не меняет скачиваемую DB-версию. Остальные таблицы не обновлять молча.
3. Выполнить меню «Подготовить UUID для переноса». Оно создаёт скрытую колонку `SheetCollab UUID`, сохраняет UUID заполненных строк и показывает JSON, без HTTP/AC. Проверить task-ссылки и сохранить JSON локально, вне public/web root. Манифест содержит только идентификаторы/ссылки, не содержимое задач. Дубликаты сначала сверить; копия становится новой записью только явным действием с очисткой ссылки.
4. Выполнить dry-run от существующего администратора с тремя permissions recovery/users/roles:

```bash
make artisan CMD='integrations:migrate-record-uuids --integration=INTEGRATION_UUID --manifest=/path/inside/container/manifest.json --actor=ADMIN_ID --reason="Confirmed task links during editing pause" --dry-run --enable-uuid-only'
```

Команда требует JSON version=1, spreadsheet_id и records с record_uuid, sheet_id, row_number, nullable task_id/task_url. Лимиты: 4 MiB/20 000 строк; UUID уникальны во всей таблице. Sheet ID — до 64 символов, spreadsheet ID — до 255, URL — до 2048, row/task ID положительные. Причина — 10–1000 символов. Файл должен быть доступен PHP runner; путь локального хоста сам по себе не является путём внутри Sail.

Отчёт: planned/already_bound/unresolved/orphaned/conflicts, uuid_only/applied. Exit 1 означает блокировку/невалидные аргументы. Сопоставление выполняется по подтверждённой полной task-ссылке и integration/project/task ID; row number не служит доказательством. Принимается точная task URL сервиса (допускается trailing slash); другой host, project, query/redirect/неполная ссылка требуют сверки.

5. Разрешить все blockers до apply. Все исторические экспорты этой интеграции должны быть подтверждены; null integration ID для её spreadsheet, чужая история, ambiguous exports, unbound/неполная связь, missing manifest rows и running блокируют переключение. Orphans других таблиц перечисляются, но не переносятся. Unknown с полной подтверждённой связью можно перенести, сохранив unknown и запрет повторного create. Для unbound сначала провести сверку/восстановление 1.3; при необходимости завершить безопасный legacy sync старым скриптом, снова остановить запись и подготовить новый манифест. Не удалять историю и не назначать UUID через SQL ради обхода проверки.
6. Повторить команду без dry-run. Все UUID/locator/audit/readiness меняются одной транзакцией; без `--enable-uuid-only` идентичности переносятся, но интеграция остаётся на паузе до повторного enable. Даже случайная реактивация такого промежуточного состояния блокирует все legacy-запросы. Ошибка audit откатывает перенос. Идентичный повтор — no-op без новых audit rows; замена существующего UUID запрещена.
7. Сверить отчёт, UUID и ссылки, убедиться в `uuid_only=true`, активировать интеграцию и разрешить редактирование/синхронизацию новым скриптом. Проверить одну строку: update после перемещения полной строки сохраняет task ID. Если до переноса был unknown, сначала разрешить его отдельно через recovery; UUID-перенос сам его не снимает.

Для новой таблицы без истории выполнить ту же проверку с пустым `records: []` и enable при inactive, затем активировать. Новые строки без task-ссылки не создают экспорт/AC-задачу во время переноса. Не включать readiness обычной формой.

### Откат

До первой UUID-привязки возможен только согласованный rollback кода/схемы под остановкой writers: проверить отсутствие UUID, uuid_only и повторяющихся locator; восстановить старый unique, затем additive schema/код. Down проверяет данные заранее и отказывается вместо потери UUID/индексной ошибки.

После первой UUID-привязки возврат к коду 1.3 и обычный down запрещены: старый алгоритм может создать/обновить чужую задачу по row number. Остановить запись, сохранить текущее состояние, выбрать forward fix либо отдельное backup restore с ручной сверкой AC-задач, созданных после backup. Восстановление БД не отменяет внешние задачи. Не стирать UUID/audit/attempts ради отката.
