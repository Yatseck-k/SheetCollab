# Task Summary

Статус: **шаг 1.3 — диагностика и административное восстановление** явно одобрен пользователем и реализован. Результаты и ограничения — `work/REPORT-1.3.md`; `make test/check` через штатный Sail недоступны, соответствующие проверки выполнены отдельно. Выполненный план 1.2 сохранён в `work/PLAN-1.2.md`, его результаты — `work/REPORT-1.2.md`.

Цель: дать администратору управляемый выход из unknown/remote_missing, не разрешая автоматический второй create; обнаруживать зависшие операции, сохранять связь и аудит решений. Результат можно проверить через Orchid, API feature tests и гонки восстановления на MariaDB.

## Scope

Включено:

- Разделить безопасные ошибки до dispatch, полученный отказ upstream и неизвестность внешнего результата. Не трактовать один HTTP error code как доказательство отсутствия побочного эффекта.
- Обработать недоступную задачу: remote_missing с сохранением task ID/URL и подтверждённого snapshot; повторная отправка не создаёт замену.
- Добавить команду обнаружения и перевода просроченных running в unknown с dry-run.
- Добавить отдельный защищённый экран Orchid: проверить/привязать существующую задачу, подтвердить отсутствие результата, явно создать замену недоступной задачи. Каждое изменение имеет operation ID и аудит.
- Сохранить атомарность 1.2; HTTP не держит транзакцию, восстановление конкурирует с sync через тот же export lock и operation ID.

Вне этапа: UUID, новый sync endpoint, SHA-256/skip, Connections, индивидуальные токены, полноценные назначения manager/viewer и перестройка меню/истории по этапам 4–5. Не добавлять автоматический поиск задач по тексту, automatic retry POST/PUT, удаление/восстановление задач в корзине AC или универсальный workflow engine. Не менять зависимости. Не переводить production данные при реализации кода.

## Existing Changes

- `work/TASK.md`, `work/diff.txt`, `work/AGENT_RULES.md` прочитаны; diff.txt пуст. Рабочее дерево содержит staged и unstaged результаты 1.1–1.2; сохранять их и пользовательское staging. Коммиты не создавать без отдельного запроса.
- В коде уже есть reserve/prepareDispatch/complete, operation ID/lease, attempts, timeout 5/30 и lease 120. Все неподтверждённые внешние отказы сейчас становятся unknown; remote_missing в vocabulary есть, но не устанавливается.
- Complete проверяет владельца и срок lease. Expiry обрабатывается только при обращении к конкретному экспорту; отдельного sweep нет. Backfill классифицирует старые снимки без выдуманных attempts.
- `ActiveCollabRequestException` содержит HTTP status и timeout, но не failure category. HTTP repository имеет create/update, но не чтение конкретной задачи.
- `ExportListScreen`/`ExportListLayout` показывают только legacy status. Экрана карточки экспорта сейчас нет; `ExportViewScreen.php` не существует. Новый recovery screen нужен отдельно.
- User наследует Orchid UserAccess; `hasAccess(permission, false)` перечитывает role permissions, а `Screen::permission()` проверяет **любое** из перечисленных разрешений. Список permissions не заменяет явную серверную policy с AND-проверкой.
- В приложении нет отдельного признака admin или готовых admin/manager/viewer role slugs. Есть полномочия управления users и roles; DatabaseSeeder задаёт их локальному администратору. Новую параллельную систему ролей не создавать.
- Audit table/model пока отсутствуют. Источник настроек — текущая интеграция с fallback config; Connection появится позже.
- Последние измерения из отчёта 1.2: 138 SQLite cases и 5 MariaDB cases без падений; 11 прежних notices; Sail не был запущен. Это история, а не baseline нового запуска.

## Constraints and Rules

