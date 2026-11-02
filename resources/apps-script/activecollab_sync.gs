// UUID travels with the FULL row, including this hidden technical column.
// Before using this version, complete the inactive integration's UUID migration.
const COL = {
  UUID: 'SheetCollab UUID', LINK: 'Ссылка на задачу', SEVERITY: 'Серьезность',
  ENV: 'Стенд', SECTION: 'Раздел', DESCRIPTION: 'Описание проблемы', PRIORITY: 'Приоритет',
  DEVICE: 'Устройство', RESOLUTION: 'Разрешение', SCREENSHOT: 'Скриншот (ссылка)', SCREENSHOT2: 'Скриншот(ссылка)',
  COMMENT: 'Комментарий', TASK_TYPE: 'Тип задач', RESPONSIBLE: 'Ответственный', REPORTER: 'Постановщик',
  STATUS: 'Статус', ERROR: 'Ошибка синхронизации', SYNC_DATE: 'Дата синхронизации', ATTEMPT_DATE: 'Дата попытки',
};
const HEADER_SEARCH_ROWS = 10;
const TASKS_API_PATH = '/api/tasks/sync';
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function syncTaskFromRow() { sendRow(); }
function createTaskFromRow() { syncTaskFromRow(); }
function updateTaskFromRow() { syncTaskFromRow(); }

function withDocumentLock(callback) {
  const lock = LockService.getDocumentLock();
  if (!lock || !lock.tryLock(5000)) throw new Error('Таблица занята. Повторите действие после завершения другого запуска.');
  try { return callback(); } finally { lock.releaseLock(); }
}

function sendRow() {
  try {
    const config = getServiceConfig();
    const prepared = withDocumentLock(() => {
      const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
      const context = sheetContext(sheet);
      if (!context) throw new Error('Строка заголовков не найдена.');
      const row = sheet.getActiveRange().getRow();
      if (row <= context.headerRow) throw new Error('Выберите строку с данными.');
      requireColumns(context.col);
      ensureUuidColumn(sheet, context);
      // Re-read after the document lock was acquired, never replace an existing identity.
      const uuid = ensureRowUuid(sheet, row, context.col);
      SpreadsheetApp.flush();
      if (findUuidRows(uuid).length !== 1) throw new Error('Дубликат SheetCollab UUID. Для копии выполните «Новая запись из копии».');
      const link = sheet.getRange(row, context.col[COL.LINK]).getValue();
      const taskId = parseTaskId(taskLinkUrl(sheet, row, context.col));
      if (link && !taskId) throw new Error('Нет корректной ссылки на задачу. Проверьте существующую привязку.');
      const payload = buildPayload(sheet, row, context.col);
      payload.record_uuid = uuid;
      return { uuid, payload };
    });
    const response = fetchApi(config.serviceUrl + TASKS_API_PATH, 'post', config.serviceToken, prepared.payload);
    const data = response.result && response.result.data;
    const validTask = data && Number.isSafeInteger(data.task_id) && data.task_id > 0
      && parseTaskId(data.task_url) === data.task_id && data.record_uuid === prepared.uuid
      && ['created', 'updated', 'skipped'].includes(data.action)
      && /^[0-9a-f]{64}$/.test(data.sync_hash || '') && UUID_PATTERN.test(data.operation_id || '')
      && Number.isFinite(Date.parse(data.synced_at)) && Number.isFinite(Date.parse(data.attempted_at || data.synced_at));
    const duplicate = false;
    const success = response.code >= 200 && response.code < 300 && validTask;
    let message = '';
    if (!success && !duplicate) {
      message = response.networkError || (response.result && response.result.error && response.result.error.message)
        || (response.result && response.result.message) || ('Некорректный ответ сервера (HTTP ' + response.code + ').');
      message = String(message).split(config.serviceToken).join('[REDACTED]').slice(0, 1000);
    }
    withDocumentLock(() => {
      const matches = findUuidRows(prepared.uuid);
      if (matches.length !== 1) throw new Error('Строка удалена или UUID продублирован. Ответ не записан; проверьте историю сервиса.');
      const target = matches[0];
      requireColumns(target.col);
      if (String(target.sheet.getRange(target.row, target.col[COL.UUID]).getValue()).trim().toLowerCase() !== prepared.uuid) {
        throw new Error('Строка изменилась перед записью ответа. Проверьте историю сервиса.');
      }
      if (success || duplicate) writeTaskLink(target.sheet, target.row, target.col, data.task_url);
      writeError(target.sheet, target.row, target.col, message, success ? data.synced_at : null,
        success ? data.attempted_at || data.synced_at : null);
      SpreadsheetApp.flush();
    });
    SpreadsheetApp.getUi().alert(message || (data.action === 'skipped' ? 'Изменений нет.\n' : 'Синхронизация завершена.\n') + data.task_url);
  } catch (error) {
    SpreadsheetApp.getUi().alert(error.message);
  }
}

