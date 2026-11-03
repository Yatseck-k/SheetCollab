# SheetCollab

**Connect Google Sheets to ActiveCollab — manage tasks directly from your spreadsheet.**

[Русский](README.ru.md) · [User guide](docs/PROJECT_MANUAL.md) · [API](docs/API_REFERENCE.md) · [Deployment](docs/DEPLOY.md) · [License](LICENSE)

![PHP](https://img.shields.io/badge/PHP-8.5.12%2B-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Orchid](https://img.shields.io/badge/Admin-Orchid%2014-6366F1) ![License](https://img.shields.io/badge/License-MIT-blue)

## Overview

**SheetCollab** integrates **Google Sheets ↔ ActiveCollab** using Google Apps Script and a Laravel backend. Select a spreadsheet row, request synchronization, and let the application create, update or skip the corresponding ActiveCollab task. Each sync attempt is recorded for inspection and recovery.

The project is designed for **multiple spreadsheets, integrations and users**, with scoped access and explicit administrative workflows.

## Features

| Area | What it provides |
| --- | --- |
| **Task synchronization** | Create or update ActiveCollab tasks from spreadsheet records |
| **Multiple integrations** | Configure connections and integrations for different spreadsheets |
| **Access control** | Manage users, roles, integration membership and individual tokens |
| **Admin interface** | Manage configurations, connections, exports and recovery in Orchid |
| **Traceability** | Record synchronization attempts and administrative changes |
| **Recovery** | Handle uncertain outcomes and conflicting task bindings explicitly |
| **Deployment** | Local Docker Compose workflow and a separate production configuration |

## How it works

```text
Google Sheets
    │  Select row → ActiveCollab → Sync task
    ▼
Google Apps Script
    │  Scoped integration token over HTTPS
    ▼
SheetCollab API (Laravel)
    │  Validation → service → repository
    ▼
ActiveCollab tasks
```

The ActiveCollab API credentials stay on the SheetCollab server. Apps Script uses its own scoped integration token. Spreadsheet rows retain stable UUID identifiers; do not manually edit UUIDs.

**Safety:** an uncertain create response must be reconciled before another create attempt. The system must not blindly retry tasks with unknown outcomes.

## Technology

| Layer | Stack |
| --- | --- |
| **Backend** | PHP 8.5.12+, Laravel 13 |
| **Administration** | Orchid 14 |
| **Database** | MariaDB (local runtime); SQLite (standard tests) |
| **Frontend tooling** | Node.js 26, Vite 8, Tailwind CSS 4 |
| **Integration** | Google Apps Script, ActiveCollab API |
| **Infrastructure** | Docker Compose / Laravel Sail for development; dedicated production image |

See `composer.json` and `package.json` for constraints and their lock files for pinned dependencies.

## Quick start (local development)

Requires Git, Docker Compose, PHP 8.5.12+ and Composer 2.

```bash
git clone https://github.com/Yatseck-k/SheetCollab.git
cd SheetCollab
composer install
cp .env.example .env
make up
make artisan CMD='key:generate'
make setup
```

Open `http://localhost/admin` (or the configured `APP_PORT`). Local seed credentials are for development only: **never use them in production**.

### Connect a spreadsheet

1. Add an ActiveCollab connection and a spreadsheet integration in Orchid.
2. Grant the intended users access and issue an individual integration token.
3. Export the Apps Script from Orchid and configure `INTEGRATION_SERVICE_URL` and `INTEGRATION_SERVICE_TOKEN` in Script Properties.
4. Complete the documented spreadsheet UUID migration before synchronization.
5. Select a row and run **ActiveCollab → Sync task**.

Apps Script requires a public HTTPS endpoint; it cannot call a server bound to `localhost`.

## Development commands

```bash
make up
make down
make test
make lint
make analyse
make check
make migrate
make artisan CMD='route:list'
make logs
```

`make check` runs the project's checks; see [Development](docs/DEVELOPMENT.md) for test database isolation and safety requirements.

## Documentation

- [User and administrator guide](docs/PROJECT_MANUAL.md)
- [API reference](docs/API_REFERENCE.md)
- [Local development and testing](docs/DEVELOPMENT.md)
- [Production deployment, migrations and recovery](docs/DEPLOY.md)

**Production upgrades require database backups and a controlled migration procedure.** Follow the deployment guide; do not treat the local development setup as a production installation.

## License

[MIT](LICENSE).
