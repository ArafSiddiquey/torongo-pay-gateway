const SHEET_SECRET = 'CHANGE_THIS_SECRET';
const SCRIPT_VERSION = 'simple-columns-v3';

const TABS = {
  dashboard: 'Dashboard',
  successful: 'Successful Payments',
  pending: 'Pending Payments',
  unsuccessful: 'Failed & Expired',
  balances: 'Account Balances',
};

const TX_HEADERS = [
  'Invoice ID', 'Customer Number', 'Amount', 'Currency', 'Trx ID', 'Method',
  'Created At', 'Verified At', 'Status', 'Device', 'SMS Type', 'Manual Note',
];

const BALANCE_HEADERS = [
  'Method', 'Account', 'Options', 'Base Amount', 'Received Amount', 'Debit Amount',
  'Current Balance', 'Base Set At',
];

function doPost(e) {
  const payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');
  if (String(payload.secret || '') !== SHEET_SECRET) {
    return jsonResponse({ ok: false, error: 'Invalid secret' }, 403);
  }

  const ss = SpreadsheetApp.getActiveSpreadsheet();
  setupWorkbook(ss);

  const eventTime = payload.generated_at || new Date().toISOString();
  if (Array.isArray(payload.transactions)) {
    writeBulkTransactions(ss, eventTime, payload.event || 'bulk_backfill', payload.transactions);
    writeDashboard(ss, eventTime, payload.summary || {});
    replaceRows(ss.getSheetByName(TABS.balances), BALANCE_HEADERS, balanceRows(eventTime, payload.account_balances || []));
    return jsonResponse({ ok: true, version: SCRIPT_VERSION, count: payload.transactions.length });
  }

  const tx = payload.transaction || {};
  writeTransactionRows(ss, eventTime, payload.event || '', tx);
  writeDashboard(ss, eventTime, payload.summary || {});
  replaceRows(ss.getSheetByName(TABS.balances), BALANCE_HEADERS, balanceRows(eventTime, payload.account_balances || []));

  return jsonResponse({ ok: true, version: SCRIPT_VERSION, invoice_id: tx.invoice_id || null });
}

function setupWorkbook(ss) {
  deleteSheetIfExists(ss, 'All Events');
  deleteSheetIfExists(ss, 'SMS Logs');

  Object.values(TABS).forEach((name) => {
    const sheet = ss.getSheetByName(name) || ss.insertSheet(name);
    sheet.setFrozenRows(1);
    sheet.setHiddenGridlines(false);
  });

  ensureHeaders(ss.getSheetByName(TABS.successful), TX_HEADERS);
  ensureHeaders(ss.getSheetByName(TABS.pending), TX_HEADERS);
  ensureHeaders(ss.getSheetByName(TABS.unsuccessful), TX_HEADERS);
  ensureHeaders(ss.getSheetByName(TABS.balances), BALANCE_HEADERS);
}

function writeTransactionRows(ss, eventTime, event, tx) {
  const row = transactionRow(eventTime, event, tx);

  if (tx.status_group === 'successful') {
    deleteByInvoice(ss.getSheetByName(TABS.pending), tx.invoice_id);
    deleteByInvoice(ss.getSheetByName(TABS.unsuccessful), tx.invoice_id);
    appendSuccessOnce(ss.getSheetByName(TABS.successful), row);
    return;
  }

  if (tx.status_group === 'pending') {
    upsertByInvoice(ss.getSheetByName(TABS.pending), row);
    return;
  }

  upsertByInvoice(ss.getSheetByName(TABS.unsuccessful), row);
}

