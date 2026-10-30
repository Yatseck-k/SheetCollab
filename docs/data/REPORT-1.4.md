# Результат шага 1.4

Дата: 2026-10-30. Реализован явно согласованный план `work/PLAN.md`. План 1.3 сохранён в `work/PLAN-1.3.md`. Исходный TASK и объём следующих этапов не менялись.

## Root cause

Номер строки был одновременно позицией и идентичностью. Сортировка, вставка/перемещение и повторное использование позиции могли потерять связь либо направить update не той записи. Apps Script записывал ответ в исходный row, хотя тот мог измениться во время HTTP. Снятие прежнего global external_id unique без изменения reserve также оставило бы legacy first insert без защиты.

## Files changed

35 файлов репозитория относительно начального снимка этого шага; точный перечень — `work/CHANGED-1.4.txt`. Сохранены уже существовавшие изменения 1.1–1.3.

- Две новые миграции `2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations.php` и `2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports.php`.
- TaskData, Store/Update requests, Integration/Export models, ExportRepository и TaskSyncConflictException: UUID, режим, scoped lookup/locks/locator.
- Новые RecordUuidManifest, MigrateActiveCollabRecordUuids, RecordUuidMigrationService/Repository; AdminAuditLogRepository — атомарный административный перенос.
- DataSanitizer/RecoveryService — UUID и исторические источники после перемещения.
- Реальный Apps Script и Node harness; DTO/API/repository/recovery/migration/MariaDB tests и worker.
- AGENTS, CLAUDE, docs/API_REFERENCE/DATA_MODEL/LOGIC/DEPLOY/PROJECT_MANUAL/TESTING, admin guide; локальные work/AGENT_RULES, PLAN и отчёт.

Изменение существующих routes/controller/middleware/HTTP client/GoogleAppsScriptService/DB-редактора не потребовалось. Dependency manifests и lock-файлы не менялись. Команды git add/reset/commit не выполнялись, существующий staging не отменялся. Индекс отличается от начального снимка: новые PHP-файлы появились в staged состоянии при работе с общим workspace; вручную staging не восстанавливался и не переписывался.

## Fix summary

Идентичность UUID-интеграции — только `(integration_id, record_uuid)`. Внешний локатор сохраняет формат `{spreadsheet_id}:{sheet_id}:{row_number}`, но теперь имеет неуникальный индекс. Один UUID остаётся той же записью после переноса между листами одной таблицы; другой UUID может использовать прежнюю позицию независимо. PUT по-прежнему требует полного совпадения сохранённой task-связи. Новый UUID не захватывает историческую запись через row number/task ID.

`uuid_only` default false управляется только paused migration command. Legacy и UUID режимы не смешиваются; mismatch даёт понятный 409 до HTTP. Наличие UUID-привязок при ещё false readiness блокирует весь legacy writer, включая пустые позиции. Reserve берёт integration lock, затем export lock; внешний HTTP остаётся после commit. Recovery с двумя lock использует тот же порядок. Operation ID/lease/attempts, unknown/remote_missing и duplicate-create со своей ссылкой сохранены.

Команда `integrations:migrate-record-uuids` принимает локальный JSON-манифест, actor/reason, dry-run и optional enable. Она требует inactive, полномочия recovery/users/roles, отсутствие running, полное однозначное покрытие исторических экспортов подтверждёнными task-ссылками. Nullable/orphan история сохраняется и попадает в report; неоднозначные данные блокируют apply. UUID и locator/audit/readiness меняются одной транзакцией; ошибка аудита на binding либо enable откатывает всё. Повтор — no-op; attempts, source snapshots, task-связь, sync operation/status/hash/synced_at не фабрикуются и не перезаписываются. Новый пустой контекст можно включить пустым манифестом. Лимит 4 MiB/20 000 строк, проверка записей без квадратичного wildcard/distinct expansion.

Apps Script создаёт скрытую `SheetCollab UUID`, под document lock перечитывает/сохраняет UUID и flush до HTTP, проверяет копии во всей таблице. После любого HTTP-исхода заново находит единственную UUID-строку перед записью ссылки/error/date. Удаление/дубликат target не приводит к записи в старую позицию. Явная новая копия меняет только выбранную строку и очищает локальную ссылку. Есть подготовка безопасного манифеста без HTTP. Create/update пока раздельны, 409 различает duplicate и unresolved conflicts; network exception text с credentials не отображается. Исторический recovery source без UUID допустим только внутри собственного export, чужой явный UUID отклоняется.

## Validation result

Baseline: 192 cases / 652 assertions, включая 9 MariaDB skips; 183 выполненных SQLite cases, 11 прежних notices. Новые серверные тесты сначала упали на отсутствующем UUID/пропущенной валидации; JS harness сначала дал 15 ожидаемых failures. После реализации:

