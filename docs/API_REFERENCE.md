# API Reference

## Общее

Все API-маршруты находятся под префиксом:

```
/api/integrations/activecollab
```

Все маршруты защищены middleware `VerifyIntegrationToken`.

---

## Аутентификация

Каждый запрос обязан содержать заголовок:

```
Authorization: Bearer <GOOGLE_SHEETS_INTEGRATION_TOKEN>
```

Значение токена должно совпадать с переменной `GOOGLE_SHEETS_INTEGRATION_TOKEN` в `.env` сервиса.

### Ответы при ошибках аутентификации

**Токен не задан в .env (сервис не настроен):**

```
HTTP 500
{
  "message": "Integration token not configured."
}
```

**Токен отсутствует в запросе или не совпадает:**

```
HTTP 401
{
  "message": "Unauthorized."
}
```

Сравнение производится через `hash_equals()` (защита от timing attacks).

---

## Endpoints

### POST /api/integrations/activecollab/tasks

Создаёт новую задачу в ActiveCollab на основе строки Google Sheets.

#### Request Headers

```
Content-Type: application/json
Authorization: Bearer <token>
```

#### Request Body

| Поле | Тип | Обязательно | Описание |
|------|-----|-------------|---------|
| `spreadsheet_id` | string | да | ID Google Sheets таблицы или полная URL таблицы |
| `sheet_id` | string | да | ID листа (обычно `"0"` для первого листа) |
| `row_number` | integer ≥ 1 | да | Номер строки в таблице |
| `environment` | string | да | Стенд (напр. `"stage"`, `"prod"`) |
| `section` | string | да | Раздел приложения |
| `description` | string | да | Описание проблемы |
| `severity` | string\|null | нет | Серьёзность (напр. `"critical"`, `"major"`) |
| `priority` | string\|null | нет | Приоритет (напр. `"высокий"`) |
| `device` | string\|null | нет | Устройство (напр. `"desktop"`) |
| `resolution` | string\|null | нет | Разрешение экрана (напр. `"1920x1080"`) |
| `screenshot` | url\|null | нет | Ссылка на скриншот (валидный URL) |
| `comment` | string\|null | нет | Дополнительный комментарий |
| `task_type` | string\|null | нет | Тип задачи (используется для маппинга task list) |
| `responsible` | string\|null | нет | Ответственный (используется для маппинга assignee) |
| `reporter` | string\|null | нет | Постановщик задачи |
| `status` | string\|null | нет | Статус в таблице (записывается в тело задачи) |

**Нормализация `spreadsheet_id`:** если передан полный URL вида `https://docs.google.com/spreadsheets/d/{ID}/edit`, сервис автоматически извлекает `{ID}`.

#### Success Response

```
HTTP 201
{
  "data": {
    "task_id": 123,
    "task_url": "https://your-activecollab.example.com/projects/10/tasks/123",
    "synced_at": "2026-07-05T14:30:00+00:00",
    "sync_hash": "a1b2c3d4e5f6..."
  }
}
```

| Поле | Тип | Описание |
|------|-----|---------|
| `task_id` | integer | ID задачи в ActiveCollab |
| `task_url` | string | URL задачи в браузере (без `/api/v1`) |
| `synced_at` | ISO 8601 string | Время синхронизации (UTC) |
| `sync_hash` | string (md5) | Хеш 13 полей строки для детектирования изменений |

#### Error Responses

```
HTTP 401  { "message": "Unauthorized." }
          токен не передан или не совпал

HTTP 404  { "message": "Для таблицы \"...\", не настроена интеграция с ActiveCollab." }
          интеграция для spreadsheet_id не найдена или неактивна

HTTP 409  {
            "message": "Задача для этой строки уже создана.",
            "data": {
              "task_id": 123,
              "task_url": "https://..."
            }
          }
          задача для этого external_id уже создана (статус created или updated)

HTTP 422  {
            "message": "The given data was invalid.",
            "errors": {
              "environment": ["The environment field is required."],
              ...
            }
          }
          ошибки валидации

HTTP 500  { "message": "Ответственный \"..\" не найден в маппинге." }
          ошибка маппинга (не настроен assignee или task_list)

HTTP 500  { "message": "..." }
          ошибка HTTP-запроса в ActiveCollab (4xx/5xx от AC API)
```

