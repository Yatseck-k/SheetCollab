const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { randomUUID } = require('node:crypto');
const vm = require('node:vm');
const source = readFileSync('resources/apps-script/activecollab_sync.gs', 'utf8');
const UUID = 'SheetCollab UUID';
const LINK = 'Ссылка на задачу';
const ERROR = 'Ошибка синхронизации';
const DATE = 'Дата синхронизации';
const headers = ['Серьезность', 'Стенд', 'Раздел', 'Описание проблемы', LINK, ERROR, DATE];
const row = (description = 'Bug') => ['major', 'stage', 'Catalog', description, '', '', ''];
const id = 'ae091ce2-282a-43ad-a258-85a2b8eecfc4';
const url = 'https://ac.example.test/projects/10/tasks/123';

function harness() {
  const state = { held: false, lockAvailable: true, calls: [], alerts: [], html: '', hook: null, beforeLock: null, flushes: 0 };
  const value = cell => cell && typeof cell === 'object' ? cell.text : (cell ?? '');
  class Sheet {
    constructor(id, rows) { this.id = id; this.rows = rows; this.activeRow = 2; this.hidden = []; }
    getSheetId() { return this.id; }
    getName() { return 'Sheet ' + this.id; }
    getLastColumn() { return Math.max(0, ...this.rows.map(r => r.length)); }
    getLastRow() { return this.rows.length; }
    getMaxRows() { return 100; }
    getMaxColumns() { return 26; }
    insertColumnAfter() {}
    hideColumns(c) { this.hidden.push(c); }
    getActiveRange() { return this.getRange(this.activeRow, 1); }
    getRange(r, c, nr = 1, nc = 1) {
      const sheet = this;
      const get = (rr, cc) => (sheet.rows[rr - 1] || [])[cc - 1];
      const put = v => { while (sheet.rows.length < r) sheet.rows.push([]); sheet.rows[r - 1][c - 1] = v; };
      return {
        getRow: () => r,
        getValue: () => value(get(r, c)),
        getValues: () => Array.from({ length: nr }, (_, i) => Array.from({ length: nc }, (_, j) => value(get(r + i, c + j)))),
        setValue: put,
        clearContent: () => put(''),
        getRichTextValue: () => ({ getLinkUrl: () => get(r, c)?.url || null }),
        setRichTextValue: put,
      };
    }
  }
  const sheet = new Sheet(0, [[...headers], row()]);
  state.sheets = [sheet]; state.active = sheet;
  const ui = {
    ButtonSet: { YES_NO: 'YES_NO' }, Button: { YES: 'YES' },
    alert: (...args) => { state.alerts.push(args.join(' ')); return 'YES'; },
    showModalDialog: output => { state.html = output.html; },
    createMenu: () => ({ addItem() { return this; }, addToUi() {} }),
  };
  const spreadsheet = { getId: () => state.spreadsheetId || 'sheet', getActiveSheet: () => state.active, getSheets: () => state.sheets };
  const context = vm.createContext({
    SpreadsheetApp: {
      getActiveSpreadsheet: () => spreadsheet, getUi: () => ui, flush: () => { state.flushes++; },
      newRichTextValue: () => ({ text: '', url: '', setText(t) { this.text = t; return this; }, setLinkUrl(u) { this.url = u; return this; }, build() { return { text: this.text, url: this.url }; } }),
    },
    LockService: { getDocumentLock: () => ({ tryLock: () => { if (state.beforeLock) { const hook = state.beforeLock; state.beforeLock = null; hook(); } if (!state.lockAvailable || state.held) return false; state.held = true; return true; }, releaseLock: () => { state.held = false; } }) },
    Utilities: { getUuid: randomUUID },
    PropertiesService: { getScriptProperties: () => ({ getProperty: key => key === 'INTEGRATION_SERVICE_URL' ? 'https://service.example.test' : 'test-token' }) },
    HtmlService: { createHtmlOutput: html => ({ html, setWidth() { return this; }, setHeight() { return this; } }) },
    UrlFetchApp: { fetch: (target, options) => {
      assert.equal(state.held, false, 'No document lock during external HTTP');
      const request = { target, method: options.method, payload: JSON.parse(options.payload) };
      state.calls.push(request);
      const response = state.hook ? state.hook(request) : null;
      return { getResponseCode: () => response?.code ?? 201, getContentText: () => response?.raw ?? JSON.stringify(response?.body ?? { data: { task_id: 123, task_url: url } }) };
    } },
  });
  vm.runInContext(source, context);
  state.context = context; state.sheet = sheet; state.Sheet = Sheet;
  state.cell = (name, r = 2, s = sheet) => s.rows[r - 1]?.[s.rows[0].indexOf(name)];
  state.set = (name, val, r = 2, s = sheet) => { let c = s.rows[0].indexOf(name); if (c < 0) { c = s.rows[0].length; s.rows[0].push(name); } s.rows[r - 1][c] = val; };
  return state;
}

