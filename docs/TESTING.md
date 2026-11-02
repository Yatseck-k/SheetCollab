# Тестирование

## Обзор

Проект использует PHPUnit 13.4 с двумя suite:

| Suite | Директория | Назначение |
|-------|-----------|-----------|
| Unit | `tests/Unit/` | Тестирование изолированных классов без БД и HTTP |
| Feature | `tests/Feature/` | Тестирование API endpoints, репозиториев, интеграций с БД |

**Текущее покрытие:** 92 теста, 236 assertions.

---

## Конфигурация тестовой среды

Файл: `phpunit.xml`

Ключевые настройки для тестов:

| Переменная | Значение | Смысл |
|-----------|---------|-------|
| `DB_CONNECTION` | `sqlite` | In-memory SQLite вместо MariaDB |
| `DB_DATABASE` | `:memory:` | База пересоздаётся каждый раз |
| `CACHE_STORE` | `array` | Кеш в памяти |
| `SESSION_DRIVER` | `array` | Сессии в памяти |
| `QUEUE_CONNECTION` | `sync` | Очередь выполняется синхронно |
| `LOG_CHANNEL` | `null` | Логи подавлены |
| `BCRYPT_ROUNDS` | `4` | Быстрое хеширование паролей |

Feature-тесты с БД используют трейт `RefreshDatabase`. Laravel загружает `database/schema/sqlite-schema.sql` с полной базовой migration history; новые migration files, добавленные после baseline, выполняются поверх snapshot.

MariaDB использует отдельный baseline `database/schema/mariadb-schema.sql`. Оба snapshots созданы после replay исходной истории и содержат migration records. При добавлении следующей migration не редактируйте snapshots вручную: добавьте обычный migration-файл, примените его на обоих поддерживаемых драйверах и обновите snapshots командой `php artisan schema:dump --database=sqlite` и `php artisan schema:dump --database=mariadb`. `--prune` использовать только для намеренного нового squash после проверки всех целевых баз и обоих snapshots. В Sail для MariaDB schema load/dump требуется установленный `mariadb-client`.

---

## Запуск тестов

### Через make (рекомендуется)

```bash
make test                    # запустить все тесты
make check                   # lint + analyse + test + frontend build
```

### Через composer

```bash
composer test                # config:clear + php artisan test
```

### Напрямую через artisan

```bash
php artisan test
php artisan test --filter test_it_creates_task     # один тест по имени
php artisan test --group feature                   # только Feature suite
php artisan test tests/Unit/ActiveCollabMappingServiceTest.php  # один файл
```

### Через Docker Compose

```bash
docker compose exec laravel.test php artisan test
docker compose exec laravel.test php artisan test --filter ActiveCollabTaskApiTest
```

### С указанием suite

```bash
php artisan test --testsuite Unit
php artisan test --testsuite Feature
```

---

## Тест-файлы — подробное описание

### Feature: ActiveCollabTaskApiTest (35 тестов)

`tests/Feature/ActiveCollabTaskApiTest.php`

Тестирует API endpoints через HTTP (использует `withToken()`, `postJson()`, `putJson()`). Использует `RefreshDatabase` и `Http::fake()`.

**setUp():**
- Задаёт `services.google_sheets.integration_token = 'sheet-token'`
- Задаёт `services.active_collab.api_token = 'global-ac-token'`
- Задаёт `services.active_collab.project_id = 10`

**Что покрывает:**

