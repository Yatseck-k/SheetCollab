# Отчёт — реализация этапа 2

Дата: 2026-11-02. План этапа 2 одобрен пользователем. Реализация завершена в согласованном scope.

## Root cause

До этапа 2 Apps Script выбирал create/PUT по ссылке в строке, сервер разрешал только общий bearer-токен, справочники опирались на глобальную ActiveCollab-конфигурацию, а MD5 строкового набора полей не отражал полный конечный payload и destination. Клиент не мог безопасно пропускать неизменившиеся записи, а настройка общего токена не ограничивала старые маршруты одной интеграцией.

## Files changed

- `app/Http`, `app/Data`, `app/Exceptions`, `routes/api.php`, `bootstrap/app.php`: новый `POST /api/tasks/sync`, лимит и строгая валидация JSON, ошибки с retryability и общий контекст scoped-авторизации.
- `app/Models`, новая миграция `2026_10_30_000004_add_payload_hash_version_and_integration_tokens.php`, token repository/service/CLI: индивидуальные хешированные токены, revoke/last-used, default-deny legacy allowlist, AND-проверка recovery/users/roles permissions, аудит и guarded rollback.
- `app/Services/ActiveCollab` и `app/Repositories/ActiveCollab`: общий конечный payload, escaping, полный список labels и `assignee_id=0` для очистки, контекстные справочники, строгая проверка ответа, SHA-256 версии payload и общий выбор create/update/skip.
- `resources/apps-script/activecollab_sync.gs`: одно основное действие через `/api/tasks/sync`; прежние функции оставлены алиасами. Скрипт сверяет UUID/action/ID/URL/hash/operation/date и отображает confirmed date отдельно от попытки skip.
- `app/Orchid`, `app/Repositories/ActiveCollab/ActiveCollabIntegrationRepository.php`: candidate-сведения для ручной сверки и блокировка удаления интеграций с историей/токенами.
- `tests/Feature/TaskSyncApiTest.php`, `ActiveCollabIntegrationTokenTest.php`, обновлённые legacy/concurrency tests, `tests/Unit/ActiveCollabTaskPayloadBuilderTest.php`, `ActiveCollabTaskBodyBuilderTest.php`, Apps Script harness.
- `docs/API_REFERENCE.md`, `DATA_MODEL.md`, `DEPLOY.md`, `LOGIC.md`, `PROJECT_MANUAL.md`, admin guide, `CLAUDE.md`, `work/PLAN.md` и этот отчёт.

## Fix summary

`/api/tasks/sync` принимает только индивидуальный `sc_` bearer, находит интеграцию по token record и сверяет переданный spreadsheet context. Клиент не может выбрать действие, интеграцию или задачу через task ID. Тело ограничено 64 KiB; лишние поля отклоняются. Отзыв и active/context сверяются под lock перед dispatch и подтверждением результата.

CLI `integrations:tokens` выпускает bearer один раз и хранит только SHA-256, позволяет безопасно list/revoke и явное включение/выключение legacy-доступа для одной интеграции. Mutations и audit атомарны; повторные revoke/toggle идемпотентны. Новый endpoint никогда не принимает общий token.

Конечный payload включает очищение назначения (`assignee_id=0`) и полную коллекцию labels. Пустая severity отправляет `labels: []`; это намеренно удаляет в ActiveCollab labels, добавленные вручную, и описано в документации. Сервер принимает результат только при точном подтверждении проекта, состояния задачи, назначения и label IDs. Hash `payload-sha256-v1` учитывает canonical payload, destination и mapping; legacy endpoints сохраняют `legacy-md5-v1`. Skip имеет отдельную succeeded attempt/operation ID, не выполняет POST/PUT и не перезаписывает последний подтверждённый snapshot/hash/date.

Ошибки до отправки могут быть retryable только если durable failure записан. Неизвестный результат, отзыв/деактивация после возможного HTTP, malformed response и commit failure остаются блокирующими `unknown`; автоматического повтора create нет. Recovery не применяет чужой candidate автоматически.

## Validation result

- `./vendor/bin/pint --test` — PASS, 143 файла.
- `./vendor/bin/phpstan analyse --memory-limit=1G` — PASS, 104 файла, ошибок нет.
- `./vendor/bin/phpunit --display-warnings` — 249 tests, 913 assertions, 0 failures; 11 PHPUnit Notices и 14 MariaDB-only skipped тестов в штатном SQLite suite.
- Изолированный `phpunit.mariadb.xml` на MariaDB 11.4.12 — PASS, 14 tests / 145 assertions, включая два процесса на одном UUID нового sync.
- `node --test tests/AppsScript/activecollab_sync.test.cjs` — PASS, 19/19.
- `npm run build` — PASS; `php artisan view:cache` и `view:clear` — PASS.
- Отдельная MariaDB upgrade-схема: состояние 1.4 с legacy export/hash/snapshot пережило добавление этапа 2 без backfill; `allow_legacy_token=false`, `hash_version=null`. Откат остановлен при установленной hash version и при наличии токена; после снятия ограничивающих данных откат удалил только схему этапа 2 и сохранил прежний export/hash.
- `git diff --check` — PASS. Тесты и upgrade-проверка использовали отдельную in-memory SQLite и временные Docker MariaDB database/network. `.env` не создавался, production database/Google/ActiveCollab не вызывались.

## Remaining risks / unverified paths

- Не проверено на живом ActiveCollab, что установленная версия принимает полное `labels: []` и `assignee_id=0` и возвращает нужные поля. Это следует вручную проверить на отдельном тестовом проекте до rollout.
- Не выполнена ручная установка Apps Script в Google и ротация реального scoped token. Canonical script в БД не заменён автоматически; оператор должен установить версию вручную по инструкции `docs/DEPLOY.md`.
- Не выполнен production rollout или rollback приложения. При откате к версии 1.4 API нужно закрыть: старый код игнорирует revoke/legacy-deny и версионирование hash.
- Dependencies не обновлялись: это отдельный пункт задачи и отдельный этап плана, вне одобренного scope реализации этапа 2.
- Sail не запускался; PHP проверки выполнялись в имеющемся изолированном Docker runner. PHPUnit сохраняет 11 Notices; детализация существующих notices находится в выводе `phpunit --display-warnings`.
