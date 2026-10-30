<style>
    .guide {
        --guide-accent: #b4572e;
        --guide-accent-dark: #7d391a;
        --guide-ink: #241b11;
        --guide-text: #5e5448;
        --guide-border: #e7e2d8;
        --guide-soft: #f8f3eb;
        display: grid;
        gap: 18px;
    }

    .guide-hero,
    .guide-card {
        background: #fff;
        border: 1px solid var(--guide-border);
        border-radius: 20px;
        box-shadow: 0 12px 32px rgba(31, 26, 18, 0.06);
    }

    .guide-hero {
        padding: 30px;
        background: linear-gradient(135deg, #fffdf8 0%, #f7efe3 100%);
    }

    .guide h2,
    .guide h3,
    .guide h4 {
        color: var(--guide-ink);
    }

    .guide h2 {
        margin: 0 0 12px;
        font-size: 28px;
    }

    .guide h3 {
        margin: 0 0 14px;
        font-size: 21px;
    }

    .guide h4 {
        margin: 22px 0 8px;
        font-size: 16px;
    }

    .guide p,
    .guide li,
    .guide td,
    .guide th {
        color: var(--guide-text);
        line-height: 1.65;
    }

    .guide p:last-child,
    .guide ul:last-child,
    .guide ol:last-child {
        margin-bottom: 0;
    }

    .guide-card {
        padding: 24px;
        scroll-margin-top: 24px;
    }

    .guide-links,
    .guide-flow,
    .guide-columns {
        display: grid;
        gap: 12px;
    }

    .guide-links {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: 20px;
    }

    .guide-link {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        padding: 10px 14px;
        border-radius: 12px;
        background: var(--guide-accent);
        color: #fff !important;
        font-weight: 600;
        text-align: center;
        text-decoration: none !important;
    }

    .guide-link--secondary {
        background: #fff;
        border: 1px solid #d6c7b6;
        color: var(--guide-accent-dark) !important;
    }

    .guide-flow {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-top: 22px;
    }

    .guide-flow-item {
        position: relative;
        padding: 14px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.72);
        color: var(--guide-ink);
        font-weight: 600;
        text-align: center;
    }

    .guide-flow-item:not(:last-child)::after {
        position: absolute;
        top: 50%;
        right: -10px;
        content: "→";
        color: var(--guide-accent);
        transform: translateY(-50%);
    }

    .guide-toc {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 22px;
        margin: 0;
        padding-left: 22px;
    }

    .guide-toc a {
        color: var(--guide-accent-dark);
        text-decoration: none;
    }

    .guide-step-title {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .guide-step-number {
        display: inline-grid;
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        place-items: center;
        border-radius: 12px;
        background: rgba(180, 87, 46, 0.12);
        color: var(--guide-accent-dark);
        font-weight: 700;
    }

    .guide-step-title h3 {
        margin: 0;
    }

    .guide-list,
    .guide-ordered {
        margin: 12px 0 0;
        padding-left: 22px;
    }

    .guide-list li + li,
    .guide-ordered li + li {
        margin-top: 7px;
    }

    .guide-columns {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin-top: 16px;
    }

    .guide-panel {
        padding: 16px;
        border: 1px solid var(--guide-border);
        border-radius: 14px;
        background: #fffdf9;
    }

    .guide-panel h4 {
        margin-top: 0;
    }

    .guide-note {
        margin-top: 16px;
        padding: 14px 16px;
        border-left: 4px solid var(--guide-accent);
        border-radius: 10px;
        background: var(--guide-soft);
    }

    .guide-note--warning {
        border-left-color: #b7791f;
        background: #fff9e8;
    }

    .guide-note--success {
        border-left-color: #2f855a;
        background: #edf8f2;
    }

    .guide-note strong {
        color: var(--guide-ink);
    }

    .guide code {
        padding: 2px 6px;
        border-radius: 7px;
        background: #f1ebe2;
        color: #3c3328;
        overflow-wrap: anywhere;
    }

    .guide-code {
        display: block;
        margin: 12px 0;
        padding: 14px 16px;
        border-radius: 12px;
        background: #2f2923;
        color: #fff;
        line-height: 1.55;
        overflow-x: auto;
        white-space: pre-wrap;
    }

    .guide-table-wrap {
        margin-top: 16px;
        overflow-x: auto;
    }

    .guide-table {
        width: 100%;
        min-width: 720px;
        border-collapse: collapse;
    }

    .guide-table th,
    .guide-table td {
        padding: 11px 12px;
        border: 1px solid var(--guide-border);
        vertical-align: top;
        text-align: left;
    }

    .guide-table th {
        background: var(--guide-soft);
        color: var(--guide-ink);
        font-weight: 700;
    }

    .guide-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        background: #edf8f2;
        color: #276749;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    .guide-badge--optional {
        background: #f1f3f5;
        color: #59636e;
    }

    .guide-details {
        border-top: 1px solid var(--guide-border);
    }

    .guide-details:first-of-type {
        margin-top: 8px;
    }

    .guide-details summary {
        padding: 14px 0;
        color: var(--guide-ink);
        cursor: pointer;
        font-weight: 700;
    }

    .guide-details > div {
        padding: 0 0 14px;
    }

    @media (max-width: 900px) {
        .guide-links,
        .guide-flow,
        .guide-columns,
        .guide-toc {
            grid-template-columns: 1fr;
        }

        .guide-flow-item:not(:last-child)::after {
            top: auto;
            right: 50%;
            bottom: -15px;
            transform: translateX(50%) rotate(90deg);
        }
    }