| Тест | Сценарий |
|------|---------|
| `test_it_rejects_requests_without_valid_bearer_token` | 401 при отсутствии токена |
| `test_it_returns_not_found_when_integration_does_not_exist` | 404 когда нет интеграции |
| `test_it_creates_task_and_persists_export_history` | Happy path: создание задачи, запись в БД, маппинги, AC payload |
| `test_it_returns_conflict_for_already_created_row` | 409 при повторном create для той же строки |
| `test_it_updates_existing_task_and_marks_export_as_updated` | PUT обновляет задачу, статус становится `updated` |
| `test_it_returns_error_when_integration_token_is_not_configured` | 500 если `GOOGLE_SHEETS_INTEGRATION_TOKEN` не задан |
| `test_it_marks_export_as_failed_when_task_type_mapping_is_missing` | create: маппинг не найден → статус `failed` |
| `test_it_marks_existing_export_as_failed_when_update_mapping_breaks` | update: маппинг не найден → статус `failed` |
| `test_it_returns_task_lists_from_activecollab` | GET /task-lists проксирует ответ AC |
| `test_it_returns_422_when_required_fields_are_missing` | 422 при отсутствии обязательных полей |
| `test_it_returns_422_when_screenshot_is_not_a_valid_url` | 422 при невалидном URL скриншота |
| `test_it_returns_422_when_row_number_is_zero` | 422 при row_number = 0 |
| `test_it_returns_404_when_integration_is_inactive` | 404 для неактивной интеграции |
| `test_it_creates_task_with_minimal_payload_and_no_optional_mappings` | Создание без необязательных полей и маппингов |
| `test_it_blocks_legacy_pending_without_repeating_create` | Старый pending классифицируется unknown, HTTP запрещён |
| `test_it_marks_export_failed_when_activecollab_api_returns_server_error` | Ошибка AC после dispatch → legacy failed и новое unknown |
| `test_it_marks_update_export_failed_when_activecollab_api_returns_server_error` | Ошибка AC при update → unknown с сохранением подтверждённой связи |
| `test_get_users_returns_activecollab_users` | GET /users проксирует ответ AC |
| `test_get_labels_returns_activecollab_labels` | GET /labels проксирует ответ AC |
| ...и другие вариации вышеперечисленных сценариев | |

---

### Feature: ActiveCollabRepositoryTest (9 тестов)

`tests/Feature/ActiveCollabRepositoryTest.php`

Тестирует HTTP-клиент к AC API напрямую через `ActiveCollabRepository`. Использует `Http::fake()`, **не** использует `RefreshDatabase`.

**setUp():**
- Задаёт глобальный config (base_url, api_token, project_id)
- Создаёт `new ActiveCollabRepository()`

| Тест | Сценарий |
|------|---------|
| `test_create_task_posts_to_correct_url_with_auth_header` | POST на правильный URL с токеном из интеграции |
| `test_update_task_puts_to_correct_task_url_with_auth_header` | PUT с интеграционным токеном |
| `test_get_task_lists_uses_global_config_credentials` | Глобальный токен для helper-endpoint |
| `test_get_task_lists_for_connection_uses_provided_base_url_and_token` | Явные URL и токен |
| `test_create_task_list_for_connection_posts_name` | POST создания task list |
| `test_get_users_hits_users_endpoint_with_global_token` | GET /users с глобальным токеном |
| `test_get_labels_hits_labels_endpoint_with_global_token` | GET /labels |
| `test_send_throws_on_4xx_response` | 404 от AC → безопасный ActiveCollabRequestException |
| `test_send_throws_on_5xx_response` | 500 от AC → безопасный ActiveCollabRequestException |

---

### Feature: ActiveCollabExportRepositoryTest

Проверяет reservation и competing request, атомарное завершение export+attempt, expiry до dispatch и после HTTP, защиту от старого operation ID, rollback при ошибке записи attempt, scoped lookup и lazy-классификацию legacy pending. Безусловные createPending/markCreated/markUpdated/markFailed удалены: все новые записи проходят operation ownership.

### Feature: ActiveCollabIntegrationRepositoryTest (4 теста)

`tests/Feature/ActiveCollabIntegrationRepositoryTest.php`

Тестирует поиск интеграции. Использует `RefreshDatabase`.

| Тест | Сценарий |
|------|---------|
| `test_find_by_spreadsheet_id_returns_active_integration` | Находит активную интеграцию |
| `test_find_by_spreadsheet_id_returns_null_for_inactive_integration` | null для неактивной |
| `test_find_by_spreadsheet_id_returns_null_when_no_integration_exists` | null при отсутствии |
| `test_find_by_spreadsheet_id_ignores_other_spreadsheet_ids` | Не находит чужой spreadsheet_id |

---

### Unit: ActiveCollabIntegrationModelTest (7 тестов)

