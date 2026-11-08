'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../staff/pos.php'), 'utf8');
assert.match(source, /posSetServiceStockState\(false, '', ''\)/, 'a successful validation displays no material list or stock quantities');
assert.match(source, /if \(Number\(serviceId\) !== 71\) return \[\];/, 'POS never derives material IDs from production-material controls for any service except the verified Sintra thickness specification');
assert.match(source, /return \[selectedId\];/, 'Sintraboard POS payload contains only the Board Thickness-mapped item, never the optional sticker layer');
assert.doesNotMatch(source, /Materials for this order|data-pos-material-picker/, 'the POS page does not contain a second production-material picker');
assert.match(source, /posSetServiceStockState\(true, '', posServiceStockDisplayMessage\(message, serviceId\)\)/, 'non-stock material explanations are filtered while validation remains blocking');
assert.match(source, /body\.firstElementChild\.textContent = posServiceStaffFacingMaterialMessage\(data\.error \|\| 'Failed to load fields\.', serviceId\)/,
    'service-field API configuration errors are inserted as sanitized staff-facing text');
const stockValidationStart = source.indexOf('        async function posValidateServiceStockNow(');
const stockBindingStart = source.indexOf('        function posBindServiceStockValidation(serviceId) {', stockValidationStart);
const stockValidationSource = source.slice(stockValidationStart, stockBindingStart);
assert.doesNotMatch(stockValidationSource, /Estimated consumption:|to consume|`Stock: \$\{availability\}`/, 'informational consumption and available-stock summaries are not rendered');
assert.match(source, /const message = `Insufficient \$\{issue\.name \|\| 'selected material'\} stock\. Available: \$\{formatQty\(issue\.available\)\} \$\{issue\.uom \|\| 'units'\}\. Required: \$\{formatQty\(issue\.required\)\}/,
    'Insufficient selected-material stock reports the actual material, available quantity, and required quantity for every service');
assert.match(source, /Number\(serviceId\) === 36 && issue\.inventory_discrepancy && issue\.message/,
    'a laminate balance/roll-width mismatch is surfaced as a reconciliation error rather than a false zero-stock message');
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
const boardThicknessRadio = { tagName: 'INPUT', type: 'radio', name: 'board_thickness', value: '', checked: false, dataset: { fieldName: 'board_thickness' }, matches(selector) { return selector === 'input, select, textarea'; } };
// Production places the custom-size hidden fields before the actual preset
// select, so a broad name*=dimension selector can resolve the wrong control.
const dimensionsControl = {
    tagName: 'SELECT', name: 'dimensions', value: '',
    classList: { contains(name) { return name === 'pf-select-with-others'; } },
    dataset: { otherOption: 'Others' }
};
const unitControl = { name: 'dimensions_unit', type: 'hidden', value: 'ft' };
const input = {
    value: '1',
    name: 'quantity',
    type: 'number',
    dataset: {},
    classList: {
        contains(name) { return name === 'pf-service-quantity-input' ? !!this._hasQtyClass : false; },
        add(name) { if (name === 'pf-service-quantity-input') this._hasQtyClass = true; }
    },
    setAttribute(name, value) { this[name] = value; this.attrs = Object.assign({}, this.attrs, { [name]: value }); },
    matches(selector) { return selector === 'input, select, textarea'; },
    addEventListener(name, callback) { (this.listeners ||= {})[name] = callback; },
    closest(selector) {
        if (selector === '.shopee-form-row') return null;
        assert.equal(selector, '.quantity-container');
        return container;
    }
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
    matches(selector) { return selector === 'input, select, textarea'; },
    getAttribute(name) { return name === 'data-other-option' ? 'Others' : null; }
};
const radioDimension = {
    tagName: 'INPUT', type: 'radio', name: 'sizes', value: '', checked: false, dataset: {},
    matches(selector) { return selector === 'input, select, textarea'; },
    closest(selector) { return selector === 'label' ? { textContent: this.labelText || '' } : null; }
};
const branchField = {
    name: 'branch_id', value: '5', type: 'hidden', dataset: {},
    matches(selector) { return selector === 'input, select, textarea'; }
};
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
            ? [input, dimensionWidth, dimensionHeight, finishOption, sizesSelect, radioDimension, boardThicknessRadio, branchField, widthHidden, heightHidden]
            : [input, finishOption, sizesSelect, radioDimension, boardThicknessRadio, branchField];
    },
    querySelector(selector) {
        if (selector === '[name="branch_id"]') return branchField;
        // The renderer's custom-size nodes remain in the DOM while their
        // wrapper is hidden; the selected option determines whether they apply.
        if (selector.includes('.custom-dim-width')) return widthField;
        if (selector.includes('.custom-dim-height')) return heightField;
        if (selector === 'select[name="dimensions"]') return dimensionsControl;
        if (selector.includes('input[name*="dimension" i]')) return unitControl;
        return null;
    }
};
const elements = {
    'sm-fields-body': fieldsBody,
    'service-modal-overlay': { dataset: { materialDimensionFieldKey: '', materialDimensionUnit: 'ft', materialSelectionFieldMap: '' } },
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
    'Please select Sintra 3mm or Sintra 5mm.',
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
    elements['service-modal-overlay'].dataset.materialSelectionFieldMap = JSON.stringify({
        field_key: 'board_thickness', options: { 'Sintra 3mm': 206, 'Sintra 5mm': 207 }
    });
    sizesSelect.name = 'sizes';
    sizesSelect.value = '1 Ft x 2 Ft';
    sizesSelect.selectedIndex = 0;
    sizesSelect.options = [{ textContent: '1 Ft x 2 Ft' }];
    selectedPlateMaterial = null;
    boardThicknessRadio.value = thicknessId === 206 ? 'Sintra 3mm' : 'Sintra 5mm';
    boardThicknessRadio.checked = true;
    context.posBindServiceStockValidation(71);
    const thicknessRequest = validations.shift();
    assert.deepEqual(Array.from(thicknessRequest[2].pos_selected_material_ids), [thicknessId],
        `Sintraboard stock validation receives only the explicitly selected thickness item ${thicknessId}`);
    assert.equal(thicknessRequest[2].width, '1', `Sintraboard thickness ${thicknessId} uses dimensions only for consumption`);
    assert.equal(thicknessRequest[2].height, '2', `Sintraboard thickness ${thicknessId} sends selected height for stock demand`);
    assert.deepEqual(Array.from(context.posCollectServiceStockCustomization().pos_selected_material_ids), [thicknessId],
        `Sintraboard Add to Order customization maps the configured Board Thickness choice to item ${thicknessId}`);
}

// Board thickness, branch, and quantity changes each trigger a fresh
// server-authoritative availability request carrying the current state.
elements['service-modal-overlay'].dataset.serviceId = '71';
elements['service-modal-overlay'].dataset.materialDimensionFieldKey = 'sizes';
elements['service-modal-overlay'].dataset.materialDimensionUnit = 'ft';
elements['service-modal-overlay'].dataset.materialSelectionFieldMap = JSON.stringify({
    field_key: 'board_thickness', options: { 'Sintra 3mm': 206, 'Sintra 5mm': 207 }
});
sizesSelect.name = 'sizes';
sizesSelect.value = '1 Ft x 2 Ft';
sizesSelect.options = [{ textContent: '1 Ft x 2 Ft' }];
input.value = '1';
branchField.value = '1';
boardThicknessRadio.name = 'board_thickness';
boardThicknessRadio.value = 'Sintra 3mm';
boardThicknessRadio.checked = true;
context.posBindServiceStockValidation(71);
validations.shift(); // initial validation on modal open
boardThicknessRadio.value = 'Sintra 5mm';
fieldsBody.listeners.change({ target: boardThicknessRadio });
assert.equal(timers.length, 1, 'Changing Sintraboard thickness schedules immediate stock revalidation.');
timers.shift()();
let sintraRequest = validations.shift();
assert.deepEqual(Array.from(sintraRequest[2].pos_selected_material_ids), [207], 'Changing to 5mm updates validation to item 207 only.');
assert.equal(sintraRequest[2].branch_id, '1', 'Thickness validation uses the currently selected branch.');
input.value = '2';
input.listeners.change();
timers.shift()();
sintraRequest = validations.shift();
assert.equal(sintraRequest[1], '2', 'Changing quantity revalidates using the new quantity.');
assert.deepEqual(Array.from(sintraRequest[2].pos_selected_material_ids), [207], 'Quantity changes preserve only the selected 5mm item.');
branchField.value = '4';
fieldsBody.listeners.change({ target: branchField });
timers.shift()();
sintraRequest = validations.shift();
assert.equal(sintraRequest[2].branch_id, '4', 'Changing branch revalidates against the new branch.');
assert.deepEqual(Array.from(sintraRequest[2].pos_selected_material_ids), [207], 'Branch changes do not switch the selected thickness.');
assert.match(source, /!plateTypeSelected\s*&&\s*!sintraThicknessSelected\)/,
    'Incomplete-form deferral still performs a stock check as soon as a valid Sintra thickness is selected.');
assert.match(source, /if \(Number\(serviceId\) === 71 && sintraThicknessSelected\)[\s\S]*?sintraMaterial\.availability_only[\s\S]*?safeRequired > safeAvailable/,
    'The Add to Order validation path blocks unavailable stock while allowing availability-only checks before dimensions are entered.');
input.value = '1';
branchField.value = '5';
selectedPlateMaterial = { value: '23', type: 'checkbox', checked: true, dataset: { posMaterialId: '23', posMaterialGroup: 'optional' } };
boardThicknessRadio.value = 'Sintra 3mm';
boardThicknessRadio.checked = true;
const thicknessWithOptionalLayer = context.posCollectServiceStockCustomization();
assert.deepEqual(Array.from(thicknessWithOptionalLayer.pos_selected_material_ids), [206],
    'the explicit thickness field reaches validation while a stale optional-layer control is ignored');
selectedPlateMaterial = null;
boardThicknessRadio.checked = false;
const noSintraThickness = context.posCollectServiceStockCustomization();
assert.deepEqual(Array.from(noSintraThickness.pos_selected_material_ids), [], 'unselected Board Thickness sends no material ID and remains blocking');
assert.match(source, /posSelectedServiceMaterialIds\(serviceId, customization, body, overlay\)/,
    'stock validation and Add to Order share the configured Board Thickness to stable item ID resolver');

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

// Matte Cut Out is a mapped laminate finish too. Finish/size, quantity, and
// branch edits must each recheck the current branch's laminate availability.
elements['service-modal-overlay'].dataset.serviceId = '36';
elements['service-modal-overlay'].dataset.materialDimensionFieldKey = 'dimensions';
elements['service-modal-overlay'].dataset.materialDimensionUnit = 'ft';
sizesSelect.name = 'dimensions';
sizesSelect.value = 'Matte Sticker Printed (1ft X 2ft)';
sizesSelect.options = [{ textContent: sizesSelect.value }];
input.value = '1';
branchField.value = '1';
context.posBindServiceStockValidation(36);
validations.shift(); // initial modal availability check
sizesSelect.value = 'Matte Sticker Cut Out (1ft X 2ft)';
sizesSelect.options = [{ textContent: sizesSelect.value }];
fieldsBody.listeners.change({ target: sizesSelect });
timers.shift()();
let stickerRequest = validations.shift();
assert.equal(stickerRequest[0], 36, 'Matte Cut Out revalidates through the service stock endpoint');
assert.equal(stickerRequest[2].dimensions, 'Matte Sticker Cut Out (1ft X 2ft)', 'finish change sends the selected matte cut-out option');
assert.equal(stickerRequest[2].branch_id, '1', 'sticker finish check is scoped to the current branch');
input.value = '100';
input.listeners.change();
timers.shift()();
stickerRequest = validations.shift();
assert.equal(stickerRequest[1], '100', 'sticker quantity changes trigger demand revalidation');
assert.equal(stickerRequest[2].dimensions, 'Matte Sticker Cut Out (1ft X 2ft)', 'quantity recheck retains the selected finish and dimensions');
branchField.value = '4';
fieldsBody.listeners.change({ target: branchField });
timers.shift()();
stickerRequest = validations.shift();
assert.equal(stickerRequest[2].branch_id, '4', 'branch changes revalidate sticker laminate against the selected branch');
assert.equal(stickerRequest[1], '100', 'branch recheck retains requested sticker quantity');

plateHasDimensionControls = false;
selectedPlateMaterial = { value: '2', dataset: { posMaterialId: '2' } };
input.value = '1';
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
assert.deepEqual(Array.from(plateRequest[2].pos_selected_material_ids), [], 'Plate material choice is deferred to Production Assignment because Type Of Plates does not identify a verified inventory item');
assert.equal(plateRequest[2].quantity, '1', 'the standard Plates order quantity is included in stock validation');
const plateAddCustomization = context.posCollectServiceStockCustomization();
assert.equal(plateAddCustomization.type_of_plates, 'Motor Cycle Plate', 'the Plate type remains in the Add to Order customization');
assert.equal(Object.hasOwn(plateAddCustomization, 'width'), false, 'the Add to Order customization remains quantity-based and dimension-free for Plates');
assert.deepEqual(Array.from(plateAddCustomization.pos_selected_material_ids), [], 'the Add to Order payload does not force an internal Plate material choice');

// Verified customer plate types run the immediate branch-scoped stock check
// even while another required specification is incomplete. A type change
// schedules a fresh check; the server remains authoritative on Add to Order.
plateHasDimensionControls = false;
elements['service-modal-overlay'].dataset.serviceId = '39';
elements['service-modal-overlay'].dataset.materialDimensionFieldKey = '';
branchField.value = '2';
input.value = '3';
for (const [type, itemId] of [
    ['New Plate', 2],
    ['Car And Euro Plate', 3],
    ['Address Plate', 6],
    ['Adress Plate', 6]
]) {
    radioDimension.name = 'type_of_plates';
    radioDimension.value = type;
    radioDimension.checked = true;
    context.posBindServiceStockValidation(39);
    const request = validations.shift();
    assert.equal(request[2].type_of_plates, type, `${type} is sent to early stock validation`);
    assert.equal(request[2].branch_id, '2', `${type} stock check uses the selected branch`);
    assert.equal(request[1], '3', `${type} stock check uses current order quantity`);
    assert.match(source, new RegExp(`'${type.toLowerCase()}'\\s*:\\s*${itemId}`),
        `${type} uses the exact verified inventory item #${itemId}`);
}
radioDimension.value = 'New Plate';
radioDimension.checked = true;
context.posBindServiceStockValidation(39);
validations.shift(); // initial check on modal open
radioDimension.value = 'Car And Euro Plate';
fieldsBody.listeners.change({ target: radioDimension });
assert.equal(timers.length, 1, 'changing plate type schedules an immediate stock recheck');
timers.shift()();
const changedPlateRequest = validations.shift();
assert.equal(changedPlateRequest[2].type_of_plates, 'Car And Euro Plate', 'type-change recheck carries the new selected Plate type');
assert.equal(changedPlateRequest[2].branch_id, '2', 'type-change recheck retains current branch scope');
branchField.value = '4';
fieldsBody.listeners.change({ target: branchField });
timers.shift()();
const changedBranchRequest = validations.shift();
assert.equal(changedBranchRequest[2].branch_id, '4', 'branch-change recheck carries the new branch');
assert.equal(changedBranchRequest[2].type_of_plates, 'Car And Euro Plate', 'branch-change recheck retains selected plate type');
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
assert.ok(sharedConfirmSource.indexOf('posSelectedServiceMaterialIds(serviceId, customization, body, overlay)')
    < sharedConfirmSource.indexOf("syncedCartAction('add'")
    && /customization\s*:\s*customization/.test(sharedConfirmSource),
    'only specification-resolved material IDs flow into the Add to Order request; unresolved production materials are deferred');
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
assert.equal(listenerCounts.input, 16, 'each service modal opening, including sticker finish, Sintraboard, and Plate type cases, registers one delegated stock input handler');
assert.equal(listenerCounts.change, 16, 'each service modal opening, including sticker finish, Sintraboard, and Plate type cases, registers one delegated stock change handler');
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
