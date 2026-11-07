const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialConsumption = require('../public/assets/js/material_consumption_estimator.js');

globalThis.window = globalThis.window || { PrintFlowMaterialConsumption: materialConsumption, PrintFlowProductionMaterials: null };

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');

assert.match(page, /productionAssignmentSubmitAttempted/, 'Submit-attempt gate exists for Actual Usage errors');
assert.match(page, /showPendingMaterialUsageError\(pm\)/, 'Field-level usage error is gated by helper');
assert.match(page, /Please select at least one material to be used for this job\./, 'No-material message is configured');
assert.doesNotMatch(
  page,
  /Enter a valid positive actual quantity in each material inventory unit\./,
  'Duplicate general Actual Usage banner copy is removed'
);
assert.match(
  page,
  /Blank Actual Usage is deferred until/,
  'approvalStockErrors no longer includes blank quantity errors'
);

const showStart = page.indexOf('            showPendingMaterialUsageError(material) {');
const showEnd = page.indexOf('            pendingMaterialStockError(material) {', showStart);
assert.ok(showStart >= 0 && showEnd > showStart, 'showPendingMaterialUsageError is present');

const stockStart = page.indexOf('            pendingMaterialStockError(material) {');
const stockEnd = page.indexOf('            /** @deprecated Kept for older callers', stockStart);
assert.ok(stockStart >= 0 && stockEnd > stockStart, 'pendingMaterialStockError is present');

const qtyStart = page.indexOf('            posMaterialQuantityError(materials = []) {');
const qtyEnd = page.indexOf('            tarpaulinCalculationForItem(itemId', qtyStart);
assert.ok(qtyStart >= 0 && qtyEnd > qtyStart, 'posMaterialQuantityError is present');

const approvalStart = page.indexOf('            get approvalStockErrors() {');
const approvalEnd = page.indexOf('            // Ink Settings', approvalStart);
assert.ok(approvalStart >= 0 && approvalEnd > approvalStart, 'approvalStockErrors getter is present');

const validationStart = page.indexOf('            validateProductionAssignments(extraMaterials = [], extraInks = []) {');
const validationEnd = page.indexOf('            setProductionValidationFeedback() {', validationStart);
assert.ok(validationStart >= 0 && validationEnd > validationStart, 'validateProductionAssignments is present');

const methods = new Function(`return ({
${page.slice(showStart, showEnd)}
${page.slice(stockStart, stockEnd).replace(/\r\n/g, '\n')}
${page.slice(qtyStart, qtyEnd)}
${page.slice(approvalStart, approvalEnd)}
${page.slice(validationStart, validationEnd)}
});`)();

const item = {
  id: 212,
  name: 'C2s Board',
  unit_of_measure: 'sheet',
  current_stock: 104,
};

const blankRow = {
  item_id: 212,
  name: 'C2s Board',
  uom: 'sheet',
  qty: '',
  manual_confirmation_required: true,
  manual_quantity_confirmed: false,
  dimension_fit_error: '',
  metadata: {},
};

const validRow = {
  ...blankRow,
  qty: 2,
  manual_quantity_confirmed: true,
  metadata: { material_usage: { actual: 2, staff_confirmed: true } },
};

function baseCtx(overrides = {}) {
  return {
    productionAssignmentSubmitAttempted: false,
    productionErrors: { material: '', ink_set: '', ink_consumption: '' },
    pendingMaterials: [],
    currentJo: { materials: [] },
    requiresInk: false,
    materialCompatibilityContext: { serviceId: 71 },
    materialRules: [],
    selectedMaterialStockError: '',
    inkStockIssues: [],
    isPosSetPriceFlow: () => true,
    isPendingTarpaulinMaterial: () => false,
    platesBlankUsageError: () => '',
    posterPrimaryUsageError: () => '',
    getInventoryItem(id) { return Number(id) === 212 ? item : null; },
    isValidPosMaterialQuantity(value, itemId) {
      const inv = this.getInventoryItem(itemId);
      return !!(inv && materialConsumption.parseMaterialQuantity(value, inv) !== null);
    },
    getMaterialRequiredStock(_id, qty) { return Number(qty) || 0; },
    ...overrides,
  };
}

const preSubmit = Object.assign(Object.create(methods), baseCtx({ pendingMaterials: [{ ...blankRow }] }));
assert.equal(
  methods.showPendingMaterialUsageError.call(preSubmit, blankRow),
  false,
  'No field-level Actual Usage error before Confirm Set Price'
);
assert.deepEqual(
  preSubmit.approvalStockErrors,
  [],
  'Blank Actual Usage does not populate the red stock banner before submit'
);

const afterBlankSubmit = Object.assign(Object.create(methods), baseCtx({
  pendingMaterials: [{ ...blankRow }],
  productionAssignmentSubmitAttempted: false,
}));
assert.equal(
  methods.validateProductionAssignments.call(afterBlankSubmit, [{ ...blankRow }], []),
  false,
  'Blank Actual Usage cannot save after Confirm Set Price'
);
assert.equal(afterBlankSubmit.productionAssignmentSubmitAttempted, true, 'Submit attempt is recorded');
assert.equal(
  afterBlankSubmit.productionErrors.material,
  'Enter a valid actual quantity before saving.',
  'Blank/invalid usage shows the field message after submit'
);
assert.equal(
  methods.showPendingMaterialUsageError.call(afterBlankSubmit, blankRow),
  true,
  'Field-level Actual Usage error appears after failed submit'
);

const noMaterialCtx = Object.assign(Object.create(methods), baseCtx({
  pendingMaterials: [],
  currentJo: { materials: [] },
}));
assert.equal(
  methods.validateProductionAssignments.call(noMaterialCtx, [], []),
  false,
  'Confirm Set Price with no material does not save'
);
assert.equal(
  noMaterialCtx.productionErrors.material,
  'Please select at least one material to be used for this job.',
  'No selected material shows the select-a-material message'
);

const validCtx = Object.assign(Object.create(methods), baseCtx({
  pendingMaterials: [{ ...validRow }],
  currentJo: { materials: [] },
}));
assert.equal(
  methods.validateProductionAssignments.call(validCtx, [{ ...validRow }], []),
  true,
  'Valid assignment passes client validation and can save'
);
assert.equal(validCtx.productionErrors.material, '', 'Valid assignment clears material errors');

const stockCtx = Object.assign(Object.create(methods), baseCtx({
  pendingMaterials: [{ ...validRow, qty: 200, manual_quantity_confirmed: true }],
}));
assert.match(
  methods.pendingMaterialStockError.call(stockCtx, stockCtx.pendingMaterials[0]),
  /Insufficient C2s Board stock/,
  'Stock shortage validation remains intact'
);
assert.equal(
  methods.validateProductionAssignments.call(stockCtx, [...stockCtx.pendingMaterials], []),
  false,
  'Insufficient stock still blocks save'
);

assert.match(
  page,
  /:disabled="actionBusy \|\| approvalStockErrors\.length > 0 \|\| !isValidPosFinalPrice\(jobPriceInput\)"/,
  'Confirm Set Price remains clickable when only Actual Usage is blank (stock/price still gate)'
);

console.log('production_assignment_validation_timing_test: PASS');