`tests/Unit/ActiveCollabIntegrationModelTest.php`

Тестирует методы модели `ActiveCollabIntegration`. Без БД — использует `new ActiveCollabIntegration([...])`.

| Тест | Сценарий |
|------|---------|
| `test_resolved_base_url_returns_trimmed_model_value` | Значение из модели |
| `test_resolved_base_url_strips_trailing_slash` | Убирает trailing slash |
| `test_resolved_base_url_falls_back_to_config_when_not_set` | Fallback на config |
| `test_resolved_instance_url_strips_api_v1_suffix` | Убирает /api/v1 |
| `test_resolved_instance_url_strips_any_api_version_suffix` | Убирает /api/v2 и т.д. |
| `test_resolved_api_token_returns_trimmed_model_token` | Токен из модели (зашифрован через cast) |
| `test_resolved_api_token_falls_back_to_config_when_model_token_is_null` | Fallback на config |

---

### Unit: ActiveCollabMappingServiceTest (4 теста)

`tests/Unit/ActiveCollabMappingServiceTest.php`

Тестирует три метода `ActiveCollabMappingService`. Без БД — создаёт `ActiveCollabIntegration` через `new`.

| Тест | Сценарий |
|------|---------|
| `test_it_returns_default_task_list_when_mapping_is_not_needed` | task_type пустой → дефолтный task_list_id |
| `test_it_uses_task_type_mapping_and_throws_for_unknown_type` | Маппинг найден / не найден → RuntimeException |
| `test_it_resolves_assignee_and_throws_when_mapping_is_missing` | assignee_mapping: найден / не найден |
| `test_it_resolves_label_case_insensitively` | label lowercase lookup + null если не найден |

---

### Unit: ActiveCollabIntegrationSettingsServiceTest (16 тестов)

`tests/Unit/ActiveCollabIntegrationSettingsServiceTest.php`

Тестирует нормализацию настроек. Использует `Http::fake()` для вызовов к AC API (getTaskListsForConnection).

| Тест | Сценарий |
|------|---------|
| `test_it_normalizes_spreadsheet_and_activecollab_settings` | Полный happy path нормализации |
| `test_it_keeps_explicit_task_list_id_without_api_lookup` | Числовой task_list_id → без API-вызова |
| `test_it_resolves_task_list_by_name_from_existing_project_lists` | Имя task list → находит в AC |
| `test_it_creates_task_list_when_name_is_missing_in_project` | Имя не найдено → создаёт через AC API |
| `test_normalize_base_url_keeps_existing_api_v1_url` | URL с /api/v1 → без изменений |
| `test_normalize_base_url_strips_trailing_slash_and_adds_api_v1` | Без /api/v1 → добавляет |
| `test_normalize_base_url_strips_projects_path_before_adding_api` | URL с /projects/... → очищает |
| `test_normalize_project_id_extracts_id_from_project_url` | URL с /projects/{id} → число |
| `test_normalize_project_id_parses_numeric_string` | Строка "10" → 10 |
| `test_normalize_project_id_throws_on_non_numeric_invalid_value` | Невалидное значение → InvalidArgumentException |
| `test_resolve_api_token_throws_when_both_value_and_config_are_empty` | Нет токена нигде → исключение |
| `test_resolve_api_token_returns_provided_value_when_set` | Явный токен → возвращается |
| `test_normalize_spreadsheet_id_extracts_id_from_full_url` | Полный URL → только ID |
| `test_normalize_spreadsheet_id_returns_plain_id_unchanged` | Чистый ID → без изменений |
| `test_normalize_spreadsheet_id_throws_when_empty` | Пустая строка → InvalidArgumentException |
| `test_resolve_task_list_id_extracts_id_from_task_list_url` | URL с /task-lists/{id} → ID |
| `test_resolve_task_list_id_throws_when_value_is_empty_and_project_has_no_lists` | Пустое поле + нет lists → исключение |

---

### Unit: ActiveCollabTaskBodyBuilderTest (13 тестов)

`tests/Unit/ActiveCollabTaskBodyBuilderTest.php`

Тестирует формирование имени и тела задачи. Без БД.

