# API Reference

> Актуальный контракт этапа 2 описан в разделе «Единый sync API» ниже. Прежние POST/PUT и глобальная авторизация сохранены только для совместимости и требуют явного `allow_legacy_token` на интеграции.

## Общее

Все API-маршруты находятся под префиксом:

```
/api/integrations/activecollab
```

Все маршруты защищены middleware `VerifyIntegrationToken`.

## Единый sync API (этап 2)

`POST /api/tasks/sync` принимает индивидуальный bearer `sc_` + 64 hex символа. Интеграция определяется записью токена; передавать `spreadsheet_id` можно только как контекстную проверку, а выбирать задачу, интеграцию или действие клиент не может. Тело ограничено 64 KiB и неизвестные поля отвергаются.

Обязательные поля: `record_uuid`, `spreadsheet_id`, `sheet_id`, `row_number`, `environment`, `section`, `description`. Остальные поля совпадают с legacy DTO. Сервер по `(integration_id, record_uuid)` выбирает `created`, `updated` или `skipped`. Успешный ответ содержит `record_uuid`, `action`, `task_id`, `task_url`, `synced_at`, `attempted_at`, SHA-256 `sync_hash` и UUID `operation_id`. Для skip `synced_at` остаётся временем последней подтверждённой записи; `attempted_at` отмечает текущую проверку.

`201` означает создание, `200` — update или skip. `401/403` — неверный, отозванный или недостаточно scoped токен; `409` — конфликт состояния/неизвестный результат; `413` — превышен лимит; `422` — невалидный payload; `502/504` — неподтверждённая внешняя ошибка, повторять её как новый create нельзя. В `retryable` указывается, была ли безопасно сохранена ошибка до внешней отправки.

Legacy POST/PUT и справочники принимают индивидуальный токен. Общий `GOOGLE_SHEETS_INTEGRATION_TOKEN` допускается только для старых маршрутов интеграций, где включён `allow_legacy_token` и master switch `ACTIVECOLLAB_ALLOW_LEGACY_TOKEN=true`. После перехода scripts master switch можно выключить; индивидуальные токены продолжают работать. Справочники с общим токеном требуют `spreadsheet_id`; индивидуальные токены ограничивают запрос своей интеграцией.