function writeBulkTransactions(ss, eventTime, event, transactions) {
  const successRows = [];
  const pendingRows = [];
  const unsuccessfulRows = [];

  transactions.forEach((tx) => {
    const row = transactionRow(eventTime, tx.backup_event || event, tx);
    if (tx.status_group === 'successful') {
      successRows.push(row);
    } else if (tx.status_group === 'pending') {
      pendingRows.push(row);
    } else {
      unsuccessfulRows.push(row);
    }
  });

  replaceRows(ss.getSheetByName(TABS.successful), TX_HEADERS, successRows);
  replaceRows(ss.getSheetByName(TABS.pending), TX_HEADERS, pendingRows);
  replaceRows(ss.getSheetByName(TABS.unsuccessful), TX_HEADERS, unsuccessfulRows);
}

function writeDashboard(ss, eventTime, summary) {
  const sheet = ss.getSheetByName(TABS.dashboard);
  sheet.clear();
  sheet.getRange('A1:H1').merge().setValue('Torongo Pay Backup Dashboard');
  sheet.getRange('A2:H2').merge().setValue('Last updated: ' + formatTime(eventTime) + ' | Script: ' + SCRIPT_VERSION);

  const cards = [
    ['Total Transactions', num(summary.total_transactions)],
    ['Successful Transactions', num(summary.successful_transactions)],
    ['Pending Transactions', num(summary.pending_transactions)],
    ['Failed / Expired', num(summary.failed_transactions)],
    ['Successful Amount', money(summary.successful_amount_total)],
    ['Successful Paid Total', money(summary.successful_paid_total)],
    ['Pending Amount', money(summary.pending_amount_total)],
    ['Today Successful Amount', money(summary.today_successful_amount)],
    ['Pending Unmatched SMS', num(summary.pending_unmatched_sms)],
  ];

  sheet.getRange(4, 1, cards.length, 2).setValues(cards);
  sheet.getRange('D4:F4').setValues([['Sheet', 'Purpose', 'Rule']]);
  sheet.getRange('D5:F8').setValues([
    [TABS.successful, 'Confirmed payment log', 'Append only'],
    [TABS.pending, 'Latest pending snapshot', 'Upsert by invoice'],
    [TABS.unsuccessful, 'Failed and expired backup', 'Upsert by invoice'],
    [TABS.balances, 'Account balance snapshot', 'Replace latest'],
  ]);

  styleDashboard(sheet);
}

function transactionRow(eventTime, event, tx) {
  return [
    tx.invoice_id || '',
    tx.customer_number || '',
    num(tx.paid_amount || tx.amount),
    tx.currency || '',
    tx.trx_id || '',
    tx.method_name || tx.method || '',
    formatTime(tx.created_at),
    formatTime(tx.verified_at),
    tx.status || '',
    tx.device_name || tx.device_id || '',
    tx.sms_type || '',
    tx.manual_note || '',
  ];
}

function balanceRows(eventTime, balances) {
  return balances.map((item) => [
    item.method_name || item.method || '',
    item.account || '',
    item.options || '',
    num(item.base_amount),
    num(item.received_amount),
    num(item.debit_amount),
    num(item.balance),
    formatTime(item.base_set_at),
  ]);
}

function ensureHeaders(sheet, headers) {
  const wasEmpty = sheet.getLastRow() === 0;
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
  } else {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  }
  styleTable(sheet, headers.length, wasEmpty);
}

function appendRow(sheet, row) {
  sheet.appendRow(row);
  styleTable(sheet, row.length, false);
}

function appendRows(sheet, rows) {
  if (!rows.length) return;

  const startRow = sheet.getLastRow() + 1;
  sheet.getRange(startRow, 1, rows.length, rows[0].length).setValues(rows);
  styleTable(sheet, rows[0].length, false);
}

function appendMissingSuccessRows(sheet, rows) {
  if (!rows.length) return;

  const values = sheet.getDataRange().getValues();
  const existingInvoices = new Set();
  for (let i = 1; i < values.length; i++) {
    if (values[i][2]) existingInvoices.add(String(values[i][2]));
  }

  const missingRows = rows.filter((row) => {
    const invoice = String(row[2] || '');
    return invoice && !existingInvoices.has(invoice);
  });

  appendRows(sheet, missingRows);
}

