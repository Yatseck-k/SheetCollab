# Task Summary

Статус: **шаг 1.4 — план явно одобрен пользователем и реализован; результаты и ограничения — work/REPORT-1.4.md**.

Цель: идентифицировать запись по `(integration_id, record_uuid)`, чтобы полная перестановка строки и повторное использование её номера не меняли привязанную AC-задачу. Перенести существующие связи без внешнего create, сохранив гарантии шагов 1.1–1.3.

Выполненный план 1.3 сохранён в `work/PLAN-1.3.md`; результаты — `work/REPORT-1.3.md`. Основание: §§3–4, 5.3, 6.1–6.3, 9–12 TASK и разрешение пользователя подготовить следующий план.

## Scope

Включено: nullable UUID и индекс; явный UUID-only режим интеграции; безопасное снятие глобальной уникальности external_id; UUID в существующих POST/PUT; идентификация/блокировки; техническая колонка Apps Script, дубликаты и запись результата после перемещения; подготовка манифеста и административная CLI-команда переноса с dry-run/report/audit; совместимость recovery; документация и проверки.

Вне шага: `/api/tasks/sync`, SHA-256/skip, единое основное действие вместо create/update, Connections, индивидуальные токены/полные роли, перестройка Orchid, удаление DB-редактора, обновление зависимостей. Это следующие этапы. Рабочие БД, AC и Google-таблицы при реализации/автоматических тестах не менять.

## Existing Changes

- Обязательные TASK, diff, AGENT_RULES прочитаны. diff.txt пуст. В дереве staged/unstaged результаты 1.1–1.3; сохранить их, пользовательское staging и отчёты. Коммиты не создавать.
- Reserve ищет глобально по external_id; первый insert защищает его unique. После снятия индекса legacy insert требует другой защиты.
- Operation ID, lease, attempts, unknown/remote_missing, sweep и recovery уже реализованы. UUID не должен обходить эти ограничения.
- Recovery сверяет источник с текущими row number/external_id: это потребуется заменить проверкой идентичности для UUID-записей.
- DTO, обе FormRequest и sanitizer не переносят UUID. MD5 из 13 бизнес-полей остаётся прежним в этом шаге.
- Apps Script записывает результат по исходному номеру строки, имеет create/update и не использует LockService.
- GoogleAppsScriptService скачивает каноническую DB-версию из script_assets, файл служит bootstrap. Обновление файла не меняет рабочую DB-версию автоматически; редактор пока сохраняется.
- По отчёту 1.3 проходили 183 выполненных SQLite cases и 9 MariaDB cases, Pint/PHPStan/build; Sail был недоступен. Это история, а не новые запуски.

## Constraints and Rules

- Соблюдать AGENTS, CLAUDE, work/AGENT_RULES, TASK, ub-mode, work-task-flow. До одобрения менять только план/архив.
- HTTP к AC только через ActiveCollabRepository. Для переноса новые AC-запросы не нужны. DB-запросы/транзакции — в repositories; CLI валидирует → DTO → service → repository.
- Формат external_id остаётся `{spreadsheet_id}:{sheet_id}:{row_number}`; меняется роль, не формат. Выполненные миграции не редактировать.
- Новый UUID не присваивается сервером и не усыновляет старый экспорт по row number. Перенос не создаёт attempts, synced_at или фиктивного успешного sync.
- Legacy success envelopes и duplicate-create 409 с собственной ссылкой сохраняются. Unknown/expired/remote_missing не разрешают автоматический create.
- Общий legacy token пока сохраняется; UUID не обеспечивает изоляцию независимых пользователей. Промежуточную версию им не публиковать. Индивидуальные токены обязательны для нового API на этапе 2.
- В манифест/аудит переноса не включать секреты, raw HTTP или бизнес-содержимое строк. Отчёт: идентификаторы и безопасные причины конфликтов.

## Decisions

### Схема и API

