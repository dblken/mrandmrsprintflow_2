'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../staff/pos.php'), 'utf8');
assert.match(source, /posSetServiceStockState\(false, '', ''\)/, 'a successful validation displays no material list or stock quantities');
assert.doesNotMatch(source, /posSetServiceStockState\(true, indicator, message\)/, 'validation warnings do not include a separate material inventory list');
const collectStart = source.indexOf('        function posCollectServiceStockCustomization() {');
const validateStart = source.indexOf('        async function posValidateServiceStockNow(', collectStart);
const bindStart = source.indexOf('        function posBindServiceStockValidation(serviceId) {', validateStart);
const end = source.indexOf('        function pfLayoutOptionCanonical(', bindStart);
assert.ok(collectStart >= 0 && validateStart > collectStart && bindStart > validateStart && end > bindStart, 'service stock collection and binding functions are present');

const callbacks = {};
const buttons = [{ listeners: {} }, { listeners: {} }];
const input = {
    value: '1',
    name: 'quantity',
    type: 'number',
    dataset: {},
    matches(selector) { return selector === 'input, select, textarea'; },
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
const dimensionWidth = { name: 'width', value: '3', type: 'number', dataset: { dimensionRole: 'width', fieldName: 'width' } };
const dimensionHeight = { name: 'height', value: '4', type: 'number', dataset: { dimensionRole: 'height', fieldName: 'height' } };
const finishOption = { name: 'finish', value: 'Matte', type: 'select-one', dataset: { fieldName: 'finish' } };
const branchField = { name: 'branch_id', value: '5', type: 'hidden', dataset: {} };
fieldsBody.querySelectorAll = selector => selector.includes('data-dimension-role')
    ? [dimensionWidth, dimensionHeight]
    : [input, dimensionWidth, dimensionHeight, finishOption, branchField];
fieldsBody.querySelector = selector => selector === '[name="branch_id"]' ? branchField : null;
fieldsBody.addEventListener = (name, callback) => { (fieldsBody.listeners ||= {})[name] = callback; };
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
    posServiceStockRequestVersion: 0,
    posServiceStockBlocking: false,
    isAddingToOrder: false
});

vm.runInContext(source.slice(collectStart, validateStart) + source.slice(bindStart, end), context);
const customization = context.posCollectServiceStockCustomization();
assert.equal(customization.width, '3', 'stock preview captures current width');
assert.equal(customization.height, '4', 'stock preview captures current height');
assert.equal(customization.finish, 'Matte', 'stock preview captures selected service options');
assert.equal(customization.branch_id, '5', 'stock preview captures the assigned branch');
assert.equal(customization.quantity, '1', 'stock preview captures requested quantity');
context.posBindServiceStockValidation(42);
assert.deepEqual(validations.shift(), [42, '1'], 'opening the modal validates its initial quantity');

input.value = '2';
buttons[1].listeners.click();
assert.equal(timers.length, 1, 'plus/minus click schedules validation after its inline value update');
timers.shift()();
assert.equal(timers.length, 1, 'quantity button validation is debounced after the control updates');
timers.shift()();
assert.deepEqual(validations.shift(), [42, '2'], 'button-driven quantity change validates the updated value');

input.value = '4';
input.listeners.input();
assert.equal(timers.length, 1, 'typing continues to debounce stock validation');
timers.shift()();
assert.deepEqual(validations.shift(), [42, '4'], 'typed quantity validates through the existing request path');

let optionValidationTimer;
fieldsBody.listeners.change({ target: { matches: () => true } });
assert.equal(timers.length, 1, 'changing a service option schedules stock revalidation');
optionValidationTimer = timers.shift();
optionValidationTimer();
assert.deepEqual(validations.shift(), [42, '4'], 'service option revalidation uses the current quantity');

let versionBeforeDimensionChange = context.posServiceStockRequestVersion;
fieldsBody.listeners.input({ target: { matches: () => true } });
assert.ok(context.posServiceStockRequestVersion > versionBeforeDimensionChange, 'dimension changes immediately invalidate stale validations');
assert.equal(timers.length, 1, 'changing a dimension schedules stock revalidation');
timers.shift()();
assert.deepEqual(validations.shift(), [42, '4'], 'dimension revalidation uses the current quantity');

console.log('POS service stock input validation tests passed.');