</style>

<div class="guide">
    <section class="guide-hero">
        <h2>Настройка Google Sheets → ActiveCollab</h2>
        <p>
            Эта инструкция поможет полностью настроить интеграцию: подготовить таблицу,
            получить данные ActiveCollab, связать их в SheetCollab и проверить создание
            и обновление задач. Специальные знания Laravel не нужны.
        </p>

        <div class="guide-flow" aria-label="Схема работы интеграции">
            <div class="guide-flow-item">Google Sheets</div>
            <div class="guide-flow-item">Apps Script</div>
            <div class="guide-flow-item">SheetCollab</div>
            <div class="guide-flow-item">ActiveCollab</div>
        </div>

        <div class="guide-links">
            <a class="guide-link" href="{{ route('platform.integrations') }}">Интеграции</a>
            <a class="guide-link" href="{{ route('platform.script') }}">Apps Script</a>
            <a class="guide-link guide-link--secondary" href="{{ route('platform.exports') }}">История экспортов</a>
        </div>
    </section>

    <section class="guide-card">
        <h3>Содержание</h3>
        <ol class="guide-toc">
            <li><a href="#guide-before-start">Что потребуется</a></li>
            <li><a href="#guide-activecollab">Подготовка ActiveCollab</a></li>
            <li><a href="#guide-sheet">Подготовка Google Sheets</a></li>
            <li><a href="#guide-integration">Создание интеграции</a></li>
            <li><a href="#guide-script">Установка Apps Script</a></li>
            <li><a href="#guide-test">Первая проверка</a></li>
            <li><a href="#guide-daily-use">Ежедневная работа</a></li>
            <li><a href="#guide-errors">Ошибки и решения</a></li>
        </ol>
    </section>

    <section class="guide-card" id="guide-before-start">
        <div class="guide-step-title">
            <span class="guide-step-number">1</span>
            <h3>Что потребуется до начала настройки</h3>
        </div>

        <ul class="guide-list">
            <li>Google-аккаунт с правом редактировать нужную таблицу и её Apps Script.</li>
            <li>Аккаунт ActiveCollab с доступом к нужному проекту.</li>
            <li>ActiveCollab API token пользователя, который может создавать и изменять задачи.</li>
            <li>Публичный HTTPS-адрес SheetCollab, например <code>https://sync.example.com</code>.</li>
            <li>Интеграционный токен SheetCollab — значение <code>GOOGLE_SHEETS_INTEGRATION_TOKEN</code>.</li>
        </ul>

        <div class="guide-note guide-note--warning">
            <strong>Важно:</strong> Google Apps Script выполняется на серверах Google и не видит
            <code>http://localhost</code>. Для локальной проверки нужен публичный tunnel,
            например cloudflared или ngrok. В рабочей среде используйте постоянный HTTPS-домен.
        </div>

        <div class="guide-note">
            Здесь используются два разных токена. <strong>ActiveCollab API token</strong>
            хранится только в SheetCollab и даёт доступ к ActiveCollab.
            <strong>Integration token</strong> записывается в Apps Script и разрешает ему
            обращаться к SheetCollab. Не меняйте их местами.
        </div>
    </section>

    <section class="guide-card" id="guide-activecollab">
        <div class="guide-step-title">
            <span class="guide-step-number">2</span>
            <h3>Подготовьте ActiveCollab</h3>
        </div>

        <h4>2.1. Выберите проект</h4>
        <p>
            Откройте в ActiveCollab проект, куда должны попадать задачи из таблицы.
            Скопируйте URL проекта. Число после <code>/projects/</code> — это Project ID.
        </p>
        <span class="guide-code">https://ac.example.com/projects/10
