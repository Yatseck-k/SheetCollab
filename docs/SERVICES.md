# Внутренняя логика кода

Подробное описание всех классов проекта: назначение, конструктор, методы, поведение.

---

## HTTP Layer

### VerifyIntegrationToken

`app/Http/Middleware/VerifyIntegrationToken.php`

Middleware, защищающий все API-маршруты.

**Алгоритм:**
1. Читает `config('services.google_sheets.integration_token')` (`GOOGLE_SHEETS_INTEGRATION_TOKEN` из `.env`).
2. Если токен не задан → `500 Integration token not configured.`
3. Читает Bearer-токен из заголовка `Authorization`.
4. Сравнивает через `hash_equals()` (защита от timing attacks).
5. Несовпадение → `401 Unauthorized.`

---

### StoreActiveCollabTaskRequest / UpdateActiveCollabTaskRequest

`app/Http/Requests/`

Оба класса идентичны по набору правил. Отличие — семантика (POST vs PUT).

**Правила валидации:**

| Поле | Правила |
|------|---------|
| `spreadsheet_id` | required, string |
| `sheet_id` | required, string |
| `row_number` | required, integer, min:1 |
| `environment` | required, string |
| `section` | required, string |
| `description` | required, string |
| `severity` | nullable, string |
| `priority` | nullable, string |
| `device` | nullable, string |
| `resolution` | nullable, string |
| `screenshot` | nullable, url |
| `comment` | nullable, string |
| `task_type` | nullable, string |
| `responsible` | nullable, string |
| `reporter` | nullable, string |
| `status` | nullable, string |

**`prepareForValidation()`**: Если `spreadsheet_id` — полный URL вида `https://docs.google.com/spreadsheets/d/{ID}/edit`, извлекает `{ID}` до валидации. Это позволяет Apps Script передавать полный URL таблицы без дополнительной обработки.

---

### ActiveCollabTaskController

`app/Http/Controllers/Api/ActiveCollabTaskController.php`

Тонкий контроллер. Только `validate → DTO → service`.

**Конструктор:**
```php
public function __construct(
    private readonly ActiveCollabTaskService $taskService,
    private readonly ActiveCollabRepository $acRepository,
)
```

**Методы:**

```php
// POST /api/integrations/activecollab/tasks
// Returns: HTTP 201 { "data": { task_id, task_url, synced_at, sync_hash } }
public function store(StoreActiveCollabTaskRequest $request): JsonResponse

// PUT /api/integrations/activecollab/tasks/{taskId}
// Returns: HTTP 200 { "data": { task_id, task_url, synced_at, sync_hash } }
public function update(UpdateActiveCollabTaskRequest $request, int $taskId): JsonResponse

// GET /api/integrations/activecollab/task-lists
// Returns: HTTP 200 (raw AC API response)
public function taskLists(): JsonResponse

// GET /api/integrations/activecollab/users
// Returns: HTTP 200 (raw AC API response)
public function users(): JsonResponse

// GET /api/integrations/activecollab/labels
// Returns: HTTP 200 (raw AC API response)
public function labels(): JsonResponse
```

---

## Data Transfer Objects

### ActiveCollabTaskData

`app/Data/ActiveCollab/ActiveCollabTaskData.php`

Readonly DTO. Представляет одну строку Google Sheets как типизированный объект.

**Конструктор (readonly properties):**

| Свойство | Тип | Источник |
|----------|-----|---------|
| `externalId` | string | Вычисляется: `"{spreadsheetId}:{sheetId}:{rowNumber}"` |
| `spreadsheetId` | string | `spreadsheet_id` из запроса |
| `sheetId` | string | `sheet_id` |
| `rowNumber` | int | `row_number` |
| `severity` | ?string | `severity` |
| `priority` | ?string | `priority` |
| `device` | ?string | `device` |
| `resolution` | ?string | `resolution` |
| `environment` | string | `environment` |
| `section` | string | `section` |
| `problemDescription` | string | `description` |
| `screenshotUrl` | ?string | `screenshot` |
| `comment` | ?string | `comment` |
| `taskType` | ?string | `task_type` |
| `responsible` | ?string | `responsible` |
| `reporter` | ?string | `reporter` |
| `sheetStatus` | ?string | `status` |

