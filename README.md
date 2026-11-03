# SheetCollab

SheetCollab syncs rows from Google Sheets with ActiveCollab. The Apps Script sends a selected row to Laravel; SheetCollab creates, updates, or skips the matching ActiveCollab task and records each attempt.

## Stack

- PHP 8.5.12+, Laravel 13, Orchid 14
- MariaDB 12.3.3 for the local runtime; SQLite for the standard test suite
- Node.js 26, Vite 8, Tailwind CSS 4
- Docker Compose and Laravel Sail

Dependency constraints are in composer.json and package.json; lock files pin the installed versions.
Sail is for local development. Production uses the dedicated image in `compose.prod.yaml`; see [deployment](docs/DEPLOY.md).

## Start locally

Requirements: Git, Docker Compose, PHP 8.5.12+, and Composer 2.

    composer install
    cp .env.example .env
    make up
    make artisan CMD='key:generate'
    make setup

Open http://localhost/admin (or the configured APP_PORT). The local seed account is admin@example.test / LocalAdmin123!; never use it outside local development.

## Connect a spreadsheet

1. In Orchid, add an ActiveCollab connection and create an integration for the spreadsheet.
2. Assign the integration to its users and issue an individual SheetCollab token.
3. Download the Apps Script from Orchid and set INTEGRATION_SERVICE_URL and INTEGRATION_SERVICE_TOKEN in Script Properties.
4. Complete the spreadsheet UUID migration with an administrator before syncing.
5. Select a row and use **ActiveCollab → Sync task**.

The ActiveCollab API token stays in the SheetCollab connection. Apps Script receives only the individual SheetCollab token and requires a public HTTPS URL; it cannot reach localhost. Keep the hidden SheetCollab UUID column with each full row when sorting or moving rows. Do not edit UUIDs manually. An unknown request outcome requires reconciliation before another create.

## Commands

    make up
    make down
    make migrate
    make seed
    make test
    make lint
    make analyse
    make check
    make artisan CMD='route:list'
    make logs

## Documentation

- [User guide](docs/PROJECT_MANUAL.md)
- [API reference](docs/API_REFERENCE.md)
- [Deployment and upgrades](docs/DEPLOY.md)
- [Development and testing](docs/DEVELOPMENT.md)
