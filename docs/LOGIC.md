# Логика работы и настройки

## Общий сценарий

1. Поднимаем Laravel-проект.
2. Логинимся в Orchid-админку.
3. Открываем раздел `Apps Script`.
4. При необходимости вставляем или редактируем код `activecollab_sync.gs`.
5. Скачиваем `activecollab_sync.gs`.
6. Вставляем его в Google Apps Script, привязанный к нужной таблице.
7. В Script Properties задаем:
   - `INTEGRATION_SERVICE_URL`
   - `INTEGRATION_SERVICE_TOKEN`
   Где брать:
   - `INTEGRATION_SERVICE_URL` — публичный базовый URL этого Laravel-сервиса без `/admin` и без `/api/...`
   - `INTEGRATION_SERVICE_TOKEN` — значение `GOOGLE_SHEETS_INTEGRATION_TOKEN` из `.env` или из переменных окружения сервера
8. Создаем интеграцию в админке.
9. Вставляем ссылку на Google Sheets или `spreadsheet_id`.
10. Заполняем параметры ActiveCollab и включаем интеграцию.
11. После этого из таблицы можно создавать и обновлять задачи в ActiveCollab.

Важно для локального теста:

- `http://localhost` подходит только для открытия админки в браузере.
- Для реального вызова из Google Apps Script нужен публичный URL, например через `cloudflared` или `ngrok`.
- Именно этот URL и нужно указывать в `INTEGRATION_SERVICE_URL`.

## Что именно заполняется в интеграции

Нужно указать:

- `Название`
- `Google Sheets ID`
- `ActiveCollab API Base URL`
- `Project ID`
- `Активна`

Опционально:

- `Task List ID или название списка`
- `ActiveCollab API Token`

## Откуда брать значения

### Google Sheets ID

Берется из URL таблицы:

`https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit`

Можно вставить полную ссылку, сервис сам выделит `spreadsheet_id`.

### ActiveCollab API Base URL

Для self-hosted ActiveCollab обычно это:

`https://your-activecollab.example.com/api/v1`

Если указать URL без `/api/v1`, сервис добавит суффикс автоматически.

То есть обычно:

- `ActiveCollab API Base URL = https://your-activecollab.example.com/api/v1`

Проверить можно так:

```bash
curl -I https://your-activecollab.example.com/api/v1
```

Если endpoint живой, это и есть нужная API base URL.

### Project ID

Обычно берется из URL проекта.

Пример:

`https://your-activecollab.example.com/projects/10`

Здесь:

`Project ID = 10`

Можно вставить не только число, но и полную ссылку на проект.

### Task List ID или название списка

Берется из ActiveCollab API или через вспомогательные endpoints Laravel.

Если поле пустое в админке, сервис пытается автоматически выбрать первый task list проекта.

Если в поле указано название списка, сервис сначала пытается найти его в проекте, а если не находит, пробует создать такой task list через ActiveCollab API.

Можно получить через:

- `GET /api/integrations/activecollab/task-lists`

Либо напрямую из ActiveCollab API.

### ActiveCollab API Token

Берется в самом ActiveCollab для пользователя, у которого есть доступ к нужному проекту и задачам.

Если глобальный `ACTIVECOLLAB_API_TOKEN` уже задан в `.env`, поле токена в интеграции можно оставить пустым.

Этот токен:

- хранится в Laravel
- не должен храниться в Apps Script
- не должен вставляться в таблицу

## Что происходит после настройки

1. Пользователь выбирает строку в Google Sheets.
2. Нажимает `Создать задачу` или `Обновить задачу`.
3. Apps Script отправляет payload в Laravel.
4. Laravel находит интеграцию по `spreadsheet_id`.
5. Laravel создает или обновляет задачу в ActiveCollab.
6. Ссылка на задачу записывается обратно в таблицу.
7. История синхронизации сохраняется в базе.

## Важные замечания

- `activecollab_sync.gs` теперь канонически хранится в базе данных и редактируется через админку.
- Если файла `plugin/app/activecollab_sync.gs` нет на диске, это не проблема.
- Файл на диске нужен только как стартовый шаблон, если он существует.
- URL задач для браузера вычисляется из `ActiveCollab API Base URL`.
- Старый ключ скрипта `poc.gs` больше не используется; legacy-запись при миграции безопасно схлопывается в `activecollab_sync.gs`.