**Статические фабрики:**

```php
// Создаёт DTO из validated() массива FormRequest
// Строит externalId автоматически
public static function fromRequest(array $data): self
```

**Методы:**

```php
// Сериализует для хранения в source_payload
public function toArray(): array
```

---

## Services

### ActiveCollabTaskService

`app/Services/ActiveCollab/ActiveCollabTaskService.php`

Главный оркестратор. Управляет полным циклом создания и обновления задач.

**Конструктор:**
```php
public function __construct(
    private readonly ActiveCollabRepository $acRepository,
    private readonly ActiveCollabExportRepository $exportRepository,
    private readonly ActiveCollabIntegrationRepository $integrationRepository,
    private readonly ActiveCollabTaskBodyBuilder $bodyBuilder,
    private readonly ActiveCollabMappingService $mappingService,
)
```

**Методы:**

```php
// Полный цикл создания задачи:
// 1. resolveIntegration → 404 если не найдена
// 2. findByExternalId → 409 если задача уже создана
// 3. createPending (новая export-запись)
// 4. buildPayload (маппинги, name, body)
// 5. acRepository.createTask → HTTP в AC
// 6. markCreated (статус + task_id + task_url + sync_hash)
// Returns: { task_id, task_url, synced_at, sync_hash }
public function create(ActiveCollabTaskData $data): array

// Полный цикл обновления задачи:
// 1. resolveIntegration → 404 если не найдена
// 2. findByTaskId || findByExternalId (export-запись, может быть null)
// 3. buildPayload
// 4. acRepository.updateTask → HTTP в AC
// 5. markUpdated если export-запись найдена
// Returns: { task_id, task_url, synced_at, sync_hash }
public function update(int $taskId, ActiveCollabTaskData $data): array
```

**Вычисление sync_hash:**

```php
private function calcSyncHash(ActiveCollabTaskData $data): string
{
    return md5(implode('|', [
        $data->severity, $data->priority, $data->device, $data->resolution,
        $data->environment, $data->section, $data->problemDescription,
        $data->screenshotUrl, $data->comment, $data->taskType,
        $data->responsible, $data->reporter, $data->sheetStatus,
    ]));
}
```

**Логирование:**
- `Log::info` при начале create/update (external_id, integration_id, task_name).
- `Log::warning` при ошибке маппинга (не бросает в лог секреты).
- `Log::error` при ошибке HTTP в AC (external_id, integration_id, message — без токена).
- `Log::warning` при ненайденной интеграции (spreadsheet_id).

---

### ActiveCollabMappingService

`app/Services/ActiveCollab/ActiveCollabMappingService.php`

Резолвит три ID из JSON-маппингов интеграции.

**Методы:**

```php
// Возвращает task_list_id для задачи.
// Если taskType пустой ИЛИ mapping пустой → integration.active_collab_task_list_id
// Иначе ищет strtolower(trim(taskType)) в task_list_mapping
// Не найдено → RuntimeException "Для типа задачи X не настроен ActiveCollab Task List."
public function resolveTaskListId(?string $taskType, ActiveCollabIntegration $integration): int

// Возвращает assignee_id или null.
// Если responsible пустой → null (поле не добавляется в payload)
// Иначе ищет EXACT MATCH в assignee_mapping (регистрозависимо)
// Не найдено → RuntimeException "Ответственный X не найден в маппинге."
public function resolveAssigneeId(?string $responsible, ActiveCollabIntegration $integration): ?int

// Возвращает label_id или null.
// Если severity пустой → null
// Ищет strtolower(trim(severity)) в label_mapping
// Не найдено → null (НЕ ошибка — label просто не добавляется)
public function resolveLabelId(?string $severity, ActiveCollabIntegration $integration): ?int
```

**Важное различие:**

