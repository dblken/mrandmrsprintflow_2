const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialConsumption = require('../public/assets/js/material_consumption_estimator.js');

globalThis.window = { PrintFlowMaterialConsumption: materialConsumption, PrintFlowProductionMaterials: null };

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');

assert.doesNotMatch(page, />Add actual usage<\/button>/, 'Intermediate Add actual usage step is removed');
assert.doesNotMatch(page, />Confirm Actual Usage<\/button>/, 'Redundant Confirm Actual Usage button is removed');
assert.match(page, /queueMaterialFromPicker\(item/, 'Picker click queues through queueMaterialFromPicker');
assert.match(page, /Enter a valid actual quantity before saving\./, 'Blank usage prompts staff to enter a quantity');
assert.match(page, /maybeAutoSelectTarpaulinRoll/, 'Tarpaulin restores automatic roll selection for Production Assignment');

const selectStart = page.indexOf('            selectMaterialCandidate(item, deliberateKeyboardAction = false) {');
const queueStart = page.indexOf('            queueMaterialFromPicker(item');
const end = page.indexOf('            get materialOverrideTitle() {', queueStart);
assert.ok(selectStart >= 0 && queueStart > selectStart && end > queueStart, 'selectMaterialCandidate and queueMaterialFromPicker are present');
const methods = new Function(`return ({${page.slice(selectStart, end).replace(/\r\n/g, '\n').replace(/\/\*[\s\S]*?\*\//g, '')}\n});`)();

const validationStart = page.indexOf('            validateProductionAssignments(extraMaterials = [], extraInks = []) {');
const validationEnd = page.indexOf('            setProductionValidationFeedback() {', validationStart);
const validationMethods = new Function(`return ({${page.slice(validationStart, validationEnd).replace(/\r\n/g, '\n')}\n});`)();

const stockStart = page.indexOf('            pendingMaterialStockError(material) {');
const stockEnd = page.indexOf('            /** @deprecated Kept for older callers', stockStart);
const stockMethods = new Function(`return ({${page.slice(stockStart, stockEnd).replace(/\r\n/g, '\n')}\n});`)();

const item = {
  id: 212,
  name: 'C2s Board',
  size_variant: 'A4',
  unit_of_measure: 'sheet',
  current_stock: 104
};

const selectableItem = {
  ...item,
  compatibility: { selectable: true, directSelectable: true, inStock: true, tier: 'recommended' }
};
const removedIndexes = [];
const ctx = {
  pendingMaterials: [],
  currentJo: { materials: [] },
  productionErrors: { material: '' },
  materialListExpanded: false,
  materialCompatibilityContext: { serviceId: 67, serviceKind: 'brochure' },
  materialClassification() { return { selectable: true, directSelectable: true, inStock: true, tier: 'recommended' }; },
  getMaterialEntryUom() { return 'sheet'; },
  getInventoryItem(id) { return Number(id) === 212 ? item : null; },
  resolveOrderDimensions() { return { width: 0, height: 0 }; },
  isPendingTarpaulinMaterial() { return false; },
  platesBlankUsageError() { return ''; },
  posterPrimaryUsageError() { return ''; },
  persistedPosTarpaulinSelection() { return null; },
  tarpaulinCalculationForItem() { return null; },
  resetMaterialForm() {},
  syncInkSelectionWithMaterial() {},
  showStaffAlert() {},
  productionAssignmentInventoryItemId(material) {
    const id = Number(material && material.item_id);
    return Number.isFinite(id) && id > 0 ? id : 0;
  },
  clearPendingMaterialConfirmationError() {},
  removePendingMaterial(index) {
    removedIndexes.push(index);
    this.pendingMaterials.splice(index, 1);
    this.syncInkSelectionWithMaterial();
  },
  getMaterialRequiredStock(_id, qty) { return Number(qty) || 0; },
  isValidPosMaterialQuantity(value, itemId) {
    const inv = this.getInventoryItem(itemId);
    return !!(inv && materialConsumption.parseMaterialQuantity(value, inv) !== null);
  },
  pendingMaterialStockError: stockMethods.pendingMaterialStockError,
  productionAssignmentSubmitAttempted: false,
  posMaterialQuantityError(materials = []) {
    for (const material of materials) {
      if (!this.isValidPosMaterialQuantity(material.qty ?? material.quantity, material.item_id)) {
        return 'Enter a valid actual quantity before saving.';
      }
    }
    return '';
  }
};

Object.assign(ctx, methods);
ctx.selectMaterialCandidate(selectableItem);
assert.equal(ctx.pendingMaterials.length, 1, 'One click adds exactly one assignment row');
assert.equal(ctx.pendingMaterials[0].item_id, 212);
assert.equal(ctx.pendingMaterials[0].qty, '', 'No invented quantity is queued');
assert.equal(ctx.pendingMaterials[0].uom, 'sheet');
assert.equal(ctx.pendingMaterials[0].manual_quantity_confirmed, false);
assert.equal(ctx.materialListExpanded, true);

ctx.pendingMaterials[0].qty = 2;
ctx.pendingMaterials[0].manual_quantity_confirmed = true;
ctx.pendingMaterials[0].metadata.material_usage = {
  estimate: null, actual: 2, method: 'manual', staff_confirmed: true, manual_actual_usage_confirmed: true, overridden: false
};
const preservedQty = ctx.pendingMaterials[0].qty;
ctx.queueMaterialFromPicker(selectableItem);
assert.equal(ctx.pendingMaterials.length, 1, 'Duplicate pending assignment for the same material is prevented');
assert.equal(ctx.pendingMaterials[0].qty, preservedQty, 'Existing staff quantity is preserved when the picker refreshes selection state');

const blankRow = { ...ctx.pendingMaterials[0], qty: '', manual_quantity_confirmed: false };
const blankSaveCtx = {
  ...ctx,
  pendingMaterials: [blankRow],
  requiresInk: false,
  productionErrors: {},
  posMaterialQuantityError: ctx.posMaterialQuantityError,
  pendingMaterialStockError: ctx.pendingMaterialStockError
};
assert.equal(
  validationMethods.validateProductionAssignments.call(blankSaveCtx, [blankRow], []),
  false,
  'Blank actual usage cannot save'
);
assert.equal(
  blankSaveCtx.productionErrors.material,
  'Enter a valid actual quantity before saving.',
  'Blank usage after Confirm Set Price uses the field-level message'
);
assert.equal(blankSaveCtx.productionAssignmentSubmitAttempted, true, 'Submit attempt gates post-submit field errors');

const readySaveCtx = {
  ...ctx,
  requiresInk: false,
  productionErrors: {},
  posMaterialQuantityError: ctx.posMaterialQuantityError,
  pendingMaterialStockError: ctx.pendingMaterialStockError
};
assert.equal(
  validationMethods.validateProductionAssignments.call(readySaveCtx, [...ctx.pendingMaterials], []),
  true,
  'Staff-entered valid quantity saves without a Confirm Actual Usage click'
);

ctx.pendingMaterials[0].qty = 200;
assert.equal(
  stockMethods.pendingMaterialStockError.call(ctx, ctx.pendingMaterials[0]),
  'Insufficient C2s Board stock: requested 200 sheet, available 104 sheet in this branch.',
  'Insufficient stock names material, requested qty, available qty, and unit'
);
assert.equal(
  validationMethods.validateProductionAssignments.call({
    ...readySaveCtx,
    pendingMaterials: [...ctx.pendingMaterials],
    productionErrors: {}
  }, [...ctx.pendingMaterials], []),
  false,
  'Insufficient branch stock is rejected before Set Price save'
);

ctx.pendingMaterials[0].qty = 2;
ctx.selectMaterialCandidate(selectableItem);
assert.deepEqual(removedIndexes, [0], 'Clicking an already-assigned pending material uses the safe pending removal path');
assert.equal(ctx.pendingMaterials.length, 0, 'Assigned pending material is unselected on second click');

ctx.selectMaterialCandidate(selectableItem);
assert.equal(ctx.pendingMaterials.length, 1, 'Material can be reselected with one click after safe removal');

const deductedAlerts = [];
const deductedCtx = {
  ...ctx,
  pendingMaterials: [],
  currentJo: {
    materials: [{ id: 88, item_id: 212, deducted_at: '2026-11-05 10:00:00' }]
  },
  showStaffAlert(title) { deductedAlerts.push(title); },
  removeMaterial() { throw new Error('deducted material must not call removeMaterial'); }
};
Object.assign(deductedCtx, methods);
deductedCtx.selectMaterialCandidate(selectableItem);
assert.deepEqual(deductedAlerts, ['Material Already Deducted'], 'Deducted assignments remain protected from picker removal');

assert.match(page, /Material Already Deducted/, 'Deducted assignments remain protected from picker removal');
assert.match(page, /this\.removeMaterial\(assignment\.id\)/, 'Undeducted assigned materials use the safe removal path');
assert.match(page, /fdMaterial\.append\('quantity', pm\.qty\)/, 'Saved assignment posts the manual Actual Usage quantity');
assert.match(page, /productionAssignmentInventoryItemId\(material\)/, 'Confirm Set Price posts canonical inventory item_id only');
assert.match(page, /isEligibleTarpaulinRoll/, 'Auto-select requires an eligible track_by_roll Tarpaulin candidate');
assert.doesNotMatch(page, /material\.item_id \|\| material\.id \|\| 0/, 'JOM primary key is not posted as inventory item_id');

// Auto-select and manual click must serialize the same canonical inventory item_id.
const tarpItem = {
  id: 13,
  name: '3ft Tarpaulin',
  track_by_roll: 1,
  unit_of_measure: 'ft',
  current_stock: 100,
  compatibility: { selectable: true, directSelectable: true, inStock: true, tier: 'recommended' }
};
const tarpCtx = {
  ...ctx,
  pendingMaterials: [],
  currentJo: { materials: [], quantity: 1 },
  materialCompatibilityContext: { serviceId: 40, serviceKind: 'tarpaulin' },
  materialClassification() {
    return { selectable: true, directSelectable: true, inStock: true, tier: 'recommended' };
  },
  getMaterialEntryUom() { return 'ft'; },
  getInventoryItem(id) { return Number(id) === 13 ? tarpItem : null; },
  resolveOrderDimensions() { return { width: 3, height: 8 }; },
  persistedPosTarpaulinSelection() {
    return { item_id: 13, width_ft: 3, height_ft: 8, required_linear_ft: 8, roll_width_ft: 3, orientation: 'original' };
  },
  tarpaulinCalculationForItem() {
    return { required_linear_ft: 8, roll_width_ft: 3, orientation: 'original', run_length_per_piece_ft: 8 };
  },
  allInventoryItems: [tarpItem],
  materialRules: []
};
Object.assign(tarpCtx, methods);
globalThis.window.PrintFlowProductionMaterials = {
  getAutoSelectCandidate() { return tarpItem; },
  tarpaulinRollWidthFor() { return 3; },
  calculateTarpaulinLinearLength() {
    return { required_linear_ft: 8, roll_width_ft: 3, orientation: 'original' };
  }
};
tarpCtx.maybeAutoSelectTarpaulinRoll();
assert.equal(tarpCtx.pendingMaterials.length, 1, 'Auto-select queues the fitted active roll');
assert.equal(tarpCtx.pendingMaterials[0].item_id, 13, 'Auto-select stores canonical mapped inventory id');
assert.equal(tarpCtx.pendingMaterials[0].qty, 8, 'Auto-select prefills Actual Usage from dimensions');
assert.equal(
  tarpCtx.productionAssignmentInventoryItemId(tarpCtx.pendingMaterials[0]),
  13,
  'Auto-selected row posts inventory item_id 13'
);
const autoPayload = { ...tarpCtx.pendingMaterials[0] };
tarpCtx.pendingMaterials = [];
tarpCtx.queueMaterialFromPicker(tarpItem, { autoSelected: false });
assert.equal(tarpCtx.pendingMaterials[0].item_id, autoPayload.item_id, 'Manual select serializes the same item_id as auto-select');
assert.equal(
  tarpCtx.productionAssignmentInventoryItemId(tarpCtx.pendingMaterials[0]),
  tarpCtx.productionAssignmentInventoryItemId(autoPayload),
  'Manual and auto Confirm Set Price payloads share the same canonical item_id'
);

const api = fs.readFileSync(path.join(__dirname, '..', 'admin', 'job_orders_api.php'), 'utf8');
assert.match(api, /getStockOnHandForUpdate/, 'Server rechecks stock before material assignment persistence');
assert.match(api, /Insufficient %s stock for this branch/, 'Server insufficient-stock message names material and quantities');

console.log('production_assignment_one_click_material_test: PASS');
