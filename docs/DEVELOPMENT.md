# Development

## Project structure

| Area | Responsibility |
|---|---|
| app/Http | Thin HTTP controllers, validation, and middleware |
| app/Data | Validated request data objects |
| app/Services | Sync orchestration, payload building, access, and recovery |
| app/Repositories/ActiveCollab | Persistence and the only ActiveCollab HTTP client |
| app/Orchid | Authenticated admin screens and layouts |
| routes | API and Orchid routes |
| resources/apps-script | Canonical Google Apps Script source |
| database/schema | Fresh-install baseline snapshots |
| database/migrations | Incremental schema changes |

Request flow: token middleware → validation → DTO → service → repository. Keep business logic in services.

## Data and safety rules

- Stable identity is (integration_id, record_uuid).
- external_id remains spreadsheet_id:sheet_id:row_number and is a nonunique position locator; never use row number alone to adopt a task.
- Reserve sync operations in MariaDB before HTTP. Perform external requests after the reservation transaction commits.
- Unknown create outcomes stay blocked until an administrator reconciles them. Do not add automatic retries for creates.
- Do not log ActiveCollab tokens or expose them in API responses or exceptions.
- A schema snapshot is the baseline for a fresh database. Apply new schema changes as migrations and refresh both supported snapshots when changing the baseline.

## Local commands

Run from the repository root:

    make up
    make setup
    make test
    make lint
    make analyse
    make check
    make migrate
    make artisan CMD='route:list'

make check runs formatting, PHPStan, PHPUnit, and the frontend build. Dependency versions are constrained in composer.json/package.json and pinned by lock files.

## Tests

- tests/Unit covers isolated services, data objects, and repositories.
- tests/Feature covers API, Orchid, persistence, and sync behavior.
- The normal PHPUnit configuration uses in-memory SQLite. MariaDB concurrency tests use a separate database named sheetcollab_concurrency_test.

Run the MariaDB suite only against a disposable database or isolated volume. Its harness checks the exact database name before migrate:fresh.

    DB_HOST=<isolated-host> DB_PORT=3306 DB_DATABASE=sheetcollab_concurrency_test \
    DB_USERNAME=<test-user> DB_PASSWORD=<test-password> DB_URL= \
    php vendor/bin/phpunit -c phpunit.mariadb.xml

Never use production credentials, the application database, real ActiveCollab, or live Google Sheets in automated tests.