1. Новая `2026_10_30_000002_add_record_identity_to_active_collab_exports_and_integrations.php`: nullable `active_collab_task_exports.record_uuid`; unique `(integration_id, record_uuid)` с именем `ac_exports_integration_record_unique`; boolean `active_collab_integrations.uuid_only` default false. Cast флага; UUID в fillable экспорта. Флаг не включать в публичную mass assignment/форму настроек.
2. Новая `2026_10_30_000003_replace_external_id_unique_with_index_on_active_collab_task_exports.php`: заменить прежний global unique на неуникальный external_id index. Применять при остановленных writers после размещения нового алгоритма. Миграции не назначают UUID и не переносят данные.
3. Все новые экспорты имеют integration ID. Исторические `integration_id=null` сохраняются и перечисляются в отчёте; совпадающий spreadsheet блокирует перевод соответствующей интеграции до ручной сверки.
4. Перевод целой интеграции без смешанного runtime: `uuid_only=false` без UUID-привязок принимает legacy-запросы без UUID; `uuid_only=true` принимает запросы с UUID. UUID до перевода и отсутствие UUID после перевода дают `409 legacy_binding_conflict` с инструкцией перехода, без HTTP/нового экспорта. Маршруты POST/PUT остаются прежними.
5. Обе FormRequest принимают optional record_uuid: валидный непустой UUID, нормализация регистра; null/пустая строка считаются отсутствием. Остальные правила и успешные ответы сохранить. Полные лимиты нового sync API — этап 2.
6. Флаг устанавливается только командой при inactive после полного переноса; обычная форма его не снимает. Пустая новая интеграция переводится пустым манифестом по тем же правилам.

### Идентичность, состояние и транзакции

- В короткой reserve-транзакции сначала lock строки интеграции и свежая проверка active/spreadsheet/project/uuid_only, затем export lock. Это сериализует first insert и защищает legacy-путь после снятия global unique. HTTP выполняется после commit, integration lock не держится во время внешнего запроса.
- UUID lookup только по `(integration_id, record_uuid)` с проверкой project/spreadsheet. sheet_id/row_number — позиция, разрешено перемещение в пределах той же Google-таблицы. PUT требует совпадения task ID полной связи. Неизвестный UUID не усыновляется через task ID или external_id. Известная полная связь на POST даёт прежний duplicate 409 независимо от failed update.
- Legacy lookup scoped, проверяет неоднозначность и чужой/осиротевший контекст. Если при uuid_only=false в интеграции уже есть хотя бы одна UUID-привязка, блокировать все её legacy-запросы как незавершённый переход, включая пустые позиции; случайная реактивация между apply и enable не должна открыть row-number writer. Не выбирать произвольный first из нескольких совпадений. Конфликт блокирует HTTP и insert.
- Новый UUID на прежнем месте другой UUID-записи создаёт отдельный экспорт. Locator обновляется только при принятой reserve-операции; отклонённый запрос его не меняет. Исторические attempts/snapshots не переписываются. Callback не возвращает старую позицию.
- Сохранить reserve → prepareDispatch → complete, ownership/lease, состояния и аудит. В транзакциях, берущих оба lock, использовать порядок integration → export (reserve/recovery/finalization/migration) с повторной проверкой контекста. Sweep и обычный complete с одним export lock не расширять. Сохранить deadlock retries.
- Все действующие identity lookup helpers применяют те же правила; не оставлять обход global locator lookup. Удалять helper только при подтверждённом отсутствии потребителей.
- TaskSyncConflictException получает понятное сообщение legacy_binding_conflict; существующий renderer уже передаёт code/message. Общую перестройку error envelope сюда не включать.

### Перенос существующих связей

Команда: `integrations:migrate-record-uuids --integration=<uuid> --manifest=<file> --actor=<id> --reason=<text> [--dry-run] [--enable-uuid-only]`.

