# Результат шага 1.1

Дата: 2026-10-29. Пользователь одобрил реализацию `work/PLAN.md`. Реализована только подробная часть 1.1; дорожная карта остальных этапов не выполнялась.

## Root cause

Create распознавал существующую задачу по `status`, хотя failed update сохраняет task ID/URL. Update искал экспорт по глобальному task ID, затем по external ID и допускал внешний запрос без найденной записи. Это позволяло повторный create и изменение чужой истории.

## Files changed

- `app/Repositories/ActiveCollab/ActiveCollabExportRepository.php`: lookup требует integration/project context; конфликт существующего locator не трактуется как отсутствие записи.
- `app/Services/ActiveCollab/ActiveCollabTaskService.php`: проверка полной привязки до HTTP, отсутствие fallback и ветвей update без экспорта.
- `app/Exceptions/TaskBindingConflictException.php`, `bootstrap/app.php`: безопасный JSON 409 `legacy_binding_conflict` без метаданных несовпавшей записи.
- `tests/Feature/ActiveCollabTaskApiTest.php`, `tests/Feature/ActiveCollabExportRepositoryTest.php`: регрессии, несовпадение контекста, неполные связи, одинаковые task ID, сохранность подтверждённых данных.
- `docs/API_REFERENCE.md`, `docs/LOGIC.md`: актуальное поведение и оставшиеся ограничения.
- `work/PLAN.md`: статус и результаты критериев; этот локальный отчёт. Папка `work/` игнорируется Git.

## Fix summary

Полная связь с положительным task ID и непустым URL блокирует повторный create при любом legacy-статусе; прежний duplicate response сохранён. Неполная связь, orphan integration или другой integration/project возвращают безопасный конфликт. Update допускается только по совпадению integration/project/external ID/task ID с полной привязкой. Ошибка update сохраняет последнюю подтверждённую связь, hash и payload/response. Существующий `markFailed()` уже имел нужное поведение и не изменён.

Тесты API теперь явно задают глобальный URL справочников и запрещают неожиданные HTTP-запросы: прежний тест зависел от отсутствующего локального `.env`. Итоговые проверки PHP выполнялись с `--network none`.

## Validation result

- Рабочее дерево до реализации было чистым; `work/diff.txt` пуст.
- Исходные `make test`, `make lint`, `make analyse` не запустились: отсутствовал `vendor/laravel/sail/bin/sail`.
- Зависимости установлены строго из `composer.lock` командой `composer install --no-interaction --no-progress --prefer-dist` в имеющемся Docker-образе `composer:2` (PHP 8.5.12). 122 установки, 0 обновлений. Манифесты и lock-файлы не менялись.
- До исправления 24 новых API-сценария упали на исходном коде. В частности, ошибка update разрешала новый POST, а одинаковые task ID приводили к изменению чужого экспорта.
- После исправления первоначальный focused-набор прошёл: 58 tests, 208 assertions. Затем добавлены ещё сценарии неполной failed-связи и всех legacy-статусов.
- `docker run --rm --network none --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 composer check`: exit 0; Pint — 96 файлов, PHPStan — без ошибок, тесты без падений. Artisan test сообщил warnings о чтении отсутствующего `/app/.env`; локальный `.env` не создавался и не менялся.
- Полный прямой запуск `php vendor/bin/phpunit --display-warnings` в том же контейнере: exit 0, 124 tests, 369 assertions, 11 PHPUnit notices. Все 11 относятся к существующему `ActiveCollabIntegrationSettingsServiceTest` и mock без expectations; подтверждено отдельным запуском с `--display-phpunit-notices`. Этот файл не менялся.
- `php vendor/bin/pint --test --dirty`: exit 0, 6 PHP-файлов.
- `npm ci --ignore-scripts` и `npm run build`: exit 0, Vite build выполнен. Установлены версии из `package-lock.json`.
- `git diff --check`: без ошибок. Вручную проверены все lookup callers, отсутствие нового HTTP-пути, сохранение legacy envelope и границы scope. Новая общая проверка полноты привязки находится в одном методе; лишние ветви update без экспорта удалены.
- Повторный `make check`: не прошёл из-за `Sail is not running`. Эквивалентные lint/analyse/test/build выполнены отдельно, но проверка в обычном Sail-стеке остаётся неподтверждённой. Пункт плана о полном `make check` оставлен открытым.
- `vendor/bin/ub-php` отсутствует. CRAP, структурная схожесть и mutation score не измерялись; CDMU/Infection не устанавливались. Ручная проверка не заменяет эти измерения.

## Remaining risks or unverified paths

- Открытая проверка: `make check` в штатном Sail-окружении. Рабочие ActiveCollab/Sheets не использовались в итоговых тестах.
- Конкурентные create, unknown после таймаута, UUID и индивидуальная авторизация остаются для следующих согласуемых частей. Повтор pending/failed без связи в правильном контексте пока сохраняет прежний путь.
- Изменение назначения существующей интеграции и безопасная обработка raw upstream errors остаются за пределами 1.1.
- `npm ci` сообщил 6 уязвимостей: 5 high и 1 critical. Пакеты и достижимость не исследовались в этом шаге. Это фактический повод для раннего отдельного аудита/плана обновления по TASK §13; автоматический audit fix не запускался.
- Миграций, backfill и изменений зависимостей нет. Откат кода возвращает небезопасное поведение и требует остановки синхронизации; данные не удалять.
