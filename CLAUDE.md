# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project State

This is an active Laravel 13 project. The main product code is in `app/`, routes in `routes/`, migrations in `database/migrations/`, frontend in `resources/`, and tests in `tests/`. The `resources/apps-script/` directory contains the Google Apps Script client template.

## Build, Test, and Development Commands

Run from the repository root:

```
make up                           # build images, start Sail stack (auto-copies .env.example → .env)
make down                         # stop stack
make setup                        # migrate + seed + npm install + frontend build (first run)
make test                         # run PHPUnit suite
make lint                         # Laravel Pint formatting check
make analyse                      # PHPStan / Larastan static analysis
make check                        # lint + analyse + test + frontend build
make migrate                      # run database migrations
make seed                         # run DatabaseSeeder (creates admin@example.test)
make artisan CMD='route:list'     # any artisan command
make shell                        # shell inside app container
```

Without Docker (local PHP — `composer dev` starts server + queue + logs + Vite):

```
composer setup    # install deps, copy .env, key:generate, migrate, npm install + build
composer dev      # php artisan serve + queue:listen + pail + npm run dev (concurrently)
composer test     # config:clear + php artisan test
composer lint     # pint --test
composer analyse  # phpstan analyse
composer check    # lint + analyse + test
```

Or using docker compose directly:

```
docker compose exec laravel.test php artisan test
docker compose exec laravel.test ./vendor/bin/pint
docker compose exec laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
```

## Coding Style

- PSR-12 formatting, 4-space indentation, `declare(strict_types=1)` in every PHP file
- `PascalCase` classes, `camelCase` methods/variables, `UPPER_SNAKE_CASE` constants
- One class per file; namespace mirrors PSR-4 path under `app/`
- Format with `./vendor/bin/pint`

## Architecture Constraints

**Never violate these:**

- All HTTP calls to ActiveCollab go through `ActiveCollabRepository` only — never directly from controllers or services.
- ActiveCollab API token must never appear in logs, API responses, or exception messages.
- Controllers stay thin: `validate → DTO → service`. Business logic lives in services.
- Repository pattern for all DB access and external HTTP calls.
- `external_id` format is always `{spreadsheet_id}:{sheet_id}:{row_number}`. Never change without updating `ActiveCollabExportRepository` and the Apps Script simultaneously.

**Must not introduce:**

- Data seeding inside migrations
- Duplicate fields, method calls, services, or logic
- Non-existent routes, configs, services, or log channels
- Business logic inside controllers
- Direct HTTP calls to ActiveCollab outside `ActiveCollabRepository`
- ActiveCollab API token in logs, API responses, or exception messages

## Domain Rules

### Export status flow

`pending` → `created` or `failed` (create); `created` → `updated` or `failed` (update).
Never set `created`/`updated` without recording `task_id`, `task_url`, `sync_hash`, `active_collab_payload`, and `active_collab_response`.

### Mapping resolution

`task_list_id`, `assignee_id`, `label_id` are resolved in `ActiveCollabMappingService` from the `ActiveCollabIntegration` model (`task_list_mapping`, `assignee_mapping`, `label_mapping` JSON columns), with fallback to `config('services.active_collab.*')`.
- Missing required mapping → throw `RuntimeException` with a descriptive message.
- Missing optional mapping → return `null`, skip the field in payload.

### Sync hash

Calculated in `ActiveCollabTaskService::calcSyncHash()` — MD5 of 13 data fields joined with `|`:
`severity|priority|device|resolution|environment|section|problemDescription|screenshotUrl|comment|taskType|responsible|reporter|sheetStatus`.

### AC API response shape

Always access task ID via `$response['single']['id']` and URL via `$response['single']['url_path']`.

### Auth

`VerifyIntegrationToken` middleware is applied to all `/api/integrations/activecollab/*` routes. It validates a Bearer token against `config('services.google_sheets.integration_token')`.

### Task name format

`[{environment}] [{severity}] {section}: {description (max 100 chars)}`

### Task body format

HTML with sections: Описание проблемы, Параметры (ul), Скриншоты (only if URL), Комментарий (only if present), hr, Источник. HTML-escape all user content. nl2br for multi-line fields. Skip empty sections entirely.

### Apps Script

`resources/apps-script/activecollab_sync.gs` is the source template. The canonical working version is stored in the database via the Orchid admin `Apps Script` section. The file on disk is only used as a bootstrap template when no DB record exists.

Apps Script reads `INTEGRATION_SERVICE_URL` and `INTEGRATION_SERVICE_TOKEN` from Script Properties and calls Laravel. The ActiveCollab token must never appear in Apps Script.

## Work Folder Workflow

Planned tasks go through `work/`:

- `work/TASK.md` — task description
- `work/diff.txt` — existing changes / context
- `work/AGENT_RULES.md` — task-level execution policy
- `work/PLAN.md` — plan written before any implementation

**Never implement before the plan is approved.** Use `/work` to trigger the workflow.
