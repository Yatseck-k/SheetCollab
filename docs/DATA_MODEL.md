# Модель данных

## Таблицы проекта

| Таблица | Модель | Назначение |
|---------|--------|-----------|
| `active_collab_integrations` | `ActiveCollabIntegration` | Настройки интеграции для Google Sheets таблицы |
| `active_collab_connections` | `ActiveCollabConnection` | Переиспользуемые подключения к ActiveCollab |
| `active_collab_task_exports` | `ActiveCollabTaskExport` | Журнал синхронизаций строк |
| `active_collab_integration_tokens` | `ActiveCollabIntegrationToken` | Индивидуальные credentials интеграций; хранится только SHA-256 bearer |
| `script_assets` | `ScriptAsset` | Архивируемое legacy-хранилище; runtime download использует файл из Git |
| `users` | `User` | Пользователи Orchid-панели |
| `roles` | Orchid `Role` | Роли пользователей |
| `role_users` | — | Связь пользователей с ролями |
| `cache` | — | Кеш (driver=database) |
| `sessions` | — | Сессии (driver=database) |
| `jobs` | — | Очереди (driver=database) |

---

## active_collab_integrations

Хранит настройки одной интеграции: связь между конкретной Google Sheets таблицей и проектом ActiveCollab.

```sql
CREATE TABLE active_collab_integrations (
    id                          CHAR(36)         PRIMARY KEY,          -- UUID
    name                        VARCHAR(255)     NOT NULL,
    spreadsheet_id              VARCHAR(255)     NOT NULL UNIQUE,
    connection_id               CHAR(36)         NULL REFERENCES active_collab_connections(id),
    active_collab_base_url      VARCHAR(255)     NULL,                  -- legacy, migration only
    active_collab_project_id    INT UNSIGNED     NOT NULL,
    active_collab_task_list_id  INT UNSIGNED     NOT NULL,
    active_collab_api_token     TEXT             NULL,                  -- legacy, зашифровано
    task_list_mapping           JSON             NULL,
    assignee_mapping            JSON             NULL,
    label_mapping               JSON             NULL,
    uuid_only                   TINYINT(1)       NOT NULL DEFAULT 0,
    active                      TINYINT(1)       NOT NULL DEFAULT 1,
    created_at                  TIMESTAMP        NULL,
    updated_at                  TIMESTAMP        NULL
);
```

Этап 2 добавляет `allow_legacy_token BOOLEAN NOT NULL DEFAULT false`. Флаг задаётся только административной CLI-командой, не включается массовым заполнением или миграцией.

## active_collab_integration_tokens

Таблица содержит UUID, `integration_id` с `RESTRICT` при удалении, имя, уникальный `token_hash CHAR(64)`, `created_at`, `revoked_at` и `last_used_at`. Исходный `sc_…` показывается только один раз при выдаче и никогда не хранится.

`active_collab_task_exports.hash_version` и `active_collab_sync_attempts.hash_version` nullable. Старые хеши остаются без версии; новые legacy попытки используют `legacy-md5-v1`, новый sync — `payload-sha256-v2`. Destination hash включает `connection_id`; переход со старой версии требует одного успешного PUT. Незаполненные старые значения не считаются подтверждёнными для skip.

## active_collab_connections

Содержит общий для нескольких интеграций ActiveCollab endpoint и зашифрованный API token. Интеграция обязана ссылаться на активное подключение через `connection_id`; legacy URL/token и старые переменные окружения используются только контролируемым backfill/remediation и не являются runtime fallback.

| Поле | Описание |
|------|----------|
| `id` | UUID подключения |
| `name` | Имя в административной панели |
| `base_url` | Нормализованный API URL с `/api/v1` |
| `api_token` | Зашифрованный Laravel `encrypted` cast token |
| `active` | Доступно ли подключение для новых операций |

### Описание столбцов

