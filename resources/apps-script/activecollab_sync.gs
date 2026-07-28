
// Имена заголовков колонок в таблице (используются как ключи для поиска нужного столбца)
const COL = {
  LINK:        'Ссылка на задачу',
  SEVERITY:    'Серьезность',
  ENV:         'Стенд',
  SECTION:     'Раздел',
  DESCRIPTION: 'Описание проблемы',
  PRIORITY:    'Приоритет',
  DEVICE:      'Устройство',
  RESOLUTION:  'Разрешение',
  SCREENSHOT:  'Скриншот (ссылка)',
  SCREENSHOT2: 'Скриншот(ссылка)',  // альтернативное написание заголовка, проверяется как запасной вариант
  COMMENT:     'Комментарий',
  TASK_TYPE:   'Тип задач',
  RESPONSIBLE: 'Ответственный',
  REPORTER:    'Постановщик',
  STATUS:      'Статус',
  ERROR:       'Ошибка синхронизации',
  SYNC_DATE:   'Дата синхронизации',
};

const HTTP_CONFLICT  = 409;
const HTTP_NOT_FOUND = 404;
const HTTP_OK_MIN    = 200;  // успешные коды: [200, 300)
const HTTP_OK_MAX    = 300;

// Сколько строк сверху просматривать при поиске заголовка таблицы
const HEADER_SEARCH_ROWS = 10;

const TASKS_API_PATH = '/api/integrations/activecollab/tasks';

// ── Точки входа (привязаны к меню) ───────────────────────────────────────────

/**
 * Создаёт задачу в ActiveCollab по данным из активной строки таблицы.
 * Вызывается через меню «ActiveCollab → Создать задачу».
 */
function createTaskFromRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  const { col, activeRow } = resolveSheetContext(sheet);
  if (!col || !activeRow) return;

  // Колонка для ссылки должна присутствовать в таблице
  if (!col[COL.LINK]) {
    SpreadsheetApp.getUi().alert('В таблице отсутствует колонка «Ссылка на задачу». Добавьте её после колонки «Статус».');
    return;
  }

  // Если ссылка уже есть — задача уже создана, повторно не создаём
  const existingLink = sheet.getRange(activeRow, col[COL.LINK]).getValue();
  if (existingLink) {
    SpreadsheetApp.getUi().alert('Задача уже создана. Для обновления используйте «Обновить задачу».');
    return;
  }

  const payload = buildPayload(sheet, activeRow, col);
  if (!payload) return;

  const { serviceUrl, serviceToken } = getServiceConfig();
  if (!serviceUrl) return;

  const { code, result, networkError } = fetchApi(serviceUrl + TASKS_API_PATH, 'post', serviceToken, payload);
  if (networkError) {
    writeError(sheet, activeRow, col, networkError);
    SpreadsheetApp.getUi().alert('Ошибка сети: ' + networkError);
    return;
  }

  // 409 — задача уже существует на сервере (например, создана не через таблицу)
  if (code === HTTP_CONFLICT) {
    const taskUrl = result && result.data ? result.data.task_url : null;
    if (taskUrl) writeTaskLink(sheet, activeRow, col, taskUrl);
    SpreadsheetApp.getUi().alert('Задача уже создана.\n' + (taskUrl || ''));
    return;
  }

  // 404 — интеграционный сервис не знает об этой таблице
  if (code === HTTP_NOT_FOUND) {
    SpreadsheetApp.getUi().alert('Интеграция не настроена для этой таблицы. Обратитесь к администратору.');
    return;
  }

  if (handleCommonErrors(sheet, activeRow, col, code, result)) return;

  const taskId  = result.data.task_id;
  const taskUrl = result.data.task_url;

  writeTaskLink(sheet, activeRow, col, taskUrl);
  writeError(sheet, activeRow, col, '');  // очищаем предыдущую ошибку, фиксируем дату синхронизации
  SpreadsheetApp.getUi().alert('Задача создана!\nID: ' + taskId + '\n' + taskUrl);
}

/**
 * Обновляет существующую задачу в ActiveCollab данными из активной строки.
 * Вызывается через меню «ActiveCollab → Обновить задачу».
 */
function updateTaskFromRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  const { col, activeRow } = resolveSheetContext(sheet);
  if (!col || !activeRow) return;

  if (!col[COL.LINK]) {
    SpreadsheetApp.getUi().alert('В таблице отсутствует колонка «Ссылка на задачу».');
    return;
  }

  // ID задачи извлекается из гиперссылки, сохранённой в ячейке LINK
  const taskId = extractTaskId(sheet, activeRow, col);
  if (!taskId) {
    SpreadsheetApp.getUi().alert('Задача ещё не создана. Используйте «Создать задачу».');
    return;
  }

  const payload = buildPayload(sheet, activeRow, col);
  if (!payload) return;

  const { serviceUrl, serviceToken } = getServiceConfig();
  if (!serviceUrl) return;

  const { code, result, networkError } = fetchApi(serviceUrl + TASKS_API_PATH + '/' + taskId, 'put', serviceToken, payload);
  if (networkError) {
    writeError(sheet, activeRow, col, networkError);
    SpreadsheetApp.getUi().alert('Ошибка сети: ' + networkError);
    return;
  }

  // 404 — задача удалена из ActiveCollab после того, как была создана через таблицу
  if (code === HTTP_NOT_FOUND) {
    SpreadsheetApp.getUi().alert('Задача #' + taskId + ' не найдена в ActiveCollab.');
    return;
  }

  if (handleCommonErrors(sheet, activeRow, col, code, result)) return;

  writeError(sheet, activeRow, col, '');  // очищаем предыдущую ошибку, фиксируем дату синхронизации
  SpreadsheetApp.getUi().alert('Задача #' + taskId + ' обновлена!\n' + result.data.task_url);
}

// ── Вспомогательные функции ───────────────────────────────────────────────────

/**
 * Выполняет HTTP-запрос к интеграционному сервису.
 * Возвращает { code, result } при успехе или { networkError } при исключении сети.
 */
function fetchApi(url, method, token, body) {
  try {
    const response = UrlFetchApp.fetch(url, {
      method:             method,
      contentType:        'application/json',
      headers:            { 'Authorization': 'Bearer ' + token },
      payload:            JSON.stringify(body),
      muteHttpExceptions: true,  // GAS не бросает исключение на 4xx/5xx — код проверяем сами
    });
    return { code: response.getResponseCode(), result: safeJson(response.getContentText()) };
  } catch (e) {
    return { networkError: e.message };
  }
}

/** JSON.parse с возвратом null при ошибке — устраняет повторный try/catch в вызывающем коде. */
function safeJson(text) {
  try { return JSON.parse(text); } catch (_) { return null; }
}

/**
 * Обрабатывает все не-2xx ответы и некорректные тела ответа.
 * Возвращает true, если обнаружена ошибка (вызывающий должен прервать выполнение).
 */
function handleCommonErrors(sheet, row, col, code, result) {
  if (code < HTTP_OK_MIN || code >= HTTP_OK_MAX) {
    const msg = result && result.message ? result.message : 'HTTP ' + code;
    writeError(sheet, row, col, msg);
    SpreadsheetApp.getUi().alert('Ошибка: ' + msg);
    return true;
  }
  if (!result || !result.data) {
    writeError(sheet, row, col, 'Некорректный ответ сервера');
    SpreadsheetApp.getUi().alert('Ошибка: сервер вернул некорректный ответ');
    return true;
  }
  return false;
}

/**
 * Сканирует первые HEADER_SEARCH_ROWS строк в поисках строки заголовков
 * (определяется по наличию ячейки COL.SEVERITY), затем строит карту
 * «имя колонки → 1-based индекс столбца».
 * Также проверяет, что активная ячейка находится ниже строки заголовков.
 */
function resolveSheetContext(sheet) {
  const lastCol     = sheet.getLastColumn();
  const searchRange = sheet.getRange(1, 1, HEADER_SEARCH_ROWS, lastCol).getValues();

  let headerRowIndex = -1;
  for (let i = 0; i < searchRange.length; i++) {
    if (searchRange[i].some(cell => String(cell).trim() === COL.SEVERITY)) {
      headerRowIndex = i;
      break;
    }
  }

  if (headerRowIndex === -1) {
    SpreadsheetApp.getUi().alert('Строка заголовков не найдена');
    return { col: null, activeRow: null };
  }

  // Строим карту: имя заголовка → 1-based номер столбца
  const col = {};
  searchRange[headerRowIndex].forEach((h, i) => { if (h) col[String(h).trim()] = i + 1; });

  const activeRow = sheet.getActiveRange().getRow();
  if (activeRow <= headerRowIndex + 1) {
    SpreadsheetApp.getUi().alert('Выберите строку с данными (не заголовок)');
    return { col: null, activeRow: null };
  }

  return { col, activeRow };
}

