<style>
    .guide {
        --guide-border: #e7e2d8;
        --guide-ink: #241b11;
        --guide-text: #5e5448;
        --guide-accent: #8a4526;
        display: grid;
        gap: 16px;
    }

    .guide-hero,
    .guide-card {
        padding: 22px;
        border: 1px solid var(--guide-border);
        border-radius: 16px;
        background: #fff;
    }

    .guide-hero {
        background: linear-gradient(135deg, #fffdf8, #f7efe3);
    }

    .guide h2,
    .guide h3 {
        margin: 0 0 10px;
        color: var(--guide-ink);
    }

    .guide h2 { font-size: 24px; }
    .guide h3 { font-size: 18px; }

    .guide p,
    .guide li,
    .guide td,
    .guide th {
        color: var(--guide-text);
        line-height: 1.55;
    }

    .guide p:last-child,
    .guide ul:last-child,
    .guide ol:last-child { margin-bottom: 0; }

    .guide-links,
    .guide-grid { display: grid; gap: 10px; }
    .guide-links { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 16px; }
    .guide-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 12px; }

    .guide-link {
        display: block;
        padding: 10px 14px;
        border: 1px solid #d6c7b6;
        border-radius: 10px;
        background: #fff;
        color: var(--guide-accent) !important;
        font-weight: 600;
        text-align: center;
        text-decoration: none !important;
    }

    .guide-list,
    .guide-ordered { margin: 10px 0 0; padding-left: 22px; }
    .guide-list li + li,
    .guide-ordered li + li { margin-top: 5px; }

    .guide-note {
        margin-top: 12px;
        padding: 12px 14px;
        border-left: 3px solid var(--guide-accent);
        border-radius: 8px;
        background: #f8f3eb;
        color: var(--guide-text);
    }

    .guide-note--warning { border-color: #b7791f; background: #fff9e8; }
    .guide code { overflow-wrap: anywhere; }

    .guide-table-wrap { margin-top: 10px; overflow-x: auto; }
    .guide-table { width: 100%; border-collapse: collapse; }
    .guide-table th,
    .guide-table td { padding: 9px 10px; border-bottom: 1px solid var(--guide-border); text-align: left; vertical-align: top; }
    .guide-table th { color: var(--guide-ink); }

    @media (max-width: 800px) {
        .guide-links,
        .guide-grid { grid-template-columns: 1fr; }
        .guide-hero,
        .guide-card { padding: 16px; }
    }
</style>

<div class="guide">
    <section class="guide-hero">
        <h2>Подключите Google Sheets к ActiveCollab</h2>
        <p>Создайте подключение, настройте таблицу и установите Apps Script. Для новой задачи используйте меню ActiveCollab в Google Sheets.</p>
        <div class="guide-links">
            <a class="guide-link" href="{{ route('platform.connections') }}">Подключения</a>
            <a class="guide-link" href="{{ route('platform.integrations') }}">Интеграции</a>
            <a class="guide-link" href="{{ route('platform.exports') }}">История экспортов</a>
        </div>
    </section>

    <section class="guide-card">
        <h3>Настройка</h3>
        <ol class="guide-ordered">
            <li>В <a href="{{ route('platform.connections') }}">Подключениях</a> добавьте ActiveCollab URL и API token. Проверьте соединение.</li>
            <li>В <a href="{{ route('platform.integrations') }}">Интеграциях</a> создайте интеграцию: укажите Google Sheets URL, подключение, Project ID и при необходимости ID существующего Task List.</li>
            <li>При необходимости настройте маппинги списков, ответственных и меток. Ключи должны совпадать со значениями в таблице.</li>
            <li>Попросите администратора назначить вам доступ к интеграции и выпустить для неё индивидуальный токен.</li>
            <li>Подготовьте заголовки Google Sheets. Заголовок <code>Серьезность</code> обязателен для поиска строки заголовков; он должен быть среди первых 10 строк.</li>
            <li>Скачайте <a href="{{ route('platform.script.download') }}">Apps Script</a>, откройте в таблице <strong>Расширения → Apps Script</strong>, замените код в <code>Code.gs</code> и сохраните.</li>
            <li>В <strong>Настройки проекта → Свойства скрипта</strong> добавьте свойства ниже, затем обновите Google Sheets.</li>
        </ol>

        <div class="guide-table-wrap">
            <table class="guide-table">
                <thead><tr><th>Свойство</th><th>Значение</th></tr></thead>
                <tbody>
                    <tr><td><code>INTEGRATION_SERVICE_URL</code></td><td>Публичный HTTPS URL SheetCollab без завершающего слеша и без <code>/admin</code>.</td></tr>
                    <tr><td><code>INTEGRATION_SERVICE_TOKEN</code></td><td>Индивидуальный токен этой интеграции. Администратор показывает его только при выпуске.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="guide-note guide-note--warning">
            В Apps Script хранится только токен SheetCollab. ActiveCollab API token храните в подключении SheetCollab. Не меняйте их местами. Google Apps Script не может обратиться к <code>localhost</code>.
        </div>
    </section>

    <section class="guide-card">
        <h3>Колонки таблицы</h3>
        <p>Порядок колонок свободный. <code>Серьезность</code> нужна, чтобы найти строку заголовков; для синхронизации строки нужны:</p>
        <div class="guide-grid">
            <div><strong>Обязательные:</strong> <code>Стенд</code>, <code>Раздел</code>, <code>Описание проблемы</code>, <code>Ссылка на задачу</code>.</div>
            <div><strong>Необязательные:</strong> <code>Серьезность</code>, <code>Тип задач</code>, <code>Ответственный</code>, <code>Приоритет</code>, <code>Устройство</code>, <code>Разрешение</code>, <code>Скриншот (ссылка)</code>, <code>Комментарий</code>, <code>Постановщик</code>, <code>Статус</code>, <code>Ошибка синхронизации</code>, <code>Дата синхронизации</code>, <code>Дата попытки</code>.</div>
        </div>
        <p>Скрипт добавит скрытую колонку <code>SheetCollab UUID</code>. Заголовок скриншота также поддерживает вариант <code>Скриншот(ссылка)</code>.</p>
    </section>

    <section class="guide-card">
        <h3>Перед первой синхронизацией</h3>
        <p>Новый скрипт использует UUID для связи строки с задачей. До запуска администратор должен перевести интеграцию в UUID-режим.</p>
        <ol class="guide-ordered">
            <li>В таблице выберите <strong>ActiveCollab → Подготовить UUID для переноса</strong> и сохраните показанный JSON.</li>
            <li>Остановите синхронизацию и правки таблицы. Администратор проверит манифест в режиме dry-run, затем выполнит перенос и подтвердит готовность.</li>
            <li>После подтверждения обновите таблицу. Для пустой новой таблицы администратору также нужно включить UUID-режим.</li>
        </ol>
    </section>

    <section class="guide-card">
        <h3>Работа и важные ограничения</h3>
        <ul class="guide-list">
            <li>Выберите строку и нажмите <strong>ActiveCollab → Синхронизировать задачу</strong>. Повторный запуск создаст или обновит задачу по её UUID; неизменённые данные пропускаются.</li>
            <li>Сортируйте и перемещайте полные строки вместе со скрытой колонкой <code>SheetCollab UUID</code>. Не меняйте UUID вручную.</li>
            <li>Копия строки сохраняет UUID. Для отдельной задачи выберите <strong>ActiveCollab → Новая запись из копии</strong>.</li>
            <li>Если исход неизвестен, автоматическое повторное создание блокируется. Откройте <a href="{{ route('platform.exports') }}">Историю экспортов</a> и попросите администратора сверить задачу и разрешить дальнейшее действие.</li>
        </ul>
    </section>

    <section class="guide-card">
        <h3>Если что-то не работает</h3>
        <div class="guide-table-wrap">
            <table class="guide-table">
                <thead><tr><th>Сообщение</th><th>Что проверить</th></tr></thead>
                <tbody>
                    <tr><td>Заголовок не найден</td><td>Точное имя <code>Серьезность</code>, первые 10 строк, без повторяющихся заголовков.</td></tr>
                    <tr><td>401 Unauthorized</td><td>Токен SheetCollab, его срок действия и принадлежность интеграции.</td></tr>
                    <tr><td>Интеграция не найдена</td><td>Google Sheets ID и активность интеграции.</td></tr>
                    <tr><td>Ошибка маппинга</td><td>Значение ячейки и соответствующий ID в настройках интеграции.</td></tr>
                    <tr><td>ActiveCollab 403/404</td><td>URL подключения, API token и доступ к проекту.</td></tr>
                    <tr><td>Меню ActiveCollab не появилось</td><td>Сохраните Apps Script и обновите страницу таблицы.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
