'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../staff/pos.php'), 'utf8');
const start = source.indexOf('        function posBindServiceStockValidation(serviceId) {');
const end = source.indexOf('        function pfLayoutOptionCanonical(', start);
assert.ok(start >= 0 && end > start, 'service stock binding function is present');

const callbacks = {};
const buttons = [{ listeners: {} }, { listeners: {} }];
const input = {
    value: '1',
    addEventListener(name, callback) { (this.listeners ||= {})[name] = callback; },
    closest(selector) { assert.equal(selector, '.quantity-container'); return container; }
};
const container = {
    querySelectorAll(selector) { assert.equal(selector, 'button'); return buttons; }
};
buttons.forEach(button => {
    button.addEventListener = (name, callback) => { button.listeners[name] = callback; };
});
const fieldsBody = { contains(node) { return node === input; } };
const elements = {
    'sm-fields-body': fieldsBody,
    'pos-service-stock-feedback': { style: {} },
    'pos-service-stock-warning': { textContent: '' },
    'pos-service-stock-indicator': { textContent: '' }
};
const validations = [];
const timers = [];
const context = vm.createContext({
    document: { getElementById(id) { return elements[id] || null; } },
    posServiceQuantityInput() { return input; },
    posValidateServiceStockNow(serviceId, quantity) { validations.push([serviceId, String(quantity)]); },
    posReadPositiveWholeQuantity(value) { return /^[1-9]\d*$/.test(String(value)) ? Number(value) : null; },
    resolveServiceModalPricing() { return { quantity: 1 }; },
    setServiceAddButtonBusy() {},
    posSetServiceStockState() {},
    setTimeout(callback) { timers.push(callback); return timers.length; },
    clearTimeout() {},
    posServiceStockRequestTimer: null,
    posServiceStockBlocking: false,
    isAddingToOrder: false
});

vm.runInContext(source.slice(start, end), context);
context.posBindServiceStockValidation(42);
assert.deepEqual(validations.shift(), [42, '1'], 'opening the modal validates its initial quantity');

input.value = '2';
buttons[1].listeners.click();
assert.equal(timers.length, 1, 'plus/minus click schedules validation after its inline value update');
timers.shift()();
assert.deepEqual(validations.shift(), [42, '2'], 'button-driven quantity change validates the updated value');

input.value = '4';
input.listeners.input();
assert.equal(timers.length, 1, 'typing continues to debounce stock validation');
timers.shift()();
assert.deepEqual(validations.shift(), [42, '4'], 'typed quantity validates through the existing request path');

console.log('POS service stock input validation tests passed.');