| Столбец | Тип | NULL | Описание |
|---------|-----|------|---------|
| `id` | UUID | нет | Первичный ключ (UUID v7, auto-generated) |
| `name` | string | нет | Человекочитаемое название интеграции |
| `spreadsheet_id` | string | нет | ID Google Sheets (без URL). Unique — одна таблица = одна интеграция |
| `connection_id` | UUID | да на период миграции | Явно выбранное ActiveCollab connection; runtime без него закрывается |
| `active_collab_base_url` | string | да | Legacy URL только для migration/remediation; runtime его не использует |
| `active_collab_project_id` | integer | нет | ID проекта в ActiveCollab |
| `active_collab_task_list_id` | integer | нет | Дефолтный task list ID в проекте |
| `active_collab_api_token` | text | да | Legacy token, зашифрованный Laravel `encrypted` cast; runtime не использует и не подменяет глобальным config |
| `task_list_mapping` | JSON | да | Маппинг `task_type → task_list_id`. Ключи — lowercase строки |
| `assignee_mapping` | JSON | да | Маппинг `responsible → user_id`. Ключи — exact match строки |
| `label_mapping` | JSON | да | Маппинг `severity → label_id`. Ключи — lowercase строки |
| `uuid_only` | boolean | нет | Готовность к UUID-only; false по умолчанию, меняется только контролируемой командой |
| `active` | boolean | нет | Включена ли интеграция. Только активные интеграции участвуют в синхронизации |
| `created_at` | timestamp | да | Создана |
| `updated_at` | timestamp | да | Обновлена |

### Структура JSON-столбцов

**`task_list_mapping`** — маппинг типа задачи на ID task list:

```json
{
  "backend": 42,
  "frontend": 43,
  "design": 44
}
```

Ключи сравниваются через `strtolower(trim(task_type))`. Если `task_type` пустой или маппинг не задан — используется `active_collab_task_list_id`. Если `task_type` есть, но ключ не найден — ошибка `RuntimeException`.

**`assignee_mapping`** — маппинг имени ответственного на ID пользователя AC:

```json
{
  "Tony Yats": 3,
  "Jane Smith": 7
}
```

Ключи сравниваются **точно** (регистрозависимо, без trim). Если `responsible` есть, но ключ не найден — ошибка `RuntimeException`.

**`label_mapping`** — маппинг серьёзности на ID label в AC:

```json
{
  "critical": 1,
  "major": 2,
  "minor": 3
}
```

Ключи сравниваются через `strtolower(trim(severity))`. Если `severity` есть, но ключ не найден — `label_id` просто пропускается, ошибки нет.

### Методы модели

```php
// URL разрешается через явно назначенное active connection
$integration->resolvedBaseUrl(): string

// URL для браузера (без /api/v1)
$integration->resolvedInstanceUrl(): string

// Токен берётся только из явно назначенного active connection
$integration->resolvedApiToken(): string
```

---

## active_collab_task_exports

Текущее состояние записи и её подтверждённая связь. История попыток хранится отдельно. Идентичность UUID-записи уникальна по `(integration_id, record_uuid)`; legacy locator защищается транзакционным алгоритмом.

```sql
CREATE TABLE active_collab_task_exports (
    id                      CHAR(36)         PRIMARY KEY,
    integration_id          CHAR(36)         NULL     INDEX,
    record_uuid             CHAR(36)         NULL,             -- UNIQUE(integration_id, record_uuid)
    external_id             VARCHAR(255)     NOT NULL,         -- nonunique INDEX
    spreadsheet_id          VARCHAR(255)     NOT NULL,
    sheet_id                VARCHAR(255)     NOT NULL,
    row_number              INT UNSIGNED     NOT NULL,
    project_id              INT UNSIGNED     NOT NULL,
    task_id                 INT UNSIGNED     NULL,
    task_url                TEXT             NULL,
    source_payload          JSON             NOT NULL,
    active_collab_payload   JSON             NULL,
    active_collab_response  JSON             NULL,
    sync_hash               VARCHAR(255)     NULL,
    status                  VARCHAR(255)     NOT NULL DEFAULT 'pending',
    error_message           TEXT             NULL,
    created_at              TIMESTAMP        NULL,
    updated_at              TIMESTAMP        NULL
);
```