function upsertByInvoice(sheet, row) {
  const invoice = row[2];
  if (!invoice) {
    appendRow(sheet, row);
    return;
  }

  const values = sheet.getDataRange().getValues();
  for (let i = 1; i < values.length; i++) {
    if (String(values[i][2]) === String(invoice)) {
      sheet.getRange(i + 1, 1, 1, row.length).setValues([row]);
      styleTable(sheet, row.length, false);
      return;
    }
  }
  appendRow(sheet, row);
}

function appendSuccessOnce(sheet, row) {
  const invoice = row[2];
  if (!invoice) {
    appendRow(sheet, row);
    return;
  }

  const values = sheet.getDataRange().getValues();
  for (let i = 1; i < values.length; i++) {
    if (String(values[i][2]) === String(invoice)) {
      return;
    }
  }
  appendRow(sheet, row);
}

function deleteByInvoice(sheet, invoice) {
  if (!invoice) return;

  const values = sheet.getDataRange().getValues();
  for (let i = values.length - 1; i >= 1; i--) {
    if (String(values[i][2]) === String(invoice)) {
      sheet.deleteRow(i + 1);
    }
  }
}

function deleteSheetIfExists(ss, name) {
  const sheet = ss.getSheetByName(name);
  if (sheet && ss.getSheets().length > 1) {
    ss.deleteSheet(sheet);
  }
}

function replaceRows(sheet, headers, rows) {
  const oldLastColumn = Math.max(sheet.getLastColumn(), headers.length);
  sheet.clear();
  if (oldLastColumn > headers.length) {
    sheet.getRange(1, headers.length + 1, Math.max(sheet.getMaxRows(), 1), oldLastColumn - headers.length).clear();
  }
  sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  if (rows.length > 0) {
    sheet.getRange(2, 1, rows.length, headers.length).setValues(rows);
  }
  styleTable(sheet, headers.length, false);
}

function formatTime(value) {
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);

  return Utilities.formatDate(date, 'Asia/Dhaka', 'h:mm a, d MMM yy').toLowerCase();
}

function styleDashboard(sheet) {
  sheet.setFrozenRows(2);
  sheet.getRange('A1:H1')
    .setBackground('#0F766E')
    .setFontColor('#FFFFFF')
    .setFontWeight('bold')
    .setFontSize(18)
    .setHorizontalAlignment('center');
  sheet.getRange('A2:H2')
    .setBackground('#CCFBF1')
    .setFontWeight('bold')
    .setHorizontalAlignment('center');
  sheet.getRange('A4:B12').setBackground('#E0F2FE').setFontWeight('bold');
  sheet.getRange('D4:F10').setBackground('#F8FAFC').setFontWeight('bold');
  sheet.getRange('A4:F12').setBorder(true, true, true, true, true, true);
}

function styleTable(sheet, columns, allowInitialSizing) {
  const lastRow = Math.max(sheet.getLastRow(), 1);
  const range = sheet.getRange(1, 1, lastRow, columns);
  range.setFontWeight('bold');
  range.setWrap(true);
  sheet.getRange(1, 1, 1, columns)
    .setBackground('#14532D')
    .setFontColor('#FFFFFF')
    .setHorizontalAlignment('center');
  if (lastRow > 1) {
    sheet.getRange(2, 1, lastRow - 1, columns).setBackground('#F8FAFC');
  }
  range.setBorder(true, true, true, true, true, true);

  if (allowInitialSizing) {
    for (let col = 1; col <= columns; col++) {
      sheet.setColumnWidth(col, 130);
    }
  }
}

function jsonResponse(data, statusCode) {
  return ContentService
    .createTextOutput(JSON.stringify({ status: statusCode || 200, ...data }))
    .setMimeType(ContentService.MimeType.JSON);
}

function num(value) {
  const parsed = Number(value || 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function blankableNum(value) {
  if (value === null || value === undefined || value === '') return '';
  return num(value);
}

function money(value) {
  return num(value);
}