| Маппинг | Ключ | Не найдено |
|---------|------|-----------|
| task_list_mapping | lowercase | RuntimeException |
| assignee_mapping | exact match (case-sensitive) | RuntimeException |
| label_mapping | lowercase | null, не ошибка |

---

### ActiveCollabTaskBodyBuilder

`app/Services/ActiveCollab/ActiveCollabTaskBodyBuilder.php`

Строит имя и HTML-тело задачи ActiveCollab.

**Методы:**

```php
// Строит имя задачи по шаблону:
// "[{environment}] [{severity}] {section}: {description max 100 chars}"
// Части без данных (severity=null) пропускаются.
// environment и section проверяются на '' и '0'
public function buildName(ActiveCollabTaskData $data): string

// Строит HTML-тело задачи.
// Секции: Описание проблемы, Параметры (ul), [Скриншоты], [Комментарий], hr, Источник
// Секции Скриншоты и Комментарий полностью пропускаются, если данных нет.
// Все пользовательские данные проходят через htmlspecialchars(ENT_QUOTES).
// Многострочные поля — nl2br.
// Null-значения в списке параметров → '—'
public function buildBody(ActiveCollabTaskData $data): string
```

---

### ActiveCollabIntegrationSettingsService

`app/Services/ActiveCollab/ActiveCollabIntegrationSettingsService.php`

Нормализует и валидирует настройки интеграции перед сохранением в БД. Вызывается в Orchid screen при сохранении формы.

**Конструктор:**
```php
public function __construct(
    private readonly ActiveCollabRepository $repository,
)
```

**Основной метод:**

```php
// Принимает данные формы (и опционально существующую запись)
// Возвращает массив, готовый для ActiveCollabIntegration::update()
public function prepareForStorage(array $data, ?ActiveCollabIntegration $existing = null): array
```

**Нормализация отдельных полей:**

```php
// Извлекает ID из полного URL или возвращает как есть
// "https://docs.google.com/spreadsheets/d/{ID}/edit" → "{ID}"
public function normalizeSpreadsheetId(string $value): string

// Нормализует URL: добавляет /api/v1, убирает лишние суффиксы
// "https://ac.example.com" → "https://ac.example.com/api/v1"
// "https://ac.example.com/api/v1/projects/10/tasks" → "https://ac.example.com/api/v1"
public function normalizeBaseUrl(?string $value): string

// Нормализует project_id: число или URL с /projects/{id}
// "https://ac.example.com/projects/10" → 10
public function normalizeProjectId(string|int|null $value): int

// Резолвит API token: из данных формы → из config → InvalidArgumentException
public function resolveApiToken(?string $value): string

// Резолвит task list ID. Алгоритм:
// 1. Если число → возвращает как есть
// 2. Если URL с /task-lists/{id} → извлекает id
// 3. Если строка с цифрами → int
// 4. Запрашивает AC API (getTaskListsForConnection)
// 5. Если указано имя → ищет по имени (mb_strtolower)
//    → не найдено → createTaskListForConnection
// 6. Если имя не указано → берёт первый task list
public function resolveTaskListId(string|int|null $value, string $baseUrl, string $token, int $projectId): int
```

---

### GoogleAppsScriptService

`app/Services/GoogleAppsScriptService.php`

Отдаёт канонический файл `resources/apps-script/activecollab_sync.gs` из текущего deployment.

**Методы:**

```php
public function path(): string
public function read(): string
public function download(): StreamedResponse
```

Сервис не читает и не записывает `script_assets`, не создаёт DB bootstrap-записи и не имеет editor write-path. Уже установленные Google Sheets проекты deployment не меняет.

### ExportScriptAssets

`app/Console/Commands/ExportScriptAssets.php`

`php artisan scripts:export-assets --output=<basename.json>` экспортирует все DB-версии, включая legacy keys, в `storage/app/private`. Имя файла — basename без директорий; существующий файл не перезаписывается. Команда выводит только количество строк, путь и SHA-256 архива. БД не изменяется.

## Repositories

### ActiveCollabRepository

`app/Repositories/ActiveCollab/ActiveCollabRepository.php`

