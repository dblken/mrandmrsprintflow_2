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
const widthHidden = { dataset: { dimensionRole: 'width' }, value: '' };
const heightHidden = { dataset: { dimensionRole: 'height' }, value: '' };
const widthField = { value: '', matches(selector) { return selector.includes('.custom-dim-width'); }, closest() { return null; } };
const heightField = { value: '', matches(selector) { return selector.includes('.custom-dim-height'); }, closest() { return null; } };
let customSizeVisible = false;
// Production places the custom-size hidden fields before the actual preset
// select, so a broad name*=dimension selector can resolve the wrong control.
const dimensionsControl = {
    tagName: 'SELECT', name: 'dimensions', value: '',
    classList: { contains(name) { return name === 'pf-select-with-others'; } },
    dataset: { otherOption: 'Others' }
};
const unitControl = { name: 'dimensions_unit', type: 'hidden', value: 'ft' };
const directionControl = { value: 'automatic' };
const allowanceModeControl = { value: 'included' };
const allowanceControl = { value: '0' };
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
const fieldsBody = {
    listeners: {},
    contains(node) { return node === input; },
    addEventListener(name, callback) { this.listeners[name] = callback; },
    querySelectorAll(selector) {
        assert.match(selector, /data-dimension-role/);
        return [widthHidden, heightHidden];
    },
    querySelector(selector) {
        // The renderer's custom-size nodes remain in the DOM while their
        // wrapper is hidden; the selected option determines whether they apply.
        if (selector.includes('.custom-dim-width')) return widthField;
        if (selector.includes('.custom-dim-height')) return heightField;
        if (selector === 'select[name="dimensions"]') return dimensionsControl;
        if (selector.includes('input[name*="dimension" i]')) return unitControl;
        if (selector.includes('[name="joining_direction"]')) return directionControl;
        if (selector.includes('[name="joining_allowance_mode"]')) return allowanceModeControl;
        if (selector.includes('[name="joining_allowance_ft"]')) return allowanceControl;
        return null;
    }
};
const elements = {
    'sm-fields-body': fieldsBody,
    'pos-service-stock-feedback': { style: {} },
    'pos-service-stock-warning': { textContent: '' },
    'pos-service-stock-indicator': { textContent: '' }
};
const validations = [];
const timers = [];
let context;
context = vm.createContext({
    document: { getElementById(id) { return elements[id] || null; } },
    posServiceQuantityInput() { return input; },
    posValidateServiceStockNow(serviceId, quantity) {
        validations.push([
            serviceId,
            String(quantity),
            Number(serviceId) === 40 ? context.posCollectTarpaulinStockCustomization() : null
        ]);
    },
    pfPrepareServiceFormDimensions() {
        widthHidden.value = customSizeVisible ? widthField.value : '';
        heightHidden.value = customSizeVisible ? heightField.value : '';
    },
    posReadPositiveWholeQuantity(value) { return /^[1-9]\d*$/.test(String(value)) ? Number(value) : null; },
    resolveServiceModalPricing() { return { quantity: 1 }; },
    setServiceAddButtonBusy() {},
    posSetServiceStockState() {},
    setTimeout(callback) { timers.push(callback); return timers.length; },
    clearTimeout() {},
    posServiceStockRequestTimer: null,
    posServiceStockBlocking: false,
    posServiceStockRequestVersion: 0,
    isAddingToOrder: false
});

const collectStart = source.indexOf('        function posCollectTarpaulinStockCustomization() {');
const collectEnd = source.indexOf('        async function posValidateServiceStockNow(', collectStart);
assert.ok(collectStart >= 0 && collectEnd > collectStart, 'Tarpaulin payload collector is present');
vm.runInContext(source.slice(collectStart, collectEnd), context);
vm.runInContext(source.slice(start, end), context);
context.posBindServiceStockValidation(42);
assert.equal(validations.shift()[0], 42, 'opening a non-Tarpaulin service validates its initial quantity');

input.value = '2';
buttons[1].listeners.click();
assert.equal(timers.length, 1, 'plus/minus click schedules validation after its inline value update');
timers.shift()();
assert.deepEqual(validations.shift().slice(0, 2), [42, '2'], 'button-driven quantity change validates the updated value');

input.value = '4';
input.listeners.input();
assert.equal(timers.length, 1, 'typing continues to debounce stock validation');
timers.shift()();
assert.deepEqual(validations.shift().slice(0, 2), [42, '4'], 'typed quantity validates through the existing request path');