### Описание столбцов

| Столбец | Тип | NULL | Описание |
|---------|-----|------|---------|
| `id` | UUID | нет | Первичный ключ |
| `integration_id` | UUID | да | Логическая ссылка на `active_collab_integrations.id`; FK пока отсутствует из-за исторических данных |
| `record_uuid` | UUID | да | Стабильная идентичность из Sheets, unique вместе с integration_id |
| `external_id` | string | нет | Текущий неуникальный локатор `{spreadsheet_id}:{sheet_id}:{row_number}` |
| `spreadsheet_id` | string | нет | ID Google Sheets таблицы |
| `sheet_id` | string | нет | ID листа |
| `row_number` | integer | нет | Номер строки |
| `project_id` | integer | нет | ID проекта AC на момент синхронизации |
| `task_id` | integer | да | ID задачи в ActiveCollab (заполняется после успешного создания) |
| `task_url` | text | да | URL задачи в браузере |
| `source_payload` | JSON | нет | Сериализованный `ActiveCollabTaskData::toArray()` — состояние строки на момент синхронизации |
| `active_collab_payload` | JSON | да | Payload, отправленный в ActiveCollab API |
| `active_collab_response` | JSON | да | Ответ ActiveCollab API |
| `sync_hash` | string | да | MD5-хеш 13 полей строки (для детектирования изменений) |
| `status` | string | нет | Текущий статус (см. ниже) |
| `error_message` | text | да | Сообщение ошибки при статусе `failed` |
| `created_at` | timestamp | да | Время первой попытки |
| `updated_at` | timestamp | да | Время последнего обновления |

### Жизненный цикл статусов

Legacy status сохраняется для прежнего UI: pending при новом экспорте, created/updated при подтверждённом успехе, failed при неуспехе. Операционные состояния шага 1.2 приведены ниже; они определяют право отправки независимо от legacy status.

**Правила:**
- `task_id`, `task_url`, `sync_hash`, `active_collab_payload`, `active_collab_response` заполняются только при переходе в `created` или `updated`.
- В `failed` всегда записывается `error_message`.
- Legacy `status` не разрешает повтор HTTP. Право отправки определяется reservation и `last_attempt_status`; unknown и running блокируют новую отправку, наличие связи блокирует create.

### Структура source_payload (JSON)

```json
{
  "external_id": "1r9uFA2...:0:8",
  "spreadsheet_id": "1r9uFA2...",
  "sheet_id": "0",
  "row_number": 8,
  "severity": "critical",
  "priority": "высокий",
  "device": "desktop",
  "resolution": "1920x1080",
  "environment": "stage",
  "section": "Каталог",
  "problem_description": "Не открывается карточка товара",
  "screenshot_url": "https://example.com/screenshot.png",
  "comment": "Воспроизводится стабильно",
  "task_type": "backend",
  "responsible": "Tony Yats",
  "reporter": "QA Team",
  "sheet_status": "новая"
}
```

### Структура active_collab_payload (JSON)

```json
{
  "name": "[stage] [critical] Каталог: Не открывается карточка товара",
  "body": "<h3>Описание проблемы</h3>...",
  "task_list_id": 42,
  "assignee_id": 3,
  "label_id": 1
}
```

### Структура active_collab_response (JSON)

```json
{
  "single": {
    "id": 123,
    "url_path": "/projects/10/tasks/123",
    ...
  }
}
```

---

## script_assets

Legacy-таблица, сохранённая до отдельной contract-миграции. Orchid DB-editor и runtime read/write сняты; строки используются только командой архивации.

```sql
CREATE TABLE script_assets (
    id          CHAR(36)        PRIMARY KEY,
    script_key  VARCHAR(255)    NOT NULL UNIQUE,
    name        VARCHAR(255)    NOT NULL,
    content     LONGTEXT        NOT NULL,
    created_at  TIMESTAMP       NULL,
    updated_at  TIMESTAMP       NULL
);
```

