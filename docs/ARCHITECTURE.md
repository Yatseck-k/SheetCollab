# Архитектура системы

## 1. Назначение

SheetCollab — Laravel-сервис, реализующий мост между Google Sheets и ActiveCollab.

Поток данных:

```
Google Sheets
    │
    │  (пользователь нажимает «Создать задачу»)
    ▼
Google Apps Script
    │
    │  POST /api/integrations/activecollab/tasks
    │  Authorization: Bearer <GOOGLE_SHEETS_INTEGRATION_TOKEN>
    ▼
Laravel API (этот сервис)
    │
    │  X-Angie-AuthApiToken: <ACTIVECOLLAB_API_TOKEN>
    ▼
ActiveCollab REST API
    │
    │  (ответ с task_id и url_path)
    ▼
Laravel API
    │
    │  { task_id, task_url, synced_at, sync_hash }
    ▼
Google Apps Script
    │
    │  (записывает ссылку в ячейку «Ссылка на задачу»)
    ▼
Google Sheets
```

---

## 2. Стек технологий

| Компонент       | Версия       | Роль                                      |
|-----------------|--------------|-------------------------------------------|
| PHP             | 8.5          | язык платформы                            |
| Laravel         | 13.18        | фреймворк                                 |
| Orchid Platform | 14           | админ-панель                              |
| MariaDB         | 11.4         | основная БД                               |
| Docker Compose  | —            | локальная разработка и production-деплой  |
| Laravel Sail    | —            | обёртка над Docker Compose для dev        |
| Vite 8          | —            | сборка фронтенда Orchid                   |
| Tailwind CSS 4  | —            | стили Orchid                              |
| PHPUnit         | 12.5         | тестирование                              |
| Laravel Pint    | ^1.27        | форматирование кода                       |
| Larastan        | ^3.0         | статический анализ (PHPStan level 6)      |
| Rector          | ^2.5         | автоматический рефакторинг                |

Redis отсутствует. Сессии, кеш и очереди работают через database-драйвер.

Актуальный срез реализации, результаты проверок и известные ограничения
зафиксированы в [CURRENT_STATE.md](CURRENT_STATE.md).

---

## 3. Слоевая архитектура

```
┌─────────────────────────────────────────────────────────┐
│                       HTTP Layer                        │
│  routes/api.php           routes/platform.php           │
│  VerifyIntegrationToken   Orchid middleware              │
│  ActiveCollabTaskController                             │
└───────────────────────────┬─────────────────────────────┘
                            │  validated DTO
                            ▼
┌─────────────────────────────────────────────────────────┐
│                     Service Layer                       │
│  ActiveCollabTaskService          (оркестрация)         │
│  ActiveCollabMappingService       (резолв маппингов)    │
│  ActiveCollabTaskBodyBuilder      (формирование задачи) │
│  ActiveCollabIntegrationSettingsService (нормализация)  │
│  GoogleAppsScriptService          (скачивание скрипта)  │
└────────────┬───────────────────────────┬────────────────┘
             │                           │
             ▼                           ▼
┌────────────────────┐     ┌─────────────────────────────┐
│  Repository Layer  │     │  Repository Layer            │
│  (DB access)       │     │  (External HTTP)             │
│                    │     │                              │
│  ActiveCollab-     │     │  ActiveCollabRepository      │
│  ExportRepository  │     │  (HTTP client → AC API)      │
│                    │     │                              │
│  ActiveCollab-     │     └──────────────┬──────────────┘
│  IntegrationRepo.  │                    │
└────────────────────┘                    │ X-Angie-AuthApiToken
                                          ▼
                               ┌──────────────────────┐
                               │  ActiveCollab REST   │
                               │  API (external)      │
                               └──────────────────────┘
```

### Правила слоёв

- **Контроллеры**: только `validate → DTO → service`. Никакой бизнес-логики.
- **Сервисы**: бизнес-логика, оркестрация, вычисления. Не работают с БД напрямую — через репозитории.
- **Репозитории (DB)**: всё чтение и запись в Eloquent-модели.
- **Репозиторий (HTTP)**: все HTTP-вызовы в ActiveCollab — только через `ActiveCollabRepository`. Никогда не из сервисов или контроллеров напрямую.

---

## 4. Карта классов

### HTTP / Middleware

| Класс | Путь | Назначение |
|-------|------|-----------|
| `ActiveCollabTaskController` | `app/Http/Controllers/Api/` | 5 API endpoints для Apps Script |
| `VerifyIntegrationToken` | `app/Http/Middleware/` | Bearer-token аутентификация |
| `StoreActiveCollabTaskRequest` | `app/Http/Requests/` | валидация payload создания задачи |
| `UpdateActiveCollabTaskRequest` | `app/Http/Requests/` | валидация payload обновления задачи |

### Data Transfer Objects

