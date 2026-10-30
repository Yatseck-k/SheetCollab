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
- `external_id` format remains `{spreadsheet_id}:{sheet_id}:{row_number}`; it is a nonunique position locator. UUID integrations identify records only by `(integration_id, record_uuid)`, never adopt a binding by row number. Legacy integrations keep guarded locator lookup until explicit migration.

**Must not introduce:**

- Data seeding inside migrations
- Duplicate fields, method calls, services, or logic
- Non-existent routes, configs, services, or log channels
- Business logic inside controllers
- Direct HTTP calls to ActiveCollab outside `ActiveCollabRepository`
- ActiveCollab API token in logs, API responses, or exception messages

## Domain Rules

### Export status flow

Legacy `status` remains `pending/created/updated/failed` for existing consumers; it never grants permission to send HTTP.
`last_attempt_status`: `idle` → `running` → `succeeded/failed/unknown`. Only failures confirmed before dispatch are safely retryable. A bound PUT 404 becomes remote_missing with the binding preserved; it never grants automatic replacement. Historical pending/failed with null new state are conservatively classified unknown.
`binding_status`: `unbound/bound/remote_missing`; failed/unknown must preserve the last confirmed task ID/URL, hash and payload.
Reserve an operation and its attempt atomically in `ActiveCollabExportRepository`, commit before external HTTP, then complete both under the same operation ID and running-state lock. Expired lease becomes unknown, never takeover. Reject ordinary retries of unknown. Only explicit admin recovery with expected-state checks, a new operation ID and an atomic audit may resolve it. Do not mix old and new writers.
Never set `created`/`updated` without recording `task_id`, `task_url`, `sync_hash`, `active_collab_payload`, and `active_collab_response`.
Admin recovery requires all three Orchid permissions: platform.activecollab.recovery, platform.systems.users, platform.systems.roles. Recheck the actor and frozen effective destination before finalization. GET verifies existence/binding only: invalidate hash, never fabricate synced_at. Keep old unknown attempts unchanged and append a reconcile attempt plus requested/result audit phases.
The scheduled integrations:expire-sync-operations command marks only expired running operations unknown; missing attempt history is reported, never fabricated.
Attempts store only sanitized source/task fields and whitelisted response `single.id/url_path`. Never store or log raw upstream exception messages, response bodies or auth headers. No automatic POST/PUT retries. Connect/total timeout defaults are 5/30 seconds, with a 120-second lease that must exceed total timeout.

### Mapping resolution

`task_list_id`, `assignee_id`, `label_id` are resolved in `ActiveCollabMappingService` from the `ActiveCollabIntegration` model (`task_list_mapping`, `assignee_mapping`, `label_mapping` JSON columns), with fallback to `config('services.active_collab.*')`.
- Missing required mapping → throw `RuntimeException` with a descriptive message.
- Missing optional mapping → return `null`, skip the field in payload.

### Sync hash

Calculated in `ActiveCollabTaskService::calcSyncHash()` — MD5 of 13 data fields joined with `|`:
`severity|priority|device|resolution|environment|section|problemDescription|screenshotUrl|comment|taskType|responsible|reporter|sheetStatus`.

### AC API response shape

For recovery GET, validate exact task ID, project_id and boolean is_trashed; 404 alone does not prove deletion or absence of an earlier create. Do not automatically retry POST/PUT or search by task text.
Always access task ID via `$response['single']['id']` and URL via `$response['single']['url_path']`.

### Auth

`VerifyIntegrationToken` middleware is applied to all `/api/integrations/activecollab/*` routes. It validates a Bearer token against `config('services.google_sheets.integration_token')`.

### Task name format

`[{environment}] [{severity}] {section}: {description (max 100 chars)}`

### Task body format

HTML with sections: Описание проблемы, Параметры (ul), Скриншоты (only if URL), Комментарий (only if present), hr, Источник. HTML-escape all user content. nl2br for multi-line fields. Skip empty sections entirely.

### Record identity

`uuid_only` defaults false and is changed only by the paused, audited `integrations:migrate-record-uuids` command. UUID and legacy modes do not mix: mismatched requests return legacy_binding_conflict. Any existing UUID binding blocks legacy writes even before the readiness flag is enabled. Reserve locks integration then export; HTTP runs after commit. Recovery taking both locks uses the same order. Orphan/ambiguous history blocks migration; UUID comes from Sheets, and an existing task link confirms initial adoption. Keep unknown/remote_missing and historical attempts unchanged. Never roll back to row-number code after UUID bindings without a separate stopped-writer recovery procedure.

### Apps Script

`resources/apps-script/activecollab_sync.gs` is the source template. The canonical working version is stored in the database via the Orchid admin `Apps Script` section. The file on disk is only used as a bootstrap template when no DB record exists.

The UUID template uses a hidden `SheetCollab UUID` column, document locks and a whole-spreadsheet duplicate check. Sort full rows including this column. After HTTP find the unique UUID again before writing the result. Explicit new-copy action resets only the selected copy. Existing DB script versions require export/review/manual update through the editor; changing the bootstrap file alone does not deploy a client update.

Apps Script reads `INTEGRATION_SERVICE_URL` and `INTEGRATION_SERVICE_TOKEN` from Script Properties and calls Laravel. The ActiveCollab token must never appear in Apps Script.

## Work Folder Workflow

Planned tasks go through `work/`:

- `work/TASK.md` — task description
- `work/diff.txt` — existing changes / context
- `work/AGENT_RULES.md` — task-level execution policy
- `work/PLAN.md` — plan written before any implementation

**Never implement before the plan is approved.** Use `/work` to trigger the workflow.