| Столбец | Тип | Описание |
|---------|-----|---------|
| `id` | UUID | Первичный ключ |
| `script_key` | string | Канонический ключ файла (единственное значение: `activecollab_sync.gs`) |
| `name` | string | Название для отображения в UI |
| `content` | longtext | Содержимое .gs файла |

`GoogleAppsScriptService` не читает эту таблицу. `scripts:export-assets` выгружает все строки в private JSON archive без изменения данных; таблицу можно удалить отдельной contract-миграцией только после проверки реального backup и diff.

---

## Связи между моделями

```
ActiveCollabIntegration
    │
    │  hasMany (через integration_id)
    ▼
ActiveCollabTaskExport
```

```php
// ActiveCollabTaskExport.php
public function integration(): BelongsTo
{
    return $this->belongsTo(ActiveCollabIntegration::class);
}
```

`integration_id` nullable — исторические записи могут существовать без соответствующей интеграции (если интеграция была удалена).

---

## История миграций

| Файл | Дата | Что делает |
|------|------|-----------|
| `0001_01_01_000000_create_users_table` | Laravel default | Таблицы users, password_reset_tokens, sessions |
| `0001_01_01_000001_create_cache_table` | Laravel default | Таблица cache |
| `0001_01_01_000002_create_jobs_table` | Laravel default | Таблицы jobs, job_batches, failed_jobs |
| `2015_04_12_000000_create_orchid_users_table` | Orchid | Столбцы Orchid в таблице users |
| `2015_10_19_214424_create_orchid_roles_table` | Orchid | Таблица roles |
| `2015_10_19_214425_create_orchid_role_users_table` | Orchid | Таблица role_users |
| `2016_08_07_125128_create_orchid_attachmentstable_table` | Orchid | Таблица attachments |
| `2017_09_17_125801_create_notifications_table` | Orchid | Таблица notifications |
| `2026_06_24_000001_create_active_collab_task_exports_table` | Проект | Создаёт `active_collab_task_exports` |
| `2026_06_24_000002_create_active_collab_integrations_table` | Проект | Создаёт `active_collab_integrations` |
| `2026_06_28_000001_create_script_assets_table` | Проект | Создаёт `script_assets` |
| `2026_06_28_000002_rename_legacy_script_asset_key` | Проект | Переименовывает `poc.gs` → `activecollab_sync.gs` (legacy cleanup) |
| `2026_06_28_000003_drop_active_collab_instance_url_from_integrations` | Проект | Удаляет устаревший столбец `active_collab_instance_url` |
| `2026_07_05_141657_make_active_collab_api_token_nullable` | Проект | Делает `active_collab_api_token` nullable (токен опционален, если задан глобально) |

---

## Индексы

| Таблица | Столбец | Тип |
|---------|---------|-----|
| `active_collab_integrations` | `id` | PRIMARY |
| `active_collab_integrations` | `spreadsheet_id` | UNIQUE |
| `active_collab_task_exports` | `id` | PRIMARY |
| `active_collab_task_exports` | `external_id` | INDEX (неуникальный) |
| `active_collab_task_exports` | `integration_id, record_uuid` | UNIQUE, nullable UUID до переноса |
| `active_collab_task_exports` | `integration_id` | INDEX |
| `script_assets` | `id` | PRIMARY |
| `script_assets` | `script_key` | UNIQUE |

---

## Заметки по безопасности

- `active_collab_api_token` хранится через Laravel `encrypted` cast — значение шифруется с помощью `APP_KEY` перед записью в БД.
- Токен никогда не возвращается в API-ответах, не попадает в логи и не включается в exception messages.
- `active_collab_response` в `active_collab_task_exports` хранит сырой ответ AC API. AC не возвращает токены в теле ответа, поэтому это безопасно.


## Операционные состояния и история (шаг 1.2)

Новая additive-миграция добавляет к exports nullable поля:

| Поле | Значение |
| --- | --- |
| `last_attempt_status` | idle, running, succeeded, failed, unknown; null у ещё не классифицированного legacy-снимка |
| `binding_status` | unbound, bound, remote_missing (задача недоступна в выбранном контексте) |
| `operation_id` | UUID последней операции, сохраняется после завершения для корреляции |
| `lease_expires_at` | Предел владения running-операцией; terminal state очищает lease |
| `synced_at` | Время подтверждённого нового успеха; для старых данных не выводится из updated_at |

Добавлен неуникальный индекс `(integration_id, task_id)`; global unique external_id заменён неуникальным индексом в шаге 1.4 после переключения алгоритма. Nullable legacy integration ID и осиротевшие строки не исправляются автоматически.

`active_collab_sync_attempts`: UUID primary key, export ID (FK restrict delete), integration ID, уникальный operation UUID, action, status, started/finished timestamps, nullable http_status/error_code/error_message, очищенные source_payload/active_collab_payload/active_collab_response и sync_hash. Action vocabulary: create/update/skip/reconcile; записываются create/update и административный reconcile. Status: running/succeeded/failed/unknown. FK на integration не добавлен из-за исторических orphan данных.

Подтверждённые task ID/URL, payload/response и hash на экспорте меняются только при succeeded. Каждая новая попытка отдельно хранит свой source и подготовленный payload/hash. Успешный response ограничен `single.id/url_path`; токены и auth headers в историю не входят. Старый полный upstream snapshot не дополняется вымышленной историей.

Команда `integrations:backfill-sync-state --dry-run` показывает классификацию и ID для сверки без записи. Без флага применяет классификацию только к nullable состояниям без operation ID. Полная связь при created/updated → succeeded/bound; pending/failed и неполный снимок → unknown, binding вычисляется по фактической полноте ID/URL. Повторный запуск не меняет уже классифицированные/работающие операции.


## admin_audit_logs (шаг 1.3)

Additive-миграция `2026_10_30_000001` создаёт UUID primary key, nullable actor_id (BIGINT FK users, nullOnDelete), subject_type/UUID subject_id, nullable integration_id/connection_id, action, request_id UUID, индексированный operation_id UUID, phase, JSON before/after/context и created_at. Unique `(request_id, phase)` допускает только requested/result для одного решения.

Repository добавляет записи, не редактирует их. Before/after содержит только task ID/очищенный URL, состояния и operation ID; context содержит короткую очищенную причину и IDs назначения/источника. Source/AC body, headers, токены и их encrypted values не входят в audit. Connection context пока null, отдельная Connection модель появится позже. Удаление actor сохраняет audit с null actor ID.

Reconcile attempt не переписывает исходную unknown историю. При успешном bind новой задачи текущие confirmed payload/response/hash/synced_at не переносятся от другой цели. При verify той же задачи исторический confirmed snapshot и synced_at сохраняются, hash инвалидируется для следующего PUT. История и audit сохраняют основание смены связи; получить task по GET недостаточно для выставления времени успешной синхронизации.


## Стабильная идентичность (шаг 1.4)

Миграция `2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations.php` добавляет nullable UUID и unique `ac_exports_integration_record_unique`, а также uuid_only default false. Миграция `2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports.php` снимает global locator unique. Данные не переносятся внутри миграций.

Перенос меняет только UUID и текущие locator-поля. Task-связь, confirmed source/payload/response/hash, sync operation, статусы и synced_at сохраняются; старые attempts не дополняются выдуманными данными. Новые экспорты требуют integration ID, исторические null/orphan записи сохраняются и требуют сверки.

Admin audit использует атомарные действия `record_uuid_bound` и `record_uuid_enabled` с phase=result, без фиктивного sync attempt. Batch UUID в operation_id коррелирует административный перенос, не заменяет export.operation_id. Before/after — идентификаторы/locator либо readiness; context — очищенная причина и project ID. Повтор идентичного переноса не создаёт новый аудит. Down отказывается удалять UUID/readiness или восстанавливать global unique при конфликтующих данных; процедура — DEPLOY.