/**
 * Читает все нужные ячейки из activeRow и формирует тело API-запроса.
 * Возвращает null, если хотя бы одно из обязательных полей (ENV, SECTION, DESCRIPTION) пусто.
 */
function buildPayload(sheet, activeRow, col) {
  // Читает ячейку по имени колонки, возвращает обрезанную строку или ''
  const get = (name) => {
    const c = col[name];
    return c ? String(sheet.getRange(activeRow, c).getValue()).trim() : '';
  };

  const environment = get(COL.ENV);
  const section     = get(COL.SECTION);
  const description = get(COL.DESCRIPTION);

  const missing = [];
  if (!environment) missing.push(COL.ENV);
  if (!section)     missing.push(COL.SECTION);
  if (!description) missing.push(COL.DESCRIPTION);
  if (missing.length) {
    SpreadsheetApp.getUi().alert('Не заполнены обязательные поля: ' + missing.join(', '));
    return null;
  }

  const spreadsheetId = SpreadsheetApp.getActiveSpreadsheet().getId();
  const sheetId       = String(sheet.getSheetId());

  return {
    spreadsheet_id: spreadsheetId,
    sheet_id:       sheetId,
    row_number:     activeRow,
    severity:       get(COL.SEVERITY)    || null,
    priority:       get(COL.PRIORITY)    || null,
    device:         get(COL.DEVICE)      || null,
    resolution:     get(COL.RESOLUTION)  || null,
    environment:    environment,
    section:        section,
    description:    description,
    // Два возможных написания заголовка скриншота — берётся первый непустой
    screenshot:     get(COL.SCREENSHOT)  || get(COL.SCREENSHOT2) || null,
    comment:        get(COL.COMMENT)     || null,
    task_type:      get(COL.TASK_TYPE)   || null,
    responsible:    get(COL.RESPONSIBLE) || null,
    reporter:       get(COL.REPORTER)    || null,
    status:         get(COL.STATUS)      || null,
  };
}

/**
 * Извлекает числовой ID задачи из гиперссылки в ячейке LINK.
 * Ожидаемый формат URL: …/tasks/<id>.
 */
function extractTaskId(sheet, row, col) {
  const c = col[COL.LINK];
  if (!c) return null;
  const richText = sheet.getRange(row, c).getRichTextValue();
  const url      = richText ? richText.getLinkUrl() : null;
  if (!url) return null;
  const match = url.match(/\/tasks\/(\d+)/);
  return match ? parseInt(match[1], 10) : null;
}

/** Читает INTEGRATION_SERVICE_URL и INTEGRATION_SERVICE_TOKEN из Script Properties. */
function getServiceConfig() {
  const props        = PropertiesService.getScriptProperties();
  const serviceUrl   = props.getProperty('INTEGRATION_SERVICE_URL');
  const serviceToken = props.getProperty('INTEGRATION_SERVICE_TOKEN');

  if (!serviceUrl || !serviceToken) {
    SpreadsheetApp.getUi().alert('Не настроены Script Properties: INTEGRATION_SERVICE_URL, INTEGRATION_SERVICE_TOKEN');
    return { serviceUrl: null, serviceToken: null };
  }

  return { serviceUrl, serviceToken };
}

/** Записывает гиперссылку «Открыть задачу» в ячейку LINK нужной строки. */
function writeTaskLink(sheet, row, col, taskUrl) {
  const c = col[COL.LINK];
  if (!c) return;
  const richText = SpreadsheetApp.newRichTextValue()
    .setText('Открыть задачу')
    .setLinkUrl(taskUrl)
    .build();
  sheet.getRange(row, c).setRichTextValue(richText);
}

/**
 * Записывает сообщение об ошибке (или '' для очистки) в колонку ERROR
 * и фиксирует текущее время в SYNC_DATE.
 * Вызывается и при ошибке, и при успехе — SYNC_DATE всегда отражает время последней попытки.
 */
function writeError(sheet, row, col, message) {
  if (col[COL.ERROR]) {
    sheet.getRange(row, col[COL.ERROR]).setValue(message);
  }
  if (col[COL.SYNC_DATE]) {
    sheet.getRange(row, col[COL.SYNC_DATE]).setValue(new Date().toISOString());
  }
}

/** Добавляет меню «ActiveCollab» при открытии таблицы. */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('ActiveCollab')
    .addItem('Создать задачу', 'createTaskFromRow')
    .addItem('Обновить задачу', 'updateTaskFromRow')
    .addToUi();
}
