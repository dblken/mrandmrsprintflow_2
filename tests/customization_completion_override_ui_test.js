'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');

const source = fs.readFileSync(__dirname + '/../staff/customizations.php', 'utf8');
const start = source.indexOf('            async completeOrder(machineId = null) {');
const end = source.indexOf('\n        };', start);
assert.ok(start >= 0 && end > start, 'completeOrder method is present');
const methodObject = new Function('return ({' + source.slice(start, end) + '\n})')();
assert.equal(typeof methodObject.completeOrder, 'function', 'completion handler parses as JavaScript');
assert.match(source.slice(start, end), /requires_unpaid_confirmation/,
    'UI retries only when the server says unpaid confirmation is required');
assert.match(source.slice(start, end), /makeCompletionForm\(true\)/,
    'the retry includes explicit confirmation');
assert.match(source.slice(start, end), /businessDate >= '2026-09-01' && businessDate <= '2026-10-05'/,
    'override intent is restricted to the selected business-date range');
console.log('Customizations completion override UI checks passed.');
