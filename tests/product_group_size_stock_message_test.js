'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const page = fs.readFileSync(path.join(__dirname, '..', 'customer', 'product_group.php'), 'utf8');

assert.doesNotMatch(page, /Maximum available:\s*' \+ max/, 'legacy Maximum available wording is removed');
assert.match(page, /function pfGroupQuantityStockMessage\(availableQty, sizeLabel\)/, 'size stock message helper is defined');
assert.match(page, /Only ' \+ max \+ ' ' \+ size \+ ' shirts are available\./, 'message uses selected size and available quantity');
assert.match(
    page,
    /qtyErr\.textContent = pfGroupQuantityStockMessage\(max, pfGroupSelectedSizeValue\(\)\)/,
    'qty validation uses the size-aware stock message'
);

const helperStart = page.indexOf('function pfGroupQuantityStockMessage(availableQty, sizeLabel) {');
const helperEnd = page.indexOf('\nfunction pfGroupCustomizationPayload', helperStart);
assert.ok(helperStart >= 0 && helperEnd > helperStart, 'helper block boundaries found');
const helperSource = page.slice(helperStart, helperEnd);
const sandbox = { module: { exports: {} } };
vm.runInNewContext(helperSource + '\nmodule.exports = pfGroupQuantityStockMessage;', sandbox);
const messageFor = sandbox.module.exports;

assert.equal(messageFor(2, 'Small'), 'Only 2 Small shirts are available.');
assert.equal(messageFor(403, 'Large'), 'Only 403 Large shirts are available.');
assert.equal(messageFor(88, 'XS'), 'Only 88 XS shirts are available.');
assert.equal(messageFor('5', 'Medium'), 'Only 5 Medium shirts are available.');
assert.equal(messageFor(0, 'Small'), 'Only 0 Small shirts are available.');
assert.equal(messageFor(3, ''), 'Only 3 items are available.', 'missing size keeps a non-size fallback');
assert.notEqual(messageFor(2, 'Small'), messageFor(2, 'Large'), 'selected-size changes update the wording');
assert.notEqual(messageFor(2, 'Small'), messageFor(403, 'Small'), 'available-stock changes update the wording');

console.log('product_group_size_stock_message_test: PASS');
