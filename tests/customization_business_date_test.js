const assert = require('node:assert/strict');
const fs = require('node:fs');
const source = fs.readFileSync(__dirname + '/../staff/customizations.php', 'utf8');
const start = source.indexOf('            formatOrderBusinessDate(row) {');
const end = source.indexOf('            async completeOrder(', start);
assert.ok(start >= 0 && end > start);
const formatter = new Function('return ({' + source.slice(start, end) + '})')().formatOrderBusinessDate;
const selected = '2026-09-07 14:30:00';
const expected = new Date(selected.replace(' ', 'T')).toLocaleString(undefined, {
    month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
});
assert.equal(formatter({order_business_date: selected, order_date: '2026-09-30 01:00:00', created_at: '2026-09-30 13:00:00'}), expected);
assert.equal(formatter({order_date: selected, created_at: '2026-09-30 13:00:00'}), expected);
assert.equal(formatter({created_at: selected}), expected);
assert.equal(formatter({}), '');
console.log('PASS: business date takes precedence; time and legacy fallback are preserved.');