- Манифест JSON version 1: spreadsheet_id, records с record_uuid/sheet_id/row_number и nullable task_id/task_url из фактической ссылки. Без токенов/бизнес-полей. Причина переноса — 10–1000 символов. Лимиты: файл 4 MiB, 20 000 записей, spreadsheet ID 255 символов, sheet ID 64, URL 2048; положительные integer row/task ID; уникальные UUID во всей таблице. Проверять до mutation.
- Apps Script под document lock назначает/сохраняет UUID заполненным строкам всех подходящих листов, проверяет дубликаты и показывает JSON в безопасном копируемом диалоге. Никаких service/AC HTTP. Неправильная структура листа с ожидаемыми заголовками блокирует подготовку. Пустые строки пропускаются.
- Оператор останавливает triggers, ручную синхронизацию, сортировку/редактирование; деактивирует интеграцию; сохраняет backup БД, таблицы и DB-скрипта; подтверждает task-ссылки. Команда не может доказать остановку Google-редакторов — это эксплуатационное условие.
- Старый экспорт выбирается по integration/project/подтверждённому task ID и должен быть единственным. Host/project/task из ссылки сверяются с эффективным назначением и полной сохранённой связью. Номер строки не является доказательством. Две строки одного task/export, конфликт UUID, чужой project, неоднозначный URL/отсутствие подтверждения блокируют перенос.
- Строки без ссылки и исторического экспорта остаются новыми: команда не создаёт экспорт/AC-задачу. Несопоставленные, unbound или неполные исторические экспорты блокируют готовность: нужна ручная сверка/восстановление 1.3 и повторный манифест. Unknown с полной подтверждённой связью может получить UUID, сохраняя unknown. Любой running, включая expired, блокирует apply до завершения/sweep.
- Dry-run имеет те же проверки, ноль записей, planned/already_bound/unresolved/orphaned/conflicts/export IDs, ненулевой exit при blockers. Apply допускает только полный проверенный перенос одной inactive-интеграции. Без enable флаг остаётся false и запись из таблицы остаётся остановленной; повторный запуск может включить его.
- Одна транзакция: свежая проверка actor существующей recovery-policy (все три permissions), integration lock, exports в порядке ID, повторная полная проверка, запись только UUID/текущего locator и audit, при enable — uuid_only. Не менять task-связь, snapshots, hash, operation ID, статусы/synced_at.
- Аудит `record_uuid_bound` и переключения readiness: существующая admin_audit_logs, actor/reason/project, before/after UUID/locator или флаг. Узкий метод AdminAuditLogRepository пишет атомарное DB-действие с phase=result; batch UUID в operation_id только для корреляции, без изменения sync operation/создания attempt. Audit failure откатывает перенос. Повтор идентичного манифеста — no-op без новых audit; замена уже привязанного UUID запрещена.
- После отчёта оператор устанавливает проверенный UUID-скрипт, включает флаг и активирует интеграцию. Эти действия с рабочими данными не выполнять автоматически при разработке.

### Apps Script и recovery

