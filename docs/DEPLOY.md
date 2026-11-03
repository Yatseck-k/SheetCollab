# Deployment

## Requirements

- Docker Engine and Docker Compose v2
- A Linux host with a public HTTPS domain and a reverse proxy such as Nginx, Caddy, or Traefik
- MariaDB 12.3.3 for a fresh deployment

The production image contains PHP 8.5.12, Apache, Composer production dependencies, and compiled frontend assets. It is built independently of Sail. MariaDB is reachable only on the private Compose network; do not expose its port publicly.

## Fresh installation

Clone the repository and create a production-only environment file. Do not use the local sample passwords in production:

    git clone <REPO_URL> sheetcollab
    cd sheetcollab
    cp .env.example .env.production
    chmod 600 .env.production

Set these values in `.env.production`:

- `APP_ENV=production`, `APP_DEBUG=false`, and the public HTTPS `APP_URL`.
- `APP_KEY=base64:<key>` using a unique 32-byte random key. For example, generate the value with `printf 'base64:'; openssl rand -base64 32` and save the complete output. Keep it private and stable across releases.
- Unique random `DB_ROOT_PASSWORD` and `DB_PASSWORD`; set `DB_DATABASE`, `DB_USERNAME`, `DB_CONNECTION=mariadb`, and `DB_HOST=mariadb`.
- `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `LOG_CHANNEL=stderr`, and `LOG_LEVEL=warning`.
- `TRUSTED_PROXIES` as a comma-separated list of the reverse proxy IP addresses or CIDRs that connect to the app. Do not use `*`.

Keep the file out of Git and back it up securely. `APP_KEY` is required to decrypt stored ActiveCollab credentials; losing or rotating it without a credential migration will make those credentials unreadable.

Build and start the stack:

    docker compose --env-file .env.production -f compose.prod.yaml build app
    docker compose --env-file .env.production -f compose.prod.yaml up -d
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan migrate --force
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan config:cache
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan route:cache
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan view:cache
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan orchid:admin

Create the first administrator interactively. Do not run `DatabaseSeeder` in production. Verify the app and database health with `docker compose --env-file .env.production -f compose.prod.yaml ps`.

## Scheduler

The `scheduler` service runs `php artisan schedule:work` from the same image and environment as `app`. It is required: the scheduled `integrations:expire-sync-operations` command (every minute) moves expired `running` operations to `unknown`. Without it, interrupted operations stay `running` indefinitely and cannot be retried or recovered. No queue worker is needed; the application does not dispatch queued jobs.

`up -d` starts it together with the stack. Verify it after every deploy:

    docker compose --env-file .env.production -f compose.prod.yaml ps scheduler
    docker compose --env-file .env.production -f compose.prod.yaml exec scheduler php artisan schedule:list
    docker compose --env-file .env.production -f compose.prod.yaml exec scheduler php artisan integrations:expire-sync-operations --dry-run

Run only one scheduler instance. Recreate it with `app` on release (`up -d app scheduler`), because it keeps the old code loaded until restarted.

Configure the reverse proxy to terminate TLS and forward requests to `127.0.0.1:${APP_HTTP_PORT:-8088}`. Forward the original `Host`, `X-Forwarded-For`, and `X-Forwarded-Proto` headers; configure `TRUSTED_PROXIES` to match the proxy source address. The app trusts proxy headers only from those configured addresses. Set the Apps Script service URL to the public HTTPS base URL without `/admin` or `/api`.

The image is immutable and does not mount the checkout or `.env.production`. Uploaded public files use the persistent `app-storage` volume. Database data uses `prod-mariadb`. Keep both volumes in backups.

## Database snapshots and upgrades

Fresh databases load `database/schema/mariadb-schema.sql` or `database/schema/sqlite-schema.sql`. These snapshots include baseline migration records and are not reversible migrations. The incremental migration files must remain present for databases upgrading from an earlier release.

For an existing database, first update it with the prior release to its complete migration head. Take a backup and verify restoration on an isolated database before deploying a release that uses a squashed snapshot. Never import a fresh-install snapshot over a working database or treat an old partial migration history as current.

For every regular release:

1. Back up the database and verify that the backup can be restored separately.
2. Build the new app image and recreate only the app and scheduler services:

       docker compose --env-file .env.production -f compose.prod.yaml build app
       docker compose --env-file .env.production -f compose.prod.yaml up -d app scheduler

3. Run `php artisan migrate --force`, then refresh config, route, and view caches.
4. Check container health, HTTPS, Orchid login, API behavior with a test integration, and application logs.

The MariaDB image is pinned to the project's tested version. Do not change the database image tag for an existing volume without a planned upgrade and a verified backup/restore rehearsal. Never attach a volume to an older MariaDB server. Roll back application and database together; a database restore does not undo ActiveCollab changes.

## Roles and integration access

- Admin: `platform.activecollab.admin`.
- Manager: `platform.activecollab.integration.manage` and `platform.activecollab.integration.view`, plus explicit integration membership.
- Viewer: `platform.activecollab.integration.view`, plus explicit integration membership.

Connections, token management, memberships, and recovery are admin-only. Do not run the seed command in production to grant new permissions.

## ActiveCollab connections

Before migrating existing integrations, back up the database and stop writers. Review the dry-run, then apply while writers remain stopped:

    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan integrations:migrate-activecollab-connections
    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan integrations:migrate-activecollab-connections --apply --writers-stopped

The command does not print tokens or token fingerprints. Correct missing credentials before applying. Verify every live integration has a connection and test it from Orchid. Runtime no longer falls back to the old global URL/token fields; retain legacy settings until the migration and verification are complete.

## Token rollout

Issue a separate token for each spreadsheet integration. The plaintext is shown once:

    docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan integrations:tokens issue --integration=<INTEGRATION_UUID> --actor=<USER_ID> --reason="Issue scoped credential" --name="<sheet name>"

Put it in that spreadsheet's `INTEGRATION_SERVICE_TOKEN` property. Use `integrations:tokens legacy-status` to inventory old shared-token integrations. After every writer uses its individual token, disable legacy access per integration, then set `ACTIVECOLLAB_ALLOW_LEGACY_TOKEN=false` and refresh config cache. Individual tokens continue to work.

For rotation, issue a second token, update Script Properties, verify sync, then revoke the old token by ID. Do not revoke the only working token before confirming the replacement.

## Migrate an existing spreadsheet

This migration changes record identity. Back up the database and spreadsheet, stop synchronization and editing, and disable the integration before starting. A server or Apps Script lock cannot prevent manual spreadsheet edits.

1. From the sheet menu, prepare the UUID manifest and save it privately outside the web root.
2. Run a dry-run as an existing administrator with recovery, users, and roles permissions:

       docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan integrations:migrate-record-uuids --integration=<INTEGRATION_UUID> --manifest=<CONTAINER_PATH> --actor=<ADMIN_ID> --reason="Verified spreadsheet links" --dry-run --enable-uuid-only

3. Resolve every unresolved or conflicting record. The migration matches confirmed task links in the correct integration/project; row number alone never proves identity.
4. Apply with the same arguments and remove `--dry-run`. Verify the report, UUIDs, linked task IDs, and `uuid_only` state before reactivating the integration.
5. Test one known record, then resume writers.

The manifest is limited to 4 MiB and 20,000 records. Use an empty records list for a new spreadsheet with no history. The manifest file must be available inside the PHP container; a host path is not automatically a container path.

After the first UUID binding, do not roll back to code that identifies records by row number and do not run a destructive down migration. Use a forward fix or restore a verified backup and manually reconcile external tasks created after that backup. A database restore does not undo ActiveCollab changes.
