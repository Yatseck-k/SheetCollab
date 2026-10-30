# Task Summary

Статус: шаг **1.2 — состояния и атомарное резервирование** явно одобрен пользователем и реализован. Проверки и ограничения — `work/REPORT-1.2.md`; штатный Sail не запущен, проверки выполнены отдельно. Шаг 1.1 сохранён в `work/PLAN-1.1.md`, результаты — `work/REPORT.md`.

Цель: для одной legacy-записи только один исполнитель получает право отправить create/update. Внешний HTTP выполняется вне транзакции; поздний ответ не может завершить чужую операцию. Принятые попытки имеют отдельную историю. Истёкший lease и неопределённый исход не разрешают повторный create.

## Scope

Включено:

- Additive-схема состояний экспорта и новая таблица попыток; текущий global unique `external_id` пока сохраняется.
- Резервирование с обработкой конфликта первого insert, блокировкой строки и operation ID; условное завершение вместе с историей в одной транзакции.
- Минимальная безопасная обработка unknown: блокирование повторов после возможной отправки, истечения lease и ошибки фиксации результата.
- Connect timeout 5 секунд, total timeout 30 секунд, lease 120 секунд. Это необходимая предварительная часть шага 1.3: lease нельзя согласовать с неограниченным HTTP. Никаких автоматических повторов POST/PUT.
- Идемпотентная классификация исторических экспортов отдельной командой с dry-run; действующие данные и история не удаляются.
- Проверка двумя независимыми процессами на отдельной MariaDB, включая гонку первого insert.

За границами: UUID, SHA-256/skip, новый sync endpoint, Connections, индивидуальные токены, роли, новый Orchid UI, ручное разрешение unknown и полный reconciliation, contract-миграции и обновление зависимостей. Следующий шаг 1.3 добавит подробную классификацию отказов и административное восстановление с аудитом. До этого unknown безопасно блокируется без автоматического снятия.

## Existing Changes

- `work/diff.txt` существует и пуст. В рабочем дереве остаются восемь файлов шага 1.1: сервис, export repository, bootstrap, новая binding exception, два тестовых файла и две страницы документации. Эти изменения не отменять и не смешивать с чужими будущими правками.
- Scoped lookup и защита полной привязки уже реализованы, но между чтением экспорта и HTTP нет резервирования. `markCreated/markUpdated/markFailed` обновляют модель без проверки владельца операции.
- В таблице exports есть UUID primary key, global unique `external_id`, nullable integration ID и поля подтверждённой связи. FK на старую integration ID отсутствует.
- `ActiveCollabRepository` пока возвращает необработанный JSON, логирует body внешней ошибки и не задаёт timeout. Эти пути надо скорректировать для безопасного нового журнала и unknown.
- Все существующие PHPUnit-тесты используют SQLite in-memory. `composer:2` доступен локально с PHP 8.5 и SQLite, но без pdo_mysql; Sail-стек проекта не запущен. Результаты предыдущего шага не заменяют новые проверки.
- `work/` игнорируется Git. Предыдущий отчёт содержит известные npm findings: 5 high и 1 critical; их аудит/обновление требует отдельного плана по TASK §13.

## Constraints and Rules

- Соблюдать TASK, AGENTS, CLAUDE, `work/AGENT_RULES.md`, ub-mode и work-task-flow. Отдельное согласование этого плана обязательно до изменения кода.
- Контроллеры остаются тонкими; DB-операции проходят через repository, весь HTTP ActiveCollab — только через `ActiveCollabRepository`.
- Сохранить формат external ID, успешные legacy envelopes и duplicate 409 с task ID/URL. Не обещать exactly-once между MariaDB и ActiveCollab.
- Миграции меняют только схему; исторические данные классифицируются отдельной командой. Не редактировать выполненные миграции, не выдумывать старые попытки и synced_at.
- В журнал, ошибки и логи не включать токены, auth headers, сырые HTTP exception/response body и зашифрованные секреты.
- Не использовать production БД, AC или Sheets в тестах. Не удалять существующие volumes и не менять локальный `.env` ради запуска проверок.

## Change Map