#### Пример curl

```bash
curl -X POST http://localhost/api/integrations/activecollab/tasks \
  -H "Authorization: Bearer your-integration-token" \
  -H "Content-Type: application/json" \
  -d '{
    "spreadsheet_id": "1r9uFA2w0_pd_ciYKmyE50qeXiiUkDVPsV6kCByjZVj4",
    "sheet_id": "0",
    "row_number": 8,
    "environment": "stage",
    "section": "Каталог",
    "description": "Не открывается карточка товара",
    "severity": "critical",
    "priority": "высокий",
    "device": "desktop",
    "resolution": "1920x1080",
    "screenshot": "https://example.com/screenshot.png",
    "comment": "Воспроизводится стабильно",
    "task_type": "backend",
    "responsible": "Tony Yats",
    "reporter": "QA Team",
    "status": "новая"
  }'
```

---

### PUT /api/integrations/activecollab/tasks/{taskId}

Обновляет существующую задачу в ActiveCollab.

#### URL Parameters

| Параметр | Тип | Описание |
|----------|-----|---------|
| `taskId` | integer | ID задачи в ActiveCollab |

#### Request Headers

```
Content-Type: application/json
Authorization: Bearer <token>
```

#### Request Body

Идентичен телу POST /tasks. Обязательные и необязательные поля — те же.

#### Success Response

```
HTTP 200
{
  "data": {
    "task_id": 123,
    "task_url": "https://your-activecollab.example.com/projects/10/tasks/123",
    "synced_at": "2026-07-05T14:35:00+00:00",
    "sync_hash": "f6e5d4c3b2a1..."
  }
}
```

#### Error Responses

```
HTTP 401  Unauthorized
HTTP 404  Интеграция не найдена
HTTP 422  Ошибки валидации
HTTP 500  Ошибка маппинга или ошибка AC API
```

**Особенность:** При обновлении запись `active_collab_task_exports` ищется сначала по `task_id`, затем по `external_id`. Если запись не найдена — обновление в ActiveCollab всё равно выполняется, но история синхронизации не сохраняется.

#### Пример curl

```bash
curl -X PUT http://localhost/api/integrations/activecollab/tasks/123 \
  -H "Authorization: Bearer your-integration-token" \
  -H "Content-Type: application/json" \
  -d '{
    "spreadsheet_id": "1r9uFA2w0_pd_ciYKmyE50qeXiiUkDVPsV6kCByjZVj4",
    "sheet_id": "0",
    "row_number": 8,
    "environment": "stage",
    "section": "Каталог",
    "description": "Не открывается карточка товара (уточнение)",
    "severity": "major"
  }'
```

---

### GET /api/integrations/activecollab/task-lists

Возвращает список task lists из дефолтной конфигурации ActiveCollab (из `.env`).

Используется для вспомогательных целей: выяснить ID task list при настройке маппингов.

#### Request Headers

```
Authorization: Bearer <token>
```

#### Success Response

```
HTTP 200
{
  "data": [
    { "id": 11, "name": "Backlog" },
    { "id": 12, "name": "Sprint 1" }
  ]
}
```

Структура ответа повторяет структуру ActiveCollab API (`GET /api/v1/projects/{id}/task-lists`).

#### Пример curl

```bash
curl http://localhost/api/integrations/activecollab/task-lists \
  -H "Authorization: Bearer your-integration-token"
```

---

### GET /api/integrations/activecollab/users

Возвращает список пользователей ActiveCollab из дефолтной конфигурации.

Используется для выяснения `assignee_id` при настройке `assignee_mapping`.

#### Request Headers

```
Authorization: Bearer <token>
```

#### Success Response

```
HTTP 200
{
  "data": [
    { "id": 3, "display_name": "Tony Yats", "email": "tony@example.com" },
    ...
  ]
}
```

#### Пример curl