| Класс | Путь | Назначение |
|-------|------|-----------|
| `ActiveCollabTaskData` | `app/Data/ActiveCollab/` | readonly DTO входящего запроса; строит `external_id` |

### Services

| Класс | Путь | Назначение |
|-------|------|-----------|
| `ActiveCollabTaskService` | `app/Services/ActiveCollab/` | главный оркестратор create/update |
| `ActiveCollabMappingService` | `app/Services/ActiveCollab/` | резолвит task_list_id, assignee_id, label_id из маппингов |
| `ActiveCollabTaskBodyBuilder` | `app/Services/ActiveCollab/` | строит name и HTML-body задачи |
| `ActiveCollabIntegrationSettingsService` | `app/Services/ActiveCollab/` | нормализует и валидирует настройки интеграции перед сохранением |
| `GoogleAppsScriptService` | `app/Services/` | отдаёт .gs файл из базы данных на скачивание |

### Repositories

| Класс | Путь | Назначение |
|-------|------|-----------|
| `ActiveCollabRepository` | `app/Repositories/ActiveCollab/` | HTTP-клиент к ActiveCollab API |
| `ActiveCollabExportRepository` | `app/Repositories/ActiveCollab/` | CRUD для `active_collab_task_exports` |
| `ActiveCollabIntegrationRepository` | `app/Repositories/ActiveCollab/` | поиск интеграции по `spreadsheet_id` |

### Models

| Класс | Таблица | Назначение |
|-------|---------|-----------|
| `ActiveCollabIntegration` | `active_collab_integrations` | настройки интеграции для таблицы |
| `ActiveCollabTaskExport` | `active_collab_task_exports` | журнал синхронизаций строк |
| `ScriptAsset` | `script_assets` | хранилище .gs скриптов в БД |
| `User` | `users` | пользователи Orchid-панели |

### Exceptions

| Класс | Путь | HTTP-ответ |
|-------|------|-----------|
| `IntegrationNotFoundException` | `app/Exceptions/` | 404 |
| `TaskAlreadyExistsException` | `app/Exceptions/` | 409 с task_id и task_url |

### Orchid Screens

| Класс | Маршрут | Назначение |
|-------|---------|-----------|
| `PlatformScreen` | `/admin/main` | главная панели |
| `IntegrationListScreen` | `/admin/integrations` | список интеграций |
| `IntegrationEditScreen` | `/admin/integrations/{id}/edit` | создание и редактирование интеграции |
| `ExportListScreen` | `/admin/exports` | история экспортов |
| `ScriptEditorScreen` | `/admin/script` | редактор Google Apps Script |
| `GuideScreen` | `/admin/guide` | инструкция |
| `UserListScreen` | `/admin/users` | управление пользователями |
| `UserEditScreen` | `/admin/users/{id}/edit` | редактирование пользователя |
| `UserProfileScreen` | `/admin/profile` | профиль текущего пользователя |
| `RoleListScreen` | `/admin/roles` | управление ролями |
| `RoleEditScreen` | `/admin/roles/{id}/edit` | редактирование роли |

---

## 5. Полная цепочка create-запроса

```
Apps Script
  │
  │  POST /api/integrations/activecollab/tasks
  │  { spreadsheet_id, sheet_id, row_number, environment, section, description, ... }
  ▼
VerifyIntegrationToken::handle()
  │  проверяет Authorization: Bearer == GOOGLE_SHEETS_INTEGRATION_TOKEN
  ▼
StoreActiveCollabTaskRequest::rules()
  │  validates required fields, types, URL format
  ▼
ActiveCollabTaskController::store()
  │  ActiveCollabTaskData::fromRequest($request->validated())
  │    строит externalId = "{spreadsheetId}:{sheetId}:{rowNumber}"
  ▼
ActiveCollabTaskService::create()
  │
  ├─ ActiveCollabIntegrationRepository::findBySpreadsheetId()
  │    SELECT * FROM active_collab_integrations WHERE spreadsheet_id=? AND active=1
  │    → null → IntegrationNotFoundException → 404
  │
  ├─ ActiveCollabExportRepository::findByExternalId()
  │    SELECT * FROM active_collab_task_exports WHERE external_id=?
  │    → found AND status IN (created, updated) → TaskAlreadyExistsException → 409
  │
  ├─ ActiveCollabExportRepository::createPending()
  │    INSERT INTO active_collab_task_exports (status='pending', ...)
  │
  ├─ ActiveCollabMappingService::resolveTaskListId()
  │    если task_type пустой → integration.active_collab_task_list_id
  │    иначе ищет strtolower(task_type) в task_list_mapping
  │    → не найдено → RuntimeException → 500
  │
  ├─ ActiveCollabMappingService::resolveAssigneeId()
  │    если responsible пустой → null (поле пропускается)
  │    иначе ищет exact match в assignee_mapping
  │    → не найдено → RuntimeException → 500
  │
  ├─ ActiveCollabMappingService::resolveLabelId()
  │    если severity пустой → null (поле пропускается)
  │    иначе ищет strtolower(severity) в label_mapping
  │    → не найдено → null (не ошибка, поле просто пропускается)
  │
  ├─ ActiveCollabTaskBodyBuilder::buildName()
  │    "[environment] [severity] section: description (max 100)"
  │
  ├─ ActiveCollabTaskBodyBuilder::buildBody()
  │    HTML с секциями: Описание, Параметры, Скриншоты, Комментарий, Источник
  │
  ├─ ActiveCollabRepository::createTask()
  │    POST {base_url}/projects/{project_id}/tasks
  │    X-Angie-AuthApiToken: {resolved token}
  │    → 4xx/5xx → RequestException → markFailed + rethrow
  │
  ├─ calcSyncHash()
  │    md5(13 полей, разделённых '|')
  │
  ├─ ActiveCollabExportRepository::markCreated()
  │    UPDATE ... SET status='created', task_id=?, task_url=?, sync_hash=?, ...
  │
  └─ return { task_id, task_url, synced_at, sync_hash }
       │
       ▼
  HTTP 201 { "data": { task_id, task_url, synced_at, sync_hash } }
       │
       ▼
  Apps Script записывает ссылку в ячейку
```

