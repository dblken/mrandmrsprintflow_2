'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const pos = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');

function extractFunction(source, start, end) {
    const from = source.indexOf(start);
    assert.notEqual(from, -1, `Missing ${start}`);
    const to = source.indexOf(end, from);
    assert.notEqual(to, -1, `Missing function boundary ${end}`);
    return source.slice(from, to).trim();
}

const quantityReaderSource = extractFunction(
    pos,
    'function posReadPositiveWholeQuantity(value) {',
    'function posShowCartQuantityMessage('
);
const readQuantity = new Function(`return (${quantityReaderSource});`)();

for (const [raw, expected] of [
    ['1', 1], ['999', 999], ['1000', 1000], ['999999', 999999],
    ['1000000', 1000000], ['0001', 1], ['0001000000', 1000000]
]) {
    assert.equal(readQuantity(raw), expected, `Expected ${raw} to parse as ${expected}`);
}
for (const raw of ['', '0', '000', '-1', '+1', '1.5', '1e3', '1E3', 'abc', '12abc', ' 1', '1 ', '1000001', '９', '1'.repeat(65)]) {
    assert.equal(readQuantity(raw), null, `Expected ${JSON.stringify(raw)} to be rejected`);
}

assert.match(pos, /\.pos-qty-val\s*\{[^}]*width:\s*60px;[^}]*min-width:\s*60px;[^}]*max-width:\s*60px;[^}]*flex:\s*0 0 60px;/s);
assert.match(pos, /\.pos-qty-val\s*\{[^}]*overflow-x:\s*auto;[^}]*overflow-y:\s*hidden;/s);
assert.match(pos, /\.pos-qty-val\s*\{[^}]*color:\s*#0f172a;/s);
assert.match(pos, /\.pos-item-controls\s*\{[^}]*width:\s*max-content;[^}]*max-width:\s*100%;[^}]*justify-self:\s*start;/s);
assert.match(pos, /class="pos-qty-val" type="text" inputmode="numeric" pattern="\[0-9\]\*" maxlength="64"/);
assert.match(pos, /text\.textContent = hasMalformedQuantity \? 'Fix Quantity' : 'Update Quantity';/);
assert.match(pos, /const confirmedLineTotal = item\.line_total_override !== null && item\.line_total_override !== undefined/);
assert.match(pos, /\(\(parseFloat\(item\.price\) \|\| 0\) \* \(item\.qty \|\| 1\)\) \+ Math\.max\(0, Number\(item\.design_fee \?\? item\.customization\?\.design_fee\) \|\| 0\)/);
assert.match(pos, /currentTotal \+= confirmedLineTotal;/);

const bindSource = extractFunction(
    pos,
    'function posBindCartQuantityInput(index, input) {',
    'function validateCartQuantityInput('
);
const handlers = {};
const input = {
    addEventListener(type, callback) { handlers[type] = callback; },
    setCustomValidity() {}
};
const rejectedMessages = [];
const bind = new Function('posShowCartQuantityMessage', `return (${bindSource});`)(
    (_index, _input, message) => rejectedMessages.push(message)
);
bind(0, input);

let prevented = false;
handlers.keydown({ key: 'e', ctrlKey: false, metaKey: false, altKey: false, preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'keydown fallback blocks letters');

prevented = false;
handlers.beforeinput({ data: 'e', preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'beforeinput blocks exponent letters');

prevented = false;
handlers.paste({ clipboardData: { getData: () => '12abc' }, preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'paste blocks mixed alphanumeric content');

prevented = false;
handlers.drop({ dataTransfer: { getData: () => '1.5' }, preventDefault() { prevented = true; } });
assert.equal(prevented, true, 'drop blocks decimal content');
assert.equal(rejectedMessages.length, 4);

const updateSource = extractFunction(
    pos,
    'async function updateQtyByCartIndex(index, delta) {',
    'async function removeByCartIndex('
);
const updateCalls = [];
const cart = [{ qty: 1 }];
const quantityInput = { value: '1', dataset: {}, reportValidity() {} };
const fakeDocument = {
    querySelectorAll(selector) {
        return selector === '.pos-cart-item' ? [{ querySelector: () => quantityInput }] : [];
    }
};
const update = new Function('cart', 'document', 'posReadPositiveWholeQuantity', 'syncedCartAction', `return (${updateSource});`)(
    cart,
    fakeDocument,
    readQuantity,
    async (...args) => { updateCalls.push(args); return { success: true }; }
);

(async () => {
    await update(0, -1);
    assert.equal(updateCalls.length, 0, 'minus cannot go below 1');
    cart[0].qty = 999999;
    quantityInput.value = '999999';
    await update(0, 1);
    assert.deepEqual(updateCalls[0], ['update', { index: 0, qty: 1000000 }]);
    cart[0].qty = 1000000;
    quantityInput.value = '1000000';
    await update(0, 1);
    assert.equal(updateCalls.length, 1, 'plus cannot exceed 1,000,000');
    quantityInput.value = '1e3';
    await update(0, -1);
    assert.equal(updateCalls.length, 1, 'plus/minus refuses to replace malformed typed input');
    console.log('POS cart quantity UI tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