test('UUID is persisted and flushed before HTTP, existing assignment is preserved', () => {
  const h = harness();
  h.beforeLock = () => h.set(UUID, id); // Another execution completed assignment while this one waited.
  h.hook = request => { assert.equal(request.payload.record_uuid, id); assert.equal(h.cell(UUID), id); assert.ok(h.flushes > 0); };
  h.context.createTaskFromRow();
  assert.equal(h.calls.length, 1);
  assert.equal(h.cell(LINK).url, url);
});

test('new technical column is hidden and a fresh UUID is assigned', () => {
  const h = harness(); h.context.createTaskFromRow();
  assert.match(h.cell(UUID), /^[0-9a-f-]{36}$/);
  assert.equal(h.calls[0].payload.record_uuid, h.cell(UUID));
  assert.ok(h.sheet.hidden.includes(h.sheet.rows[0].indexOf(UUID) + 1));
});

test('result follows UUID after full-row move to another sheet during HTTP', () => {
  const h = harness(); const destination = new h.Sheet(1, [[...headers, UUID], row('Other')]); h.sheets.push(destination);
  h.hook = () => { destination.rows.push(h.sheet.rows.splice(1, 1)[0]); h.sheet.rows.push(row('Reused position')); };
  h.context.createTaskFromRow();
  assert.equal(h.cell(LINK), '');
  assert.equal(h.cell(LINK, 3, destination).url, url);
  assert.equal(h.cell(ERROR, 3, destination), '');
});

for (const outcome of ['network', 'validation', 'conflict']) {
  test(`${outcome} message follows UUID after insertion during HTTP`, () => {
    const h = harness();
    h.hook = () => { h.sheet.rows.splice(1, 0, row('Inserted')); if (outcome === 'network') throw Error('test-token must never be shown'); return { code: outcome === 'validation' ? 422 : 409, body: { error: { code: 'sync_outcome_unknown', message: 'Safe error' } } }; };
    h.context.createTaskFromRow();
    assert.equal(h.cell(ERROR), '');
    assert.ok(h.cell(ERROR, 3));
    assert.equal(h.cell(LINK, 3), '');
    assert.ok(!h.alerts.join(' ').includes('test-token'));
  });
}

for (const duplicate of [false, true]) {
  test(`deleted/duplicated response target never writes the old row (${duplicate})`, () => {
    const h = harness();
    h.hook = () => { if (duplicate) h.sheet.rows.push([...h.sheet.rows[1]]); else h.sheet.rows[1] = row('Replacement'); };
    h.context.createTaskFromRow();
    assert.equal(h.cell(LINK), ''); assert.equal(h.cell(ERROR), '');
    assert.ok(h.alerts.length);
  });
}

test('duplicate UUID across sheets blocks HTTP; explicit new copy changes only selected row', () => {
  const h = harness(); h.set(UUID, id);
  const copy = new h.Sheet(1, [[...h.sheet.rows[0]], [...h.sheet.rows[1]]]); h.sheets.push(copy);
  h.context.createTaskFromRow(); assert.equal(h.calls.length, 0);
  h.set(LINK, { text: 'Open', url }, 2, copy); h.set(ERROR, 'Old error', 2, copy); h.set(DATE, 'Old date', 2, copy);
  h.active = copy; h.context.newRecordFromCopy();
  assert.notEqual(h.cell(UUID, 2, copy), id); assert.equal(h.cell(UUID), id);
  for (const name of [LINK, ERROR, DATE]) assert.equal(h.cell(name, 2, copy), '');
});

