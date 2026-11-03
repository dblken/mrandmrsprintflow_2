'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../staff/pos.php'), 'utf8');
assert.match(source, /posSetServiceStockState\(false, '', ''\)/, 'a successful validation displays no material list or stock quantities');
assert.match(source, /posSetServiceStockState\(true, '', posServiceStockDisplayMessage\(message, serviceId\)\)/, 'non-stock material explanations are filtered while validation remains blocking');
assert.match(source, /body\.firstElementChild\.textContent = posServiceStaffFacingMaterialMessage\(data\.error \|\| 'Failed to load fields\.', serviceId\)/,
    'service-field API configuration errors are inserted as sanitized staff-facing text');
const stockValidationStart = source.indexOf('        async function posValidateServiceStockNow(');
const stockBindingStart = source.indexOf('        function posBindServiceStockValidation(serviceId) {', stockValidationStart);
const stockValidationSource = source.slice(stockValidationStart, stockBindingStart);
assert.doesNotMatch(stockValidationSource, /Estimated consumption:|to consume|`Stock: \$\{availability\}`/, 'informational consumption and available-stock summaries are not rendered');
assert.match(source, /const message = `Insufficient \$\{issue\.name \|\| 'selected material'\} stock\. Available: \$\{formatQty\(issue\.available\)\} \$\{issue\.uom \|\| 'units'\}\. Required: \$\{formatQty\(issue\.required\)\}/,
    'Insufficient selected-material stock reports the actual material, available quantity, and required quantity for every service');
assert.match(source, /posSetServiceStockState\(true, '', posServiceStockDisplayMessage\(message, serviceId\)\);\s*return \{ valid: false, message \};/,
    'Insufficient selected-material stock continues to block Add to Order');
const confirmStart = source.indexOf('        async function confirmServiceModal() {');
const confirmEnd = source.indexOf('\n        function ', confirmStart + 20);
const confirmSource = source.slice(confirmStart, confirmEnd);
assert.ok(confirmSource.indexOf('validateServiceOrderForm()') < confirmSource.indexOf('posValidateServiceStockNow(')
    && /if \(!validationResult\.valid\) \{[\s\S]*?showValidationErrors\(validationResult\.errors\)[\s\S]*?return;/.test(confirmSource),
    'Required empty or invalid configured fields still show validation and block before stock checks or Add to Order');
assert.match(source, /<div class="sm-footer-inner">[\s\S]*?<div class="sm-footer-buttons">[\s\S]*?<\/div>\s*<\/div>\s*<div id="pos-service-stock-feedback"/,
    'stock feedback is outside the stable Estimated Price and button row');
const footerCssStart = source.indexOf('#service-modal-overlay .sm-footer-inner {');
const footerCssEnd = source.indexOf('\n        }', footerCssStart);
assert.ok(footerCssStart >= 0 && footerCssEnd > footerCssStart, 'footer layout rule exists');
assert.doesNotMatch(source.slice(footerCssStart, footerCssEnd), /flex-wrap/,
    'stock feedback visibility cannot cause the price and action row to flex-wrap');
const collectStart = source.indexOf('        function posCollectServiceStockCustomization() {');
const validateStart = source.indexOf('        async function posValidateServiceStockNow(', collectStart);
const bindStart = source.indexOf('        function posBindServiceStockValidation(serviceId) {', validateStart);
const disposeStart = source.indexOf('        function posDisposeServiceStockValidationListeners() {');
const end = source.indexOf('        function pfLayoutOptionCanonical(', bindStart);
assert.ok(collectStart >= 0 && validateStart > collectStart && bindStart > validateStart && disposeStart >= 0 && end > bindStart, 'service stock collection, cleanup, and binding functions are present');

const callbacks = {};
const listenerOptions = {};
const listenerCounts = {};
const validationControllers = [];
class TestAbortController {
    constructor() { this.signal = {}; this.aborted = false; validationControllers.push(this); }
    abort() { this.aborted = true; }
}
const buttons = [{ listeners: {} }, { listeners: {} }];
const widthHidden = { name: 'width', type: 'hidden', dataset: { dimensionRole: 'width' }, value: '3' };
const heightHidden = { name: 'height', type: 'hidden', dataset: { dimensionRole: 'height' }, value: '4' };
const widthField = { value: '', matches(selector) { return selector === 'input, select, textarea' || selector.includes('.custom-dim-width'); }, closest() { return null; } };
const heightField = { value: '', matches(selector) { return selector === 'input, select, textarea' || selector.includes('.custom-dim-height'); }, closest() { return null; } };
let customSizeVisible = false;
let plateHasDimensionControls = true;
let selectedPlateMaterial = null;
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
const dimensionWidth = { name: 'width', value: '3', type: 'number', dataset: { dimensionRole: 'width', fieldName: 'width' } };
const dimensionHeight = { name: 'height', value: '4', type: 'number', dataset: { dimensionRole: 'height', fieldName: 'height' } };
const finishOption = { name: 'finish', value: 'Matte', type: 'select-one', dataset: { fieldName: 'finish' } };
const sizesSelect = {
    tagName: 'SELECT', name: 'sizes', value: '', type: 'select-one', selectedIndex: 0,
    options: [], dataset: {},
    getAttribute(name) { return name === 'data-other-option' ? 'Others' : null; }
};
const radioDimension = {
    tagName: 'INPUT', type: 'radio', name: 'sizes', value: '', checked: false, dataset: {},
    closest(selector) { return selector === 'label' ? { textContent: this.labelText || '' } : null; }
};
const branchField = { name: 'branch_id', value: '5', type: 'hidden', dataset: {} };
const fieldsBody = {
    listeners: {},
    contains(node) { return node === input; },
    addEventListener(name, callback, options) {
        this.listeners[name] = callback;
        listenerOptions[name] = options;
        listenerCounts[name] = (listenerCounts[name] || 0) + 1;
    },
    querySelectorAll(selector) {
        if (selector.includes('data-pos-material-id')) return selectedPlateMaterial ? [selectedPlateMaterial] : [];
        if (selector.includes('data-dimension-role')) return plateHasDimensionControls ? [widthHidden, heightHidden] : [];
        if (selector === 'select') return [sizesSelect, dimensionsControl];
        if (selector === 'select, input[type="radio"]') return [sizesSelect, radioDimension];
        return plateHasDimensionControls
            ? [input, dimensionWidth, dimensionHeight, finishOption, sizesSelect, radioDimension, branchField, widthHidden, heightHidden]
            : [input, finishOption, sizesSelect, radioDimension, branchField];
    },
    querySelector(selector) {
        if (selector === '[name="branch_id"]') return branchField;
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
    'service-modal-overlay': { dataset: { materialDimensionFieldKey: '', materialDimensionUnit: 'ft' } },
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
            Number(serviceId) === 40 ? context.posCollectTarpaulinStockCustomization()
                : [36, 39, 67, 71].includes(Number(serviceId)) ? context.posCollectServiceStockCustomization() : null
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
    posServiceStockRequestVersion: 0,
    posServiceStockValidationController: null,
    posServiceStockBlocking: false,
    AbortController: TestAbortController,
    posServiceStockRequestVersion: 0,
    isAddingToOrder: false
});

const staffMessageStart = source.indexOf('        function posServiceStaffFacingMaterialMessage(message, serviceId = null) {');
const staffMessageEnd = source.indexOf('\n        async function openServiceModal(', staffMessageStart);
const displayMessageStart = source.indexOf('        function posServiceStockDisplayMessage(message, serviceId = null) {');
const displayMessageEnd = source.indexOf('\n        function posDisposeServiceStockValidationListeners()', displayMessageStart);
assert.ok(staffMessageStart >= 0 && staffMessageEnd > staffMessageStart && displayMessageStart > staffMessageEnd && displayMessageEnd > displayMessageStart, 'material feedback display filter and staff-safe error translator are present');
vm.runInContext(source.slice(staffMessageStart, staffMessageEnd) + source.slice(displayMessageStart, displayMessageEnd), context);
const sintraStaffMessage = 'May kailangan bang linawin tungkol sa material na gagamitin? May nalilito ba sa pagpili ng material? Pakisuri ang Sintra 3mm o Sintra 5mm bago magpatuloy.';
const sintraTechnicalErrors = [
    'Stable and legacy material mappings conflict. An Admin must resolve the duplicate configuration.',
    'Stable-ID and legacy Sintraboard mappings conflict by item, rule type, multiplier basis, or approval state. An Admin must review the service-material configuration before POS stock validation.',
    'Multiple rules exist for one service/material ID. An Admin must resolve the duplicate configuration.',
];
sintraTechnicalErrors.forEach(error => {
  assert.equal(context.posServiceStockDisplayMessage(error, 71), sintraStaffMessage,
    'Sintraboard identity diagnostics show only the staff-friendly material clarification prompt');
  assert.doesNotMatch(context.posServiceStockDisplayMessage(error, 71), /stable|legacy|mapping|configuration|Admin/i,
    'technical rule identity terms do not reach Sintraboard staff');
});
assert.equal(context.posServiceStockDisplayMessage(sintraTechnicalErrors[0], 67), '',
    'the Sintraboard-only wording change does not affect other services');
assert.equal(context.posServiceStockDisplayMessage('Select exactly one material from the sintra_thickness choices.', 71),
    'Piliin ang Sintra 3mm o Sintra 5mm ayon sa hiling ng customer.',
    'an unselected thickness shows a clear staff instruction instead of an internal group key');
assert.equal(context.posServiceStockDisplayMessage('Sintra 3mm is out of stock.', 71), 'Sintra 3mm is out of stock.',
    'selected Sintraboard no-stock failures remain visible');
assert.match(context.posServiceStockDisplayMessage('Insufficient Sintra 5mm stock. Available: 1 pcs. Required: 2 pcs.', 71),
    /Insufficient Sintra 5mm stock/,
    'selected Sintraboard insufficient-stock failures remain visible with the material name');
assert.equal(context.posServiceStockDisplayMessage('Estimated consumption: C2S Board: 4 sheets to consume'), '',
    'estimated-consumption summaries are hidden');
assert.equal(context.posServiceStockDisplayMessage('Stock: C2S Board: 8 sheets available'), '',
    'available-stock summaries are hidden');
assert.equal(context.posServiceStockDisplayMessage('Select exactly one material from the brochure paper choices.'), '',
    'Brochure material-choice explanations are hidden');
assert.equal(context.posServiceStockDisplayMessage('Select a configured size or enter valid custom width and height before checking material stock.'), '',
    'material precheck dimension guidance is hidden from stock feedback');
assert.equal(context.posServiceStockDisplayMessage('Stable-ID and legacy Sintraboard mappings conflict by item, rule type, multiplier basis, or approval state. An Admin must review the service-material configuration before POS stock validation.', 71), sintraStaffMessage,
    'conflicting Sintraboard rule identities remain blocked with the staff-friendly material prompt');
assert.match(context.posServiceStockDisplayMessage('Insufficient C2s Board stock. Available: 2 sheets. Required: 4 sheets.'), /Insufficient C2s Board stock/,
    'actual insufficient-stock errors retain the material and shortage quantities');
assert.equal(context.posServiceStockDisplayMessage('Nextjet is out of stock.'), 'Nextjet is out of stock.',
    'out-of-stock errors remain visible');
assert.equal(context.posServiceStockDisplayMessage('Low stock: only 1 sheet remains.'), 'Low stock: only 1 sheet remains.',
    'existing blocking low-stock messages remain visible');
assert.equal(context.posServiceStockDisplayMessage('Width must be greater than 0 and at most 100 feet.'), 'Width must be greater than 0 and at most 100 feet.',
    'dimension field validation remains visible');
assert.equal(context.posServiceStockDisplayMessage('Width must be greater than 0 and at most 100 feet.', 40), '',
    'Tarpaulin internal material-precheck width text is hidden');
assert.equal(context.posServiceStockDisplayMessage('Height must be greater than 0 and at most 100 feet.', 40), '',
    'Tarpaulin internal material-precheck height text is hidden');
assert.equal(context.posServiceStockDisplayMessage('Tarpaulin stock must be validated for the selected POS branch. Reopen the service and try again.', 40), '',
    'Tarpaulin branch/precheck guidance is hidden without changing the failed result');
assert.equal(context.posServiceStockDisplayMessage('No active Tarpaulin roll width can fit these dimensions, including rotation.', 40), 'No active Tarpaulin roll width can fit these dimensions, including rotation.',
    'a Tarpaulin roll availability failure remains visible');
assert.equal(context.posServiceStockDisplayMessage('This service must have one explicit dimension unit before area-based material consumption can be used.'), 'This service must have one explicit dimension unit before area-based material consumption can be used.',
    'required dimension configuration validation remains visible while informational material explanations are hidden');
assert.match(stockValidationSource, /if \(!response\.ok \|\| data\.success === false\) \{[\s\S]*?posSetServiceStockState\(true, '', posServiceStockDisplayMessage\(message, serviceId\)\);\s*return \{ valid: false, message \};/,
    'suppressed stock-precheck copy does not turn a failed backend stock/dimension precheck into a valid result');
assert.match(stockValidationSource, /if \(!validDim\(width\) \|\| !validDim\(height\)\) \{[\s\S]*?posSetServiceStockState\(true, '', posServiceStockDisplayMessage\(message, serviceId\)\);\s*return \{ valid: false, message \};/,
    'Tarpaulin invalid width or height remains blocking even though its internal precheck message is hidden');
assert.match(source, /function confirmServiceModal\(\) \{[\s\S]*?const validationResult = validateServiceOrderForm\(\);[\s\S]*?if \(!validationResult\.valid\) \{[\s\S]*?showValidationErrors\(validationResult\.errors\)[\s\S]*?return;[\s\S]*?posValidateServiceStockNow\(/,
    'normal required-field errors remain visible and block before the Tarpaulin stock precheck');

vm.runInContext(source.slice(disposeStart, collectStart) + source.slice(collectStart, validateStart) + source.slice(bindStart, end), context);
const confirmFlowStart = source.indexOf('        async function confirmServiceModal() {');
const confirmDimensionSync = source.indexOf('posApplySelectedServiceDimension(customization, body, overlay);', confirmFlowStart);
const addServiceRequest = source.indexOf("const result = await syncedCartAction('add'", confirmFlowStart);
assert.ok(confirmFlowStart >= 0 && confirmDimensionSync > confirmFlowStart && confirmDimensionSync < addServiceRequest
    && source.indexOf('customization: customization', addServiceRequest) > addServiceRequest,
    'Add to Order applies the same selected preset dimension mapping as early stock validation');
const customization = context.posCollectServiceStockCustomization();
assert.equal(customization.width, '3', 'stock preview captures current width');
assert.equal(customization.height, '4', 'stock preview captures current height');
assert.equal(customization.finish, 'Matte', 'stock preview captures selected service options');
assert.equal(customization.branch_id, '5', 'stock preview captures the assigned branch');
assert.equal(customization.quantity, '1', 'stock preview captures requested quantity');
elements['service-modal-overlay'].dataset.serviceId = '67';
elements['service-modal-overlay'].dataset.materialDimensionFieldKey = 'sizes';
sizesSelect.value = '25pcs A4 (8 x 11 Inch)';
sizesSelect.selectedIndex = 0;
sizesSelect.options = [{ textContent: '25pcs A4 (8 x 11 Inch)' }];
const brochureCustomization = context.posCollectServiceStockCustomization();
assert.equal(brochureCustomization.sizes, '25pcs A4 (8 x 11 Inch)', 'Brochure stock request preserves the selected priced option');
assert.equal(brochureCustomization.width, '8', 'Brochure preset parses the physical width for area demand');
assert.equal(brochureCustomization.height, '11', 'Brochure preset parses the physical height for area demand');
assert.equal(brochureCustomization.pos_material_dimension_unit, 'in', 'Brochure preset unit is derived from the selected option label');
assert.equal(Object.hasOwn(brochureCustomization, 'pos_selected_material_ids'), false,
    'Brochure Add to Order customization does not expose an internal paper choice as a staff/customer selection');
context.posBindServiceStockValidation(67);
const initialBrochureRequest = validations.shift();
assert.equal(initialBrochureRequest[0], 67, 'Brochure modal validates through the normal service stock path');
assert.equal(initialBrochureRequest[2].width, '8', 'Brochure modal passes selected width to stock validation');
assert.equal(initialBrochureRequest[2].height, '11', 'Brochure modal passes selected height to stock validation');
assert.equal(initialBrochureRequest[2].pos_material_dimension_unit, 'in', 'Brochure modal passes the physical option unit');
assert.equal(Object.hasOwn(initialBrochureRequest[2], 'pos_selected_material_ids'), false,
    'early Brochure stock validation carries no manual material choice');
assert.match(source, /if \(serviceId === 67\) \{\s*delete customization\.pos_selected_material_ids;/,
    'the final Add to Order request omits Brochure paper IDs and lets the backend resolve the internal alternative');

for (const thicknessId of [206, 207]) {
    elements['service-modal-overlay'].dataset.serviceId = '71';
    elements['service-modal-overlay'].dataset.materialDimensionFieldKey = 'sizes';
    elements['service-modal-overlay'].dataset.materialDimensionUnit = 'ft';
    sizesSelect.name = 'sizes';
    sizesSelect.value = '1 Ft x 2 Ft';
    sizesSelect.selectedIndex = 0;
    sizesSelect.options = [{ textContent: '1 Ft x 2 Ft' }];
    selectedPlateMaterial = { type: 'radio', value: String(thicknessId), checked: true, dataset: { posMaterialId: String(thicknessId), posMaterialGroup: 'sintra_thickness' } };
    context.posBindServiceStockValidation(71);
    const thicknessRequest = validations.shift();
    assert.deepEqual(Array.from(thicknessRequest[2].pos_selected_material_ids), [thicknessId],
        `Sintraboard stock validation receives only the explicitly selected thickness item ${thicknessId}`);
    assert.equal(thicknessRequest[2].width, '1', `Sintraboard thickness ${thicknessId} uses dimensions only for consumption`);
    assert.equal(thicknessRequest[2].height, '2', `Sintraboard thickness ${thicknessId} sends selected height for stock demand`);
    assert.deepEqual(Array.from(context.posCollectServiceStockCustomization().pos_selected_material_ids), [thicknessId],
        `Sintraboard Add to Order customization retains the explicit thickness item ${thicknessId}`);
}
selectedPlateMaterial = null;
assert.match(source, /customization\.pos_selected_material_ids = Array\.from\(body\.querySelectorAll\('\[data-pos-material-id\]:checked'\)\)/,
    'Sintraboard Add to Order derives selected thickness IDs from the checked selector control');

// Configured option labels captured from the live read-only service settings.
const selectedDimensionCases = [
    { serviceId: 71, type: 'select', value: '1 Ft x 2 Ft', unit: 'ft' },
    { serviceId: 36, type: 'select', value: 'Matte Sticker Cut Out (1ft X 2ft)', unit: 'ft' },
    { serviceId: 67, type: 'select', value: '25pcs A4 (8 x 11 Inch)', unit: 'in' }
];
for (const testCase of selectedDimensionCases) {
    elements['service-modal-overlay'].dataset.materialDimensionFieldKey = testCase.serviceId === 36 ? 'dimensions' : 'sizes';
    elements['service-modal-overlay'].dataset.materialDimensionUnit = testCase.unit;
    sizesSelect.name = elements['service-modal-overlay'].dataset.materialDimensionFieldKey;
    sizesSelect.value = testCase.value;
    sizesSelect.selectedIndex = 0;
    sizesSelect.options = [{ textContent: testCase.value }];
    radioDimension.checked = false;
    context.posBindServiceStockValidation(testCase.serviceId);
    const request = validations.shift();
    assert.equal(request[0], testCase.serviceId, `service ${testCase.serviceId} uses the shared stock validation endpoint`);
    assert.equal(request[2].width, testCase.serviceId === 67 ? '8' : '1',
        `service ${testCase.serviceId} sends its selected option width`);
    assert.equal(request[2].height, testCase.serviceId === 67 ? '11' : '2',
        `service ${testCase.serviceId} sends its selected option height`);
    assert.equal(request[2].pos_material_dimension_unit, testCase.unit,
        `service ${testCase.serviceId} sends the selected option's explicit unit`);
}
plateHasDimensionControls = false;
selectedPlateMaterial = { value: '2', dataset: { posMaterialId: '2' } };
elements['service-modal-overlay'].dataset.serviceId = '39';
elements['service-modal-overlay'].dataset.materialDimensionFieldKey = '';
elements['service-modal-overlay'].dataset.materialDimensionUnit = '';
radioDimension.name = 'type_of_plates';
radioDimension.value = 'Motor Cycle Plate';
radioDimension.checked = true;
context.posBindServiceStockValidation(39);
const plateRequest = validations.shift();
assert.equal(plateRequest[2].type_of_plates, 'Motor Cycle Plate', 'Plates keeps its selected plate-type value');
assert.equal(Object.hasOwn(plateRequest[2], 'width'), false, 'standard Plates stock validation sends no invented width');
assert.equal(Object.hasOwn(plateRequest[2], 'height'), false, 'standard Plates stock validation sends no invented height');
assert.deepEqual(Array.from(plateRequest[2].pos_selected_material_ids), [2], 'the explicitly selected Plate material is included in stock validation');
assert.equal(plateRequest[2].quantity, '1', 'the standard Plates order quantity is included in stock validation');
const plateAddCustomization = context.posCollectServiceStockCustomization();
assert.equal(plateAddCustomization.type_of_plates, 'Motor Cycle Plate', 'the Plate type remains in the Add to Order customization');
assert.equal(Object.hasOwn(plateAddCustomization, 'width'), false, 'the Add to Order customization remains quantity-based and dimension-free for Plates');
assert.deepEqual(Array.from(plateAddCustomization.pos_selected_material_ids), [2], 'the selected Plate material reaches the Add to Order customization payload');
plateHasDimensionControls = true;
selectedPlateMaterial = null;
radioDimension.checked = false;
const sharedConfirmStart = source.indexOf('        async function confirmServiceModal() {');
const sharedConfirmEnd = source.indexOf('\n        function ', sharedConfirmStart + 20);
const sharedConfirmSource = source.slice(sharedConfirmStart, sharedConfirmEnd);
assert.ok(sharedConfirmSource.indexOf('posApplySelectedServiceDimension(customization, body, overlay);')
    < sharedConfirmSource.indexOf("syncedCartAction('add'"),
    'the Add to Order handler applies selected dimensions before sending the request');