```bash
curl http://localhost/api/integrations/activecollab/users \
  -H "Authorization: Bearer your-integration-token"
```

---

### GET /api/integrations/activecollab/labels

Возвращает список labels ActiveCollab из дефолтной конфигурации.

Используется для выяснения `label_id` при настройке `label_mapping`.

#### Request Headers

```
Authorization: Bearer <token>
```

#### Success Response

```
HTTP 200
{
  "data": [
    { "id": 7, "name": "Critical" },
    { "id": 8, "name": "Major" }
  ]
}
```

#### Пример curl

```bash
curl http://localhost/api/integrations/activecollab/labels \
  -H "Authorization: Bearer your-integration-token"
```

---

## Логика external_id и идемпотентность

`external_id` — уникальный ключ синхронизации строки таблицы:

```
external_id = "{spreadsheet_id}:{sheet_id}:{row_number}"
```

Пример: `1r9uFA2w0_pd_ciYKmyE50qeXiiUkDVPsV6kCByjZVj4:0:8`

**При создании (POST):**
- Если для этого `external_id` уже есть запись со статусом `created` или `updated` — возвращается `409 Conflict` с `task_id` и `task_url`. Это не ошибка — Apps Script намеренно обрабатывает `409` как "задача уже существует".
- Если запись есть, но статус `failed` или `pending` — делается попытка создать задачу заново.

**При обновлении (PUT):**
- `409` не бросается — обновление всегда выполняется.

---

## Формат имени задачи

```
[{environment}] [{severity}] {section}: {description (max 100 chars)}
```

Пример:
```
[stage] [critical] Каталог: Не открывается карточка товара
```

Части, которых нет в данных (например, `severity = null`), пропускаются.

---

## Формат тела задачи (HTML)

```html
<h3>Описание проблемы</h3>
<p>{description с nl2br и htmlspecialchars}</p>

<h3>Параметры</h3>
<ul>
  <li><strong>Серьезность:</strong> critical</li>
  <li><strong>Приоритет:</strong> высокий</li>
  <li><strong>Устройство:</strong> desktop</li>
  <li><strong>Разрешение:</strong> 1920x1080</li>
  <li><strong>Стенд:</strong> stage</li>
  <li><strong>Раздел:</strong> Каталог</li>
  <li><strong>Тип задач:</strong> backend</li>
  <li><strong>Ответственный:</strong> Tony Yats</li>
  <li><strong>Постановщик:</strong> QA Team</li>
  <li><strong>Статус в таблице:</strong> новая</li>
</ul>

<h3>Скриншоты</h3>
<p><a href="https://...">https://...</a></p>  <!-- только если screenshot задан -->

<h3>Комментарий</h3>
<p>Воспроизводится стабильно</p>  <!-- только если comment задан -->

<hr>
<p>Источник: Google Sheets, строка 8.</p>
```

Пустые поля параметров заменяются на `—`. Секции `Скриншоты` и `Комментарий` полностью опускаются, если данных нет.

---

## Sync hash

Вычисляется как MD5 от 13 полей, разделённых `|`:

```
md5("{severity}|{priority}|{device}|{resolution}|{environment}|{section}|{description}|{screenshot}|{comment}|{task_type}|{responsible}|{reporter}|{status}")
```

Null-значения участвуют как пустая строка. Hash позволяет Apps Script понять, изменилась ли строка с момента последней синхронизации, не делая запрос в Laravel.

---

## Коды ошибок — сводная таблица

| Код | Причина |
|-----|---------|
| 401 | Токен не передан или не совпадает с `GOOGLE_SHEETS_INTEGRATION_TOKEN` |
| 404 | Нет активной интеграции для данного `spreadsheet_id` |
| 409 | Задача для этой строки (`external_id`) уже существует |
| 422 | Ошибка валидации входящего payload |
| 500 | Не настроен integration token в `.env` |
| 500 | Ошибка маппинга (не найден `task_type` в `task_list_mapping` или `responsible` в `assignee_mapping`) |
| 500 | Ошибка HTTP-запроса в ActiveCollab (4xx или 5xx от AC API) |
