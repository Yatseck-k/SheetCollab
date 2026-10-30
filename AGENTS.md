# Repository Guidelines

## Project Structure & Module Organization

The main product code lives in `app/`, including `Http/`, `Models/`, `Services/`, `Repositories/ActiveCollab/`, and `Orchid/` for admin UI screens and layouts. Routes are in `routes/`, database migrations and seeders in `database/`, Blade and frontend assets in `resources/`, and public build output in `public/`. Tests live under `tests/Unit` and `tests/Feature`. The `docs/` directory contains domain documentation and deployment instructions.

## Build, Test, and Development Commands

Run from this directory:

- `make up` — build images, start Sail stack (auto-copies `.env.example → .env`)
- `make down` — stop the stack
- `make setup` — migrate + seed + npm install + frontend build (first run)
- `make test` — run PHPUnit suite
- `make lint` — check formatting with Pint
- `make analyse` — run PHPStan / Larastan static analysis
- `make check` — lint + analyse + test + frontend build
- `make migrate` — apply database migrations
- `make seed` — run DatabaseSeeder (creates `admin@example.test`)
- `make artisan CMD='route:list'` — run any artisan command
- `make shell` — shell inside app container

Without Docker (local PHP):

- `composer setup` — install deps, copy `.env`, key:generate, migrate, npm install + build
- `composer dev` — php artisan serve + queue:listen + pail + npm run dev (concurrently)
- `composer test` — config:clear + php artisan test
- `composer check` — lint + analyse + test

Or using docker compose directly:

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test ./vendor/bin/pint
docker compose exec laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec laravel.test composer check
```

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF line endings, 4-space indentation for PHP; Markdown keeps trailing spaces, YAML uses 2 spaces. Use PSR-4 namespaces that mirror directories under `app/`. Keep class names singular and descriptive, such as `ActiveCollabTaskService` or `IntegrationEditScreen`. Format PHP with `./vendor/bin/pint`.

## Architecture Constraints

- All HTTP calls to ActiveCollab go through `app/Repositories/ActiveCollab/ActiveCollabRepository.php` only.
- ActiveCollab API token must not appear in logs, API responses, or exception messages.
- Controllers stay thin: validate → DTO → service. Business logic lives in services.
- `external_id` format is always `{spreadsheet_id}:{sheet_id}:{row_number}`; it is a nonunique position locator. UUID integrations use `(integration_id, record_uuid)` as identity. Never adopt a legacy binding by row number; use the controlled, audited migration while writers are stopped.

## Testing Guidelines

PHPUnit is configured in `phpunit.xml` with `tests/Unit` and `tests/Feature`, coverage scoped to `app/`. Add focused tests beside the appropriate suite and name files `*Test.php`. Prefer feature tests for routes, middleware, and Orchid flows; use unit tests for services, data objects, and repositories. Run `make test` before opening a PR.

## Commit & Pull Request Guidelines

Use short, imperative commit subjects such as `Add ActiveCollab mapping validation`. In pull requests, include the scope, database or env changes, test coverage notes, and screenshots for Orchid UI changes.

## Security & Configuration Tips

Do not commit secrets from `.env`. Keep new configuration mirrored in `.env.example`, and document required tokens or integration settings when adding external services.

## Key Entry Points

If you need to understand behavior fast, read in this order:

1. `CLAUDE.md`
2. `docs/PROJECT_MANUAL.md`
3. `resources/apps-script/activecollab_sync.gs`
4. `routes/api.php`
5. `app/Http/Controllers/Api/ActiveCollabTaskController.php`
6. `app/Services/ActiveCollab/ActiveCollabTaskService.php`
7. `app/Repositories/ActiveCollab/`
