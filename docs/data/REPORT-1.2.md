# Результат шага 1.2

Дата: 2026-10-29. Реализован явно согласованный `work/PLAN.md`. Шаг 1.1 и пользовательское staging существующей binding exception сохранены. Коммиты и обновление зависимостей не выполнялись.

## Root cause

Scoped lookup шага 1.1 защищал последовательные запросы, но не резервировал право HTTP. Два процесса читали один unbound export и оба отправляли create. Без operation ID любое завершение могло перезаписать состояние; старые failed/pending не различали отсутствие отправки и потерю внешнего результата.

## Files changed

- Две additive-миграции `2026_10_29_000001` и `2026_10_29_000002`: nullable новые состояния exports, operation/lease/synced_at, integration/task index; таблица attempts с unique operation ID и restrict FK на export.
- `app/Models/ActiveCollabTaskExport.php`, новая `ActiveCollabSyncAttempt.php`: casts и связь с историей. Новая readonly `ActiveCollabSyncReservation.php` передаёт результат reservation.
- `ActiveCollabExportRepository.php`: короткие reserve/prepareDispatch/complete транзакции, ownership и lease, классификация legacy, backfill. Безусловные createPending/markCreated/markUpdated/markFailed удалены.
- `ActiveCollabTaskService.php`: общий create/update путь через reservation, безопасные failed/unknown, сохранность подтверждённого snapshot и очистка известных токенов.
- `ActiveCollabRepository.php`: timeout 5/30, отсутствие автоматических retry, валидация single/id/url_path, whitelist ответа, безопасные ошибки и logging без raw body/URL/token.
- Новые `TaskSyncConflictException.php`, `ActiveCollabRequestException.php`; `bootstrap/app.php`: 409 running/unknown, безопасные 502/504 с operation ID. Legacy mapping failure остаётся 500 с безопасным code/message.
- `config/services.php`, `.env.example`: connect/total timeout и lease 120 секунд; runtime lease должен превышать HTTP timeout.
- Новая команда `BackfillActiveCollabSyncState.php`: dry-run, повторяемая классификация и report IDs для сверки без секретов/поддельных attempts.
- Тесты API/export/HTTP repositories; новые backfill и concurrency tests, `tests/Fixtures/activecollab_sync_worker.php`, `phpunit.mariadb.xml`.
- `CLAUDE.md`, docs/API_REFERENCE, LOGIC, DATA_MODEL, DEPLOY, TESTING: состояния, ошибки, rollout/backfill/rollback и отдельный запуск MariaDB.
- Локальные PLAN и этот отчёт в игнорируемой Git папке work.

## Fix summary

Reservation вставляет или блокирует экспорт по global unique legacy locator, проверяет контекст/привязку, классифицирует legacy state, затем атомарно записывает operation UUID/running/lease и attempt. Unique conflict перечитывает победителя в новой транзакции; deadlock допускает ограниченный повтор только до HTTP.

HTTP выполняется после commit reservation. Перед dispatch записывается очищенный payload/hash и проверяется ownership. Success/failed/unknown завершаются вместе с attempt в одной короткой транзакции под export lock, только для своего running operation ID. Поздний ответ не подтверждает истёкший lease, старый владелец не меняет новую операцию.

Timeout, malformed success, внешний неподтверждённый отказ и ошибка фиксации после внешнего успеха дают unknown. Повтор заблокирован. Если сохранение unknown тоже недоступно, durable running остаётся до expiry и затем становится unknown без takeover. Failed допускает повтор только для подтверждённой ошибки маппинга до dispatch. Полная связь при create сохраняет прежний duplicate 409 с task ID/URL.

Старые pending/failed без новой модели классифицируются unknown консервативно. Backfill не назначает integration по догадке, не придумывает synced_at и старые attempts, не перезаписывает live operation. Dry-run и реальные записи используют один классификатор; запрос к неклассифицированной legacy-записи не открывает второй create.

## Validation result

Baseline текущего запуска до изменения кода: focused 1.1 — 61 tests/229 assertions, Pint — 96 файлов, PHPStan без ошибок.

До исправления новый тест двух независимых процессов на MariaDB воспроизвёл проблему: `A competing request sent another HTTP call`, actual calls=2 вместо 1. Это ожидаемый red для гонки первого insert.