- Колонка точно `SheetCollab UUID`, создаётся один раз и скрывается; дубли заголовка — ошибка. Document lock с ограниченным ожиданием; отсутствие lock/таймаут блокирует HTTP. Под lock перечитать UUID, назначить при отсутствии, flush, проверить уникальность во всех листах таблицы. Во время UrlFetchApp lock не держать.
- POST/PUT включают UUID. После любого HTTP-исхода, включая network/validation/duplicate conflict, заново найти UUID во всей таблице и актуальные колонки; под lock проверить единственность перед записью URL/error/date. Ноль/несколько совпадений — alert без записи в старый row. Удалённую строку не восстанавливать.
- 409 не всегда duplicate: восстановить ссылку только из проверенного duplicate response с task ID/URL; остальные codes показать как конфликт. Не делать автоматические retries/create при unknown/remote_missing.
- Дополнительное действие «Новая запись из копии»: подтверждение; новый UUID только выбранной строке; очистить link/error/date; не менять исходную строку и серверную связь. Create/update остаются раздельными до этапа 2.
- Сортировка/перемещение включают полную строку с UUID. Частичная сортировка бизнес-колонок не поддерживается. LockService координирует скрипты, не блокирует ручные правки: не обещать атомарность против правки ровно между проверкой и записью. При переносе обязательна полная пауза; при обычном HTTP проверить перемещение во время запроса.
- Recovery переносит UUID в DTO/source/view whitelist. Для UUID-записи проверяет export/integration/UUID/spreadsheet, а не текущий row. Исторический source без UUID допустим только из выбранного export после контекстной проверки; UUID подставляется из export, не по совпадению позиции. Явно другой UUID запрещён. Старые attempts не переписывать. Для legacy сохранить прежнюю строгую проверку позиции.
- GoogleAppsScriptService/ScriptAsset/редактор не менять. Описать export/diff/review DB-версии, сохранение полезных изменений и установку проверенного шаблона через текущий редактор перед скачиванием. Не перезаписывать custom scripts миграцией. Канонический Git-источник — этап 6.

## Change Map

| Файл / компонент | Изменение |
| --- | --- |
| Две новые миграции, названные выше | UUID/readiness, индексы, guarded rollback |
| `app/Models/ActiveCollabTaskExport.php`, `ActiveCollabIntegration.php` | UUID и cast флага без публичного write-path |
| `app/Http/Requests/StoreActiveCollabTaskRequest.php`, `UpdateActiveCollabTaskRequest.php` | optional UUID, нормализация |
| `app/Data/ActiveCollab/ActiveCollabTaskData.php` | nullable recordUuid, request/stored payload/toArray |
| `app/Repositories/ActiveCollab/ActiveCollabExportRepository.php` | scoped lookup, lock, locator, recovery lock order |
| `app/Exceptions/TaskSyncConflictException.php` | сообщение legacy_binding_conflict |
| `app/Services/ActiveCollab/ActiveCollabDataSanitizer.php`, `ActiveCollabRecoveryService.php` | UUID whitelist, recovery после перемещения |
| Новый `app/Data/ActiveCollab/ActiveCollabRecordUuidManifest.php` | структура/лимиты манифеста |
| Новый `app/Console/Commands/MigrateActiveCollabRecordUuids.php` | аргументы, ограниченное чтение, report/exit |
| Новый `app/Services/ActiveCollab/ActiveCollabRecordUuidMigrationService.php` | orchestration переноса без HTTP |
| Новый `app/Repositories/ActiveCollab/ActiveCollabRecordUuidMigrationRepository.php` | сопоставления/покрытие, атомарный apply |
| `app/Repositories/AdminAuditLogRepository.php` | узкий аудит идентичности/readiness |
| `resources/apps-script/activecollab_sync.gs` | UUID/lock, дубликаты, re-find, новая копия, манифест |
| `tests/Unit/ActiveCollabTaskDataTest.php`, новый `ActiveCollabRecordUuidManifestTest.php` | DTO и границы манифеста |
| `tests/Feature/ActiveCollabTaskApiTest.php`, `ActiveCollabExportRepositoryTest.php`, `ActiveCollabRecoveryTest.php` | UUID/legacy/recovery регрессии |
| Новый `tests/Feature/MigrateActiveCollabRecordUuidsTest.php` | dry-run, аудит, rollback/readiness |
| `tests/Feature/ActiveCollabSyncConcurrencyTest.php`, `tests/Fixtures/activecollab_sync_worker.php` | реальные гонки UUID/legacy/migration |
| Новый `tests/AppsScript/activecollab_sync.test.cjs` | Node built-in test/vm harness настоящего .gs |
| `docs/API_REFERENCE.md`, `DATA_MODEL.md`, `LOGIC.md`, `DEPLOY.md`, `PROJECT_MANUAL.md`, `TESTING.md` | UUID-контракт, перенос, эксплуатация/проверки |
| `resources/views/admin/guide.blade.php` | UUID/полная сортировка/копия, версия DB-скрипта |
| `CLAUDE.md`, `AGENTS.md`, `work/AGENT_RULES.md` | уточнить роль external_id, сохранить формат/остальные ограничения |
| `work/REPORT-1.4.md`, `work/PLAN.md` | фактические результаты и критерии после реализации |