Project ID: 10</span>

        <h4>2.2. Определите основной Task List</h4>
        <p>
            Выберите список задач, который будет использоваться по умолчанию, например
            <code>Bug Tracker</code> или <code>Backlog</code>. При создании интеграции можно:
        </p>
        <ul class="guide-list">
            <li>указать числовой Task List ID;</li>
            <li>указать название существующего списка;</li>
            <li>указать новое название — SheetCollab попробует создать список;</li>
            <li>оставить поле пустым — будет выбран первый доступный список проекта.</li>
        </ul>

        <h4>2.3. Создайте API token</h4>
        <p>
            В профиле пользователя ActiveCollab найдите раздел API или API Subscriptions
            и создайте token. Название раздела может немного отличаться в разных версиях
            ActiveCollab. Пользователь токена должен иметь доступ к выбранному проекту.
        </p>
        <p>Для полной работы ему нужны права:</p>
        <ul class="guide-list">
            <li>просматривать проект и его task lists;</li>
            <li>создавать и изменять задачи;</li>
            <li>просматривать пользователей и labels;</li>
            <li>создавать task lists, только если вы хотите задавать новый список по названию.</li>
        </ul>

        <h4>2.4. Подготовьте ID для маппингов — необязательно</h4>
        <p>
            Маппинги нужны, если разные значения таблицы должны выбирать разные списки,
            исполнителей или метки. Подготовьте соответствующие ActiveCollab ID:
        </p>

        <div class="guide-columns">
            <div class="guide-panel">
                <h4>Пример соответствий</h4>
                <ul class="guide-list">
                    <li><code>backend → Task List 101</code></li>
                    <li><code>frontend → Task List 102</code></li>
                    <li><code>Анна → User 7</code></li>
                    <li><code>critical → Label 12</code></li>
                </ul>
            </div>
            <div class="guide-panel">
                <h4>Где используются значения</h4>
                <ul class="guide-list">
                    <li>Task List — колонка <code>Тип задач</code>;</li>
                    <li>User — колонка <code>Ответственный</code>;</li>
                    <li>Label — колонка <code>Серьезность</code>.</li>
                </ul>
            </div>
        </div>

        <div class="guide-note guide-note--warning">
            Если в строке заполнен <code>Ответственный</code>, его имя должно точно
            присутствовать в маппинге ответственных. Иначе синхронизация завершится ошибкой.
        </div>
    </section>

    <section class="guide-card" id="guide-sheet">
        <div class="guide-step-title">
            <span class="guide-step-number">3</span>
            <h3>Подготовьте Google Sheets</h3>
        </div>

        <p>
            Создайте строку заголовков. Она должна находиться среди первых десяти строк
            таблицы и обязательно содержать колонку <code>Серьезность</code> — по ней
            Apps Script находит заголовки. Порядок колонок не важен.
        </p>

        <div class="guide-table-wrap">
            <table class="guide-table">
                <thead>
                    <tr>
                        <th>Название колонки</th>
                        <th>Обязательность</th>
                        <th>Для чего используется</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>Стенд</code></td>
                        <td><span class="guide-badge">Обязательно</span></td>
                        <td>Окружение: production, stage, test. Попадает в название и описание задачи.</td>
                    </tr>
                    <tr>
                        <td><code>Раздел</code></td>
                        <td><span class="guide-badge">Обязательно</span></td>
                        <td>Раздел продукта, например «Каталог» или «Корзина».</td>
                    </tr>
                    <tr>
                        <td><code>Описание проблемы</code></td>
                        <td><span class="guide-badge">Обязательно</span></td>
                        <td>Основной текст задачи.</td>
                    </tr>
                    <tr>
                        <td><code>Серьезность</code></td>
                        <td><span class="guide-badge">Заголовок обязателен</span></td>
                        <td>Используется в названии задачи, параметрах и маппинге Label.</td>
                    </tr>
                    <tr>
                        <td><code>Тип задач</code></td>
                        <td><span class="guide-badge guide-badge--optional">Необязательно</span></td>
                        <td>Выбирает Task List через маппинг.</td>
                    </tr>
                    <tr>
                        <td><code>Ответственный</code></td>
                        <td><span class="guide-badge guide-badge--optional">Необязательно</span></td>
                        <td>Назначает пользователя ActiveCollab через точный маппинг имени.</td>
                    </tr>
                    <tr>
                        <td><code>Приоритет</code>, <code>Устройство</code>, <code>Разрешение</code></td>
                        <td><span class="guide-badge guide-badge--optional">Необязательно</span></td>
                        <td>Добавляются в параметры задачи.</td>
                    </tr>
                    <tr>
                        <td><code>Скриншот (ссылка)</code></td>
                        <td><span class="guide-badge guide-badge--optional">Необязательно</span></td>
                        <td>Должен содержать корректный URL. Также поддерживается заголовок <code>Скриншот(ссылка)</code>.</td>
                    </tr>
                    <tr>
                        <td><code>Комментарий</code>, <code>Постановщик</code>, <code>Статус</code></td>
                        <td><span class="guide-badge guide-badge--optional">Необязательно</span></td>
                        <td>Добавляются в описание задачи.</td>
                    </tr>
                    <tr>
                        <td><code>Ссылка на задачу</code></td>
                        <td><span class="guide-badge">Нужна для работы</span></td>
                        <td>Apps Script записывает сюда ссылку после создания и читает Task ID при обновлении.</td>
                    </tr>
                    <tr>
                        <td><code>Ошибка синхронизации</code>, <code>Дата синхронизации</code></td>
                        <td><span class="guide-badge guide-badge--optional">Рекомендуется</span></td>
                        <td>Сюда автоматически записываются ошибка и время последней попытки.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h4>Рекомендуемый набор заголовков</h4>
        <span class="guide-code">Ссылка на задачу | Серьезность | Стенд | Раздел | Описание проблемы | Приоритет | Устройство | Разрешение | Скриншот (ссылка) | Комментарий | Тип задач | Ответственный | Постановщик | Статус | Ошибка синхронизации | Дата синхронизации</span>

        <div class="guide-note">
            Для колонок <code>Тип задач</code>, <code>Ответственный</code> и
            <code>Серьезность</code> удобно настроить выпадающие списки Google Sheets.
            Значения в них должны совпадать с ключами маппингов в SheetCollab.
        </div>
    </section>

    <section class="guide-card" id="guide-integration">
        <div class="guide-step-title">
            <span class="guide-step-number">4</span>
            <h3>Создайте интеграцию в SheetCollab</h3>
        </div>

        <ol class="guide-ordered">
            <li>Откройте раздел <a href="{{ route('platform.integrations') }}">Интеграции</a>.</li>
            <li>Нажмите <strong>Создать интеграцию</strong>.</li>
            <li>Заполните вкладку <strong>Основные настройки</strong>.</li>
            <li>При необходимости заполните вкладку <strong>Маппинги</strong>.</li>
            <li>Включите флаг <strong>Интеграция включена</strong> и сохраните запись.</li>
        </ol>

        <div class="guide-table-wrap">
            <table class="guide-table">
                <thead>
                    <tr>
                        <th>Поле</th>
                        <th>Что указать</th>
                        <th>Пример</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Название</td>
                        <td>Понятное внутреннее название интеграции.</td>
                        <td><code>Баги интернет-магазина</code></td>
                    </tr>
                    <tr>
                        <td>Google Sheets ID или URL</td>
                        <td>Полную ссылку на таблицу или ID между <code>/d/</code> и <code>/edit</code>.</td>
                        <td><code>https://docs.google.com/spreadsheets/d/...</code></td>
                    </tr>
                    <tr>
                        <td>ActiveCollab API Base URL</td>
                        <td>Адрес ActiveCollab. Суффикс <code>/api/v1</code> добавится автоматически.</td>
                        <td><code>https://ac.example.com/api/v1</code></td>
                    </tr>
                    <tr>
                        <td>Project ID или URL</td>
                        <td>Числовой ID или полную ссылку на проект.</td>
                        <td><code>10</code></td>
                    </tr>
                    <tr>
                        <td>Task List ID или название</td>
                        <td>Основной список задач. Можно оставить пустым для автоматического выбора.</td>
                        <td><code>Bug Tracker</code></td>
                    </tr>
                    <tr>
                        <td>ActiveCollab API Token</td>
                        <td>Token пользователя ActiveCollab. Можно оставить пустым, если он задан глобально на сервере.</td>
                        <td><code>••••••••</code></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h4>Как заполнять маппинги</h4>
        <ul class="guide-list">
            <li>
                <strong>Task List:</strong> слева значение из <code>Тип задач</code>
                в нижнем регистре, справа числовой Task List ID. Например
                <code>backend → 101</code>. Значение в таблице при сравнении автоматически
                приводится к нижнему регистру и очищается от крайних пробелов.
            </li>
            <li>
                <strong>Ответственные:</strong> слева точное имя из таблицы, справа User ID.
                Здесь регистр и написание имеют значение.
            </li>
            <li>
                <strong>Метки:</strong> слева серьёзность в нижнем регистре, справа Label ID.
                Например <code>critical → 12</code>.
            </li>
        </ul>

        <div class="guide-note guide-note--success">
            Если Task List задан названием или поле оставлено пустым, при сохранении
            SheetCollab обращается к ActiveCollab, находит подходящий список и при
            необходимости создаёт его. Если сохранение завершилось ошибкой, сначала
            проверьте URL, Project ID, token и права пользователя ActiveCollab.
        </div>
    </section>

    <section class="guide-card" id="guide-script">
        <div class="guide-step-title">
            <span class="guide-step-number">5</span>
            <h3>Установите Apps Script в таблицу</h3>
        </div>

        <ol class="guide-ordered">
            <li>
                В SheetCollab откройте раздел <a href="{{ route('platform.script') }}">Apps Script</a>
                и скачайте <code>activecollab_sync.gs</code>.
            </li>
            <li>
                Откройте нужную Google-таблицу и выберите
                <strong>Расширения → Apps Script</strong>.
            </li>
            <li>
                Откройте файл <code>Code.gs</code>, удалите пример кода и вставьте всё
                содержимое скачанного файла. Сохраните проект.
            </li>
            <li>
                В Apps Script откройте <strong>Настройки проекта → Свойства скрипта</strong>.
            </li>
            <li>Добавьте два свойства из таблицы ниже.</li>
        </ol>

        <div class="guide-table-wrap">
            <table class="guide-table">
                <thead>
                    <tr>
                        <th>Свойство</th>
                        <th>Значение</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>INTEGRATION_SERVICE_URL</code></td>
                        <td>Публичный адрес SheetCollab без <code>/admin</code>, без <code>/api/...</code> и без завершающего слеша. Например <code>https://sync.example.com</code>.</td>
                    </tr>
                    <tr>
                        <td><code>INTEGRATION_SERVICE_TOKEN</code></td>
                        <td>Точное значение <code>GOOGLE_SHEETS_INTEGRATION_TOKEN</code>, настроенное на сервере SheetCollab.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ol class="guide-ordered" start="6">
            <li>Вернитесь в таблицу и полностью обновите страницу.</li>
            <li>В верхнем меню должен появиться пункт <strong>ActiveCollab</strong>.</li>
            <li>
                При первом запуске Google попросит разрешения. Выберите свой аккаунт и
                разрешите скрипту читать таблицу и выполнять внешние HTTP-запросы.
            </li>
        </ol>

        <div class="guide-note">
            В Apps Script хранится только integration token. Никогда не вставляйте туда
            ActiveCollab API token и не записывайте его в ячейки таблицы.
        </div>
    </section>

    <section class="guide-card" id="guide-uuid">
        <h3>Подготовьте UUID перед использованием нового скрипта</h3>
        <p>Администратор сначала останавливает синхронизацию и редактирование таблицы, отключает интеграцию,
            проверяет существующие ссылки и выполняет контролируемый перенос с dry-run и аудитом.
            Это требуется и для новой таблицы без истории. Одна установка скрипта не переводит сервер в UUID-режим.</p>
        <p>Рабочая скачиваемая версия пока хранится в БД. Сохраните её копию, сверьте свои изменения с новым
            шаблоном и обновите проверенную версию в редакторе перед скачиванием.</p>
        <p>Колонка <code>SheetCollab UUID</code> создаётся автоматически и скрывается.
            Сортируйте и перемещайте <strong>полные строки вместе с этой колонкой</strong>.
            Сортировка только бизнес-колонок нарушает связь. Не меняйте UUID вручную.</p>
        <p>Копия с тем же UUID блокирует отправку. Для отдельной новой задачи выберите на копии
            «Новая запись из копии»: изменится только её UUID, ссылка и результат будут очищены.
            Для переноса используйте меню «Подготовить UUID для переноса»; оно не отправляет задач.</p>
        <p>После запроса результат записывается по UUID, даже если строка переместилась на другой лист.
            Удалённая строка или дубликат UUID требуют сверки истории. Скриптовая блокировка не запрещает
            ручные правки Google Sheets в момент записи ответа. Неизвестный результат и недоступная задача
            не разрешают автоматическое повторное создание.</p>
    </section>

    <section class="guide-card" id="guide-test">
        <div class="guide-step-title">
            <span class="guide-step-number">6</span>
            <h3>Выполните первую проверку</h3>
        </div>

        <ol class="guide-ordered">
            <li>Создайте тестовую строку ниже строки заголовков.</li>
            <li>Заполните минимум <code>Стенд</code>, <code>Раздел</code> и <code>Описание проблемы</code>.</li>
            <li>Если заполняете тип, ответственного или серьёзность, используйте значения из настроенных маппингов.</li>
            <li>Кликните любую ячейку тестовой строки.</li>
            <li>Выберите <strong>ActiveCollab → Создать задачу</strong>.</li>
        </ol>

        <p>При успешной настройке произойдёт следующее:</p>
        <ul class="guide-list">
            <li>появится сообщение с ID созданной задачи;</li>
            <li>в колонке <code>Ссылка на задачу</code> появится ссылка «Открыть задачу»;</li>
            <li>в ActiveCollab появится задача в нужном проекте и Task List;</li>
            <li>в <a href="{{ route('platform.exports') }}">Истории экспортов</a> появится статус <strong>Создана</strong>;</li>
            <li>колонка ошибки очистится, а дата синхронизации обновится.</li>
        </ul>

        <h4>Проверка обновления</h4>
        <p>
            Измените описание или комментарий в той же строке и выберите
            <strong>ActiveCollab → Обновить задачу</strong>. Скрипт возьмёт Task ID из
            сохранённой ссылки. В истории экспорта статус изменится на <strong>Обновлена</strong>.
        </p>
    </section>

    <section class="guide-card" id="guide-daily-use">
        <div class="guide-step-title">
            <span class="guide-step-number">7</span>
            <h3>Как работать после настройки</h3>
        </div>

        <div class="guide-columns">
            <div class="guide-panel">
                <h4>Создание задачи</h4>
                <ol class="guide-ordered">
                    <li>Заполните новую строку.</li>
                    <li>Выберите её.</li>
                    <li>Нажмите <strong>Создать задачу</strong>.</li>
                    <li>Не удаляйте созданную ссылку.</li>
                </ol>
            </div>
            <div class="guide-panel">
                <h4>Обновление задачи</h4>
                <ol class="guide-ordered">
                    <li>Измените данные существующей строки.</li>
                    <li>Убедитесь, что ссылка на задачу сохранена.</li>
                    <li>Нажмите <strong>Обновить задачу</strong>.</li>
                </ol>
            </div>
        </div>

        <div class="guide-note guide-note--warning">
            Одна строка считается одной задачей. Не копируйте ссылку на задачу между
            строками и не используйте команду создания повторно для уже
            синхронизированной строки.
        </div>
    </section>

    <section class="guide-card" id="guide-errors">
        <div class="guide-step-title">
            <span class="guide-step-number">8</span>
            <h3>Частые ошибки и решения</h3>
        </div>

        <details class="guide-details">
            <summary>Меню ActiveCollab не появилось в таблице</summary>
            <div>
                <p>
                    Проверьте, что код сохранён в Apps Script именно этой таблицы, затем
                    полностью обновите страницу Google Sheets. В коде должна присутствовать
                    функция <code>onOpen()</code>.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>«Строка заголовков не найдена»</summary>
            <div>
                <p>
                    Заголовок <code>Серьезность</code> должен быть написан точно так и
                    находиться среди первых десяти строк. Проверьте лишние пробелы и
                    объединённые ячейки.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>«Не настроены Script Properties»</summary>
            <div>
                <p>
                    Добавьте оба свойства: <code>INTEGRATION_SERVICE_URL</code> и
                    <code>INTEGRATION_SERVICE_TOKEN</code>. Проверьте регистр символов
                    в названиях и отсутствие лишних пробелов в значениях.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>401 Unauthorized</summary>
            <div>
                <p>
                    Значение <code>INTEGRATION_SERVICE_TOKEN</code> не совпадает с
                    <code>GOOGLE_SHEETS_INTEGRATION_TOKEN</code> на сервере SheetCollab.
                    После изменения серверной конфигурации очистите Laravel config cache.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>404 — интеграция для таблицы не найдена</summary>
            <div>
                <p>
                    Проверьте Google Sheets ID в интеграции и флаг активности. Интеграция
                    должна быть создана именно для текущей таблицы, а не только для листа
                    с похожим названием.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>Ошибка маппинга типа задачи или ответственного</summary>
            <div>
                <p>
                    Сравните значение ячейки с ключом маппинга. Для ответственного нужно
                    точное совпадение. Ключ типа задачи в маппинге должен быть записан
                    в нижнем регистре. Справа должен быть числовой ID из ActiveCollab.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>409 — задача уже создана</summary>
            <div>
                <p>
                    SheetCollab уже знает задачу для этой строки и защищает её от
                    дублирования. Если API вернул ссылку, Apps Script восстановит её в
                    таблице. Для изменения используйте команду обновления.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>Сетевая ошибка или Google Apps Script не видит сервис</summary>
            <div>
                <p>
                    Откройте <code>INTEGRATION_SERVICE_URL</code> в браузере. Адрес должен
                    быть публичным и использовать HTTPS. <code>localhost</code>, внутренний
                    IP и адрес Docker-контейнера для Google недоступны.
                </p>
            </div>
        </details>

        <details class="guide-details">
            <summary>ActiveCollab отвечает 403, 404 или 500</summary>
            <div>
                <p>
                    Проверьте API Base URL, Project ID, token и права пользователя.
                    Убедитесь, что token относится к тому экземпляру ActiveCollab, URL
                    которого указан в интеграции.
                </p>
            </div>
        </details>
    </section>

    <section class="guide-card">
        <h3>Финальный чек-лист</h3>
        <ul class="guide-list">
            <li>В ActiveCollab выбран проект, Task List и создан API token.</li>
            <li>В первых десяти строках Google Sheets есть корректная строка заголовков.</li>
            <li>В SheetCollab создана и включена интеграция для этой таблицы.</li>
            <li>Маппинги совпадают со значениями выпадающих списков таблицы.</li>
            <li>Apps Script установлен и содержит два Script Properties.</li>
            <li>Публичный URL SheetCollab доступен по HTTPS.</li>
            <li>Тестовая задача успешно создаётся и обновляется.</li>
        </ul>
    </section>
    <section class="guide-card" id="recovery">
        <h3>Восстановление неизвестного результата</h3>
        <p>Если результат отправки неизвестен, повторное создание блокируется. В «Истории экспортов» администратор открывает «Проверить». Для этого нужны права восстановления и управления пользователями и ролями.</p>
        <ol>
            <li>Остановите старые отправляющие процессы и проверьте ActiveCollab. Таймаут или ответ «не найдено» не доказывают отсутствия задачи.</li>
            <li>Если задача существует, укажите её ID и подтвердите привязку в выбранном проекте. Проверка связи не обновляет содержимое задачи.</li>
            <li>Если результат создания действительно отсутствует, явно подтвердите это с причиной. Задача сейчас не создаётся: разрешается следующая отдельная попытка.</li>
            <li>Для недоступной связанной задачи «Создать замену» — отдельное подтверждаемое действие. Проверьте выбранный сохранённый источник: используются текущие маппинги, данные могут отличаться от Sheets.</li>
        </ol>
        <p>При неполном старом источнике после разрешения неизвестности отправьте актуальные данные через обычное «Обновить» из Sheets. Ответ о недоступной задаче сохранит новый источник без внешнего обновления; выберите эту попытку на экране восстановления.</p>
        <p>После замены обновите ссылку в Sheets перед следующим обновлением задачи. Если результат замены неизвестен, повторное создание снова блокируется: сначала требуется новая сверка. Не удаляйте запись истории и не сбрасывайте блокировку вручную.</p>
    </section>
</div>