function fetchApi(url, method, token, body) {
  try {
    const response = UrlFetchApp.fetch(url, {
      method, contentType: 'application/json', headers: { Authorization: 'Bearer ' + token },
      payload: JSON.stringify(body), muteHttpExceptions: true,
    });
    let result = null;
    try { result = JSON.parse(response.getContentText()); } catch (_) { /* Invalid success is not a confirmed result. */ }
    return { code: response.getResponseCode(), result };
  } catch (_) {
    // Exception text can contain credentials or request details; never display it.
    return { networkError: 'Сетевая ошибка. Результат не подтверждён; проверьте историю сервиса перед повтором.' };
  }
}

function sheetContext(sheet) {
  const lastCol = sheet.getLastColumn();
  if (!lastCol) return null;
  const rows = sheet.getRange(1, 1, Math.min(HEADER_SEARCH_ROWS, sheet.getMaxRows()), lastCol).getValues();
  const index = rows.findIndex(row => row.some(cell => String(cell).trim() === COL.SEVERITY));
  if (index < 0) {
    if (rows.some(row => row.some(cell => String(cell).trim() === COL.UUID))) throw new Error('Лист с UUID не содержит корректных заголовков.');
    return null;
  }
  const col = Object.create(null);
  rows[index].forEach((header, i) => {
    const name = String(header).trim();
    if (!name) return;
    if (col[name]) throw new Error('Повторяющийся заголовок: ' + name);
    col[name] = i + 1;
  });
  return { col, headerRow: index + 1 };
}

function requireColumns(col) {
  for (const name of [COL.ENV, COL.SECTION, COL.DESCRIPTION, COL.LINK]) {
    if (!col[name]) throw new Error('Отсутствует колонка «' + name + '».');
  }
}

function ensureUuidColumn(sheet, context) {
  if (!context.col[COL.UUID]) {
    const column = sheet.getLastColumn() + 1;
    if (column > sheet.getMaxColumns()) sheet.insertColumnAfter(sheet.getMaxColumns());
    sheet.getRange(context.headerRow, column).setValue(COL.UUID);
    context.col[COL.UUID] = column;
  }
  sheet.hideColumns(context.col[COL.UUID]);
}

function ensureRowUuid(sheet, row, col) {
  const cell = sheet.getRange(row, col[COL.UUID]);
  let uuid = String(cell.getValue()).trim();
  if (!uuid) { uuid = Utilities.getUuid().toLowerCase(); cell.setValue(uuid); }
  if (!UUID_PATTERN.test(uuid)) throw new Error('Некорректный SheetCollab UUID. Не меняйте идентификатор существующей записи.');
  return uuid.toLowerCase();
}

// Scan the whole spreadsheet, since a row can move to a different tab during HTTP.
function findUuidRows(uuid) {
  return uuidRows().filter(target => target.uuid === uuid);
}

function uuidRows() {
  const rows = [];
  for (const sheet of SpreadsheetApp.getActiveSpreadsheet().getSheets()) {
    const context = sheetContext(sheet);
    if (!context || !context.col[COL.UUID]) continue;
    const count = sheet.getLastRow() - context.headerRow;
    if (count <= 0) continue;
    sheet.getRange(context.headerRow + 1, context.col[COL.UUID], count, 1).getValues().forEach((cells, i) => {
      const uuid = String(cells[0]).trim().toLowerCase();
      if (!uuid) return;
      if (!UUID_PATTERN.test(uuid)) throw new Error('Некорректный UUID в таблице. Требуется ручная сверка.');
      rows.push({ sheet, col: context.col, row: context.headerRow + 1 + i, uuid });
    });
  }
  return rows;
}

function newRecordFromCopy() {
  const ui = SpreadsheetApp.getUi();
  if (ui.alert('Новая запись из копии', 'Назначить выбранной строке новый UUID и очистить ссылку? Это позволит создать отдельную задачу.', ui.ButtonSet.YES_NO) !== ui.Button.YES) return;
  try {
    withDocumentLock(() => {
      const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
      const context = sheetContext(sheet);
      if (!context) throw new Error('Строка заголовков не найдена.');
      const row = sheet.getActiveRange().getRow();
      if (row <= context.headerRow) throw new Error('Выберите строку с данными.');
      requireColumns(context.col);
      ensureUuidColumn(sheet, context);
      sheet.getRange(row, context.col[COL.UUID]).setValue(Utilities.getUuid().toLowerCase());
      for (const name of [COL.LINK, COL.ERROR, COL.SYNC_DATE]) {
        if (context.col[name]) sheet.getRange(row, context.col[name]).clearContent();
      }
      SpreadsheetApp.flush();
    });
  } catch (error) { ui.alert(error.message); }
}