| Файл/компонент | Необходимое изменение |
| --- | --- |
| `database/migrations/2026_10_29_000001_add_operation_state_to_active_collab_task_exports_table.php` (новый) | Nullable last_attempt_status, binding_status, operation_id, lease_expires_at, synced_at; индекс integration/task; старые поля/индексы сохранить |
| `database/migrations/2026_10_29_000002_create_active_collab_sync_attempts_table.php` (новый) | UUID id/export_id/integration_id/operation_id; unique operation_id; action/status, timestamps, safe error fields, nullable HTTP status, очищенные JSON payload/response и hash; FK export с restrict delete |
| `app/Models/ActiveCollabTaskExport.php` | Cast дат и состояния, relation attempts; source/confirmed payload не перезаписывать неуспешной попыткой |
| `app/Models/ActiveCollabSyncAttempt.php` (новый) | UUID, casts, связь с экспортом, доступные для записи поля журнала |
| `app/Data/ActiveCollab/ActiveCollabSyncReservation.php` (новый) | Readonly результат резервирования: export ID, operation ID и nullable conflict code; сервис получает отказ после commit, а не откатывает сохранённый unknown исключением |
| `app/Repositories/ActiveCollab/ActiveCollabExportRepository.php` | reserve/create/update, owned completion, классификация legacy, проход backfill; атомарная запись экспорта и попытки |
| `app/Services/ActiveCollab/ActiveCollabTaskService.php` | reserve → подготовка → HTTP → условное завершение; удалить обходные безусловные write-path |
| `app/Repositories/ActiveCollab/ActiveCollabRepository.php` | Timeout, отсутствие retry, проверка успешной формы single/id/url_path, безопасная ошибка без raw body |
| `app/Exceptions/TaskSyncConflictException.php` (новый) | running/unknown конфликты с безопасными code и operation ID |
| `app/Exceptions/ActiveCollabRequestException.php` (новый) | Безопасные сведения о HTTP status/timeout/невалидном ответе; без исходного exception chain с секретами |
| `bootstrap/app.php` | Ответы running/unknown и безопасная ошибка upstream для legacy API |
| `config/services.php`, `.env.example` | ACTIVECOLLAB_CONNECT_TIMEOUT=5, ACTIVECOLLAB_TIMEOUT=30, ACTIVECOLLAB_SYNC_LEASE_SECONDS=120 |
| `app/Console/Commands/BackfillActiveCollabSyncState.php` (новый) | Команда `integrations:backfill-sync-state {--dry-run}`; только repository I/O, классификация и отчёт без секретов |
| `tests/Feature/ActiveCollabTaskApiTest.php`, `ActiveCollabExportRepositoryTest.php`, `ActiveCollabRepositoryTest.php` | Резервирование, competing requests, ownership, safe errors, прежние гарантии 1.1, timeout options и response validation |
| `tests/Feature/BackfillActiveCollabSyncStateTest.php` (новый) | Dry-run, повторный запуск, malformed/orphan snapshots, отсутствие поддельной истории |
| `tests/Feature/ActiveCollabSyncConcurrencyTest.php`, `tests/Fixtures/activecollab_sync_worker.php` (новые) | Родитель и два независимых app-процесса; barrier и общий счётчик fake HTTP; проверка MariaDB без реального AC |
| `phpunit.mariadb.xml` (новый), `docs/TESTING.md` | Отдельный opt-in запуск concurrency test с внешними тестовыми DB-параметрами, без SQLite overrides и production DB |
| `CLAUDE.md` | Обновить Domain Rules: legacy status больше не управляет правом отправки; описать состояния и operation ownership |
| `docs/API_REFERENCE.md`, `docs/LOGIC.md`, `docs/DATA_MODEL.md`, `docs/DEPLOY.md` | Конфликты, состояния, транзакции, rollout/backfill и безопасный rollback |

Новые FK на историческую integration ID не добавлять до отдельной проверки осиротевших данных. record_uuid/hash_version и их индексы отложены до соответствующих этапов; здесь они не используются. Existing Orchid screens не изменяются.

## Implementation Plan

