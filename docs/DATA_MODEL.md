# Модель данных

## Таблицы проекта

| Таблица | Модель | Назначение |
|---------|--------|-----------|
| `active_collab_integrations` | `ActiveCollabIntegration` | Настройки интеграции для Google Sheets таблицы |
| `active_collab_task_exports` | `ActiveCollabTaskExport` | Журнал синхронизаций строк |
| `script_assets` | `ScriptAsset` | Хранилище .gs скриптов в базе данных |
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
    active_collab_base_url      VARCHAR(255)     NOT NULL,
    active_collab_project_id    INT UNSIGNED     NOT NULL,
    active_collab_task_list_id  INT UNSIGNED     NOT NULL,
    active_collab_api_token     TEXT             NULL,                  -- зашифровано
    task_list_mapping           JSON             NULL,
    assignee_mapping            JSON             NULL,
    label_mapping               JSON             NULL,
    active                      TINYINT(1)       NOT NULL DEFAULT 1,
    created_at                  TIMESTAMP        NULL,
    updated_at                  TIMESTAMP        NULL
);
```

### Описание столбцов

| Столбец | Тип | NULL | Описание |
|---------|-----|------|---------|
| `id` | UUID | нет | Первичный ключ (UUID v7, auto-generated) |
| `name` | string | нет | Человекочитаемое название интеграции |
| `spreadsheet_id` | string | нет | ID Google Sheets (без URL). Unique — одна таблица = одна интеграция |
| `active_collab_base_url` | string | нет | AC API base URL, всегда с `/api/v1` (нормализуется при сохранении) |
| `active_collab_project_id` | integer | нет | ID проекта в ActiveCollab |
| `active_collab_task_list_id` | integer | нет | Дефолтный task list ID в проекте |
| `active_collab_api_token` | text | да | Токен AC API. Хранится зашифрованным (Laravel `encrypted` cast). Если пустой — используется `ACTIVECOLLAB_API_TOKEN` из `.env` |
| `task_list_mapping` | JSON | да | Маппинг `task_type → task_list_id`. Ключи — lowercase строки |
| `assignee_mapping` | JSON | да | Маппинг `responsible → user_id`. Ключи — exact match строки |
| `label_mapping` | JSON | да | Маппинг `severity → label_id`. Ключи — lowercase строки |
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
// Разрешённый base_url: интеграция > config
$integration->resolvedBaseUrl(): string

// URL для браузера (без /api/v1)
$integration->resolvedInstanceUrl(): string

// Токен: интеграция > config
$integration->resolvedApiToken(): string
```

---

## active_collab_task_exports

Журнал всех попыток синхронизации строк. Каждая строка таблицы имеет не более одной записи (уникальность по `external_id`).

```sql
CREATE TABLE active_collab_task_exports (
    id                      CHAR(36)         PRIMARY KEY,
    integration_id          CHAR(36)         NULL     INDEX,
    external_id             VARCHAR(255)     NOT NULL UNIQUE,
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
| `integration_id` | UUID | да | FK на `active_collab_integrations.id` (nullable для исторических записей) |
| `external_id` | string | нет | Уникальный ключ строки: `{spreadsheet_id}:{sheet_id}:{row_number}` |
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

```
                     create()
                        │
                  createPending()
                        │
                        ▼
                    ┌───────┐
                    │pending│
                    └───┬───┘
                        │
              ┌─────────┴─────────┐
              │                   │
         (успех)             (ошибка)
              │                   │
              ▼                   ▼
          ┌───────┐          ┌────────┐
          │created│          │ failed │
          └───┬───┘          └────────┘
              │
         update()
              │
      ┌───────┴───────┐
      │               │
 (успех)          (ошибка)
      │               │
      ▼               ▼
  ┌───────┐      ┌────────┐
  │updated│      │ failed │
  └───────┘      └────────┘
```

**Правила:**
- `task_id`, `task_url`, `sync_hash`, `active_collab_payload`, `active_collab_response` заполняются только при переходе в `created` или `updated`.
- В `failed` всегда записывается `error_message`.
- Статус `failed` не является терминальным — следующий create-запрос с тем же `external_id` попробует снова.

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

Хранит скрипты Google Apps Script в базе данных. Содержимое редактируется через Orchid-панель.

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

`GoogleAppsScriptService` читает запись с `script_key = 'activecollab_sync.gs'`. Если записи нет, загружает файл из `resources/apps-script/activecollab_sync.gs` и создаёт запись.

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
| `active_collab_task_exports` | `external_id` | UNIQUE |
| `active_collab_task_exports` | `integration_id` | INDEX |
| `script_assets` | `id` | PRIMARY |
| `script_assets` | `script_key` | UNIQUE |

---

## Заметки по безопасности

- `active_collab_api_token` хранится через Laravel `encrypted` cast — значение шифруется с помощью `APP_KEY` перед записью в БД.
- Токен никогда не возвращается в API-ответах, не попадает в логи и не включается в exception messages.
- `active_collab_response` в `active_collab_task_exports` хранит сырой ответ AC API. AC не возвращает токены в теле ответа, поэтому это безопасно.