- Соблюдать AGENTS, CLAUDE, TASK, work/AGENT_RULES и work-task-flow. Начать код только после явного согласования этого конкретного плана. Применять ub-mode: сначала воспроизвести нужное поведение и риск, затем небольшие проверяемые изменения.
- Весь AC HTTP остаётся в ActiveCollabRepository; custom DB queries — в repositories. Screen валидирует → создаёт DTO → вызывает service; бизнес-решения не помещать в Screen.
- Сохранить имя моделей, legacy external_id и global unique до UUID-этапа. Старые успешные API envelopes и duplicate create с собственными task ID/URL остаются совместимыми.
- Unknown не означает failed create. Не обещать exactly-once и не снимать блокировку из-за expiry, пустого поиска или одного 404.
- Новые read/actions защищаются на сервере, а не только скрытием кнопок. Обычный integration token не имеет административных прав; session/CSRF обязательны.
- Не выводить и не сохранять секреты/их encrypted values, auth headers, raw exception/body. Общий sanitizer переиспользуется sync и recovery; whitelist audit не содержит source/AC body.

## Decisions

### Подтверждённый API-контракт

Проверены официальные примеры 2026-10-30:

- [Task URL](https://developers.activecollab.com/api-documentation/v1/projects/elements/tasks/task-url.html): GET `/projects/{projectId}/tasks/{taskId}`, ответ single.id/url_path/project_id/is_trashed; показан 404 при другом проекте.
- [Tasks](https://developers.activecollab.com/api-documentation/v1/projects/elements/tasks/tasks.html): задача в корзине может оставаться доступной через GET с is_trashed=true.

Следствие для нашего дизайна: 404 обозначает отсутствие доступа/задачи в выбранном контексте, а не доказанное физическое удаление. В UI использовать текст «Задача не найдена или недоступна в этом проекте». Живой AC в автоматических проверках не вызывать; различия конкретной версии self-hosted AC фиксировать как ограничение.

Нет подтверждённой документацией гарантии, что произвольный 400/401/403/409/422 означает отсутствие записи после POST. Сохранять `upstream_rejected` как категорию полученного ответа, но не предоставлять automatic retry create: outcome остаётся unknown. Подтверждённо безопасный failed — локальная ошибка до dispatch. GET не меняет AC; в этом этапе GET также без автоматических повторов, оператор может повторить проверку явно.

### Права администратора

Добавить permission `platform.activecollab.recovery`. Policy разрешает восстановление, только если пользователь одновременно имеет это разрешение **и** `platform.systems.users` **и** `platform.systems.roles`. Это явное отображение административного полномочия на существующий Orchid Access, без role slug/admin boolean. Наличие recovery permission у менеджера само по себе недостаточно. Policy возвращает deny-as-not-found для авторизованного пользователя без полномочий.

Новый Screen.permission содержит recovery permission как предварительный фильтр, но query и каждый POST method дополнительно вызывают policy; service повторяет проверку и при завершении использует свежую запись actor/permissions через repository. Никаких generic force/override параметров в публичном API. DatabaseSeeder добавляет recovery permission только своему локальному администратору, без миграционного seeding. На действующей установке право назначается администратором через существующий интерфейс Users/Roles; production seeder ради этого не запускать.

### Состояния и ошибки

| Событие | Состояние и дальнейшее поведение |
| --- | --- |
| Валидация/маппинг/доказанная локальная ошибка до вызова HTTP | failed, подтверждённая связь сохраняется; новый запрос после исправления может резервироваться |
| Timeout/обрыв после возможной отправки, 5xx, malformed success, сбой записи после AC | unknown; код уточняет категорию, новый POST/PUT блокируется |
| Полученный иной 4xx после dispatch | error category upstream_rejected + HTTP status; пока unknown и retryable=false без доказанного контракта отсутствия эффекта |
| PUT уже связанной задачи вернул 404 | failed + remote_missing; task ID/URL/hash/payload не очищать; повтор PUT — 409 remote_task_missing |
| GET при recovery вернул 404 или is_trashed=true | Связь недоступна, remote_missing при известном task ID; автоматическая привязка/замена запрещена |
| Неистёкший running | sync_in_progress, recovery не захватывает операцию |
| Истёкший running | Старый attempt завершить unknown, не создавать исторических попыток при отсутствии данных; дефекты orphan/missing attempt вынести в report и не разрешать sync |
| Успешный bind/verify | Новый reconcile attempt succeeded; binding bound; confirmed binding не означает синхронизацию содержимого: hash инвалидируется, synced_at не выдумывается |
| Подтверждено отсутствие результата unbound unknown create | Новый reconcile attempt succeeded, export готов к следующему обычному create; в AC в момент подтверждения ничего не создавать |
| Замена remote_missing | Только отдельный admin POST, отдельная принятая create attempt через механизм 1.2; старые ID/URL сохраняются до успешного создания замены |

Legacy create с уже полной связью сохраняет прежний duplicate 409; remote_task_missing используется для PUT и recovery UI. Первый неподтверждённый внешний результат остаётся 502/504 с operation ID; subsequent unknown — 409. Проверка task existence не устанавливает synced_at или подтверждённый hash по одному GET.

## Change Map

| Файл/компонент | Необходимое изменение |
| --- | --- |
| `database/migrations/2026_10_30_000001_create_admin_audit_logs_table.php` (новый) | UUID audit ID, nullable actor FK bigint с nullOnDelete, subject type/ID, nullable integration/connection context, action, request UUID/phase, безопасный before/after и context, timestamp; unique request UUID/phase |
| `app/Models/AdminAuditLog.php` (новый) | Cast JSON/дат; audit append-only через repository |
| `app/Repositories/AdminAuditLogRepository.php` (новый) | Только добавление разрешённых audit fields; replay lookup по request UUID/subject/actor, без payload и секретов |
| `app/Repositories/ActiveCollab/ActiveCollabExportRepository.php` | Expire sweep, snapshot/actor lookup, begin/finalize recovery, expected-state проверки, locked atomic export/attempt/audit; специальная резервация admin replacement без изменения обычного create |
| `app/Repositories/ActiveCollab/ActiveCollabRepository.php` | GET конкретной задачи; строгая валидация id/project_id/is_trashed/url_path; безопасные failure categories; whitelist response |
| `app/Exceptions/ActiveCollabRequestException.php`, `TaskSyncConflictException.php` | Failure category/безопасный code для remote_missing и recovery conflict без raw upstream text |
| `app/Services/ActiveCollab/ActiveCollabTaskService.php` | Классификация 404, безопасная обёртка всех ошибок после reservation, переиспользуемое выполнение уже резервированной операции для замены |
| `app/Services/ActiveCollab/ActiveCollabDataSanitizer.php` (новый) | Перенести существующий clean без копирования логики; source/task whitelist, очистка известных токенов и коротких audit reasons |
| `app/Services/ActiveCollab/ActiveCollabRecoveryService.php` (новый) | Admin authorization → begin → GET/подтверждение → finalize; отдельный запуск авторизованной замены через TaskService, без собственного POST-клиента |
| `app/Data/ActiveCollab/ActiveCollabRecoveryData.php` (новый) | Whitelisted action, expected operation/state, request UUID, candidate task ID, source attempt ID, reason и подтверждения |
| `app/Data/ActiveCollab/ActiveCollabTaskData.php` | Явный fromStoredPayload для существующих problem_description/screenshot_url/sheet_status; валидация отсутствующих/очищенных обязательных полей до новой отправки |
| `app/Policies/ActiveCollabTaskExportPolicy.php` (новый), `app/Providers/AppServiceProvider.php` | Единая recovery policy с AND-проверкой Orchid permissions; регистрация policy |
| `app/Console/Commands/ExpireActiveCollabSyncOperations.php` (новый) | `integrations:expire-sync-operations {--dry-run}`, безопасный grouped query по истёкшим running, lock/recheck/unknown и отчёт IDs без HTTP |
| `routes/console.php` | Schedule команды каждую минуту; повторные процессы безопасны на DB lock, без зависимости от Redis |
| `app/Orchid/Screens/Export/ExportRecoveryScreen.php` (новый) | Защищённые query и POST actions, локальная история и отдельные подтверждения; только DTO/service |
| `app/Orchid/Layouts/Export/ExportRecoveryLayout.php` (новый) | Состояния, выбранный сохранённый source, предупреждения о current mappings и отдельные формы действий |
| `routes/platform.php`, `app/Orchid/PlatformProvider.php` | Recovery route перед exports, permission; без перестройки меню |
| `app/Orchid/Layouts/Export/ExportListLayout.php` | Колонки last_attempt_status/binding_status и ссылка на recovery только для admin |
| `database/seeders/DatabaseSeeder.php` | Новое право только локальному seeded admin; без смены ролей остальных пользователей |
| `tests/Feature/ActiveCollabTaskApiTest.php`, `ActiveCollabRepositoryTest.php`, `ActiveCollabExportRepositoryTest.php` | 404/unknown/ownership/secret regressions; обычные create/update не обходят recovery |
| `tests/Feature/ActiveCollabRecoveryTest.php`, `ActiveCollabRecoveryScreenTest.php`, `ExpireActiveCollabSyncOperationsTest.php` (новые) | State transitions, policy/CSRF/POST-only, replay и аудит, sweep/idempotence |
| `tests/Feature/ActiveCollabSyncConcurrencyTest.php`, `tests/Fixtures/activecollab_sync_worker.php` | Admin recovery vs sync, два recovery, stale operation и failure audit на отдельной MariaDB |
| `CLAUDE.md`, `docs/API_REFERENCE.md`, `LOGIC.md`, `DATA_MODEL.md`, `TESTING.md`, `DEPLOY.md`, `resources/views/admin/guide.blade.php` | Recovery state machine, права, расписание, безопасный rollout и инструкция администратора |

Новых Connection/role/pivot/token таблиц на этом этапе нет. Поле connection context аудита допускает null до появления Connection; текущая область подтверждается integration ID/project ID и свежими effective настройками.

## Implementation Plan

1. Повторно прочитать утверждённый план, status и staged diff; baseline выполнить в штатном окружении либо том же PHP 8.5 runner, что 1.2. Проверить доступность отдельной MariaDB 11.4.12/pdo_mysql. Не использовать production credentials и не переписывать существующее staging.
2. Сначала добавить падающие регрессии: PUT 404 сохраняет связь и блокирует последующий update; GET другого project/is_trashed не разрешает bind; неадминистратор прямым URL/POST не меняет состояние; неизвестный create не повторяется от проверочного GET или expiry sweep.
3. Создать audit schema/model/repository. Каждый manual request имеет UUID и две append-only фазы requested/result. `UNIQUE(request_id, phase)` предотвращает повтор одного решения. Audit содержит actor ID, export/integration/project/operation IDs, action, очищенный reason и before/after состояний/связи. Ни токены, ни encrypted values, source/body/headers в audit не входят. FK actor nullable сохраняет запись после удаления пользователя; integration/connection FK к историческим данным не добавлять.
4. Вынести clean в один sanitizer. Добавить GET task в единственный HTTP repository, без retry. При ответе проверять exact task ID, project ID выбранной интеграции, boolean is_trashed и безопасный relative url_path. Использовать свежие effective base URL/token только в памяти, не сохранять токены или fingerprints в audit. Не обращаться по пользовательскому произвольному URL: принимать только положительный task ID.
5. Разделить response category и доказанность исхода. Для связанного PUT 404 завершать свою running attempt failed, binding=remote_missing, safe code remote_task_missing; snapshot сохранять. Ordinary reserve для PUT возвращает этот code прежде generic unknown. POST без известной связи с 404 не становится remote_missing: он остаётся unknown. Остальные внешние ошибки классифицировать, сохраняя консервативную неизвестность, если отсутствие эффекта не доказано. Все ошибки подготовки после durable reservation возвращать безопасно с operation ID; исключения SQL/transport с секретами не передавать клиенту.
6. Добавить sweep в export repository: chunk по running с lease<=now или null, lock/recheck в транзакции для каждого экспорта. Обновить только всё ещё истёкшую операцию и соответствующий running attempt в unknown; не менять ID/URL/hash/payload. Если attempt отсутствует/противоречит state, сохранить блокирующий unknown экспорт и report ID, не придумывать попытку. Dry-run не пишет. Повторный запуск не меняет terminal state; поздний ответ по старому operation ID отвергается. Schedule command каждую минуту; документировать необходимость scheduler runner.
7. Зарегистрировать policy и permission. Создать отдельный screen `exports/{export}/recovery` без открытия общего административного доступа. Query, каждый POST action и service проверяют policy; посторонним авторизованным — 404, гостю — штатная авторизация Orchid. CSRF сохраняется. Не полагаться на OR-список Screen.permission. GET экрана читает локальные данные и не меняет состояния/AC; все проверки, создающие attempt/audit, выполняются POST. В view передавать только whitelist скалярных полей/очищенного source, не сериализовать Integration/User модели с секретами или permissions.
8. Формы принимают только action из bind/verify/confirm_absence/replace, request UUID, expected operation ID (nullable для legacy), expected status/binding, candidate ID для bind, выбранный source attempt ID и reason 10–1000 символов. Checkbox подтверждения обязателен для отсутствия/замены. Ограничить action request 16 KiB. Source редактировать в этой форме нельзя. Видимая причина и все значения экранируются. Нельзя подменить integration/project/URL/actor через body; они берутся сервером по export.
9. Begin recovery выполняется одной DB-транзакцией: свежий actor + policy, lock export, проверить expected op/status/binding, целостность integration/project, существование source attempt того же экспорта; живой running отказать. Просроченный running сначала становится unknown. Создать новый operation UUID, running reconcile attempt и requested audit; старый unknown attempt не переписывать. Запомнить immutable snapshot effective назначения и исходной связи. Повтор request UUID того же actor/action/export возвращает сохранённый результат или running conflict без новой попытки/HTTP; reuse UUID для другого решения — conflict.
10. Bind/verify: после commit сделать только GET candidate task в выбранном проекте. Finalize снова проверяет actor/permissions и свежую integration/export; exact operation ID, running и lease обязательны. Effective base URL/token/project должны совпасть с snapshot (сравнить в памяти; изменения не раскрывать). Другой task/project, trash, malformed/timeout — привязка не подтверждается. GET 404 оставляет известную связь remote_missing, а неизвестный unbound результат не объявляет отсутствующим. При успешном GET bound task ID/URL подтверждаются, reconcile attempt succeeded и result audit записываются атомарно; содержимое задачи не объявляется синхронизированным по одному GET.
11. При verify той же связи инвалидировать sync_hash для обязательного следующего PUT, сохранив последний подтверждённый source/payload и прежний synced_at как историю. При bind нового task ID также очистить текущий confirmed payload/response и synced_at: они относятся к прежней цели; before-снимок ID/URL остаётся в audit, существующие sync attempts не удаляются. Legacy status не выставлять created/updated без фактического успешного sync; отобразить успешное решение по новому reconcile status. Подтверждение связи не вызывает PUT автоматически.
12. Confirm absence разрешён для unknown create после остановки старых mutating workers и внешней сверки отсутствия результата. UI требует явного подтверждения выполненной сверки и причины; один GET 404/пустой список не подставляет checkbox. Решение создаёт reconcile attempt и audit, разрешает следующую обычную create для unbound записи, но само ничего в AC не создаёт. Для unknown update с существующей задачей отсутствие новой задачи не является основанием сбросить binding: использовать verify/rebind. Для неизвестной замены сохранять старую remote_missing связь и разрешать только новое отдельное admin replacement после подтверждения отсутствия предыдущей замены. Человеческое решение не является exactly-once гарантией; поздний старый worker не должен продолжать отправку.
13. Replace разрешён отдельным POST только для remote_missing без unresolved create. Требовать current expected state и подтверждение сверки; показать выбранный сохранённый source и предупреждение, что используются текущие маппинги, а не актуальные Sheets. Чтобы инструкция о новых данных из Sheets была выполнима, ordinary PUT известной remote_missing записи без unresolved операции сохраняет валидированный источник в отдельной failed attempt и возвращает тот же 409 без HTTP; confirmed snapshot не меняется. Для unknown замены такой приём не заменяет operation ID и не снимает блокировку. Администратор выбирает новый source attempt на экране. Перед reservation проверить stored payload через fromStoredPayload (problem_description/screenshot_url/sheet_status), обязательные поля и принадлежность source attempt; отсутствующие или заменённые [REDACTED] обязательные значения блокировать с инструкцией отправить валидные данные из Sheets. Зарезервировать create с новым operation ID и requested audit; старые task ID/URL остаются до success. TaskService выполняет уже резервированную операцию тем же prepare/HTTP/complete, специального force-параметра публичного API нет. Success меняет связь на новую, attempt/audit фиксируются вместе; timeout/DB failure даёт unknown и запрещает второй replacement без нового явного разрешения. Mapping failure до dispatch сохраняет remote_missing и допускает только новое подтверждаемое admin действие.
14. Finalize recovery не переписывает исходную unknown попытку в succeeded. Новая reconcile/create attempt и audit должны соответствовать новой операции; атомарный rollback при ошибке audit не оставляет экспорт разблокированным. Если HTTP уже мог создать замену, при потере commit сохранить running/unknown согласно 1.2 и безопасно запретить повтор. При отказе GET исходный unknown не превращать в retryable failed только потому, что GET ничего не менял: неизвестность предыдущего create/update остаётся.
15. Добавить UI-поля last_attempt/binding и защищённую ссылку на recovery в историю. Модальные подтверждения/формы отделить для каждого действия; причина и предупреждения ориентированы на результат, без реализации lease/SQL в пользовательском тексте. Обновить guide и документы. Сделать скриншот Orchid, если доступно локальное browser-окружение; при отсутствии зафиксировать, что визуальная проверка не выполнена.
16. Выполнить проверки ниже, записать реальные результаты и ограничения в `work/REPORT-1.3.md`. Следующий этап — отдельный план 1.4 для UUID; он не входит в разрешение на реализацию этого документа.

## Validation Plan

- Focused PHPUnit: ActiveCollabRepositoryTest, ActiveCollabTaskApiTest, ActiveCollabExportRepositoryTest, ActiveCollabRecoveryTest, ActiveCollabRecoveryScreenTest, ExpireActiveCollabSyncOperationsTest. До кода зафиксировать red по новым сценариям, после — green.
- HTTP fakes + preventStrayRequests: GET проверяет selected context и single.project_id/is_trashed; 404/timeout/5xx/отражённый token/невалидный response не разрешают bind/create; чтение не отправляет POST/PUT. Никаких запросов к живому AC/Sheets.
- Авторизация: guest, обычный Orchid user, только recovery permission, только users/roles, полный admin, role-granted admin, отозванное право перед finalize; прямой POST и tampered body. Проверить серверный 404, отсутствие чужих метаданных, отсутствие audit/attempt при неавторизованном запросе, CSRF с включённой middleware в соответствующем тесте. GET не меняет состояние.
- Идемпотентность: double-click с одинаковым request UUID не создаёт новую attempt/audit phase/HTTP; stale expected state и повтор UUID другого action/actor/export возвращают conflict. Два разных admin requests не завершают одну unknown запись дважды.
- Атомарность: event/DB save failure при audit приводит к rollback export+attempt; timeout/ошибка записи после внешней замены не разрешает второй POST. Старый operation ID и истёкший recovery lease не подтверждают результат.
- Sweep: dry-run, реальные chunks (>100 строк), повторный запуск, null lease, malformed missing-attempt, конкурентное завершение, без HTTP и без выдуманной истории. Стабильные IDs/context в отчёте, без source/body/токенов.
- MariaDB 11.4.12 на временной internal network, PHP 8.5/pdo_mysql: сохранить пять проверок 1.2; добавить recovery vs sync, два recovery, sweep vs late completion. Разные PHP процессы, barriers, committed fixtures; DB ровно sheetcollab_concurrency_test, защитные проверки до migrate:fresh. HTTP transactionLevel()=0; не использовать рабочий volume.
- Миграция audit: чистая установка и upgrade после 1.2, actor bigint FK/nullOnDelete, unique request UUID/phase, сохранность существующих exports/attempts. Никакого seeding в миграции; down только на пустой тестовой истории.
- Итог: make lint/analyse/test/check, либо их соответствующие проверки в отдельном PHP runner и npm build при недоступном Sail с явным отчётом. Не выдавать недоступную проверку за pass. Ub-php/CRAP/DRY только если установлен и исправен; mutation testing и установка quality tools автоматически не добавляются.
- Реализационные тесты, изолированные миграции и screenshot выполнены; команды, результаты и ограничения перечислены в `work/REPORT-1.3.md`. Реальная БД приложения и AC/Sheets не изменялись.

## Risks

- Самое существенное ограничение: после неизвестного внешнего POST отсутствие результата подтверждает человек. Оператор должен остановить старые mutating workers и проверить AC; приложение не может доказать отсутствие побочного эффекта по expiry/404. Без этого confirm_absence/replace небезопасны.
- Официальные примеры AC — v1, конкретная версия сервера может отличаться. Строгий GET validation при несовпадении формы оставляет unknown, а не ослабляется догадками.
- До Connections нет стабильного ID назначения. Сравнение свежих effective настроек и snapshot защищает обычные изменения во время recovery, но не доказывает отсутствие исторической подмены host/project. При сомнительном legacy-контексте блокировать решение для отдельной сверки; не менять назначение интеграции при операциях.
- Существующий UI старых интеграций/пользователей ещё не даёт полной многопользовательской изоляции. Новый recovery закрывается независимо; весь сервис нельзя открывать независимым пользователям до этапов 2/4.
- Stored source может быть неполным или очищенным sanitizer: восстановление связи возможно по GET, но создание замены требует валидных бизнес-данных. Не отправлять [REDACTED] как замену обязательному исходному значению.
- Новый audit хранит actor ID и безопасную причину; удаление actor не удаляет историю. Полный аудит всех настроек/токенов и manager/viewer assignments остаётся этапом 4.
- Не устранять в этом шаге известные npm findings или рефакторить весь Orchid; обновление зависимостей требует отдельного плана по TASK §13.

### Rollout и rollback

Остановить sync и старые workers, снять backup БД/конфигурации. Если 1.2 ещё не развёрнут, сначала выполнить его additive-миграции и штатный backfill по docs/DEPLOY; не считать наличие кода применённой схемой. Для установки с уже развёрнутым 1.2 применить только новую additive audit migration. Существующему администратору выдать recovery permission через штатный Users/Roles без запуска production seeder. Проверить права и recovery на тестовой записи, запустить scheduler согласно docs/DEPLOY, затем возобновить обработку только новой версией.

При проблемах отключить recovery route/permission и остановить запись; audit/history не удалять. После ручной перепривязки/замены возврат старого кода не отменяет внешнюю задачу или решение: нужен forward fix либо отдельное восстановление со сверкой AC. Не выполнять destructive down audit таблицы как рабочий rollback. Сам код/план не разрешает менять реальные Sheets или AC во время автоматических тестов.

## Done Criteria

- [x] Unknown можно разрешить через отдельное административное действие без SQL-обхода и без automatic create.
- [x] GET проверяет конкретный task/project/trash, 404 не считается доказанным отсутствием внешнего результата; remote_missing сохраняет исходную связь и блокирует ordinary update.
- [x] Живой running не захватывается; sweep переводит только истёкшие операции в unknown, повторяем и имеет dry-run.
- [x] Bind/verify/absence/replace имеют expected-state проверки, новый operation ID, idempotent request UUID и атомарный audit.
- [x] Права проверяются сервером на view/actions/service; недостаточные полномочия не дают метаданных и не создают попыток.
- [x] Замена не теряет старую связь до success; unknown replacement не разрешает повторный create. Старые callbacks и повтор формы не перезаписывают решение.
- [x] GET не подтверждает содержимое задачи: hash инвалидируется, synced_at не выдумывается; прежняя история сохраняется.
- [x] Secret-reflection и audit rollback покрыты тестами; нет raw body/token/encrypted secret в новых путях.
- [x] Focused/full checks и реальные MariaDB races пройдены либо конкретные недоступные проверки явно перечислены, без ложного pass.
- [x] Документы, guide, rollout и отчёт актуальны; UUID, Connections, полные роли и зависимости не включены попутно.