Routes/controller/middleware не требуют изменения ради optional UUID: validated data уже передаётся в DTO. Не менять API envelope, manifests/lock-файлы зависимостей, DB-редактор и GoogleAppsScriptService.

## Implementation Plan

1. Зафиксировать baseline. Добавить поведенческие UUID API/DTO tests на перемещение/повторное использование позиции; manifest и JS harness на назначение/перемещение. Проверить ожидаемое падение новых тестов без воздействия на рабочие данные.
2. Добавить nullable-схему и DTO/request/sanitizer; реализовать UUID/legacy reserve, integration lock и безопасные helpers. Согласовать recovery/finalization lock order; добавить снятие unique. Сохранить состояния/lease/attempts и проверить insert-конкуренцию.
3. Реализовать manifest DTO, CLI/service/repository, сопоставление по task-ссылкам, полное покрытие, dry-run, аудит/идемпотентность/readiness. Проверить отказ при active/running/неполном покрытии и полный rollback при DB/audit failure.
4. Адаптировать recovery для UUID и исторических sources без переписывания истории. Проверить stale forms, unknown replacement, late callback, чужой UUID/export.
5. Обновить .gs: колонка/UUID/lock, duplicate scan, манифест без HTTP, новая копия, re-find после HTTP и корректные 409. Прогнать harness реального исходника; исправлять небольшими проверяемыми блоками.
6. Обновить перечисленные документы/guide/инструкции, описать DB-версию скрипта, deploy/паузы/backup. Не переводить действующие таблицы. Сверить фактический diff со scope.
7. Выполнить Validation Plan, отметить только подтверждённые Done Criteria, записать REPORT-1.4 с командами/ограничениями/ручными проверками.

## Validation Plan

- API/DTO: malformed/null/uppercase UUID; create; тот же UUID на другом row/sheet сохраняет один export/task; новый UUID на старой позиции даёт отдельный экспорт; неверный task ID/интеграция/project/неизвестный UUID с чужой связью — ноль HTTP. UUID-only/legacy конфликты; duplicate create после failed сохраняет task ID/URL.
- State/recovery: смена позиции не обходит unknown/running/expired/remote_missing; отклонённый запрос не меняет locator/snapshot. Старый source без UUID принимается только своим export; чужой UUID/export запрещён. Весь прежний recovery/sweep suite проходит.
- Migration: fresh install и upgrade 1.3→1.4 на отдельной MariaDB; nullable история, composite unique, неуникальный locator. Dry-run без записей, повторный apply no-op, swap/reuse позиций сопоставляется по task link; дубли UUID/task ID, host/project, неполные ссылки, orphans/unbound/running блокируют. Ошибка аудита откатывает UUID/readiness. Состояния/attempts/synced_at прежние. Проверить actor без трёх прав и пустую интеграцию.
- MariaDB, отдельные процессы: два POST нового UUID с разными row → один AC POST; два legacy POST после снятия unique → один POST; разные UUID на одном locator → разные экспорты; migration против sync/recovery → полный перенос либо безопасный отказ без частичной записи. Все прежние гонки 1.2–1.3. Жёсткая проверка имени отдельной тестовой БД до migrate:fresh.
- JS: `node --test tests/AppsScript/activecollab_sync.test.cjs`. UUID сохранён до fetch; повторное/конкурирующее назначение не затирает его; lock timeout и дубликат на любом листе дают ноль HTTP. Новая копия очищает ссылку и не меняет оригинал. Сортировка/вставка/перемещение между листами во время fake fetch → запись по UUID. Удаление/дубликат во время fetch → никакой записи в старый row. Success/network/validation/разные 409; duplicate headers; манифест без fetch; безопасный HTML диалог.
- Записать ручной Google QA: полная сортировка со скрытой колонкой, две сессии, перемещение во время запроса, duplicate/new copy. Без доступа пометить «не выполнено»: Node fake не доказывает реальный Google runtime.
- Focused PHPUnit → полный suite; Pint changed PHP, PHPStan с текущим уровнем, git diff --check, npm run build для guide. Попытаться make test/make check. При недоступном Sail указать причину и выполнить эквиваленты в изолированном PHP 8.5/Docker со SQLite/MariaDB; не создавать рабочий .env и не запускать production seed.
- ub-mode: при наличии ub-php выполнить доступные doctor/check, разобрать JSON. При отсутствии не устанавливать; ревью сложности/дублирования вручную. CRAP/DRY/mutation не объявлять измеренными без инструмента.

