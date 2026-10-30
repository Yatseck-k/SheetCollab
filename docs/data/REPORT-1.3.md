# Результат шага 1.3

Дата: 2026-10-30. Реализован явно одобренный пользователем work/PLAN.md. Сохранены изменения и staging шагов 1.1–1.2. Новых коммитов, staging, обновлений зависимостей и действий в рабочих AC/Sheets/БД не выполнялось.

## Root cause

После 1.2 неизвестный результат безопасно блокировался, но отсутствовали административное разрешение, аудит, отдельная классификация недоступной задачи и sweep зависших running. Один 404 нельзя использовать как доказательство отсутствия результата create; общий permission список Orchid также не даёт требуемую AND-проверку административных полномочий.

## Files changed

- Новые audit migration `2026_10_30_000001_create_admin_audit_logs_table.php`, `AdminAuditLog` и `AdminAuditLogRepository`: append-only requested/result, unique request UUID/phase, индекс operation ID, nullable actor FK, безопасные before/after/context.
- `ActiveCollabExportRepository`: recovery snapshot/actor lookup, begin/finalize/replay, единый claim helper, frozen destination checks, expiry sweep, atomic attempt/export/audit. Ordinary PUT terminal remote_missing может принять новый валидированный source как failed attempt без HTTP; unknown replacement не переписывается.
- `ActiveCollabRepository`, `ActiveCollabRequestException`, `TaskSyncConflictException`: GET task с exact task/project/trash validation, безопасные error categories, remote_task_missing и recovery_conflict.
- `ActiveCollabTaskService`: переиспользуемое выполнение серверной reservation, различение отказа до dispatch и unknown, PUT 404 без потери связи, audit вместе с completion.
- Новые `ActiveCollabRecoveryData`, `ActiveCollabDataSanitizer`, `ActiveCollabRecoveryService`; `ActiveCollabTaskData::fromStoredPayload`: проверка сохранённых полей и их соответствия исходной записи, source whitelist, редактирование известных секретов в одном месте.
- Новые policy и регистрация в AppServiceProvider: recovery + users + roles, deny-as-not-found, повторная проверка свежего actor перед фиксацией.
- Новые ExportRecoveryScreen/Layout, изменения routes/platform, PlatformProvider и ExportListLayout: read-only view, guarded POST actions, explicit acknowledgement, saved-source selection и читаемые состояния/история. Непривилегированным ссылка не показывается, прямой доступ всё равно закрыт.
- Новый ExpireActiveCollabSyncOperations command, расписание каждую минуту в routes/console. DatabaseSeeder добавляет право только локальному администратору; production seeder не запускался.
- Новые Recovery/RecoveryScreen/Expire tests, новые API-регрессии, расширенный concurrency harness и worker.
- CLAUDE, docs/API_REFERENCE/LOGIC/DATA_MODEL/TESTING/DEPLOY, admin guide: актуальные контракты, права, расписание и rollout/rollback.
- work/PLAN.md и этот локальный отчёт; screenshot `work/QA-1.3-recovery.png`. Папка work игнорируется Git.

## Fix summary

Администратор может явно подтвердить существующую задачу, проверить старую связь, подтвердить отсутствие результата unbound create либо отдельно создать замену remote_missing. Никакой публичный force-create параметр не добавлен. Recovery использует тот же export lock и operation ownership, HTTP выполняется вне транзакции. Все решения имеют request UUID, expected state, причину и audit; повтор формы не выполняет второй HTTP. Старые unknown attempts не переписываются в succeeded.

Подтверждение связи проверяет конкретный task/project/is_trashed через GET и повторно проверяет actor/назначение перед commit. Effective base URL/token/project фиксируются в памяти, включая fallback config; смена глобального токена во время чтения не проходит проверку. Подтверждение не означает синхронизацию содержимого: hash инвалидируется, synced_at не выдумывается. При новой цели старый текущий confirmed payload/response очищается, история остаётся. Explicit absence нормализует неполный unbound locator, но не обнуляет известную task ID связь.

PUT 404 сохраняет исходные ID/URL/hash/payload и устанавливает remote_missing. GET 404 не доказывает физического удаления или отсутствия предыдущего POST. Failed read сохраняет исходную неизвестность мутации; если до read неопределённой мутации не было, сам неуспешный GET её не придумывает. Unknown replacement остаётся заблокирован после неудачной проверки старой задачи, пока администратор отдельно не подтвердит отсутствие результата замены.

Для выполнимой инструкции о неполном legacy source реализован узкий путь: валидированный Update из Sheets для terminal remote_missing сохраняет source в failed attempt без внешнего HTTP и без изменения confirmed snapshot. Администратор выбирает этот источник для отдельной подтверждаемой замены. При unresolved replacement такой Update не меняет operation ID/unknown. Это уточнение записано в плане, API docs и guide.

Sweep переводит только всё ещё истёкшие running в unknown, имеет dry-run, обрабатывает chunks и missing attempt без выдуманной истории. Ошибка audit не оставляет экспорт разблокированным; после возможного внешнего create сохраняется running/unknown, повтор запрещён.