Единственное место, откуда идут HTTP-запросы в ActiveCollab. Использует `Illuminate\Support\Facades\Http`.

**Публичные методы:**

```php
// POST {base_url}/projects/{project_id}/tasks
public function createTask(ActiveCollabIntegration $integration, array $payload): array

// PUT {base_url}/projects/{project_id}/tasks/{taskId}
public function updateTask(ActiveCollabIntegration $integration, int $taskId, array $payload): array

// GET {global_base_url}/projects/{global_project_id}/task-lists  (из config)
public function getTaskLists(): array

// GET {baseUrl}/projects/{projectId}/task-lists
public function getTaskListsForConnection(string $baseUrl, string $apiToken, int $projectId): array

// POST {baseUrl}/projects/{projectId}/task-lists  { name: ... }
public function createTaskListForConnection(string $baseUrl, string $apiToken, int $projectId, string $name): array

// GET {global_base_url}/users
public function getUsers(): array

// GET {global_base_url}/labels
public function getLabels(): array
```

**Аутентификация:** Заголовок `X-Angie-AuthApiToken: {token}` + `Accept: application/json`.

**Обработка ошибок:** При 4xx или 5xx:
1. `Log::error` с method, url, status, первые 500 байт body (без токена).
2. `$response->throw()` — бросает `Illuminate\Http\Client\RequestException`.

**Приватные методы:**

```php
// Запрос через данные конкретной интеграции (resolvedBaseUrl + resolvedApiToken)
private function requestWithIntegration(ActiveCollabIntegration $integration, string $method, string $path, array $payload = []): Response

// Запрос через глобальный config (base_url + api_token)
private function request(string $method, string $path, array $payload = []): Response

// Запрос через явные параметры соединения
private function requestForConnection(string $method, string $baseUrl, string $apiToken, string $path, array $payload = []): Response

// Финальная отправка (match по method: get/post/put)
private function send(PendingRequest $client, string $method, string $url, array $payload): Response
```

---

### ActiveCollabExportRepository

`app/Repositories/ActiveCollab/ActiveCollabExportRepository.php`

CRUD для таблицы `active_collab_task_exports`.

**Методы:**

```php
// SELECT WHERE external_id = ?
public function findByExternalId(string $externalId): ?ActiveCollabTaskExport

// SELECT WHERE task_id = ?
public function findByTaskId(int $taskId): ?ActiveCollabTaskExport

// INSERT status='pending', source_payload, интеграция, данные строки
public function createPending(ActiveCollabTaskData $data, ActiveCollabIntegration $integration): ActiveCollabTaskExport

// UPDATE status='created', task_id, task_url, sync_hash, payloads, error_message=null
public function markCreated(ActiveCollabTaskExport $export, int $taskId, string $taskUrl, string $syncHash, array $acPayload, array $acResponse): void

// UPDATE status='updated', sync_hash, source_payload, payloads, error_message=null
public function markUpdated(ActiveCollabTaskExport $export, string $syncHash, array $sourcePayload, array $acPayload, array $acResponse): void

// UPDATE status='failed', error_message
public function markFailed(ActiveCollabTaskExport $export, string $errorMessage): void
```

---

### ActiveCollabIntegrationRepository

`app/Repositories/ActiveCollab/ActiveCollabIntegrationRepository.php`

Простой репозиторий — один метод.

```php
// SELECT WHERE spreadsheet_id=? AND active=1
// Только активные интеграции участвуют в синхронизации
public function findBySpreadsheetId(string $spreadsheetId): ?ActiveCollabIntegration
```

---

## Models

### ActiveCollabIntegration

`app/Models/ActiveCollabIntegration.php`

Eloquent-модель. UUID primary key (`HasUuids`).

**Важные casты:**

| Поле | Cast | Примечание |
|------|------|-----------|
| `active_collab_api_token` | `encrypted` | Шифруется/дешифруется прозрачно |
| `task_list_mapping` | `array` | JSON → PHP array |
| `assignee_mapping` | `array` | JSON → PHP array |
| `label_mapping` | `array` | JSON → PHP array |
| `active` | `boolean` | |
| `active_collab_project_id` | `integer` | |
| `active_collab_task_list_id` | `integer` | |