**Вспомогательный метод:**
```php
private function makeData(array $overrides = []): ActiveCollabTaskData
```

| Тест | Сценарий |
|------|---------|
| `test_build_name_includes_environment_severity_section_description` | Полное имя со всеми частями |
| `test_build_name_omits_severity_when_null` | severity=null → пропускается |
| `test_build_name_omits_environment_when_empty_string` | environment='' → пропускается |
| `test_build_name_truncates_description_at_100_chars` | Обрезка description до 100 символов |
| `test_build_name_without_environment_and_severity_uses_section_and_description` | Минимальное имя |
| `test_build_body_contains_problem_description_and_parameters_sections` | HTML-структура тела |
| `test_build_body_omits_screenshot_section_when_null` | Нет скриншота → нет секции |
| `test_build_body_includes_screenshot_section_when_provided` | Скриншот → секция присутствует |
| `test_build_body_omits_comment_section_when_null` | Нет комментария → нет секции |
| `test_build_body_includes_comment_section_when_provided` | Комментарий → секция присутствует |
| `test_build_body_html_escapes_description_to_prevent_xss` | XSS-защита: htmlspecialchars |
| `test_build_body_html_escapes_screenshot_url` | Экранирование URL скриншота |
| `test_build_body_html_escapes_comment` | Экранирование комментария |
| `test_build_body_applies_nl2br_to_multiline_description` | Многострочное описание → `<br>` |
| `test_build_body_shows_dash_for_null_optional_fields` | null поля → '—' в списке |
| `test_build_body_includes_row_number_in_source_footer` | Номер строки в футере |

---

### Unit: ActiveCollabTaskDataTest (6 тестов)

`tests/Unit/ActiveCollabTaskDataTest.php`

Тестирует DTO `ActiveCollabTaskData`. Без БД.

| Тест | Сценарий |
|------|---------|
| `test_from_request_builds_external_id_as_spreadsheet_sheet_row` | external_id = "sid:shid:row" |
| `test_from_request_casts_row_number_string_to_int` | row_number приводится к int |
| `test_from_request_maps_description_key_to_problem_description` | `description` → `problemDescription` |
| `test_from_request_maps_screenshot_and_status_keys` | `screenshot` → `screenshotUrl`, `status` → `sheetStatus` |
| `test_from_request_optional_fields_are_null_when_absent` | Все nullable поля → null если не переданы |
| `test_to_array_contains_all_expected_keys_with_correct_values` | Сериализация обратно в массив |

---

## Подходы к тестированию

### Как мокируются HTTP-запросы в AC

Используется `Illuminate\Support\Facades\Http::fake()`:

```php
Http::fake([
    'https://ac.example.com/api/v1/projects/10/tasks' => Http::response([
        'single' => ['id' => 123, 'url_path' => '/projects/10/tasks/123'],
    ], 200),
]);

// После запроса проверяем, что он был отправлен
Http::assertSent(fn ($request): bool =>
    $request->method() === 'POST'
    && $request->hasHeader('X-Angie-AuthApiToken', ['integration-token'])
);
```

### Как создаются тестовые интеграции

В `ActiveCollabTaskApiTest` используется helper `createIntegration()`:

```php
private function createIntegration(array $overrides = []): ActiveCollabIntegration
{
    return ActiveCollabIntegration::create(array_merge([
        'name' => 'Test Integration',
        'spreadsheet_id' => 'sheet-123',
        'active_collab_base_url' => 'https://ac.example.com/api/v1',
        'active_collab_project_id' => 10,
        'active_collab_task_list_id' => 101,
        'task_list_mapping' => ['backend' => 101, 'frontend' => 102],
        'assignee_mapping' => ['Tony Yats' => 3],
        'label_mapping' => ['critical' => 7, 'major' => 8],
        'active' => true,
    ], $overrides));
}
```

### Как создаются тестовые DTO без FormRequest

В Unit-тестах `ActiveCollabTaskData` создаётся напрямую через `fromRequest()`:

```php
private function makeData(array $overrides = []): ActiveCollabTaskData
{
    return ActiveCollabTaskData::fromRequest(array_merge([
        'spreadsheet_id' => 'sheet-xyz',
        'sheet_id' => '0',
        'row_number' => 5,
        'environment' => 'stage',
        'section' => 'Каталог',
        'description' => 'Описание проблемы',
    ], $overrides));
}
```

---

## Что не покрыто тестами

| Аспект | Почему |
|--------|--------|
| Orchid Screens (UI) | PHPUnit не тестирует браузерный UI; нет Browser-тестов (Dusk) |
| GoogleAppsScriptService | Тривиальная логика чтения файла + CRUD; проверяется косвенно |
| Реальный e2e через tunnel | Требует публичный URL и живой AC; не для unit/feature |
| Вызовы к живому ActiveCollab API | Вместо них `Http::fake()` |
| Orchid Filters (RoleFilter) | Логика тривиальна и специфична для Orchid |
| Seeder / Factory | UserFactory — стандартный Laravel, не тестируется |

---

## Добавление новых тестов

### Соглашения

1. **Имена методов**: `test_it_{что делает}` или `test_{метод}_{сценарий}` — snake_case.
2. **Один тест — одно поведение**: не проверять несколько независимых вещей в одном методе.
3. **Feature тесты** для всего, что требует HTTP, БД или Laravel IoC.
4. **Unit тесты** для чистой логики (DTO, маппинги, строители, нормализаторы) — без БД, без HTTP.
5. **`Http::fake()`** для изоляции от AC API — всегда.
6. **Хелперы** `makeData()`, `createIntegration()`, `makeIntegration()` в приватных методах — не дублировать.

