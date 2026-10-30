# Task Summary

Статус: **этап 2 — конкретный план подготовлен, ожидает одобрения; реализация не начата**.

Цель: добавить `POST /api/tasks/sync`, где сервер по UUID и подтверждённому конечному payload выбирает create/update/skip. Apps Script получает одно основное действие. До публикации нового API внедрить индивидуальные токены и проверку контекста интеграции. Основание — TASK §§4.3–6.4, 9–12 и просьба пользователя перейти к следующему этапу.

Выполненный план 1.4 сохранён в `work/PLAN-1.4.md`, результаты — `work/REPORT-1.4.md`. Этот план не разрешает автоматически выполнять этапы 3–7 или переносить рабочие данные.

## Scope

Включено:

- Новый sync endpoint/валидация/ошибки; серверный выбор действия под существующими блокировками и запись skip в attempts.
- Общий построитель конечного AC payload, полное HTML-экранирование, версионированный SHA-256 и безопасный переход с legacy MD5.
- Минимальный обязательный доступ: индивидуальные токены, выдача/list/revoke через административную CLI, аудит, явное разрешение общего токена только старым маршрутам выбранных интеграций, контекстные справочники.
- Одно действие Apps Script, сохранение UUID/lock/re-find/copy/migration из 1.4; инструкция обновления DB-версии скрипта.
- Совместимость POST/PUT, recovery, late callbacks и unknown/remote_missing; focused/JS/MariaDB tests, документация перехода/отката.

Вне этапа: Connections и перенос credentials, удаление fallback, полный UI токенов/назначений/ролей, повтор из истории для manager, redesign Orchid, удаление DB-редактора, обновление зависимостей. Эти части остаются этапами 3–7. Не открывать промежуточный старый UI независимым пользователям до полного разграничения этапа 4. Не изменять рабочие AC/Sheets/БД при реализации и автоматических проверках.

## Existing Changes

- TASK, diff, AGENT_RULES и инструкции прочитаны; diff.txt пуст. Рабочее дерево содержит staged/unstaged результаты 1.1–1.4. Сохранить их и staging; не создавать коммиты, не выполнять git add/reset.
- Есть `(integration_id, record_uuid)`, uuid_only, integration → export locks, operation ID/lease, attempts, sweep, аудит и recovery. Есть защищённый paused UUID-перенос. Эти механизмы переиспользуются.
- TaskService пока получает интеграцию по spreadsheet_id, имеет create/update и private buildPayload/MD5. Reserve считает POST намерением создать и отклоняет полную связь; auto-выбора и skip нет.
- prepareDispatch пишет payload/hash попытки; finishLocked считает любой succeeded, кроме reconcile, синхронизацией с новым synced_at. Для skip нужна отдельная ветвь без изменения подтверждённого снимка/даты.
- Авторизация — только общий token. API-справочники читают глобальные AC config и не знают интеграцию. Individual token table/CLI отсутствуют.
- BodyBuilder экранирует description/comment/screenshot, но параметры/environment/section вставляет без полного экранирования. Payload опускает пустые assignee/label: это не гарантирует снятие назначения.
- Скачиваемая каноническая версия Apps Script всё ещё в script_assets; файл — bootstrap. Editor/read-path сохраняются до этапа 6.
- Исторические результаты 1.4: 226 SQLite cases, 13 MariaDB cases, 17 JS cases, Pint/PHPStan/build/Blade прошли; 11 прежних PHPUnit notices; Sail/реальные Sheets не проверены. Это не baseline будущего запуска.

## Constraints and Rules