**Вспомогательные методы:**

```php
// Base URL с fallback на config, нормализованный (без trailing slash)
public function resolvedBaseUrl(): string

// URL для браузера: убирает /api/v1 из resolvedBaseUrl
// "https://ac.example.com/api/v1" → "https://ac.example.com"
public function resolvedInstanceUrl(): string

// API Token с fallback на config('services.active_collab.api_token')
public function resolvedApiToken(): string
```

---

### ActiveCollabTaskExport

`app/Models/ActiveCollabTaskExport.php`

UUID primary key (`HasUuids`).

**Связи:**

```php
public function integration(): BelongsTo  // → ActiveCollabIntegration
```

---

### ScriptAsset

`app/Models/ScriptAsset.php`

UUID primary key. Простая модель без дополнительной логики.

---

### User

`app/Models/User.php`

Extends `Orchid\Platform\Models\User`. Стандартная модель Orchid.

**Фильтры и сортировки (для Orchid):**

```php
$allowedFilters = [
    'id'         => Where::class,
    'name'       => Like::class,
    'email'      => Like::class,
    'updated_at' => WhereDateStartEnd::class,
    'created_at' => WhereDateStartEnd::class,
];

$allowedSorts = ['id', 'name', 'email', 'updated_at', 'created_at'];
```

---

## Exceptions

### IntegrationNotFoundException

`app/Exceptions/IntegrationNotFoundException.php`

```php
// Бросается в ActiveCollabTaskService::resolveIntegration()
// Рендерится как HTTP 404 { "message": "..." }
new IntegrationNotFoundException(string $spreadsheetId)
```

### TaskAlreadyExistsException

`app/Exceptions/TaskAlreadyExistsException.php`

```php
// Бросается в ActiveCollabTaskService::create() если export.status in ('created', 'updated')
// Рендерится как HTTP 409 { "message": "...", "data": { "task_id": int, "task_url": str } }
new TaskAlreadyExistsException(int $taskId, string $taskUrl)

// Публичные readonly свойства:
$e->taskId   // int
$e->taskUrl  // string
```

Регистрация в `bootstrap/app.php`:

```php
$exceptions->render(function (IntegrationNotFoundException $e) {
    return response()->json(['message' => $e->getMessage()], 404);
});

$exceptions->render(function (TaskAlreadyExistsException $e) {
    return response()->json([
        'message' => $e->getMessage(),
        'data' => ['task_id' => $e->taskId, 'task_url' => $e->taskUrl],
    ], 409);
});
```

---

## Orchid Screens

### IntegrationEditScreen

`app/Orchid/Screens/Integration/IntegrationEditScreen.php`

Создание и редактирование интеграции. Работает с существующей или новой `ActiveCollabIntegration`.

**Ключевые методы:**

```php
// Загружает интеграцию или создаёт пустой объект для формы
public function query(ActiveCollabIntegration $integration): iterable

// Сохраняет интеграцию: prepareForStorage → integration->fill()->save()
public function save(Request $request, ActiveCollabIntegration $integration): void

// Удаляет интеграцию
public function remove(ActiveCollabIntegration $integration): void

// Нормализует маппинг из строк таблицы формы в associative array
// [["key" => "backend", "value" => 42]] → ["backend" => 42]
private function normalizeMapping(?array $rows): ?array
```

### Apps Script download

DB-editor удалён. Текущий файл скачивается напрямую по `platform.script.download` через `GoogleAppsScriptService`.

### ExportListScreen

`app/Orchid/Screens/Export/ExportListScreen.php`

Список экспортов с фильтрацией по интеграции и статусу. Пагинация.

---

## Конфигурация исключений API

В `bootstrap/app.php` задано:

```php
// Все /api/* маршруты всегда отвечают JSON
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*'),
);
```

Это гарантирует, что любое необработанное исключение (в том числе Laravel-стандартное `ValidationException`, `AuthenticationException`) возвращается как JSON, а не HTML.