function prepareUuidMigration() {
  try {
    const manifest = withDocumentLock(() => {
      const records = [];
      for (const sheet of SpreadsheetApp.getActiveSpreadsheet().getSheets()) {
        const context = sheetContext(sheet);
        if (!context) continue;
        requireColumns(context.col);
        ensureUuidColumn(sheet, context);
        const count = sheet.getLastRow() - context.headerRow;
        if (count <= 0) continue;
        const values = sheet.getRange(context.headerRow + 1, 1, count, sheet.getLastColumn()).getValues();
        values.forEach((cells, i) => {
          if (!Object.values(COL).some(name => context.col[name] && String(cells[context.col[name] - 1] || '').trim())) return;
          const row = context.headerRow + 1 + i;
          const uuid = ensureRowUuid(sheet, row, context.col);
          const linkValue = sheet.getRange(row, context.col[COL.LINK]).getValue();
          const taskUrl = taskLinkUrl(sheet, row, context.col);
          const taskId = parseTaskId(taskUrl);
          if (linkValue && !taskId) throw new Error('Некорректная task-ссылка. Сверьте её перед переносом.');
          records.push({ record_uuid: uuid, sheet_id: String(sheet.getSheetId()), row_number: row, task_id: taskId, task_url: taskUrl || null });
        });
      }
      SpreadsheetApp.flush();
      const seen = new Set();
      for (const target of uuidRows()) {
        if (seen.has(target.uuid)) throw new Error('Дубликат UUID. Сверьте копии до переноса.');
        seen.add(target.uuid);
      }
      if (records.length > 20000) throw new Error('Манифест превышает 20 000 строк. Требуется отдельный план переноса.');
      return { version: 1, spreadsheet_id: SpreadsheetApp.getActiveSpreadsheet().getId(), records };
    });
    const json = JSON.stringify(manifest, null, 2);
    const escaped = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const html = HtmlService.createHtmlOutput('<p>Сохраните JSON локально. Перед переносом остановите синхронизацию и редактирование таблицы.</p><textarea readonly style="width:98%;height:80%">' + escaped + '</textarea>').setWidth(800).setHeight(600);
    SpreadsheetApp.getUi().showModalDialog(html, 'Манифест UUID для администратора');
  } catch (error) { SpreadsheetApp.getUi().alert(error.message); }
}

function taskLinkUrl(sheet, row, col) {
  if (!col[COL.LINK]) return '';
  const cell = sheet.getRange(row, col[COL.LINK]);
  const rich = cell.getRichTextValue();
  return (rich && rich.getLinkUrl()) || String(cell.getValue()).trim();
}

function parseTaskId(url) {
  if (typeof url !== 'string') return null;
  const match = url.match(/^https?:\/\/[^?#\s]+\/projects\/[1-9]\d*\/tasks\/([1-9]\d*)\/?$/);
  const id = match ? Number(match[1]) : NaN;
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function getServiceConfig() {
  const props = PropertiesService.getScriptProperties();
  const serviceUrl = props.getProperty('INTEGRATION_SERVICE_URL');
  const serviceToken = props.getProperty('INTEGRATION_SERVICE_TOKEN');
  if (!serviceUrl || !serviceToken) throw new Error('Настройте INTEGRATION_SERVICE_URL и INTEGRATION_SERVICE_TOKEN в Script Properties.');
  return { serviceUrl: serviceUrl.replace(/\/+$/, ''), serviceToken };
}

function writeTaskLink(sheet, row, col, taskUrl) {
  sheet.getRange(row, col[COL.LINK]).setRichTextValue(SpreadsheetApp.newRichTextValue().setText('Открыть задачу').setLinkUrl(taskUrl).build());
}

function writeError(sheet, row, col, message, syncedAt = null, attemptedAt = null) {
  if (col[COL.ERROR]) sheet.getRange(row, col[COL.ERROR]).setValue(/^[=+@-]/.test(message) ? "'" + message : message);
  if (col[COL.SYNC_DATE] && syncedAt) sheet.getRange(row, col[COL.SYNC_DATE]).setValue(syncedAt);
  if (col[COL.ATTEMPT_DATE] && attemptedAt) sheet.getRange(row, col[COL.ATTEMPT_DATE]).setValue(attemptedAt);
}

function onOpen() {
  SpreadsheetApp.getUi().createMenu('ActiveCollab')
    .addItem('Синхронизировать задачу', 'syncTaskFromRow')
    .addItem('Новая запись из копии', 'newRecordFromCopy')
    .addItem('Подготовить UUID для переноса', 'prepareUuidMigration')
    .addToUi();
}

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
    throw new Error('Не заполнены обязательные поля: ' + missing.join(', '));
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