## Validation result

Baseline текущего запуска: 80 focused tests / 295 assertions, Pint 106 файлов, PHPStan без ошибок.

До исправления новый тест PUT 404 упал ожидаемо: вместо remote_task_missing/409 возвращался generic unknown/502. После исправления сохранность confirmed snapshot, второй заблокированный PUT и legacy duplicate create проверены.

Итог:

- Полный `php vendor/bin/phpunit --display-warnings` в PHP 8.5.12 контейнере с `--network none`: exit 0, 192 cases total, 652 assertions. 183 выполнены без падений; 9 MariaDB cases штатно skipped в SQLite. Есть 11 прежних PHPUnit notices в неизменённом ActiveCollabIntegrationSettingsServiceTest (mock без expectations).
- `php vendor/bin/phpunit -c phpunit.mariadb.xml` на выделенной MariaDB 11.4.12/internal network, PHP 8.5 + pdo_mysql: exit 0, 9 tests / 92 assertions. Сохранены 5 гонок 1.2; добавлены recovery vs sync, два recovery, sweep vs поздний результат и отказ audit в worker. Дочерние процессы имеют отдельные DB connections, fake HTTP/barriers, проверяют transactionLevel()=0.
- Миграция audit отдельно проверена на временной MariaDB поверх снимка 1.2: existing exports/attempts сохранены; recovery создаёт новую историю без изменения старого unknown; actor BIGINT FK/nullOnDelete сохраняет 2 audit rows; unique request UUID/phase отклоняет дубль. PASS. Пустой audit down/up выполнялся только на изолированной схеме.
- Service/UI tests покрывают AND-права, role grants, guest и прямой POST/GET, CSRF с включённой middleware, лимит 16 KiB, mandatory acknowledgement, подмену actor/action/integration, stale формы и replay, отзыв права/смену проекта/смену fallback token во время GET, source validation/whitelist, redaction, audit rollback и повтор после неизвестного replacement.
- Expiry tests: >100 строк, dry-run без записи, повторяемость, null lease, missing attempt без фабрикации, сохранность привязки и отсутствие HTTP.
- Pint `--dirty`: 40 PHP-файлов, pass после форматирования. PHPStan (app/routes/tests, прежний level/config) — без ошибок.
- `npm run build`: exit 0. Манифесты/lock-файлы не обновлялись.
- `make check` и `make test` в штатном Sail не запустились: Sail is not running. Соответствующие lint/analyse/test/build выполнены отдельно; штатный Sail check остаётся неподтверждённым. `.env` не создавался и не менялся.
- Chromium: реальный вход тестовым администратором и render recovery route на отдельной SQLite preview DB. Проверена читаемость, состояния, источник, кнопки и предупреждения. Снимок: `work/QA-1.3-recovery.png`; это не live integration e2e. В preview использованы только фиктивные credentials/данные, внешние admin actions не выполнялись.
- `git diff --check`: без ошибок. Ручной review подтвердил отсутствие custom DB/AC HTTP в Screen, единый sanitizer/claim, сохранение неизвестности исходной мутации после неуспешного GET и идемпотентность audit phases.
- `vendor/bin/ub-php` отсутствует. CRAP/численная схожесть/mutation score не измерялись; CDMU/Infection не устанавливались и mutation testing не запускался. Ручной review не заменяет эти измерения.

Новых обязательных проверок после этих проходов не добавлялось. Временные preview/DB контейнеры, отдельная network и браузер останавливаются после проверки. Product compose, volumes и рабочая схема не менялись; audit migration подготовлена как код релиза.

## Remaining risks or unverified paths

- Make checks в штатном Sail, production backup/rollout и настройка реального scheduler не выполнялись. Оператору нужно применить миграции/backfill 1.2 (если ещё не применены), затем audit migration и выдать permission существующему администратору. Инструкция — docs/DEPLOY. Не запускать production seeder ради этого.
- Подтверждение отсутствия требует остановить старых mutating workers и проверить AC вручную. Lease, GET 404 и пустой поиск не доказывают отсутствия внешнего эффекта; exactly-once не обещается. Ошибочное решение человека может привести к дубликату.
- Точная семантика конкретной self-hosted AC версии, реальная сеть/AC и Google Sheets здесь не проверялись. Strict GET mismatch остаётся блокирующим, не ослабляется догадками.
- Новые recovery routes закрыты независимо, но полная изоляция старого UI и индивидуальные токены ещё впереди. До этапов 2/4 не открывать промежуточную версию независимым пользователям.
- Строки пока идентифицируются legacy row locator; следующий шаг 1.4 — UUID. Connections и окончательная неизменность назначения интеграции — последующие этапы.
- Известные npm findings предыдущего шага требуют отдельного раннего аудита по TASK §13; audit fix не запускался в этом scope.
- После нового решения/замены простой возврат старого кода не отменяет внешний результат и может игнорировать unknown. Отключить recovery, остановить запись, сохранить audit/history и выполнить forward fix либо отдельно сверенное восстановление. Destructive down не использовать на рабочей истории.