- Соблюдать AGENTS/CLAUDE/work/AGENT_RULES/TASK и ub-mode/work-task-flow. Реализацию начинать после одобрения именно этого плана.
- Все AC HTTP — ActiveCollabRepository. Custom DB access/транзакции — repositories. Контроллер: validate → DTO → service. Не создавать параллельный sync engine.
- Формат external_id сохраняется; UUID не усыновляется по row number/task ID. Unknown/expired/remote_missing не разрешают автоматический create/PUT/skip. Exactly-once не обещается.
- HTTP вне DB-транзакции; integration → token (если нужен) → export — единый порядок для операций, берущих несколько lock. Expired running становится unknown, не takeover.
- Не хранить/логировать bearer/AC tokens, token hashes/fingerprints в API/audit, raw headers/exception/body. Token hash допустим только в специальной таблице. Открытый токен показывается один раз при явно вызванной административной выдаче.
- Не выполнять seeding/backfill внутри schema migrations. Старые nullable состояния, hashes и attempts не подменять выдуманной историей.

## Decisions

### Схема

Новая миграция `2026_10_30_000004_add_payload_hash_version_and_integration_tokens.php`:

- Nullable `hash_version` string(32) на exports и attempts. Старые значения остаются null; версии `legacy-md5-v1` и `payload-sha256-v1` присваиваются только новыми реальными попытками. Старые строки не классифицировать по длине hash ради skip.
- Boolean `active_collab_integrations.allow_legacy_token` default false, cast; не добавлять в публичную mass assignment/форму.
- `active_collab_integration_tokens`: UUID id, integration UUID FK restrictOnDelete, name string(100), token_hash char(64) unique, created_at, nullable revoked_at/last_used_at. Никакого encrypted/plain token. Модель скрывает token_hash при сериализации; timestamps ведутся явно.
- Не добавлять Connection/pivot ролей. Удаление интеграции с token/history блокировать через repository с понятной ошибкой формы, использовать деактивацию; FK остаётся последней защитой гонки.

### Доступ и административные команды

1. Новый bearer: `sc_` + 64 lowercase hex от 32 криптографически случайных bytes. Хранить SHA-256 полного bearer. Lookup только через token repository. Префикс sc_ зарезервирован для individual tokens; неизвестный/отозванный такой bearer не должен попадать в global fallback.
2. Новый `/api/tasks/sync` принимает только действующий individual token. Интеграция определяется token record, не body. Отсутствующий/неверный/отозванный token → 401; inactive integration или несовпадение нормализованного spreadsheet_id → 403. До uuid_only=true запрос даёт 409 legacy_binding_conflict, не автоматическую UUID-привязку.
3. Старые POST/PUT принимают individual token с тем же контекстом. Общий token допускается только при явном allow_legacy_token=true у конкретной активной интеграции и только на старых маршрутах. Default false — намеренное ужесточение: существующие таблицы перечисляются/разрешаются оператором до возобновления writers, без автоматической массовой выдачи доступа.
4. Middleware передаёт внутренний validated access context (integration ID, token ID либо legacy mode). TaskService получает этот контекст и не выбирает другую интеграцию из body. Нормализацию Sheets URL вынести в одно существующее DTO-место и переиспользовать в requests/access boundary, не копировать regex между новыми компонентами.
5. На reserve/prepare/успешном complete повторно проверять active, token revocation либо legacy-флаг и frozen destination (эффективные base URL/project/token в памяти). Initial auth не выдаёт вечное право dispatch. last_used_at обновлять при принятом контекстном обращении, включая skip; rejected foreign request не создаёт чужой attempt.
6. Отзыв до dispatch → ноль POST/PUT и owned failed; после возможного dispatch → unknown, без ложного success/повтора. Отзыв не отменяет уже разрешённый внешний in-flight запрос. При невозможности сохранить остановку остаётся durable running → sweep unknown.
7. Новая CLI `integrations:tokens {action} --integration=<uuid> --actor=<id> --reason=<text> [--name=<name>] [--token-id=<uuid>]`, actions issue/list/revoke/enable-legacy/disable-legacy. Использовать существующую recovery-policy с тремя permissions как временный административный gate; свежая проверка actor внутри mutation-транзакции. Reason 10–1000, name 1–100. Issue показывает исходный token только после commit; list показывает только id/name/timestamps/revoked, никогда hash. Повтор revoke/legacy toggle — no-op без повторного audit. Ротация: issue второго → смена Script Properties → проверка → revoke первого, без автоматического отзыва старого при выдаче.
8. Token/legacy mutations и очищенный audit атомарны. Before/after — token ID/name/revoked timestamps либо allow_legacy_token; без secret/hash. CLI actions не разрешены integration bearer и не создают новых HTTP admin endpoints. Не выпускать настоящие токены автоматически при разработке.