context.posBindServiceStockValidation(40);
const initialTarpaulinRequest = validations.shift();
assert.equal(initialTarpaulinRequest[0], 40, 'Tarpaulin validates when the modal opens.');
assert.equal(initialTarpaulinRequest[2].width, '', 'The initial request preserves a blank width until a size is selected.');
assert.equal(initialTarpaulinRequest[2].height, '', 'The initial request preserves a blank height until a size is selected.');
assert.equal(initialTarpaulinRequest[2].quantity, '4', 'The initial dimensions request preserves service quantity.');
const versionBeforeDimensions = context.posServiceStockRequestVersion;
customSizeVisible = true;
widthField.value = '2';
heightField.value = '2';
fieldsBody.listeners.input({ target: widthField });
assert.ok(context.posServiceStockRequestVersion > versionBeforeDimensions, 'Dimension edits invalidate in-flight validation responses immediately.');
assert.equal(context.posServiceStockBlocking, true, 'Dimension edits keep Add to Order blocked while revalidation is pending.');
timers.shift()();
let dimensionRequest = validations.shift();
assert.deepEqual(dimensionRequest.slice(0, 2), [40, '4'], 'Tarpaulin dimension edits revalidate without changing quantity.');
assert.equal(dimensionRequest[2].width, '2', 'The 2 ft visible custom width reaches the stock request.');
assert.equal(dimensionRequest[2].height, '2', 'The 2 ft visible custom height reaches the stock request.');

customSizeVisible = false;
dimensionsControl.value = '3x5 Ft';
const presetButton = {
    matches() { return false; },
    closest(selector) { return selector.includes('[data-dimension-choice]') ? this : null; }
};
fieldsBody.listeners.click({ target: presetButton });
assert.equal(timers.length, 1, 'Preset dimension selection schedules stock revalidation.');
timers.shift()();
dimensionRequest = validations.shift();
assert.equal(dimensionRequest[2].width, '3', 'A 3 ft preset width reaches the stock request.');
assert.equal(dimensionRequest[2].height, '5', 'A 5 ft preset height reaches the stock request.');

const presetSelect = {
    matches(selector) { return selector.includes('select.pf-select-with-others'); },
    closest() { return null; }
};
dimensionsControl.value = '3x5 Ft';
fieldsBody.listeners.change({ target: presetSelect, type: 'change' });
assert.equal(timers.length, 1, 'Tarpaulin preset-size select changes schedule stock revalidation.');
timers.shift()();
dimensionRequest = validations.shift();
assert.equal(dimensionRequest[2].width, '3', 'The selected preset width reaches the stock request.');
assert.equal(dimensionRequest[2].height, '5', 'The selected preset height reaches the stock request.');

for (const [label, width, height] of [
    ['2x2 Ft', '2', '2'], ['2x3 Ft', '2', '3'], ['2x4 Ft', '2', '4'],
    ['3x4 Ft', '3', '4'], ['3x5 Ft', '3', '5'], ['3x6 Ft', '3', '6'],
    ['3x7 Ft', '3', '7'], ['3x8 Ft', '3', '8']
]) {
    dimensionsControl.value = label;
    customSizeVisible = false;
    fieldsBody.listeners.change({ target: presetSelect, type: 'change' });
    timers.shift()();
    dimensionRequest = validations.shift();
    assert.equal(dimensionRequest[2].width, width, `${label} width reaches validation despite blank hidden fields.`);
    assert.equal(dimensionRequest[2].height, height, `${label} height reaches validation despite blank hidden fields.`);
}

dimensionsControl.value = 'Others';
customSizeVisible = true;
widthField.value = '4';
heightField.value = '6';
fieldsBody.listeners.change({ target: presetSelect, type: 'change' });
timers.shift()();
dimensionRequest = validations.shift();
assert.equal(dimensionRequest[2].width, '4', 'Selected custom-size inputs continue to provide width.');
assert.equal(dimensionRequest[2].height, '6', 'Selected custom-size inputs continue to provide height.');

dimensionsControl.value = '0x2 Ft';
customSizeVisible = false;
widthField.value = '0';
heightField.value = '2';
fieldsBody.listeners.input({ target: widthField });
timers.shift()();
dimensionRequest = validations.shift();
assert.equal(dimensionRequest[2].width, '0', 'Zero width is sent for backend rejection rather than omitted.');
assert.equal(dimensionRequest[2].height, '2', 'The valid height is preserved when width is invalid.');

assert.match(source, /requestVersion !== posServiceStockRequestVersion/,
    'Outdated asynchronous stock responses cannot override the newest validation state.');
assert.match(source, /body\.addEventListener\('click', revalidateDimensions\)/,
    'Preset and custom-size choice clicks trigger Tarpaulin revalidation.');
assert.match(source, /event\.type === 'change'.*select\.pf-select-with-others/s,
    'Preset-size select changes trigger Tarpaulin revalidation after the renderer syncs hidden dimensions.');

console.log('POS service stock input validation tests passed.');