assert.match(sharedConfirmSource, /customization\s*:\s*customization/,
    'the dimensions used in stock validation are included in the Add to Order customization payload');
assert.ok(sharedConfirmSource.indexOf("customization.pos_selected_material_ids = Array.from(body.querySelectorAll('[data-pos-material-id]:checked'))")
    < sharedConfirmSource.indexOf("syncedCartAction('add'")
    && /customization\s*:\s*customization/.test(sharedConfirmSource),
    'the selected Plate material and Type Of Plates choice flow into the Add to Order request customization');
context.posBindServiceStockValidation(42);
assert.equal(validations.shift()[0], 42, 'opening a non-Tarpaulin service validates its initial quantity');

input.value = '2';
buttons[1].listeners.click();
assert.equal(timers.length, 1, 'plus/minus click schedules validation after its inline value update');
timers.shift()();
assert.equal(timers.length, 1, 'quantity button validation is debounced after the control updates');
timers.shift()();
assert.deepEqual(validations.shift().slice(0, 2), [42, '2'], 'button-driven quantity change validates the updated value');

input.value = '4';
input.listeners.input();
assert.equal(timers.length, 1, 'typing continues to debounce stock validation');
timers.shift()();
assert.deepEqual(validations.shift().slice(0, 2), [42, '4'], 'typed quantity validates through the existing request path');