### Справочники и временное назначение до Connections

- Individual token обращается к существующим task-lists/users/labels только в своей интеграции. Необязательный spreadsheet_id query не переключает её, mismatch → 403.
- Общий token для справочников требует spreadsheet_id query и разрешённую legacy-интеграцию; отсутствие контекста → 403, а не глобальные данные. Это документируемое исправление безопасности.
- Task lists: `/projects/{project}/task-lists`. Users: `/users`, отфильтрованные по members выбранного `/projects/{project}`. Labels: `/labels/task-labels`, глобальные task labels и неглобальные ID, присутствующие в label_ids выбранного проекта. Все вызовы через выбранные resolved credentials; вернуть только нужные безопасные поля, без raw upstream metadata. Неподтверждённый контекст/ответ блокировать.
- Connection ещё нет. В hash destination использовать временный ключ `{integration_id, normalized_effective_base_url, project_id}`, без credential hash. При этапе 3 явный connection ID сменит namespace/version с контролируемым update. Одинаковый host сам по себе не объединяет интеграции.

### Новый запрос/ответ

- Новый `SyncTaskRequest`: required UUID, spreadsheet_id, sheet_id, row_number, environment/section/description; nullable остальные действующие поля. UUID lowercase, источник нормализуется; optional absent/empty приводится к null. Не принимать client action/task_id/task_url/integration_id/connection_id/external_id/hash/operation_id; неизвестные верхние ключи отклонять.
- Лимиты приложения: JSON body 64 KiB по фактической длине, не только Content-Length; spreadsheet_id 255, sheet_id 64, row_number 1–10 000 000; environment 100, section 255, description 16 000, comment 8 000, screenshot http/https URL 2048; severity/priority/status/task_type 100, device/resolution 255, responsible/reporter 255 символов. Это выбранные лимиты приложения, не утверждение о лимитах Google. Невалидный JSON/данные → 422, превышение body → 413.
- Успех `data: {record_uuid, action, task_id, task_url, synced_at, sync_hash, operation_id}`. 201 created; 200 updated/skipped. Hash 64 lowercase hex. synced_at берётся из подтверждённого сохранения, при skip остаётся прежним; время новой попытки отдельно.
- Новый endpoint возвращает `error: {code,message,operation_id,retryable}`, для validation также errors. 401/403/413/422; 409 sync_in_progress/legacy_binding_conflict/remote_task_missing/sync_outcome_unknown; 502 upstream_error либо initial unknown, 504 timeout с явно сохранённой неопределённостью при возможном dispatch.
- retryable=true только при durable owned failed до возможного POST/PUT (в том числе mapping error после исправления конфигурации или failed GET подготовки). Validation/auth/unknown/running/remote_missing/migration conflicts — false. Script не выполняет автоматических retries даже при true.
- Renderer нового маршрута нормализует validation/auth/size/upstream errors безопасно; старые success envelopes/duplicate conflict остаются прежними. Не выпускать raw exception text/debug payload.

### Конечный payload и SHA-256