---

## 6. Архитектурные инварианты

Эти правила никогда не нарушаются:

1. **Все HTTP-вызовы в ActiveCollab** — только через `ActiveCollabRepository`. Ни в сервисах, ни в контроллерах.
2. **API-токен ActiveCollab** никогда не попадает в логи, API-ответы или exception messages.
3. **Контроллеры** не содержат бизнес-логики: только `validate → DTO → service`.
4. **`external_id`** всегда имеет формат `{spreadsheet_id}:{sheet_id}:{row_number}`. Изменение требует синхронной правки Apps Script.
5. **Статус export** переходит только в одну сторону: `pending → created/failed` или `created → updated/failed`.
6. **Маппинги**: при отсутствии required-маппинга (task_list, assignee) бросается `RuntimeException`. Отсутствие optional-маппинга (label) — не ошибка.

---

## 7. Конфигурация и переменные окружения

Ключевые переменные в `.env`:

| Переменная | Config-ключ | Назначение |
|------------|-------------|-----------|
| `GOOGLE_SHEETS_INTEGRATION_TOKEN` | `services.google_sheets.integration_token` | Bearer-токен для всех API-запросов из Apps Script |
| `ACTIVECOLLAB_BASE_URL` | `services.active_collab.base_url` | Дефолтный AC API Base URL (fallback для интеграций без своего URL) |
| `ACTIVECOLLAB_PROJECT_ID` | `services.active_collab.project_id` | Дефолтный Project ID |
| `ACTIVECOLLAB_API_TOKEN` | `services.active_collab.api_token` | Дефолтный AC API Token (helper endpoints и fallback для интеграций) |
| `APP_URL` | `app.url` | Публичный URL сервиса (нужен для корректных ссылок в Orchid) |
| `DB_*` | `database.*` | Параметры подключения к MariaDB |

Настройки отдельных интеграций в таблице `active_collab_integrations` **перекрывают** глобальные defaults.

---

## 8. Структура директорий

```
SheetCollab/
├── app/
│   ├── Console/               # artisan-команды (если есть)
│   ├── Data/
│   │   └── ActiveCollab/      # DTO
│   ├── Exceptions/            # кастомные исключения
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/           # API контроллеры
│   │   ├── Middleware/        # VerifyIntegrationToken
│   │   └── Requests/          # Form Requests с валидацией
│   ├── Models/                # Eloquent-модели
│   ├── Orchid/
│   │   ├── Filters/           # фильтры для списков
│   │   ├── Layouts/           # Orchid Layout классы
│   │   ├── Presenters/        # Orchid Presenters
│   │   ├── Screens/           # Orchid Screen классы
│   │   └── PlatformProvider.php
│   ├── Providers/             # AppServiceProvider
│   ├── Repositories/
│   │   └── ActiveCollab/      # репозитории (DB + HTTP)
│   └── Services/
│       └── ActiveCollab/      # бизнес-логика
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/                      # документация
├── resources/
│   ├── apps-script/           # шаблон .gs скрипта
│   └── views/
├── routes/
│   ├── api.php                # API-маршруты
│   ├── platform.php           # Orchid-маршруты
│   └── web.php
├── tests/
│   ├── Feature/               # API и интеграционные тесты
│   └── Unit/                  # unit-тесты логики
├── pint.json                  # конфиг Laravel Pint
├── phpstan.neon.dist           # конфиг PHPStan
├── rector.php                 # конфиг Rector
├── Makefile                   # команды разработки
└── compose.yaml               # Docker Compose (dev)
```