1. Повторно проверить рабочее дерево и baseline: focused tests 1.1, lint и analyse. Сохранить причины недоступных команд; не считать прежний отчёт текущим запуском. Подготовить отдельную тестовую MariaDB той же версии, что compose (`11.4.12`), с временным хранилищем и выделенными тестовыми credentials. PHP runner должен иметь PHP 8.5 и pdo_mysql; при отсутствии создать временный проверочный образ вне продуктового compose. Не обновлять версии проекта и не использовать его рабочий DB volume.
2. До production-кода добавить поведенческие регрессии первого insert и повторного запроса. Новый процесс A удерживает исполнение на fake HTTP barrier; B обращается к той же записи и должен получить conflict без HTTP. Проверить отдельно пустую таблицу и готовый unbound export, а также update существующей связи. На исходном коде зафиксировать второй вызов/unique error либо изменение без владения операцией.
3. Добавить две названные additive-миграции и модели. У существующих экспортов новые состояния nullable, чтобы не приписать старому pending безопасный idle. У новых экспортов repository явно записывает `idle/unbound`, затем резервирует операцию в той же транзакции. State vocabulary: last_attempt_status = idle/running/succeeded/failed/unknown; binding_status = unbound/bound/remote_missing. В 1.2 remote_missing зарезервирован, подробная 404-семантика — шаг 1.3. Для attempts actions create/update/skip/reconcile, statuses running/succeeded/failed/unknown; реально сейчас создаются только create/update.
4. В repository реализовать короткую транзакцию резервирования. Под global unique external ID попытаться создать новый idle export; при unique conflict откатить эту попытку и перечитать существующую строку в новой транзакции. Ловить именно unique conflict, не поглощать другие DB errors. Для существующей строки использовать `lockForUpdate()`; повтор DB deadlock разрешён максимум три раза и только пока не было HTTP. Проверять scoped context и целостность привязки из 1.1 под блокировкой. Полная привязка при create по-прежнему возвращает duplicate 409, без новой принятой попытки.
5. Под той же блокировкой классифицировать nullable legacy-state по правилам backfill ниже. Для update проверять task ID/source/project, затем unresolved состояние. Живой running → sync_in_progress. Истёкший running → сохранить unknown и завершить его attempt как unknown; unknown → sync_outcome_unknown. Никакого takeover просроченного lease. Отказ, изменивший состояние на unknown, возвращается из транзакции результатом reservation и превращается в API exception только после commit.
6. Для разрешённой записи установить новый operation UUID, running, lease `now + 120s` и создать attempt running с тем же operation UUID. Условный UPDATE должен требовать прежнее допустимое состояние; если число изменённых строк не 1 — HTTP запрещён. Получить последний подтверждённый snapshot и выбрать create/update только в рамках этого захвата. В 1.2 legacy action задаётся маршрутом, skip отсутствует; MD5 сохранить.
7. Зафиксировать reservation до подготовки payload и HTTP. Ошибка маппинга до отправки завершает owned attempt как failed и допускает последующий безопасный запрос; подтверждённые task ID/URL/hash/payload сохраняются. В attempt хранить отдельный исходный payload этой попытки. Перед отправкой кратко проверить ownership/running/неистёкший lease; ошибка этой проверки запрещает HTTP. Не удерживать DB-транзакцию во время внешнего вызова.
8. В HTTP repository применить системные connect/total timeout без retry. Проверять JSON object с single, положительным integer id и непустым относительным url_path (начинается с /, но не //, без собственного host/scheme); update response ID должен совпадать с запрошенным ID. Проверки формы не заменяют трактовку бизнес-семантики AC. Ошибка после возможной отправки, malformed success и неоднозначная 5xx консервативно дают unknown. До шага 1.3 любой неподтверждённый внешний отказ после dispatch блокирует повтор; не считать 5xx доказательством отсутствия задачи.
9. Убрать raw response body из HTTP logging и raw exception message из sync logging. Передаваемые дальше ошибки содержат только фиксированный code/message и HTTP status при наличии. В новом журнале сохранять whitelist source/task fields и response single.id/url_path, исключить auth/secret fields; из строк удалить точные известные токены текущей интеграции и глобального legacy-доступа. Не копировать целый внешний JSON и headers. Секрет, отражённый upstream в body, не появляется в логе/журнале/API.
10. Завершение success/failed/unknown выполнить одной короткой транзакцией: условие export ID + operation ID + running и действующий lease для success/failed; обновить экспорт и соответствующую попытку атомарно. Позднее завершение при истёкшем lease переводит всё ещё свою running-операцию в unknown, но не подтверждает успех. При изменившемся operation ID не менять ни экспорт, ни новую попытку. Success записывает confirmed source/AC payload/response, task ID/URL/hash, synced_at, bound и succeeded. Failed/unknown сохраняют подтверждённый snapshot. operation ID последней операции сохранить для корреляции, lease после terminal state очистить.
11. При ошибке commit после внешнего успеха не помечать запись failed и не повторять HTTP. Если БД доступна, сохранить unknown отдельной owned-транзакцией; если недоступна — durable running reservation уже существует, а следующий запрос после lease обязан превратить её в unknown. Исходному клиенту вернуть безопасную ошибку с operation ID и retryable=false; успешного ответа без фиксации нет.
12. В bootstrap добавить HTTP 409 для sync_in_progress и sync_outcome_unknown с error.code/message/operation_id/retryable=false. Сохранить duplicate envelope. Для текущей legacy mapping error сохранить HTTP 500; для неоднозначной внешней ошибки использовать безопасный HTTP 502 либо 504 для timeout, code=sync_outcome_unknown и retryable=false. Будущий новый endpoint будет иметь отдельные mapping 422 и прочие целевые правила. Изменённую upstream-ошибку закрепить тестом и документацией.
13. Добавить backfill-команду с dry-run и repository chunking. Обновлять только записи с nullable новыми состояниями под lock; не перезаписывать работающие/новые операции. Полная связь → bound, иначе unbound с отчётом о malformed данных. Достоверный created/updated с полной связью → succeeded; pending/failed и неконсистентные снимки → unknown, потому что старая схема не доказывает отсутствия внешнего результата. synced_at не выводить из updated_at; оставить null. Orphan integration и несовпавший project не исправлять автоматически и не допускать к отправке. Не создавать исторических attempts. Тот же классификатор использовать для lazy-init на запросе, чтобы пропущенный backfill не открыл повторный create.
14. Обновить tests с явными idle/failed/succeeded fixtures вместо представления старого pending как безопасного повтора. Сохранить регрессии 1.1 о связи после failed update и scope. Доказать, что старый pending/failed без связи блокируется unknown, а новый failed из ошибки маппинга безопасно резервируется повторно. Обновить CLAUDE и указанную документацию; выполнить проверки и записать результаты в отдельный `work/REPORT-1.2.md`.

## Validation Plan

- Focused SQLite: `make artisan CMD='test --filter=ActiveCollabTaskApiTest|ActiveCollabExportRepositoryTest|ActiveCollabRepositoryTest|BackfillActiveCollabSyncStateTest'`. Если shell трактует фильтр иначе, запускать каждый класс отдельной командой. Запрет неожиданных HTTP requests во всех новых тестах.
- MariaDB: `php vendor/bin/phpunit -c phpunit.mariadb.xml --filter=ActiveCollabSyncConcurrencyTest` в PHP 8.5 runner с pdo_mysql, на отдельной БД с именем `sheetcollab_concurrency_test`. Harness должен проверить APP_ENV=testing, драйвер mariadb и точное имя БД до очистки тестовых данных; отказ при неверном окружении. Передавать явные тестовые DB-параметры дочерним процессам, не наследовать SQLite overrides или рабочий DB_URL.
- Конкуренция: committed fixtures без внешней PHPUnit-транзакции; два новых PHP-процесса с отдельными соединениями; fake HTTP с общим locked counter и файловым barrier в уникальной temp directory. Parent ждёт сигнал A на HTTP, запускает/разрешает B, проверяет 409 до освобождения A, затем разрешает A завершить. Проверить также синхронный старт обоих процессов на отсутствующей записи. Ограниченные ожидания, гарантированное завершение workers и очистка только своих временных данных. Нет реального AC HTTP.
- Гонки проверить для первого insert, существующего unbound экспорта, bound update и разных записей (они не блокируют друг друга). Проверить один accepted attempt и один fake HTTP для победителя; конфликт не вызывает 500 unique error.
- В HTTP fake победителя assert transactionLevel()=0. Отдельными repo tests доказать, что иной operation ID и late completion не перезаписывают состояние, а rollback completion не оставляет export/attempt в разных состояниях.
- Смоделировать timeout, malformed success, reflected token, ошибку сохранения после успешного fake create, истечение lease при недоступной БД; следующий запрос не отправляет второй POST/PUT. Проверить отсутствие токенов во всех новых API/error/log/history путях.
- Чистая схема и upgrade со старым snapshot: миграции, dry-run без изменений, двукратный backfill с одинаковым результатом; old orphan rows сохранены, synced_at не выдуман, attempts появляются только после новых принятых запросов.
- Итог: `make lint`, `make analyse`, `make test`, `make check`. При недоступном Sail выполнить соответствующие Composer/PHPUnit/frontend команды отдельно и явно оставить штатную проверку неподтверждённой. CRAP/DRY только при установленном исправном ub-php, без установки инструмента и без автоматического запуска mutation testing.
- Реализационные проверки выполнены и перечислены в `work/REPORT-1.2.md`; запуск штатного `make check` остаётся неподтверждённым из-за остановленного Sail.

## Risks

- Row number пока нестабилен; атомарность защищает текущий locator, UUID появится в 1.4. Общий integration token не обеспечивает изоляцию независимых пользователей.
- Консервативная классификация старых pending/failed может заблокировать безопасные на деле запросы. Это намеренная защита от дубликатов; ручное разрешение с аудитом появится в 1.3. Не очищать unknown SQL-командой и не удалять экспорт ради повтора.
- Часть timeout/error handling перенесена вперёд из 1.3 как необходимая часть безопасного reservation; полный reconciliation и remote_missing остаются последующим отдельным планом.
- Project/base URL могут изменяться через прежний UI; полная защита смены назначения — этап 3. В 1.2 не изменять назначения во время операций; rollout проводить с остановленной синхронизацией.
- SQLite не подтверждает MariaDB locks/deadlocks. Без реальной проверки двумя процессами нельзя объявлять атомарность подтверждённой. Доступность pdo_mysql runner и нужного DB image выясняется при подготовке проверочной среды, без изменения стека продукта.
- Пока есть исходный idle export или safe failed mapping, зависший процесс до dispatch всё равно может приводить к unknown после lease: безопасность предпочтительнее автоматического нового create. Это ограничение явно документировать.
- Наличие оповещения npm critical требует отдельного раннего аудита, но данный план не разрешает обновить lock-файлы.

### Rollout и откат

1. Остановить приём синхронизаций и дождаться завершения старых workers. Снять backup БД и конфигурации расшифровки, проверить процедуру восстановления. Не смешивать старые и новые writers: старый код не соблюдает operation ownership.
2. Применить additive-миграции, выполнить dry-run backfill, разобрать отчёт и выполнить backfill. Проверить counts и сохранить список unknown/orphan IDs без payload/секретов. Включить новую версию только после проверки классификации и новой state machine.
3. При проблеме остановить запись. Additive-таблицы и состояния оставить; до начала нового трафика возможен возврат к исходной версии при сохранённом backup. После новых attempts возврат к 1.1 безопасным rollback не считается: он игнорирует unknown и снова разрешает дубликаты. Использовать исправление вперёд либо отдельное восстановление backup с обязательной сверкой внешних результатов.
4. down-миграции нужны для пустой тестовой схемы и удаляют добавленные структуры только там. Не выполнять destructive down на рабочей истории как способ отката релиза.

## Done Criteria

- [x] Два процесса на MariaDB не отправляют второй HTTP для одной записи; покрыты первый insert и существующий экспорт.
- [x] У каждого принятого запроса есть operation ID/attempt; HTTP выполняется после commit reservation и вне DB-транзакции.
- [x] Завершить операцию может только её владелец; export и attempt фиксируются атомарно, late result не подтверждает истёкшую/чужую операцию.
- [x] running возвращает 409, expired running становится durable unknown; никакого takeover или автоматического повторного create.
- [x] Timeout, malformed response и DB failure после успеха не открывают повтор; mapping failure до HTTP остаётся безопасно повторяемой.
- [x] Подтверждённая связь/hash/payload не теряются при failed/unknown; scope и duplicate envelope из 1.1 сохранены.
- [x] Backfill имеет dry-run, повторяем, не придумывает историю/время успеха и не исправляет orphan rows по догадке; lazy-init следует тем же правилам.
- [x] В новых ответах, журнале и затронутых логах нет секретов, в том числе отражённых upstream.
- [x] Миграции, focused tests, реальная MariaDB concurrency и итоговые проверки выполнены; ограничения среды перечислены отдельно.
- [x] Обновлены инструкции состояний и эксплуатационная документация; изменения ограничены согласованным 1.2.