### Шаблон Feature-теста

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MyFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:J63P4m4KjJ8l0RkGxJZ4Kf9P9+K6f2R4R6V4a2M1LxQ=');
        config()->set('services.google_sheets.integration_token', 'test-token');
        // ... другие config
    }

    public function test_it_does_something(): void
    {
        Http::fake(['*' => Http::response([...], 200)]);

        $response = $this->withToken('test-token')
            ->postJson('/api/integrations/activecollab/tasks', [...]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('active_collab_task_exports', [...]);
    }
}
```


## Реальная конкуренция на MariaDB

`ActiveCollabSyncConcurrencyTest` пропускается при обычном SQLite-запуске. Он запускается отдельно с `phpunit.mariadb.xml`, требует PHP 8.5 с pdo_mysql и отдельную MariaDB 12.3.3 LTS. Перед каждым сценарием выполняется migrate:fresh **только** в БД с точным именем `sheetcollab_concurrency_test`; harness проверяет testing environment и mariadb driver до очистки. Не указывать production credentials/host и не использовать рабочий volume. Внешние DB-параметры задаются runner-окружением; DB_URL принудительно пуст.

```bash
DB_HOST=isolated-mariadb DB_PORT=3306 DB_DATABASE=sheetcollab_concurrency_test \
DB_USERNAME=sheetcollab_test DB_PASSWORD=test-only-password \
php vendor/bin/phpunit -c phpunit.mariadb.xml
```

Рекомендуется выделенная internal Docker network, DB с tmpfs и отдельный PHP runner. Продуктовый compose и `.env` менять не нужно. Два независимых процесса приложения используют разные соединения с общей БД, файловый barrier и общий locked counter fake HTTP. Worker проверяет, что DB transactionLevel() на HTTP равен 0. Проверяются первый insert, существующий idle export, bound update, одновременный старт и независимые строки. Только один запрос одной записи отправляет HTTP; конкурент завершается 409 до освобождения победителя. Рабочий AC не вызывается.

Новые feature-тесты моделируют timeout, malformed success, commit failure после внешнего успеха, недоступность сохранения unknown, поздний ответ, отражённый токен и безопасный повтор после mapping failure. Backfill tests покрывают dry-run, повторяемость, chunks и сохранение legacy records под squashed schema; SQLite не заменяет проверку MariaDB locks.


## Проверки восстановления (шаг 1.3)

- `ActiveCollabRecoveryTest`: bind/verify/explicit absence/replacement, безопасный GET, source whitelist, redaction, frozen destination при смене fallback token, отзыв прав перед finalize, stale/replayed forms, audit rollback, сохранение старой unknown попытки и nullable actor FK.
- `ActiveCollabRecoveryScreenTest`: реальный Orchid маршрут и render, AND-права вместо OR, guest/role permissions, прямые POST, GET action=405, CSRF, лимит 16 KiB, обязательное подтверждение, отсутствие секретов/неэкранированного HTML. UI validation возвращает штатный form redirect с errors, API errors сохраняют JSON.
- `ExpireActiveCollabSyncOperationsTest`: dry-run, chunks >100, повторяемость, null lease и missing attempt без выдуманной истории/HTTP.
- MariaDB suite расширен: recovery vs обычный sync, два recovery, sweep vs late result, ошибка записи audit в дочернем процессе. Сохраняются все пять гонок 1.2; настройки изолированной БД и защитные проверки прежние.

Fake HTTP при многошаговом сценарии задаётся одной последовательностью: повторный Http::fake может сохранять предыдущие stub callbacks. Проверки должны действительно получать предусмотренные 500 → 404 → success, а не проходить на первом старом stub.

UI screenshot при доступном локальном Chromium выполняется на отдельной SQLite preview DB с тестовым администратором, без подключения к рабочему AC/Sheets. Это проверка render/читаемости, а не live integration e2e.

## UUID и Apps Script (шаг 1.4)

```bash
php vendor/bin/phpunit --filter 'ActiveCollabTaskDataTest|ActiveCollabRecordUuidManifestTest|ActiveCollabTaskApiTest|ActiveCollabExportRepositoryTest|ActiveCollabRecoveryTest|MigrateActiveCollabRecordUuidsTest'
node --test tests/AppsScript/activecollab_sync.test.cjs
```

JS harness использует встроенные Node test/vm без новых npm-пакетов и исполняет реальный .gs с fake SpreadsheetApp/LockService/UrlFetchApp. Проверяет сохранение UUID до fetch, ожидание lock с перечитыванием, duplicate UUID между листами, новую копию, вставку/перемещение во время HTTP, удаление/дубликат target, network/validation/409, безопасный URL и манифест без внешнего HTTP. Это не live Google e2e.

PHP tests покрывают UUID/legacy границы и scoped update, повторное использование locator, исторический source recovery, dry-run/full coverage/idempotency/audit rollback и ограничения манифеста. После squash schema snapshot является baseline; индивидуальные `down()` старых migrations больше не запускаются. MariaDB suite дополнительно проверяет два процесса одного UUID на разных позициях, разные UUID на одной позиции и попытку переноса во время sync/recovery. Первый legacy insert проверяется уже после снятия global unique. SQLite не доказывает блокировки MariaDB.

### Ручная проверка в отдельной Google-таблице

1. Создать отдельную тестовую интеграцию и таблицу, установить проверенную DB-версию UUID-скрипта, выполнить описанный в DEPLOY переход, не использовать production данные/AC.
2. Синхронизировать строку; сохранить UUID/task ID. Отсортировать **полные строки вместе со скрытой колонкой**, вставить строку сверху, перенести полную запись на другой корректно размеченный лист. Update сохраняет UUID/task ID.
3. При задержанном тестовом ответе переместить полную строку: ссылка/error/date должны попасть в строку с UUID. Удаление или дубликат UUID во время запроса дают предупреждение без записи в прежнюю позицию.
4. Скопировать полную строку: отправка блокируется. На копии явно выбрать новую запись; UUID меняется только у копии, link/error/date очищаются. У оригинала всё прежнее.
5. Из двух Google-сессий запустить действие для строки без UUID: сохранён один UUID, нет автоматической перегенерации. Проверить timeout lock и повторное чтение.
6. Проверить ошибки validation, unresolved outcome и remote_missing: нет автоматического create/retry. Не сортировать только бизнес-колонки: скрытая колонка не может защитить такой сценарий. LockService не блокирует ручную правку между последней проверкой и записью.

Отмечать реально выполненные шаги/Google runtime отдельно. Без доступа оставить «не выполнено», не заменять ручной результат Node fake или историческим screenshot.