Выдача/листинг/отзыв токенов, `legacy-status` и переключение per-integration legacy-флага доступны только CLI-команде `php artisan integrations:tokens ...` и пользователю с разрешениями recovery, users и roles. После переноса scripts установите `ACTIVECOLLAB_ALLOW_LEGACY_TOKEN=false`. Полный синтаксис и переход описаны в [DEPLOY](DEPLOY.md#переход-скриптов-и-токенов-этап-2) и [разделе ролей](PROJECT_MANUAL.md#роли-и-доступ-к-интеграциям).

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
| `record_uuid` | UUID | после перевода | Стабильный UUID из технической колонки Sheets; до перевода интеграции запросы с UUID блокируются |
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
          для этой идентичности (UUID либо legacy locator) есть полная привязка в текущей интеграции/проекте, независимо от последнего статуса

HTTP 422  {
            "message": "The given data was invalid.",
            "errors": {
              "environment": ["The environment field is required."],
              ...
            }
          }
          ошибки валидации

HTTP 500  ошибка маппинга до HTTP; error.code = mapping_invalid
HTTP 502  результат внешнего запроса не подтверждён; error.code = sync_outcome_unknown
HTTP 504  timeout; error.code = sync_outcome_unknown
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
HTTP 409  Привязка отсутствует, неполна или не соответствует контексту запроса
HTTP 422  Ошибки валидации
HTTP 500  Ошибка маппинга до отправки
HTTP 502  Неподтверждённый внешний результат
HTTP 504  Timeout с неизвестным результатом
```

Обновление разрешено только при наличии полной привязки по одновременно совпадающим `integration_id`, `project_id`, идентичности исходной записи (`record_uuid` после перевода, `external_id` до него) и `task_id` из URL. Task ID должен быть положительным, task URL — непустым. При отсутствии или несовпадении привязки внешний запрос не выполняется; произвольный update без истории запрещён.

Одинаковые task ID в разных интеграциях/проектах не смешивают экспорты. Ошибка update меняет legacy-статус на `failed`, сохраняя task ID/URL, последний подтверждённый hash и source/AC payload/response. Последующий POST возвращает прежний duplicate conflict с task ID/URL.

Для POST и PUT конфликт неполной или несовпавшей привязки имеет безопасный ответ без метаданных чужой записи:

```json
{
  "message": "Привязка задачи не найдена или не соответствует исходной записи.",
  "error": {
    "code": "legacy_binding_conflict",
    "message": "Привязка задачи не найдена или не соответствует исходной записи.",
    "operation_id": null,
    "retryable": false
  }
}
```

HTTP status — 409. Требуется сверка привязки; автоматический повтор с теми же данными не устраняет конфликт.

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

Возвращает task lists проекта, выбранного для интеграции, аутентифицированной текущим integration token.

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

`external_id` — неуникальный локатор текущей позиции; формат сохранён:

```
external_id = "{spreadsheet_id}:{sheet_id}:{row_number}"
```

Пример: `1r9uFA2w0_pd_ciYKmyE50qeXiiUkDVPsV6kCByjZVj4:0:8`

**При создании (POST):**

- Сначала проверяется соответствие существующей записи текущим integration/project. Null integration ID или несовпадение контекста возвращают `409 legacy_binding_conflict`, без новой задачи и без автоматической перепривязки.
- Полная привязка с task ID/URL возвращает прежний `409` с `data.task_id/task_url` при любом статусе, включая `failed` после ошибки update.
- Неполная привязка (есть только ID или URL, ID неположительный, URL пуст) либо `created/updated` без привязки возвращает `409 legacy_binding_conflict`.
- Новые `idle` и подтверждённые ошибки маппинга (`last_attempt_status=failed`) без task ID/URL допускают резервирование. Старые `pending/failed` с nullable новым состоянием классифицируются `unknown`: повтор создавать задачу не будет.

**При обновлении (PUT):**

- Поиск требует точного совпадения integration/project/идентичности записи/task ID и полной привязки. При конфликте возвращается 409 до HTTP.

После контролируемого перевода интеграции (`uuid_only=true`) идентичность — только `(integration_id, record_uuid)`. UUID передаётся в обоих POST/PUT; null/пустое значение считается отсутствием, регистр нормализуется. Позиция может меняться, включая перенос на другой лист этой же таблицы. Новый UUID на прежнем месте создаёт отдельный экспорт; он не захватывает старую связь через row number или task ID.

До перевода обслуживаются старые запросы без UUID. UUID до перевода и отсутствие UUID после перевода возвращают `409 legacy_binding_conflict` с инструкцией перехода. Если UUID уже привязан, но readiness ещё false, все legacy-запросы этой интеграции блокируются, включая пустые позиции. Неоднозначный locator не выбирается произвольно. Неверный UUID даёт 422 до HTTP. Успешные envelopes остаются прежними.

Reserve берёт integration lock, затем token lock (для scoped доступа) и export lock. HTTP выполняется после commit; operation ID/lease защищают завершение. Старые writers используют общий token только если `allow_legacy_token=true` на интеграции. Переход UUID и установка scoped клиента описаны в [DEPLOY](DEPLOY.md#переход-скриптов-и-токенов-этап-2).

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

Null-значения участвуют как пустая строка в legacy `legacy-md5-v1`. Новый sync хранит `payload-sha256-v2`, рассчитанный по конечному payload, destination (включая connection ID) и mapping. Только подтверждённый SHA-256 допускает skip; старый или неизвестный hash требует одного полного PUT.

---

## Коды ошибок — сводная таблица

| Код | Причина |
|-----|---------|
| 401 | Индивидуальный bearer неверен/отозван или глобальный token не настроен для legacy маршрута |
| 404 | Нет активной интеграции для данного `spreadsheet_id` |
| 409 | Полная привязка уже существует (POST) либо привязка отсутствует, неполна или не соответствует контексту (POST/PUT) |
| 422 | Ошибка валидации входящего payload |
| 403 | Общий legacy token не разрешён для интеграции или контекст другой таблицы |
| 500 | Ошибка маппинга (не найден `task_type` в `task_list_mapping` или `responsible` в `assignee_mapping`) |
| 409 | `sync_in_progress` или `sync_outcome_unknown`: новая отправка заблокирована |
| 502 | Внешний результат не подтверждён (`sync_outcome_unknown`) |
| 504 | Timeout, возможен внешний результат (`sync_outcome_unknown`) |
| 413 | JSON sync больше 64 KiB |


## Резервирование и неопределённый результат

Каждая принятая попытка получает `operation_id` и запись в `active_collab_sync_attempts`. Пока действует running lease, конкурентный запрос возвращает HTTP 409 `sync_in_progress` без второго HTTP. Полная связь при legacy create сохраняет прежний duplicate 409 с `data.task_id/task_url`.

Истёкший lease становится `unknown`. Timeout, невалидный успешный ответ, неподтверждённый внешний отказ и сбой записи после внешнего успеха также блокируют последующую отправку. Внешние ошибки после dispatch трактуются консервативно: даже полученный 4xx не доказывает отсутствия результата POST. Для известной связи PUT 404 отдельно устанавливает remote_missing, без автоматической замены. Ошибка маппинга до HTTP записывается как failed и после исправления допускает новый запрос.

Пример ответа running (HTTP 409):

```json
{
  "message": "Синхронизация уже выполняется.",
  "error": {
    "code": "sync_in_progress",
    "message": "Синхронизация уже выполняется.",
    "operation_id": "operation-uuid",
    "retryable": false
  }
}
```

Для unknown message: `Результат синхронизации неизвестен. Требуется сверка.`, code: `sync_outcome_unknown`. Первый ответ — 502 или 504 для timeout; последующие обращения к unresolved операции — 409. У legacy-снимка без новой операции `operation_id` может быть null. `retryable=false` запрещает автоматические повторы клиента; будущий запрос после завершения running проверит текущее состояние заново.

Ручное разрешение unknown доступно администратору на экране восстановления. Не удалять экспорт и не сбрасывать состояние SQL-командой ради повторного create. Чувствительные сведения upstream не возвращаются в ошибке; хранится только безопасный код/сообщение и nullable HTTP status.


## Восстановление и недоступная задача (шаг 1.3)

Связанный PUT, получивший 404, возвращает HTTP 409 `remote_task_missing`: task ID/URL и последний подтверждённый snapshot сохраняются. Последующие ordinary PUT не отправляют HTTP. Если нет unresolved операции, валидированный источник такого PUT сохраняется в отдельной failed attempt для выбора администратором при замене. При unknown замене новый PUT не заменяет operation ID и не снимает неизвестность. Legacy create с полной связью сохраняет прежний duplicate envelope с собственными task ID/URL.

`remote_missing` означает «задача не найдена или недоступна в этом проекте», а не доказанное физическое удаление. Для неизвестного create один 404 или пустой поиск не разрешают повтор. В attempt.error_code различаются `upstream_rejected`, `upstream_uncertain`, `transport_uncertain`, `protocol_invalid`, `pre_dispatch_failed`; HTTP status хранится отдельно при наличии. Категория отказа не меняет unknown на безопасно повторяемый failed без доказательства, что отправка не начиналась.

Новый экран `/admin/exports/{export}/recovery` требует сессионной авторизации и одновременно прав recovery/users/roles. Integration Bearer token не даёт доступа. GET только читает локальный whitelist данных. POST-методы `bind`, `verify`, `confirmAbsence`, `replace` защищены CSRF и policy; прямой GET action получает 405, недостаточные административные полномочия — 404. Подмена actor/integration/project/action через body не принимается.

Действие передаёт UUID запроса, ожидаемые operation/status/binding, причину 10–1000 символов и при необходимости task ID/source attempt ID. Для отсутствия/замены обязательно явное подтверждение сверки. Размер запроса ограничен 16 KiB. Повтор того же решения возвращает сохранённый результат без нового HTTP; другое решение/actor/экспорт с тем же UUID — 409 recovery_conflict. Stale форма также получает conflict.

Bind/verify делает только GET конкретной задачи в контексте интеграции; проверяются id/project_id/is_trashed и relative URL. Это подтверждает связь, а не синхронизацию содержимого: hash инвалидируется, synced_at не выдумывается. Confirm absence сам не создаёт задачу; он разрешает следующую попытку только после внешней проверки администратором. Unknown update с существующей связью нельзя разблокировать утверждением «новая задача не создавалась».

Replace — отдельный подтверждённый admin POST с выбранным сохранённым источником и текущими маппингами. Старые ID/URL сохраняются до success. Unknown replacement блокирует ещё один create даже после неудачного GET старой задачи; сначала требуется отдельное подтверждение отсутствия результата замены. Внешние операции create/update по-прежнему проходят общий TaskService и ownership/lease из 1.2.