elements['service-modal-overlay'].dataset.materialDimensionFieldKey = '';
sizesSelect.value = '';
context.posBindServiceStockValidation(40);
assert.equal(listenerCounts.input, 9, 'each service modal opening registers one delegated stock input handler');
assert.equal(listenerCounts.change, 9, 'each service modal opening registers one delegated stock change handler');
assert.equal(validationControllers[0].aborted, true, 'opening another service modal aborts the prior delegated validation listeners');
assert.equal(listenerOptions.input.signal, listenerOptions.change.signal, 'input and change handlers share the current modal listener lifetime');
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
    matches(selector) { return selector === 'input, select, textarea' || selector.includes('select.pf-select-with-others'); },
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
assert.match(source, /body\.addEventListener\('click', \(event\) =>/,
    'Preset and custom-size choice clicks trigger Tarpaulin revalidation.');
assert.match(source, /body\.addEventListener\('change', \(event\) =>/,
    'Preset-size select changes trigger the shared service stock revalidation handler.');

let optionValidationTimer;
fieldsBody.listeners.change({ target: { matches: () => true } });
assert.equal(timers.length, 1, 'changing a service option schedules stock revalidation');
optionValidationTimer = timers.shift();
optionValidationTimer();
assert.deepEqual(validations.shift().slice(0, 2), [40, '4'], 'Tarpaulin option revalidation uses the current quantity.');

let versionBeforeDimensionChange = context.posServiceStockRequestVersion;
fieldsBody.listeners.input({ target: { matches: () => true } });
assert.ok(context.posServiceStockRequestVersion > versionBeforeDimensionChange, 'dimension changes immediately invalidate stale validations');
assert.equal(timers.length, 1, 'changing a dimension schedules stock revalidation');
timers.shift()();
assert.deepEqual(validations.shift().slice(0, 2), [40, '4'], 'Tarpaulin dimension revalidation uses the current quantity.');

console.log('POS service stock input validation tests passed.');