Окружение проверок: Docker `composer:2`, PHP 8.5.12. Для MariaDB создан отдельный образ `sheetcollab-12-check` на той же PHP-базе с pdo_mysql, отдельная internal Docker network и MariaDB 11.4.12 с tmpfs. Рабочая БД, её volume и `.env` не использовались. SQLite checks запускались с `--network none`, MariaDB workers — с fake HTTP в internal network.

Итоговые проверки:

- `php vendor/bin/phpunit --display-warnings` в PHP-контейнере: exit 0; 143 cases total, 454 assertions; 138 выполнены без падений, 5 MariaDB cases штатно skipped в SQLite. Есть 11 прежних PHPUnit notices в неизменённом `ActiveCollabIntegrationSettingsServiceTest` (mock без expectations).
- `php vendor/bin/phpunit -c phpunit.mariadb.xml` с явными параметрами выделенной БД: exit 0, все 5 cases / 55 assertions. Проверены first insert, existing idle, bound update, simultaneous insert, разные записи. Конкурент получает именно sync_in_progress с operation ID владельца до release HTTP победителя. Один attempt на принятую операцию, HTTP transactionLevel()=0.
- Отдельная проверка на временной MariaDB: rollback новых пустых структур, legacy snapshot insert, additive upgrade, dry-run без изменений, backfill, повторный backfill с classified=0; история не придумана, synced_at остался null. PASS. То же поведение, включая порционную обработку 105 строк и malformed/live/orphan snapshots, покрыто feature tests на SQLite.
- Симулированы ошибки записи после успешного fake create и отказ записи unknown через model events. Проверены rollback export+attempt, сохранение running при отказе обеих записей и subsequent expiry без второго HTTP. Это моделирование ошибок сохранения, а не отключение физической DB-сети.
- Проверены timeout, malformed response, другой task ID в update response, некорректные URL, response whitelist, явные timeout options, отражённые токены, сохранность подтверждённых полей и safe mapping retry.
- `composer check` в контейнере: exit 0. Pint и PHPStan без ошибок; artisan test дополнительно сообщает warnings чтения отсутствующего `/app/.env`. Прямой PHPUnit выше подтверждает результаты без этой особенности artisan wrapper.
- `php vendor/bin/pint --dirty`: 20 PHP-файлов, pass после форматирования. PHPStan проверяет app/routes/tests, thresholds/config не менялись.
- `npm run build`: exit 0. Composer/npm манифесты и lock-файлы не менялись.
- `git diff --check`: без ошибок. Ручной review: все production writes экспорта проходят ownership; grouped nullable-state query не зацикливает dry-run на нескольких chunks; partial live state не переписывается legacy-классификацией. Изменения schema instructions согласованы с фактическими состояниями.
- `make test` и `make check` через обычный Sail не запустились: `Sail is not running`. Эквивалентные тесты/lint/analyse/build выполнены отдельно; штатная проверка Sail остаётся неподтверждённой.
- `vendor/bin/ub-php` отсутствует: CRAP, численная структурная схожесть и mutation score не измерялись. CDMU/Infection не устанавливались; ручной review не выдаётся за такие измерения.

Созданная тестовая MariaDB останавливается после проверок, её tmpfs и отдельная сеть удаляются. Проверочный PHP-образ остаётся доступен для повторных запусков. Product compose и реальные схемы не менялись в окружении выполнения; миграции подготовлены как код релиза.

## Remaining risks or unverified paths

- Штатный Sail check и production rollout/backup restore не выполнялись. Инструкция требует остановить старых writers; одновременно запускать старый код с новым нельзя.
- Консервативная unknown-классификация блокирует часть исторических pending/failed до ручной сверки. Административное восстановление с аудитом и подробная классификация 404/отказов — следующий шаг 1.3; не очищать unknown вручную ради create.
- Exactly-once между AC и БД не обещается. Real AC semantics, живые Sheets и e2e через публичный tunnel здесь не проверялись.
- UUID, индивидуальные токены и окончательная защита смены connection/project остаются следующими этапами. Пока не менять назначение интеграции во время операций.
- Известные npm findings из предыдущего шага (5 high, 1 critical) требуют отдельного раннего аудита по TASK §13; сейчас audit fix/обновления не выполнялись.
- Down-миграции удаляют добавленные структуры: использовать только на пустой тестовой истории. После новых attempts простой возврат старого кода небезопасен; остановить запись и выбрать исправление вперёд либо отдельное восстановление backup со сверкой внешних результатов.