1. Новый `ActiveCollabTaskPayloadBuilder` строит конечный массив, делегирует name/body существующему BodyBuilder. BodyBuilder экранирует ВСЕ пользовательские HTML-значения, сохраняя nl2br/непустые секции. Источник — безопасная ссылка на конкретную Google-таблицу/лист/строку и номер строки. Перемещение меняет source в body и поэтому может вызывать PUT, сохраняя UUID/task ID.
2. Builder остаётся pure: необходимые каталоги получает сервис через ActiveCollabRepository вне транзакции после reserve, до dispatch/skip decision. Mapping service по-прежнему разрешает task-list/assignee/label IDs. Неположительные заданные IDs/неполный обязательный mapping → mapping_invalid, а не отправка случайного 0.
3. Пустой responsible означает явное снятие: assignee_id=0. Непустой — положительный mapped ID. Для label ID разрешить ровно одно существующее и разрешённое project/global имя из task-label catalog; неоднозначное/отсутствующее соответствие блокировать. Отправлять документированный `labels: [name]`, при отсутствии выбранного label — `labels: []`. Не отправлять неподтверждённый task `label_id` и не придумывать новые label names.
4. Полный labels-массив управляется Sheets: очистка снимает все labels задачи, включая добавленные вручную. Не вводить скрытый merge с обратным чтением состояния задач. Это явная граница владения полем, которую должен одобрить пользователь вместе с планом.
5. Новый sync проверяет ответ task: exact positive ID, safe relative URL, project/is_trashed, assignee ID и набор label IDs соответствуют выбранным назначениям. Игнорирование поля/несоответствие после возможного HTTP не считается успехом и не даёт SHA-hash для skip: unknown. Сохранить в attempt только проверенный whitelist candidate ID/URL/managed IDs для ручной сверки; существующую подтверждённую связь не переписывать. Recovery показывает безопасный candidate ID/URL из попытки, не raw JSON; bind по-прежнему требует собственного GET/проверки администратора.
6. Hash — SHA-256 canonical JSON `{version,destination,mapping:{label_id},payload}`; рекурсивно сортировать object keys, сохранять порядок списков, integer IDs, явные 0/[] для clear, фиксированную обработку null. JSON_THROW_ON_ERROR/UNESCAPED_UNICODE/UNESCAPED_SLASHES. Включить конечный HTML, mappings через реальные отправленные значения и разрешённое label ID как часть идентичности mapping; исключить timestamps/operation/bearer/AC credentials.
7. Известные секреты, включая текущий individual bearer, не должны попасть в source/payload/error/history/audit. Access context держит bearer только в памяти для sanitizer; не сериализовать его. Если входные бизнес-поля содержат известный secret, отклонить безопасно до отправки. Санитизировать также безопасные response/error metadata с frozen credentials, не только текущим config.
8. Старые POST/PUT остаются адаптерами того же сервиса и payload builder, но принудительно сохраняют create/update поведение и прежний MD5 response hash/`legacy-md5-v1`. Recovery replacement тоже не создаёт основания для SHA-skip без strict sync verification: legacy version либо invalidated hash. Удаление MD5 — последующий contract-этап. После такого старого writer следующий sync выполнит один контролируемый PUT.

### Проверенные источники и границы подтверждения API

На дату подготовки прочитаны официальные примеры:

- [Task labels](https://developers.activecollab.com/api-documentation/v1/projects/elements/tasks/labels.html): task-label catalog, labels как массив имён и явное снятие через пустой массив.
- [Tasks](https://developers.activecollab.com/api-documentation/v1/projects/elements/tasks/tasks.html): create/update и числовой assignee ID; unassigned ответы используют 0. Пример именно снятия через assignee_id=0 не найден — это явно указанное предположение контракта, поэтому новый sync обязан проверить возвращённый 0 и не подтверждать ignored update.

Имена labels берутся только из проверенного каталога. Rename между GET и PUT теоретически может создать новое имя на стороне AC; несоответствие возвращённых IDs становится unknown, без skip/повтора. Не заявлять поддержку конкретной рабочей AC-версии или live clearing без отдельного тестового стенда. Automatic tests не вызывают рабочий AC.

### Автомат выбора и завершения

1. После авторизации/валидации reserve в короткой транзакции перечитывает integration/token и UUID-export. Для auto intent: полная bound-связь → update; отсутствие связи при безопасном idle/failed → create. Неполная связь → conflict. running/expired/unknown/remote_missing проверяются до payload/hash; не обходить их skip.
2. Атомарно создать/claim operation и attempt с create/update. Reservation возвращает выбранное действие/task ID и frozen назначение, не позволяет клиенту указать action. Legacy intents проходят те же guards, но bound legacy create сохраняет прежний duplicate 409.
3. После commit построить payload/каталоги/hash. Ошибка подготовки записывает owned failed; внешний POST/PUT не вызывать. Повторный авторизационный/destination guard перед решением dispatch.
4. В owned prepareDispatch-транзакции снова проверить lease/контекст и сравнить только последний подтверждённый hash/version. Skip разрешён только update + bound + payload-sha256-v1 + равный hash + существующие synced_at/payload/response. Unknown/reconcile/неверсионированный MD5 не являются основанием skip.
5. Skip атомарно меняет action попытки на skip, завершает её succeeded, снимает lease и возвращает сохранённую task-связь/старый synced_at с новым operation ID. Не ставить новый legacy created/updated, не переписывать confirmed payload/response/hash/source/synced_at; http_status/response этой попытки пусты. Locator уже отражает принятую позицию. GET подготовки каталога допустим, POST/PUT отсутствует.
6. При различии hash продолжить подготовленный create/update; HTTP вне transaction, без retry. Успешный complete сохраняет export+attempt+hash_version и один согласованный timestamp атомарно, возвращает snapshot результата именно этой операции, не читает потенциально изменившийся export после commit.
7. Completion сохраняет ownership/lease и повторный access/destination guard. Timeout/malformed/mismatch/ошибка commit после возможного HTTP → unknown; старый callback не перезаписывает новую операцию. PUT 404 сохраняет binding и remote_missing; automatic replacement запрещён.
8. Verify/bind recovery инвалидирует hash и hash_version; GET не подтверждает содержимое. Skip-код нельзя применять к reconcile. Блокировку unknown и существующие audit/replay правила сохранить.

### Apps Script и совместимость

- Одно основное меню «Синхронизировать строку» → syncTaskFromRow → `/api/tasks/sync`, всегда individual token из существующего INTEGRATION_SERVICE_TOKEN. Наличие/отсутствие локальной task-ссылки не определяет action; ручной task ID не отправляется.
- Сохранить document lock, persist/flush UUID до HTTP, global duplicate scan, explicit new copy, manifest и re-find после любого HTTP из 1.4. Устаревшие create/update entry points можно оставить только тонкими alias на единый sync, убрать отдельные пункты меню; не дублировать поведение.
- Ответ сверяет UUID с отправленным, action из created/updated/skipped, ID/URL/hash/operation ID/date. Для skipped показывать «Без изменений», последнюю подтверждённую дату; дату попытки хранить/показывать отдельно в существующей колонке. Неверный/чужой ответ не записывает ссылку. Нет automatic retry/смены UUID при 401/403/409/unknown.
- Обновление файла не перезаписывает script_assets/custom scripts. Сначала export/diff/review DB-версии, затем установка проверенного содержимого через текущий editor/download и замена Script Properties. Не удалять editor/source до этапа 6.

## Change Map

| Файл / компонент | Конкретное изменение |
| --- | --- |
| Новая миграция `2026_10_30_000004_add_payload_hash_version_and_integration_tokens.php` | версии hash, legacy-флаг, tokens FK/индексы/guarded down |
| Новая `app/Models/ActiveCollabIntegrationToken.php`; Integration/Export/SyncAttempt models | скрытый hash, casts, hash_version/флаг без публичного setter |
| Новый `app/Data/ActiveCollab/ActiveCollabAccessContext.php`; TaskData/SyncReservation | серверный контекст/выбранное действие, общая нормализация источника |
| Новые `app/Repositories/ActiveCollab/ActiveCollabIntegrationTokenRepository.php`, `app/Services/ActiveCollab/ActiveCollabIntegrationTokenService.php`, `app/Console/Commands/ManageActiveCollabIntegrationTokens.php` | выдача/list/revoke/legacy allowlist, права/аудит/ротация |
| `app/Repositories/AdminAuditLogRepository.php` | безопасный атомарный audit token/legacy mutations |
| Новый `app/Http/Middleware/AuthenticateActiveCollabIntegration.php`; существующий VerifyIntegrationToken | индивидуальный контекст и scoped legacy fallback без двух реализаций auth |
| Новый `app/Http/Middleware/LimitActiveCollabSyncRequest.php`, `app/Http/Requests/SyncTaskRequest.php`; Store/Update requests | 64 KiB, новый контракт/лимиты, общая нормализация без ужесточения старых бизнес-лимитов |
| Новый `app/Http/Controllers/Api/TaskSyncController.php`; ActiveCollabTaskController | thin sync entry, legacy adapters с auth context, scoped catalog calls |
| `routes/api.php`, `bootstrap/app.php`, `app/Exceptions/TaskSyncConflictException.php`, `ActiveCollabRequestException.php` | новый route/auth alias, path-aware ошибки/retryability, безопасные candidate metadata |
| Новый `app/Services/ActiveCollab/ActiveCollabTaskPayloadBuilder.php`; BodyBuilder/TaskService/DataSanitizer | конечный payload/escaping/hash, orchestration, секреты текущего bearer, legacy response compatibility |
| `app/Repositories/ActiveCollab/ActiveCollabExportRepository.php` | auto reserve, token guard, transactional skip, versioned complete/snapshot результата |
| `app/Repositories/ActiveCollab/ActiveCollabRepository.php` | scoped catalogs и strict sync response whitelist, без обходных HTTP |
| `app/Repositories/ActiveCollab/ActiveCollabIntegrationRepository.php`; IntegrationEditScreen | серверный lookup/read guards, запрет удаления используемой интеграции через repository |
| `app/Services/ActiveCollab/ActiveCollabRecoveryService.php`; ExportRecoveryScreen | invalidation версии, безопасный candidate/skip label в истории |
| `resources/apps-script/activecollab_sync.gs` | единое действие/новый endpoint/response; сохранение UUID-защиты |
| Новые `tests/Feature/TaskSyncApiTest.php`, `ActiveCollabIntegrationTokenTest.php`, `tests/Unit/ActiveCollabTaskPayloadBuilderTest.php` | новый контракт, доступ/CLI, payload/hash/clear |
| Существующие API/Repository/IntegrationRepository/Recovery/RecoveryScreen/UUIDMigration/DTO/BodyBuilder tests | совместимость auth/schema/hash/recovery, full HTML escaping |
| `tests/Feature/ActiveCollabSyncConcurrencyTest.php`, `tests/Fixtures/activecollab_sync_worker.php` | auto create/update/skip/revoke races; catalog GET отдельно от write barriers |
| `tests/AppsScript/activecollab_sync.test.cjs` | единое действие, skip/response validation и прежние UUID сценарии |
| `CLAUDE.md`, `docs/API_REFERENCE.md`, `DATA_MODEL.md`, `LOGIC.md`, `DEPLOY.md`, `PROJECT_MANUAL.md`, `TESTING.md`, `resources/views/admin/guide.blade.php` | новые реальные контракты, роли CLI, переход, владение labels, ручной QA/rollback |
| `work/PLAN.md`, `work/REPORT-2.md` | критерии/фактические результаты после реализации |

Не менять dependency manifests, старые миграции, GoogleAppsScriptService/ScriptAsset/editor, Connections/роль-pivot. AGENTS/work rules менять только если реализация выявит действительное противоречие; текущие ограничения совместимы.

## Implementation Plan

1. Снять baseline и начальный diff/index snapshot без изменения staging. Добавить failing tests для нового sync: create → skip, изменённый mapping → update, global token denied, чужой spreadsheet denied, legacy duplicate сохранён. Зафиксировать реальные ограничения среды.
2. Добавить additive schema/models/token repository/service/CLI/audit. Проверить fresh/upgrade, права/ротацию/revoke/idempotency/legacy-флаг; никаких настоящих токенов и production backfill.
3. Ввести access context и единый auth path для новых/старых routes; нормализацию источника и scoped catalogs. Проверить прямые запросы/disabled/revoked/foreign и guard до dispatch; защитить удаление используемой интеграции.
4. Выделить payload builder, исправить полное HTML-экранирование, документированные labels/clear и canonical hash/version. Добавить fixtures каталогов/strict ответов; новый SHA сохранять только после подтверждённого sync.
5. Расширить существующие reserve/prepare/complete для auto/skip и snapshot ответа; сохранить legacy intents/MD5 envelopes и recovery semantics. Проверить durable failed/unknown/remote_missing, late callbacks, rollback при attempt/audit/DB failure.
6. Подключить thin sync controller/request/structured errors с лимитами. Проверить 201/200/skip timestamps/operation IDs, validation и retryable по сохранённому состоянию. Не допускать выбора действия/интеграции из body.
7. Обновить Apps Script/harness и guide/docs/CLAUDE. Сверить отсутствие дублирующих sync сценариев и то, что DB/custom script не перезаписан. Обновить MariaDB workers под чтение каталогов без write barrier.
8. Выполнить Validation Plan, сохранить REPORT-2 с реальными командами/результатами/ограничениями. Отметить критерии только после проверок; рабочие таблицы и deploy не выполнять автоматически.

## Validation Plan

- API/доступ: individual create/update/skip; body spoof полей/чужой UUID/task/integration/spreadsheet; invalid/revoked/missing token, inactive, global-only новый endpoint, legacy allow/deny, references без/с контекстом. Отказ не создаёт чужой attempt/не раскрывает catalogs/secrets. 64 KiB/JSON/UUID/строковые границы/дополнительные keys; прежние successful legacy envelopes/duplicate conflict.
- Token CLI: все три permission, issue/list/revoke/two-token rotation, last_used, one-time plaintext, отсутствие hash/secret в model/API/audit/logs/errors, audit rollback, repeated revoke/toggle, deletion FK/понятная ошибка. No automatic seed/global grants.
- Payload/hash: повтор/порядок object keys, pipe-collision inputs, null/absent normalization, 0/[] vs omission, изменение mapping ID/name и destination, экранирование каждого HTML-поля, multiline, source link/row move. Не подтверждать ignored clear/неправильные assignee/labels/project/trash; безопасный candidate остаётся только в attempt. Legacy MD5/new null-version требуют один PUT перед SHA skip.
- State: skip имеет собственный succeeded attempt/operation ID, ноль POST/PUT и старый synced_at/snapshot; failed/unknown hash не даёт skip. Mapping/catalog error до dispatch безопасен, commit failure после create/timeout/malformed/revoke-after-dispatch остаются blocked unknown. Remote_missing не создаёт замену; recovery/replay/sweep/UUID migration regressions проходят.
- MariaDB, независимые процессы и isolated guarded DB: auto first insert одного UUID → один POST; два изменённых sync → один PUT; два одинаковых bound sync → последовательные skip без PUT; skip против changed update не теряет изменение; revoke/legacy disable/inactive между auth/reserve/dispatch/complete; sync против recovery/sweep/late callback. Проверить реальные SQL locks и отсутствие HTTP в транзакции; SQLite этого не доказывает.
- Upgrade/schema/rollback на отдельной MariaDB: снимок 1.4 сохраняется, hashes/история не переписаны, defaults false/null, token FK/unique, atomic token+audit, guarded down, MD5 → SHA transition. Никаких рабочих credentials/volumes.
- JS built-in harness: одна команда без выбора action по локальной ссылке, отправка UUID/individual token, created/updated/skipped, response UUID/ID/hash/date/operation mismatch, 401/403/409/422/413/network, отсутствие retries. Все прежние lock/duplicate/copy/перемещение/манифест/XSS сценарии. При skip показывать прежний confirmed synced_at.
- Ручной QA на отдельном Google/AC тестовом стенде: установка DB-скрипта, ротация token, сортировка/два пользователя, mapped labels/их очистка, снятие assignee, ignored unsupported field. Если стенд недоступен — явно «не выполнено», не объявлять fake доказательством реальной AC-версии.
- Focused → полный PHPUnit, Node tests, MariaDB suite, Pint, PHPStan, build/Blade compile, git diff --check. Попытаться make test/check; при недоступном Sail выполнить эквиваленты отдельно и честно указать ограничения, не создавать рабочий .env/не запускать production seed. ub-php — только если уже установлен; CRAP/DRY/mutation не выдумывать.

## Risks

- Полный labels-массив принадлежит Sheets: clearing удаляет ручные labels. Назначение по проверенному имени и assignee_id=0 требуют strict response confirmation; различия self-hosted версии/rename race не скрывать успешным hash. Каталоги могут требовать GET даже перед skip; skip гарантирует отсутствие POST/PUT, не отсутствие всех внешних чтений.
- Общий token default-deny и обязательный legacy reference context — намеренные изменения безопасности, требующие operator allowlist до возобновления старых writers.
- Удаление/отзыв токена не отменяет уже начатый AC-запрос. Проверки до dispatch/complete предотвращают ложный success и автоматический повтор, но не дают distributed transaction.
- До Connections destination namespace временный; перед этапом 3 потребуется согласованный hash transition. Credential fingerprints не использовать. Старый UI ещё не изолирует manager/viewer — внешнюю многопользовательскую публикацию не выполнять до этапа 4.
- Канонический скрипт пока в DB, необходим export/review/manual installation. Установка файла и выпуск token — разные эксплуатационные шаги; рабочие таблицы автоматически не обновляются.

### Deployment и rollback

1. Backup БД/script_assets/ключей расшифровки; остановить writers. Разместить код/новую миграцию. Не пересчитывать hash/history seeding-командой. До возобновления старых таблиц явно включить legacy-флаг только нужным интеграциям через audited CLI. Новый API при отсутствии выпущенных токенов закрыт авторизацией.
2. Таблица должна пройти UUID-переход 1.4. Выпустить individual token, проверить контекст/справочники, установить reviewed DB-версию единого скрипта и Script Properties. Проверить create/update/skip/clear на тестовой записи. Отключить legacy global доступ этой интеграции после перевода; общий config удалять только отдельным contract-шагом после всех потребителей.
3. При проблеме сначала остановить writers/закрыть API, сохранить историю/tokens/audit. Предпочитать forward fix; отключение нового endpoint не должно возвращать безусловный global middleware. Возврат к коду 1.4 допустим только при внешне закрытых API и отдельной процедуре доступа/версий скрипта; иначе он проигнорирует новые deny-флаги и token semantics.
4. Down отказывается удалять непустую token table/новые hash versions/активные legacy-настройки; очищать аудит/историю ради down нельзя. После новых внешних операций backup restore требует отдельной ручной сверки AC, а после UUID нельзя возвращать старый row-number алгоритм.

## Done Criteria

- [ ] Новый API принимает только individual token, неизменно связан с его интеграцией/таблицей, проверяет отзыв/active до dispatch и завершения.
- [ ] CLI выдаёт/ротирует/отзывает tokens и управляет legacy allowlist с действующими admin permissions и атомарным безопасным audit.
- [ ] POST/PUT и references работают только в допустимом контексте; старые success/duplicate envelopes сохранены, исправленные несовместимости описаны.
- [ ] Конечный payload экранирован и учитывает mappings/source/clear, SHA-256 детерминирован и версионирован; неподтверждённый ответ не создаёт основания skip.
- [ ] Сервер атомарно выбирает create/update/skip; skip журналируется без POST/PUT и без новой даты/подмены подтверждённого snapshot.
- [ ] Unknown/remote_missing/recovery/late callback/commit failure не обходятся auto intent или hash; MariaDB гонки проходят.
- [ ] Apps Script содержит одно основное действие и сохраняет UUID-защиту/response ownership; DB/custom scripts не перезаписаны автоматически.
- [ ] Fresh/upgrade/guarded rollback проверены отдельно, история 1.4 сохранена, рабочие данные не менялись.
- [ ] Выполнены PHPUnit/JS/MariaDB/Pint/PHPStan/build/Blade; ограничения Sail/Google/AC и неподтверждённое live clear честно зафиксированы в REPORT-2.
- [ ] Документы описывают полный labels ownership, global deny/allowlist, token rotation, DB-script переход и безопасный rollback; dependency updates/последующие этапы не смешаны.