## Risks

- Номер строки ненадёжен: без паузы и подтверждённых task-ссылок перевод запрещён. Недостаточная история требует ручной сверки, готовность не выдаётся по догадке.
- Integration lock кратко сериализует reserve разных строк; HTTP идёт параллельно после commit. Нагрузочные пределы не обещать без измерения. Единый lock order и MariaDB tests обязательны.
- Сервер воспринимает одинаковый UUID как ту же запись; копии выявляет клиент. Частичная сортировка вне технической колонки нарушает идентичность вне возможностей схемы.
- Реальный Google runtime, rich text links и ручные правки требуют отдельного QA; fake tests проверяют алгоритм.
- DB-скрипт остаётся каноническим до этапа 6. Тест файла не означает обновление DB-версии/рабочей таблицы.

### Deployment и rollback

1. Backup, остановка writers, отсутствие живых операций. Разместить код 1.4; применить additive миграцию, затем миграцию индекса; проверить схему/read-path; запустить writers. Не допускать старый row-number writer после снятия unique. Каждую таблицу переводить отдельно при inactive; остальные интеграции остаются legacy.
2. До первой UUID-привязки rollback под паузой допустим только при отсутствии UUID/uuid_only/дубликатов locator: восстановить global unique, затем согласованно откатить additive схему и код. Down заранее проверяет условия и отказывается вместо потери данных/случайного unique failure.
3. После UUID-привязки обычный down/возврат к коду 1.3 запрещён. Остановить запись, сохранить текущее состояние; исправление вперёд либо отдельная процедура backup restore с ручной сверкой AC-задач после backup. Восстановление БД не отменяет внешние side effects. Не стирать UUID/историю ради rollback.

## Done Criteria

- [x] Одна UUID-запись сохраняет export/task после перемещения; другая UUID-запись использует прежнюю позицию независимо.
- [x] Composite unique действует, global unique снят безопасно, legacy first insert защищён на MariaDB.
- [x] Legacy работает до перевода; после перевода отсутствие UUID даёт понятный conflict без HTTP.
- [x] Перенос имеет dry-run/report/audit, повторяем, не создаёт AC-задач и не сопоставляет по одному row number.
- [x] Покрытие/actor/inactive/running проверяются до readiness; пауза Google-редактирования описана как обязанность оператора, спорная история сохраняется в отчёте.
- [x] Клиент назначает UUID до HTTP, блокирует дубликаты, явно создаёт новую копию и пишет ответ по UUID; JS tests исполняются.
- [x] Unknown/remote_missing/recovery/late callback и прежние ответы/scoped update не регрессируют.
- [x] Документы описывают сортировку, DB-скрипт, перенос/deploy/rollback.
- [x] Релевантные PHPUnit/JS/MariaDB/Pint/PHPStan/build выполнены; ограничения и ручной Google QA честно указаны в REPORT-1.4.
- [x] Рабочие данные/внешние сервисы не менялись, staging сохранён, коммитов без запроса нет.
