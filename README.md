# SheetCollab

SheetCollab is a Laravel 13 service for synchronizing issues from Google Sheets
with ActiveCollab, plus an Orchid admin panel for managing integrations and
mappings.

## Stack

- PHP 8.5
- Laravel 13.18
- Orchid Platform 14
- MariaDB 11.4
- Docker Compose / Laravel Sail for local development
- Vite 8 + Tailwind CSS 4

Redis is not part of the project runtime. Sessions, cache, and queues use database drivers by default.

## What the project does

- accepts authenticated API requests from a Google Sheets integration
- creates or updates tasks in ActiveCollab
- stores export history and sync state in the local database
- provides Orchid screens for configuring integrations, mappings, and reference data

## Main modules

- `app/Http/Controllers/Api/ActiveCollabTaskController.php`:
  API endpoints for task sync and reference lookups
- `app/Services/ActiveCollab/ActiveCollabTaskService.php`:
  main sync orchestration
- `app/Services/ActiveCollab/ActiveCollabIntegrationSettingsService.php`:
  normalizes ActiveCollab and spreadsheet settings before save
- `app/Services/ActiveCollab/ActiveCollabMappingService.php`:
  resolves task list, assignee, and label mappings
- `app/Repositories/ActiveCollab/`:
  HTTP client to ActiveCollab and export/integration persistence helpers
- `routes/api.php`:
  protected integration API
- `routes/platform.php`:
  Orchid admin routes
- `resources/apps-script/activecollab_sync.gs`:
  source template for the Google Apps Script client

## Local development

The project is Docker-first. `make` wraps all Sail commands.

### 1. Prepare environment

```bash
make up            # build images, start containers, auto-copies .env.example → .env
make setup         # migrate + seed + npm install + frontend build
```

Or manually with docker compose:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate --seed
docker compose exec laravel.test npm install
docker compose exec laravel.test npm run build
```

### 2. Main URLs

- app: `http://localhost`
- Vite dev server: `http://localhost:5173`
- admin: `http://localhost/admin`

### 3. Admin access

After `make setup` (or `migrate --seed`), the local seeded user is:

- email: `admin@example.test`
- password: `LocalAdmin123!`

Change these credentials for any non-local environment.

### 4. Useful commands

```bash
make up                              # start stack
make down                            # stop stack
make test                            # run tests
make lint                            # pint formatting check
make analyse                         # phpstan static analysis
make check                           # lint + analyse + test + npm build
make migrate                         # apply migrations
make seed                            # run DatabaseSeeder
make artisan CMD='route:list'        # any artisan command
make shell                           # shell inside container
make logs                            # follow container logs
```

## Apps Script

The canonical script is stored in the database and managed via the Orchid admin at `/admin/script`.

`resources/apps-script/activecollab_sync.gs` is the on-disk template used to seed the DB record on first run.

The script reads `INTEGRATION_SERVICE_URL` and `INTEGRATION_SERVICE_TOKEN` from Google Apps Script Properties and calls the Laravel API. It does **not** contain the ActiveCollab token.

### Columns the script understands

| Column header | Purpose |
|---|---|
| Ссылка на задачу | written back after task creation |
| Серьезность | task name + body |
| Стенд | required; task name + body |
| Раздел | required; task name + body |
| Описание проблемы | required; task name + body |
| Приоритет | body |
| Устройство | body |
| Разрешение | body |
| Скриншот (ссылка) | body |
| Комментарий | body |
| Тип задач | task list mapping |
| Ответственный | assignee mapping |
| Постановщик | body |
| Статус | body |
| Ошибка синхронизации | written back on error |
| Дата синхронизации | written back after every attempt |

Header row is found dynamically (first row containing «Серьезность» in the first 10 rows).

## Environment

Important variables in `.env`:

| Variable | Description |
|---|---|
| `GOOGLE_SHEETS_INTEGRATION_TOKEN` | Bearer token checked on all API routes |
| `ACTIVECOLLAB_BASE_URL` | AC API base URL (e.g. `https://app.activecollab.com/460565/api/v1`) |
| `ACTIVECOLLAB_PROJECT_ID` | default AC project ID |
| `ACTIVECOLLAB_API_TOKEN` | AC token for helper endpoints (task-lists, users, labels) |

Per-integration settings (base URL, project ID, API token, mappings) are also configurable in the Orchid admin per spreadsheet, overriding these defaults.

## API

All integration routes are protected by Bearer token auth with `GOOGLE_SHEETS_INTEGRATION_TOKEN`.

Base prefix:

```text
/api/integrations/activecollab
```

Endpoints:

- `POST /tasks` — create task
- `PUT /tasks/{taskId}` — update task
- `GET /task-lists` — list task lists (uses default AC config)
- `GET /users` — list users (uses default AC config)
- `GET /labels` — list labels (uses default AC config)

Example request:

```bash
curl -X POST http://localhost/api/integrations/activecollab/tasks \
  -H "Authorization: Bearer ${GOOGLE_SHEETS_INTEGRATION_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "spreadsheet_id": "YOUR_SPREADSHEET_ID",
    "sheet_id": "0",
    "row_number": 8,
    "environment": "stage",
    "section": "Каталог",
    "description": "Не открывается карточка товара",
    "severity": "critical",
    "priority": "высокий",
    "device": "desktop",
    "resolution": "1920x1080",
    "screenshot": "https://example.com/screenshot.png",
    "comment": "Воспроизводится стабильно",
    "task_type": "backend",
    "responsible": "Tony Yats",
    "reporter": "QA Team",
    "status": "новая"
  }'
```

Response:

```json
{
  "data": {
    "task_id": 123,
    "task_url": "https://next-app.activecollab.com/460565/projects/10/tasks/123",
    "synced_at": "2026-07-05T12:00:00+00:00",
    "sync_hash": "abc123..."
  }
}
```

`409` is returned when a task already exists for this row; the response includes `data.task_id` and `data.task_url`.

## Testing and quality

```bash
make test      # PHPUnit
make lint      # Pint formatting check
make analyse   # PHPStan / Larastan
make check     # all of the above + frontend build
```

Or directly:

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test ./vendor/bin/pint --test
docker compose exec laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec laravel.test composer check
```

Test suites:

- `tests/Feature`: API flows, auth, integration behavior
- `tests/Unit`: mapping logic, settings normalization

## Documentation

| Document | Description |
|----------|-------------|
| [docs/CURRENT_STATE.md](docs/CURRENT_STATE.md) | Current implementation review, verified quality status, known limitations and risks |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System architecture, layer diagram, class map, request flow |
| [docs/API_REFERENCE.md](docs/API_REFERENCE.md) | Full API reference: all endpoints, request/response schemas, error codes |
| [docs/DATA_MODEL.md](docs/DATA_MODEL.md) | Database schema, all tables and columns, JSON column structures, migration history |
| [docs/SERVICES.md](docs/SERVICES.md) | All classes: services, repositories, models, DTO, exceptions |
| [docs/TESTING.md](docs/TESTING.md) | Test coverage, how to run, test patterns |
| [docs/CODE_QUALITY.md](docs/CODE_QUALITY.md) | Pint, PHPStan, Rector — configs, commands, workflow |
| [docs/PROJECT_MANUAL.md](docs/PROJECT_MANUAL.md) | Full Russian-language manual: logic, Orchid walkthrough, troubleshooting |
| [docs/LOGIC.md](docs/LOGIC.md) | Usage scenarios, integration configuration guide |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Production deployment guide |

## Production

See `docs/DEPLOY.md` for full deployment instructions.

A production compose file is included as `compose.prod.yaml`.

```bash
docker compose -f compose.prod.yaml up -d --build
docker compose -f compose.prod.yaml exec app php artisan key:generate
docker compose -f compose.prod.yaml exec app php artisan migrate --force
docker compose -f compose.prod.yaml exec app npm run build
```
