# Подробная документация проекта

## Актуальное поведение этапа 2

Канонический клиент вызывает `POST /api/tasks/sync` одним действием «Синхронизировать задачу». Сервер по стабильному UUID строки выбирает создание, обновление или пропуск; Task ID из ссылки не является авторизацией. Script Properties содержат индивидуальный `sc_…` токен конкретной интеграции. Выдача, просмотр, отзыв токенов и временное разрешение legacy-токена выполняются администратором через CLI `integrations:tokens`; см. [API Reference](API_REFERENCE.md) и [Deploy](DEPLOY.md#переход-скриптов-и-токенов-этап-2).

> Актуальный технический срез проекта, результаты проверок и известные
> ограничения собраны в [CURRENT_STATE.md](CURRENT_STATE.md).

## 1. Назначение проекта

Этот репозиторий реализует интеграцию между Google Sheets и ActiveCollab через Laravel.

Целевой поток данных:

`Google Sheets -> Google Apps Script -> Laravel API -> ActiveCollab`

Практический смысл:

- QA или менеджер ведет баг-борд в Google Sheets
- Apps Script читает выбранную строку таблицы
- Laravel определяет, в какой ActiveCollab-проект и task list нужно отправить задачу
- Laravel создает или обновляет задачу в ActiveCollab
- ссылка на задачу записывается обратно в Google Sheets
- история синхронизации сохраняется в базе Laravel

## 2. Структура репозитория

### Ключевые части

- `app/Http/Controllers/Api/ActiveCollabTaskController.php` — API для Apps Script
- `app/Http/Middleware/VerifyIntegrationToken.php` — проверка Bearer-токена
- `app/Data/ActiveCollab/ActiveCollabTaskData.php` — DTO входящего payload
- `app/Services/ActiveCollab/` — бизнес-логика построения задачи, маппинга и синхронизации
- `app/Repositories/ActiveCollab/` — работа с БД и ActiveCollab API
- `app/Models/ActiveCollabIntegration.php` — настройки интеграции для конкретной таблицы
- `app/Models/ActiveCollabTaskExport.php` — журнал синхронизаций строк таблицы
- `app/Orchid/` — админка Orchid для управления интеграциями и историей экспортов
- `routes/api.php` — API-маршруты интеграции
- `routes/platform.php` — Orchid-маршруты
- `resources/apps-script/activecollab_sync.gs` — шаблон Google Apps Script

### `resources/apps-script/activecollab_sync.gs`

Шаблон Google Apps Script. Он:

- добавляет меню в таблицу
- собирает payload из активной строки
- вызывает Laravel API
- пишет ссылку на задачу и ошибки обратно в таблицу

Канонический исходник — `resources/apps-script/activecollab_sync.gs` в Git. Orchid скачивает файл текущего deployment. Уже привязанные Google Sheets проекты обновляются вручную: deploy файла не меняет установленный скрипт.

## 3. Как устроена бизнес-логика

### Базовая идея

Каждая строка Google Sheets интерпретируется как потенциальная задача в ActiveCollab.

Laravel использует `spreadsheet_id` как ключ для поиска нужной интеграции. Это значит:

- одна таблица Google Sheets соответствует одной записи интеграции
- у интеграции есть свой проект ActiveCollab
- у интеграции есть дефолтный task list
- у интеграции могут быть отдельные маппинги по типу задачи, исполнителю и severity

### Внешний идентификатор строки

Ключевая бизнес-константа:

`external_id = {spreadsheet_id}:{sheet_id}:{row_number}` — неуникальная текущая позиция. После контролируемого перевода стабильная идентичность — `(integration_id, record_uuid)`; номер строки может меняться.

Локатор формируется в `ActiveCollabTaskData::fromRequest()`. Он используется для безопасной совместимости legacy-интеграций, но после UUID-перехода не определяет принадлежность задачи.

Смысл:

- одна строка листа = одна logical task export record
- по этому ключу Laravel понимает, создавалась ли задача раньше

## 4. Как работает Google Apps Script

Файл: `resources/apps-script/activecollab_sync.gs`

### Меню

При открытии таблицы скрипт создает меню `ActiveCollab`:

- `Создать задачу`
- `Обновить задачу`
- `Новая запись из копии` — новый UUID и очистка ссылки только выбранной копии
- `Подготовить UUID для переноса` — манифест для администратора без HTTP

### Поиск строки заголовков

Скрипт не привязан жестко к строке заголовков по номеру. Он ищет заголовочную строку динамически в первых 10 строках по колонке:

- `Серьезность`

Это позволяет работать с таблицей, где до заголовков есть служебная шапка.

### Колонки, которые понимает скрипт

Основные колонки:

- `Ссылка на задачу`
- `Серьезность`
- `Стенд`
- `Раздел`
- `Описание проблемы`
- `Приоритет`
- `Устройство`
- `Разрешение`
- `Скриншот (ссылка)`
- `Комментарий`
- `Тип задач`
- `Ответственный`
- `Постановщик`
- `Статус`
- `Ошибка синхронизации`
- `Дата синхронизации`

### Обязательные поля строки

Для отправки строки обязательны:

- `Стенд`
- `Раздел`
- `Описание проблемы`

Если хотя бы одно из них пустое, Apps Script не отправляет запрос и показывает alert.

### Script Properties

Apps Script ожидает:

- `INTEGRATION_SERVICE_URL`
- `INTEGRATION_SERVICE_TOKEN`

Назначение:

- `INTEGRATION_SERVICE_URL` — публичный базовый URL Laravel без `/admin` и без `/api/...`
- `INTEGRATION_SERVICE_TOKEN` — Bearer-токен, который должен совпадать с `GOOGLE_SHEETS_INTEGRATION_TOKEN` в Laravel

Важно: для реального вызова из Google Apps Script `http://localhost` не подходит. Если Laravel запущен локально, нужен публичный tunnel URL.

### Создание задачи

Create/update используют общий `sendRow()`: под document lock сохраняется UUID, проверяются дубликаты, после HTTP строка ищется заново по UUID. Новая версия требует завершённого UUID-перехода. Старые немигрированные таблицы временно работают старым скриптом.

Функция `createTaskFromRow()` (проверки основного сценария):

1. Определяет текущий лист и активную строку.
2. Проверяет наличие колонки `Ссылка на задачу`.
3. Если ссылка уже есть, запрещает повторное создание.
4. Собирает payload через `buildPayload()`.
5. Отправляет `POST` на `/api/integrations/activecollab/tasks`.
6. Если задача создана успешно:
   - записывает ссылку `Открыть задачу`
   - очищает ошибку синхронизации
   - показывает alert с URL задачи

### Обновление задачи

Функция `updateTaskFromRow()`:

1. Определяет `taskId` из ссылки в колонке `Ссылка на задачу`.
2. Если ссылки нет, обновление запрещено.
3. Отправляет `PUT` на `/api/integrations/activecollab/tasks/{taskId}`.
4. Если обновление прошло:
   - очищает ошибку
   - обновляет ссылку и показывает alert с URL

### Ошибки на стороне скрипта

Обработка сценариев:

- `409` — duplicate с task ID/URL восстанавливает ссылку; error codes running/unknown/remote_missing/legacy_binding_conflict требуют отдельного решения, ссылка из них не усыновляется
- `404` — интеграция не найдена или задача в AC не найдена
- сетевые ошибки — пишутся в `Ошибка синхронизации`
- любой неуспешный ответ — message сохраняется в `Ошибка синхронизации`

После HTTP-исхода дата попытки и ошибка записываются только в заново найденную единственную UUID-строку. При локальной ошибке до HTTP или удалённом/дублированном target показывается alert без записи в прежнюю позицию.

## 5. Laravel API

### Маршруты

Все маршруты имеют префикс:

`/api/integrations/activecollab`

Доступные endpoints:

- `POST /tasks` — создать задачу
- `PUT /tasks/{taskId}` — обновить задачу
- `GET /task-lists` — получить task lists проекта аутентифицированной интеграции
- `GET /users` — получить project members аутентифицированной интеграции
- `GET /labels` — получить labels для проекта аутентифицированной интеграции

### Авторизация

Все endpoints защищены middleware `VerifyIntegrationToken`.

Проверка:

- Laravel читает `GOOGLE_SHEETS_INTEGRATION_TOKEN`
- клиент обязан передать `Authorization: Bearer {token}`

Поведение:

- если токен не настроен в Laravel — `500`
- если токен не передан или не совпал — `401`

## 6. Контракт входящего payload

Обязательные поля:

- `record_uuid: UUID` — обязателен после контролируемого перевода интеграции, до него не передаётся
- `spreadsheet_id: string`
- `sheet_id: string`
- `row_number: integer >= 1`
- `environment: string`
- `section: string`
- `description: string`

Опциональные поля:

- `severity: string|null`
- `priority: string|null`
- `device: string|null`
- `resolution: string|null`
- `screenshot: valid URL|null`
- `comment: string|null`
- `task_type: string|null`
- `responsible: string|null`
- `reporter: string|null`
- `status: string|null`

`spreadsheet_id` можно передать как полный URL таблицы — ID будет выделен автоматически.

## 7. Бизнес-логика Laravel по шагам

### Шаг 1. Поиск интеграции

`ActiveCollabTaskService` вызывает `resolveIntegration()`.

Источник:

- таблица `active_collab_integrations`
- поиск по `spreadsheet_id`
- учитываются только записи с `active = true`

Если интеграция не найдена:

- выбрасывается `IntegrationNotFoundException`
- API отвечает `404`

Перед этим данные интеграции нормализуются через `ActiveCollabIntegrationSettingsService`:

- `spreadsheet_id` можно сохранить как ID или вставить полным URL
- интеграция использует явно выбранное ActiveCollab connection; старые URL/token поля и `ACTIVE_COLLAB_*` используются только для контролируемого backfill
- `project_id` можно передать числом или ссылкой на проект
- `active_collab_task_list_id` выбирается среди существующих списков выбранного проекта
- неизвестный task list отклоняется; сохранение настроек не создаёт внешние сущности

### Шаг 2. Проверка, не создана ли задача ранее

При create выполняется scoped lookup по record_uuid после перевода интеграции, иначе по guarded legacy external_id. UUID не привязывается автоматически по совпавшей позиции.

Если запись уже имеет полную task ID/URL связь, независимо от последнего статуса:

- выбрасывается `TaskAlreadyExistsException`
- API отвечает `409` с `message`, `data.task_id`, `data.task_url`

Это поведение специально используется Apps Script.

### Шаг 3. Формирование payload для ActiveCollab

Laravel строит payload через `buildPayload()`:

- `name` — `[environment] [severity] section: description`
- `body` — HTML с секциями
- `task_list_id` — из маппинга по `task_type` или дефолтный
- `assignee_id` — при наличии маппинга по `responsible`
- `label_id` — при наличии маппинга по `severity`

### Маппинг task list

- если `task_type` пустой или `task_list_mapping` пустой — используется `active_collab_task_list_id`
- иначе Laravel ищет key по `strtolower(trim(task_type))`
- если key нет — выбрасывается `RuntimeException`

### Маппинг ответственного

- если `responsible` пустой — `assignee_id` не отправляется
- если `responsible` есть, но в `assignee_mapping` нет точного ключа — `RuntimeException`
- точное совпадение строки, без lowercase normalization

### Маппинг severity label

- если `severity` пустая — `label_id` не отправляется
- key ищется как `strtolower(trim(severity))`
- если label не найден — не ошибка, `label_id` просто пропускается

## 8. Вызовы в ActiveCollab

Все HTTP-вызовы в ActiveCollab изолированы в `ActiveCollabRepository`.

Аутентификация: заголовок `X-Angie-AuthApiToken`.

- Create: `POST {base_url}/projects/{project_id}/tasks`
- Update: `PUT {base_url}/projects/{project_id}/tasks/{taskId}`
- Helper endpoints: task-lists, users, labels — через default config

## 9. Локальный e2e-сценарий

1. Поднять проект: `make up && make migrate`
2. Задать в `.env`:
   - `GOOGLE_SHEETS_INTEGRATION_TOKEN`
   - `ACTIVECOLLAB_API_TOKEN`
   - `ACTIVECOLLAB_BASE_URL`
   - `ACTIVECOLLAB_PROJECT_ID`
3. Поднять публичный tunnel на `http://localhost` (cloudflared или ngrok)
4. Создать интеграцию в Orchid (`/admin`)
5. Скачать `activecollab_sync.gs` из админки и вставить в Google Apps Script
6. Задать Script Properties в Apps Script
7. Выбрать строку в таблице и выполнить `ActiveCollab -> Создать задачу`

## 10. Sync hash

`md5(implode('|', [13 полей]))`:

1. severity
2. priority
3. device
4. resolution
5. environment
6. section
7. problemDescription
8. screenshotUrl
9. comment
10. taskType
11. responsible
12. reporter
13. sheetStatus

## 11. Статусы экспортов

Таблица: `active_collab_task_exports`

- `pending` — запись создана, отправка еще не завершена
- `created` — задача создана в ActiveCollab
- `updated` — существующая задача обновлена
- `failed` — при последней операции произошла ошибка

При успехе дополнительно сохраняются: `task_id`, `task_url`, `sync_hash`, `active_collab_payload`, `active_collab_response`.

## 12. Модель данных

### `active_collab_integrations`

| Поле | Тип |
|------|-----|
| id | UUID |
| name | string |
| spreadsheet_id | string unique |
| active_collab_base_url | string |
| active_collab_project_id | integer |
| active_collab_task_list_id | integer nullable |
| active_collab_api_token | string encrypted |
| task_list_mapping | JSON |
| assignee_mapping | JSON |
| label_mapping | JSON |
| uuid_only | boolean, default false; управляемый перенос |
| active | boolean |

### `active_collab_task_exports`

| Поле | Тип |
|------|-----|
| id | UUID |
| integration_id | UUID |
| record_uuid | UUID nullable, unique вместе с integration_id |
| external_id | string, nonunique index |
| spreadsheet_id | string |
| sheet_id | string |
| row_number | integer |
| project_id | integer |
| task_id | integer nullable |
| task_url | text nullable |
| source_payload | JSON |
| active_collab_payload | JSON nullable |
| active_collab_response | JSON nullable |
| sync_hash | string nullable |
| status | string |
| error_message | text nullable |

## 13. Админка Orchid

Базовый URL: `http://localhost/admin`

Создание администратора:

```bash
make artisan CMD='orchid:admin'
```

### Раздел `Интеграции`

- создать интеграцию для конкретной Google Sheets таблицы
- указать default ActiveCollab project / task list
- включать и выключать интеграцию
- редактировать маппинги: `task_list_mapping`, `assignee_mapping`, `label_mapping`

### Скачивание Apps Script

- скачивать канонический `activecollab_sync.gs` из текущего deployment
- изменения скрипта вносятся в Git и попадают в скачивание после deployment
- обновление файла не устанавливает код автоматически в связанные Google Sheets проекты

### Раздел `История экспортов`

- смотреть все отправленные строки
- фильтровать по интеграции и по статусу

## 14. Практические ограничения и инварианты

- `external_id` нельзя менять без миграции всей логики синхронизации
- ActiveCollab HTTP calls должны оставаться в `ActiveCollabRepository`
- контроллеры: validate → DTO → service
- токен ActiveCollab не должен попадать в API-ответы, exception messages и логи
- `assignee_mapping` чувствителен к точному тексту
- `task_list_mapping` и `label_mapping` используют lowercase lookup
- если задача уже была создана для строки, повторное create возвращает `409`

## 15. Что покрыто тестами

- API create/update flow (feature tests)
- проверка integration token
- поведение при отсутствии интеграции
- нормализацию Google Sheets / ActiveCollab settings
- mapping logic для ответственных и labels
- сценарии выбора существующего Task List из каталога выбранного connection

Не покрыто:

- реальный e2e через tunnel
- вызовы к живому ActiveCollab API
- полный happy path в браузере через Orchid UI

---

## 16. Пошаговый walkthrough Orchid-панели

### Доступ

URL: `http://localhost/admin` (dev) или `https://your-domain/admin` (prod)

Создать первого администратора:

```bash
make artisan CMD='orchid:admin'
# интерактивно: имя, email, пароль
```

После `make setup` / `make seed` уже создан пользователь:

- email: `admin@example.test`
- password: `LocalAdmin123!`

---

### Главная страница `/admin/main`

Приветственный экран. Ссылки на основные разделы. Не содержит данных.

---

### Интеграции `/admin/integrations`

**Список интеграций.** Показывает все созданные интеграции с именем, spreadsheet_id и статусом.

Действия на строке: **Редактировать**, **Удалить**.

Кнопка **Новая интеграция** → форма создания.

---

### Создание / редактирование интеграции

URL: `/admin/integrations/create` или `/admin/integrations/{id}/edit`

**Раздел «Основные настройки»:**

| Поле | Описание |
|------|---------|
| Название | Произвольное имя для идентификации в списке |
| Google Sheets ID | ID таблицы или полная ссылка (`https://docs.google.com/spreadsheets/d/{ID}/edit`) — ID извлекается автоматически |
| ActiveCollab API Base URL | URL API ActiveCollab, например `https://ac.example.com/api/v1`. Допустимо без `/api/v1` — добавится автоматически |
| Project ID | ID проекта или ссылка на проект (`https://ac.example.com/projects/10`) |
| Task List ID | ID, ссылка, название task list, или пустое поле. При пустом — берётся первый список проекта |
| ActiveCollab API Token | Токен конкретной интеграции. Если пустой — используется глобальный `ACTIVECOLLAB_API_TOKEN` |
| Активна | Флаг включения. Только активные интеграции обрабатывают запросы |

**Раздел «Маппинг: Тип задачи → Task List»:**

Таблица строк `ключ → значение`:
- **Ключ** — значение поля `Тип задач` из Google Sheets (сравнивается без учёта регистра)
- **Значение** — ID task list в ActiveCollab

Пример:

| Тип задач (ключ) | Task List ID (значение) |
|------------------|------------------------|
| backend | 42 |
| frontend | 43 |
| design | 44 |

Если поле «Тип задач» в строке таблицы пустое или маппинг не настроен — используется основной `Task List ID`.

**Раздел «Маппинг: Ответственный → Пользователь AC»:**

- **Ключ** — точное имя из колонки «Ответственный» (регистрозависимо!)
- **Значение** — ID пользователя в ActiveCollab (взять из `GET /api/integrations/activecollab/users`)

Пример:

| Ответственный (ключ) | User ID (значение) |
|----------------------|-------------------|
| Tony Yats | 3 |
| Jane Smith | 7 |

Если «Ответственный» указан, но нет совпадения в маппинге — задача не создаётся, возникает ошибка.

**Раздел «Маппинг: Серьёзность → Label»:**

- **Ключ** — значение «Серьёзности» (без учёта регистра)
- **Значение** — ID label в ActiveCollab (взять из `GET /api/integrations/activecollab/labels`)

Пример:

| Серьёзность (ключ) | Label ID (значение) |
|---------------------|---------------------|
| critical | 1 |
| major | 2 |
| minor | 3 |

Если «Серьёзность» не найдена в маппинге — label просто не добавляется, ошибки нет.

---

### Скачивание Apps Script

Кнопка «Скачать Apps Script» отдаёт файл `resources/apps-script/activecollab_sync.gs` текущего deployment. DB-editor снят; изменение файла в Git не меняет код, уже установленный в таблицах.

До удаления архивной таблицы используйте `php artisan scripts:export-assets --output=script-assets-backup.json`; команда сохраняет все DB-версии в private storage, не изменяя БД и не печатая содержимое. Сверьте архив с Git и сохраните вне репозитория.

---

### История экспортов `/admin/exports`

Список всех строк, которые были отправлены через API (создание или обновление).

**Столбцы:**
- Spreadsheet ID, Sheet ID, Row — идентификация строки
- Integration — к какой интеграции относится
- Status — `pending`, `created`, `updated`, `failed`
- Task ID, Task URL — результат синхронизации
- Error Message — ошибка (при `failed`)
- Created At — время первой синхронизации

**Фильтры:**
- По интеграции
- По статусу

---

### Пользователи `/admin/users`

Управление пользователями Orchid-панели.

- Список пользователей с именем, email, ролями
- Создание нового пользователя
- Редактирование (имя, email, роли, права)
- Удаление

---

### Роли `/admin/roles`

Управление ролями Orchid.

- Создание ролей с произвольными permission-slug
- Назначение разрешений через checkboxes

### Роли и доступ к интеграциям

Уровень доступа задаётся Orchid permission, а область данных — явным назначением интеграции пользователю в форме редактирования пользователя. Одно без другого не даёт доступ:

| Возможность | Orchid permission | Назначение интеграции |
|---|---|---|
| Admin: connections и все интеграции, пользователи, роли, токены и аудит | `platform.activecollab.admin` (либо полный набор системных admin permissions) | Не требуется |
| Manager: просмотр и изменение mappings назначенных интеграций | `platform.activecollab.integration.manage` и `platform.activecollab.integration.view` | Требуется |
| Viewer: просмотр назначенных интеграций и их истории | `platform.activecollab.integration.view` | Требуется |

Connections, destination интеграции, включение/выключение, назначения пользователей и выдача токенов доступны только admin. Recovery неизвестного результата остаётся admin-only и требует `platform.activecollab.recovery`, `platform.systems.users` и `platform.systems.roles`. Изменения настроек, ролей, назначений и credentials журналируются; токены и пароли в audit не попадают.

---

### Профиль `/admin/profile`

Редактирование профиля текущего авторизованного пользователя:
- Имя и email
- Смена пароля

---

## 17. Настройка маппингов — детальное руководство

### Откуда брать ID для маппингов

**User ID (assignee_mapping):**

```bash
curl http://localhost/api/integrations/activecollab/users \
  -H "Authorization: Bearer $GOOGLE_SHEETS_INTEGRATION_TOKEN"
```

Ответ содержит `"id"` и `"display_name"` каждого пользователя.

**Label ID (label_mapping):**

```bash
curl http://localhost/api/integrations/activecollab/labels \
  -H "Authorization: Bearer $GOOGLE_SHEETS_INTEGRATION_TOKEN"
```

**Task List ID (task_list_mapping):**

```bash
curl http://localhost/api/integrations/activecollab/task-lists \
  -H "Authorization: Bearer $GOOGLE_SHEETS_INTEGRATION_TOKEN"
```

### Проверка маппинга assignee

**Самая частая ошибка** — несовпадение ключа в `assignee_mapping` с тем, что приходит из таблицы.

Маппинг **регистрозависимый и точный**. Если в таблице написано `"Tony Yats"`, а в маппинге ключ `"tony yats"` — будет ошибка.

Проверить: временно запросить задачу с нужным `responsible`, посмотреть ответ (500 с сообщением укажет, какой именно ключ не найден).

### Маппинг по типу задачи при update

При обновлении задачи маппинг `task_list_mapping` применяется так же, как и при создании. Если `task_type` изменился — `task_list_id` в payload будет другим, но в AC задача физически не перемещается (AC не поддерживает смену task list через PUT задачи).

### Если task list задан по имени

Task List выбирается из каталога существующих списков для выбранного connection и проекта. Если ID не существует или каталог недоступен, сохранение завершится ошибкой валидации. Обычное сохранение не выполняет POST/PUT в ActiveCollab.

---

## 18. Типичные проблемы и решения

### Задача не создаётся — 404 в Apps Script

**Причина:** Интеграция не найдена для данного `spreadsheet_id`.

**Проверить:**
1. В Orchid → Интеграции: есть ли активная интеграция?
2. Совпадает ли `spreadsheet_id` (скопировать из URL таблицы и проверить в Orchid)?
3. Установлен ли флаг «Активна»?

```bash
curl -X POST http://localhost/api/integrations/activecollab/tasks \
  -H "Authorization: Bearer $GOOGLE_SHEETS_INTEGRATION_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"spreadsheet_id": "YOUR_ID", "sheet_id": "0", "row_number": 1, "environment": "test", "section": "test", "description": "test"}'
```

---

### Задача не создаётся — 500 с ошибкой маппинга

**Причина 1:** `task_type` пришёл, но ключ не найден в `task_list_mapping`.

→ Проверить, что значение в таблице (lowercase) совпадает с ключами в маппинге.

**Причина 2:** `responsible` пришёл, но не найден в `assignee_mapping`.

→ Проверить регистр и пробелы: маппинг assignee строго точный.

---

### 409 Conflict — Apps Script не даёт создать задачу

Задача для этой строки уже была создана ранее. Ответ содержит `task_id` и `task_url` существующей задачи.

Это ожидаемое поведение. Для создания новой задачи используйте другую строку или обновите существующую через «Обновить задачу».

Если задача была удалена в AC и нужно пересоздать: в Orchid → История экспортов найдите запись и вручную сотрите статус через прямой SQL или удалите запись из `active_collab_task_exports`.

---

### Токен неверный — 401

- Проверить, что `GOOGLE_SHEETS_INTEGRATION_TOKEN` в `.env` совпадает с `INTEGRATION_SERVICE_TOKEN` в Script Properties Google Apps Script.
- Убедиться, что в Apps Script передаётся заголовок `Authorization: Bearer TOKEN`, а не просто `TOKEN`.

---

### Integration token not configured — 500

`GOOGLE_SHEETS_INTEGRATION_TOKEN` не задан в `.env`.

```bash
echo $GOOGLE_SHEETS_INTEGRATION_TOKEN   # должен быть непустым
```

После изменения `.env` в Docker: `make artisan CMD='config:clear'`.

---

### Apps Script не достигает Laravel — сетевая ошибка

Google Apps Script не может обращаться к `http://localhost`. Нужен публичный URL.

**Решение для разработки:**

```bash
# cloudflared
cloudflared tunnel --url http://localhost

# ngrok
ngrok http 80
```

Полученный URL указать в `INTEGRATION_SERVICE_URL` в Script Properties.

---

### Шифрование токена — ошибка decrypt

Если `APP_KEY` изменился после сохранения encrypted ActiveCollab token в connection (или legacy integration), значение нельзя расшифровать. Runtime запросы при этом завершаются безопасно; восстановите token в Orchid.

**Симптом:** `DecryptException` или пустой токен при вызове.

**Решение:** пересохранить интеграцию с новым токеном через Orchid.

---

### Task list не найден при сохранении интеграции

**Причина:** Указано имя task list, AC API недоступен или токен не имеет прав.

**Решение:**
1. Проверить `ACTIVECOLLAB_API_TOKEN` в `.env`.
2. Проверить доступность AC API: `curl -H "X-Angie-AuthApiToken: TOKEN" https://ac.example.com/api/v1/projects/10/task-lists`
3. Указать числовой ID вместо имени.


## UUID-переход и работа со строками (шаг 1.4)

Для UUID-шаблона интеграция должна пройти [контролируемый перенос](DEPLOY.md#переход-на-uuid-шаг-14), включая новую пустую таблицу. Команда имеет dry-run/report/audit, требует inactive, паузу редактирования и полное покрытие исторических экспортов подтверждёнными task-ссылками. По номеру строки связь не угадывается. Unknown сохраняется до отдельного recovery.

Скрытая колонка `SheetCollab UUID` должна перемещаться/сортироваться вместе с полной строкой. Не редактировать UUID существующей записи вручную. Копирование полного UUID блокирует отправку; явное действие новой записи меняет только выбранную копию и очищает link/error/date. После HTTP ищется единственный UUID во всей таблице; отсутствие/дубликат не приводит к записи в старый row. LockService не блокирует ручные правки Google UI между последней проверкой и записью.

POST/PUT сохраняют прежние envelopes, но после uuid_only требуют record_uuid. До перевода UUID-запрос блокируется legacy_binding_conflict; после перевода такой же конфликт получает запрос без UUID. MD5 и раздельные create/update пока сохранены, sync/skip — следующий этап. Общий token ещё не изолирует независимых пользователей.

Канонический скачиваемый скрипт хранится в Git и отдаётся из текущего deployment. До contract-удаления `script_assets` архивируйте все строки командой `scripts:export-assets`, сравните custom-версии с Git и сохраните полезные изменения через review. Обновление файла не означает обновление рабочих таблиц. После UUID-привязок старый row-number код нельзя возвращать обычным rollback; процедура — DEPLOY.