- Полный PHPUnit на PHP 8.5.12 через `composer:2`, сеть отключена: **239 cases / 853 assertions, 226 выполненных без падений, 13 пропущенных MariaDB cases**. Сохранились 11 прежних notices в IntegrationSettingsServiceTest; новых PHPUnit warnings нет.
- Отдельная MariaDB 11.4.12, PHP 8.5 с pdo_mysql: **13 tests / 134 assertions**, все прошли. Независимые процессы: UUID same/different position, разные UUID на одном locator, legacy first insert после снятия unique, recovery/sync/sweep и migration против update/recovery. Fake HTTP проверяет transactionLevel=0, реальный AC не вызывается.
- `node --test tests/AppsScript/activecollab_sync.test.cjs`: **17/17 passed**. Включает два перекрывающихся запуска, перечитывание после ожидания lock, duplicate между листами, полную сортировку/вставку/перенос во время fetch, удаление/дубликат target, новую копию, ошибки, response URL и escaping манифеста.
- Отдельная MariaDB upgrade DB: fresh schema → безопасный empty down до 1.3 → исторический export/unknown attempt → migrate до 1.4 → dry-run/apply/replay. Проверены сохранность истории/status/synced_at/hash/operation, audit, composite unique, разные UUID на общем locator, отказ unsafe down и отсутствие HTTP. **PASS**. Проверочный скрипт — `work/QA-1.4-schema.php`, жёсткий guard требует testing/mariadb/точное имя `sheetcollab_uuid_upgrade_test` перед migrate:fresh. Первые прогоны самого checker потребовали сравнивать свежие DB-снимки, а не неполные/устаревшие Eloquent attributes; код приложения из-за этого не менялся.
- Верхняя граница манифеста: **20 000 валидных записей**, проверка прошла за 3.32 секунды в отдельном runner. Это один локальный замер, не нагрузочная гарантия.
- Pint `--test --dirty`: **52 PHP files passed** после форматирования. PHPStan level 6 с текущим config: **No errors**.
- `npm run build`: **exit 0**. `php artisan view:cache` с отдельным `/tmp` compiled path в ephemeral container: **passed**; рабочий view cache не очищался.
- `git diff --check`: **passed**.
- `make test` и `make check`: **не выполнены успешно — Sail is not running**, exit 2. `.env` отсутствует и не создавался. Эквивалентные PHP/static/build проверки выполнены отдельными контейнерами; штатный Sail check не объявляется успешным.
- ub-php отсутствует; CDMU/Infection не устанавливались. CRAP/численная схожесть/mutation score не измерялись. Ручной review: идентичность не выводится из позиции, один владелец короткого reserve, нет HTTP в migration/транзакции, before/after очищаются, нет automatic retry/сброса unknown.

Воспроизводимые основные команды (изолированная Docker network/DB созданы отдельно, fixture password не является рабочим секретом):

```bash
docker run --rm --network none --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 php vendor/bin/phpunit --no-progress
docker run --rm --network none --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 php vendor/bin/pint --test --dirty
docker run --rm --network none --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
node --test tests/AppsScript/activecollab_sync.test.cjs
npm run build
```

MariaDB runner: образ `sheetcollab-12-check`, internal network `sheetcollab-14-test`, host `sheetcollab-14-db`, database `sheetcollab_concurrency_test`, user root, password fixture-only-password, `php vendor/bin/phpunit -c phpunit.mariadb.xml --no-progress`. Upgrade checker использует только отдельную `sheetcollab_uuid_upgrade_test`. После проверок собственный MariaDB-контейнер и network удалены; продуктовый compose, другие контейнеры/volumes, AC и Google Sheets не менялись.

## Remaining risks or unverified paths

- Реальная Google-таблица/Google runtime и live AC не проверялись. Ручные шаги сортировки/двух Google-сессий/перемещения описаны в docs/TESTING и остаются **не выполнены**. Fake JS tests не заменяют их. Сортировка части колонок без UUID не поддерживается; document lock не блокирует ручные правки между последней проверкой и записью.
- Production deployment, backup restore и миграция действующих таблиц не выполнялись. Оператору нужна процедура docs/DEPLOY: пауза, backup, новый серверный writer/схема, export/review DB-скрипта, подтверждённые ссылки, dry-run/apply/enable, активация. Команда проверяет inactive, но не может доказать остановку Google-редакторов.
- Каноническая скачиваемая версия скрипта пока в script_assets. Файл в Git обновлён, существующие DB/custom-версии автоматически не перезаписаны. Их установка — эксплуатационный шаг; удаление редактора/DB source остаётся этапом 6.
- Partial/неоднозначные/осиротевшие экспорты блокируют readiness и требуют ручной сверки. UUID-перенос не разрешает unknown и не доказывает наличие/отсутствие внешнего результата. Exactly-once не обещается.
- После UUID-привязок обычный rollback к алгоритму row number запрещён. Down сохраняет данные отказом; нужен stopped-writer forward fix либо отдельно сверенный backup restore с учётом внешних side effects.
- Общий token/старое UI ещё не дают полной изоляции независимых пользователей. Sync endpoint, индивидуальные токены и SHA-256/skip — следующий этап 2; Connections/полные роли/очистка/обновление зависимостей остаются в следующих согласуемых планах.
