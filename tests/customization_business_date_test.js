const assert = require('node:assert/strict');
const fs = require('node:fs');
const source = fs.readFileSync(__dirname + '/../staff/customizations.php', 'utf8');
const helperStart = source.indexOf('            parseBusinessDateValue(value) {');
const helperEnd = source.indexOf('            normalizeOrderRow(row) {', helperStart);
const formatStart = source.indexOf('            formatOrderBusinessDate(row) {');
const formatEnd = source.indexOf('            async completeOrder(', formatStart);
assert.ok(helperStart >= 0 && helperEnd > helperStart && formatStart >= 0 && formatEnd > formatStart);
const context = Object.create(new Function('return ({' + source.slice(helperStart, helperEnd) + source.slice(formatStart, formatEnd) + '})')());
const selected = '2026-09-07 14:30:00';
const expected = new Intl.DateTimeFormat(undefined, {
    timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
}).format(new Date(Date.UTC(2026, 8, 7, 14, 30) - 8 * 60 * 60 * 1000));
assert.equal(context.formatOrderBusinessDate({order_business_date: selected, order_date: '2026-09-30 01:00:00', created_at: '2026-09-30 13:00:00'}), expected);
assert.equal(context.formatOrderBusinessDate({order_date: selected, created_at: '2026-09-30 13:00:00'}), expected);
assert.equal(context.formatOrderBusinessDate({created_at: selected}), expected);
assert.equal(context.formatOrderBusinessDate({}), '');
console.log('PASS: business date takes precedence; Manila time and legacy fallback are preserved.');