test('lock timeout blocks UUID assignment and HTTP', () => {
  const h = harness(); h.lockAvailable = false; h.context.createTaskFromRow();
  assert.equal(h.calls.length, 0); assert.equal(h.cell(UUID), undefined);
});

test('duplicate UUID headers block HTTP', () => {
  const h = harness(); h.sheet.rows[0].push(UUID, UUID); h.context.createTaskFromRow(); assert.equal(h.calls.length, 0);
});

test('duplicate conflict restores link, unknown conflict never does', () => {
  const h = harness(); h.hook = () => ({ code: 409, body: { message: 'Already created', data: { task_id: 123, task_url: url } } });
  h.context.createTaskFromRow(); assert.equal(h.cell(LINK).url, url);
  const u = harness(); u.hook = () => ({ code: 409, body: { error: { code: 'sync_outcome_unknown' }, data: { task_id: 123, task_url: url } } });
  u.context.createTaskFromRow(); assert.equal(u.cell(LINK), '');
});

test('update includes UUID and verifies response task ID and safe URL', () => {
  const h = harness(); h.set(LINK, { text: 'Open', url }); h.context.updateTaskFromRow();
  assert.equal(h.calls[0].method, 'put'); assert.equal(h.calls[0].payload.record_uuid, h.cell(UUID));
  const bad = harness(); bad.hook = () => ({ body: { data: { task_id: 123, task_url: 'javascript:alert(1)' } } }); bad.context.createTaskFromRow();
  assert.equal(bad.cell(LINK), ''); assert.ok(bad.cell(ERROR));
});

test('manifest prepares all populated rows without HTTP and safely escapes dialog', () => {
  const h = harness(); h.sheet.rows.push(row('Second')); h.set(LINK, { text: 'Open', url });
  h.context.prepareUuidMigration();
  assert.equal(h.calls.length, 0); assert.ok(h.cell(UUID)); assert.ok(h.cell(UUID, 3)); assert.notEqual(h.cell(UUID), h.cell(UUID, 3));
  assert.ok(h.html.includes('123')); assert.ok(!h.html.includes('test-token')); assert.ok(!h.html.includes('Second'));
  const unsafe = harness(); unsafe.spreadsheetId = '</textarea><script>alert(1)</script>';
  unsafe.context.prepareUuidMigration(); assert.ok(unsafe.html.includes('&lt;script&gt;')); assert.ok(!unsafe.html.includes('<script>'));
});

test('manifest preparation refuses duplicate IDs and broken participating sheets', () => {
  const h = harness(); h.set(UUID, id); h.sheet.rows.push([...h.sheet.rows[1]]); h.context.prepareUuidMigration(); assert.equal(h.html, '');
  const broken = harness(); broken.sheet.rows[0].splice(1, 1); broken.context.prepareUuidMigration(); assert.equal(broken.html, '');
});


test('two overlapping executions reuse one persisted UUID', () => {
  const h = harness(); let overlap = false;
  h.hook = () => {
    if (!overlap) { overlap = true; h.context.createTaskFromRow(); return null; }
    return { code: 409, body: { error: { code: 'sync_in_progress', message: 'Running' } } };
  };
  h.context.createTaskFromRow();
  assert.equal(h.calls.length, 2);
  assert.equal(h.calls[0].payload.record_uuid, h.calls[1].payload.record_uuid);
  assert.equal(h.cell(UUID), h.calls[0].payload.record_uuid);
  assert.equal(h.cell(LINK).url, url);
  assert.equal(h.cell(ERROR), '');
});

test('full-row sorting during HTTP changes position but not response ownership', () => {
  const h = harness(); h.sheet.rows.push(row('Another record'));
  h.hook = () => { h.sheet.rows.splice(1, 2, ...h.sheet.rows.slice(1).reverse()); };
  h.context.createTaskFromRow();
  assert.equal(h.cell(LINK), '');
  assert.equal(h.cell(LINK, 3).url, url);
});
