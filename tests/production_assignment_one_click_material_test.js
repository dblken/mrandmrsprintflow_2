const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialConsumption = require('../public/assets/js/material_consumption_estimator.js');

globalThis.window = { PrintFlowMaterialConsumption: materialConsumption, PrintFlowProductionMaterials: null };

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');

assert.doesNotMatch(page, />Add actual usage<\/button>/, 'Intermediate Add actual usage step is removed');
assert.doesNotMatch(page, />Confirm Actual Usage<\/button>/, 'Redundant Confirm Actual Usage button is removed');
assert.match(page, /queueMaterialFromPicker\(item\)/, 'Picker click queues through queueMaterialFromPicker');
assert.match(page, /Enter a valid actual quantity before saving\./, 'Blank usage prompts staff to enter a quantity');

const selectStart = page.indexOf('            selectMaterialCandidate(item, deliberateKeyboardAction = false) {');
const queueStart = page.indexOf('            queueMaterialFromPicker(item) {');
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
  materialClassification() { return { selectable: true, directSelectable: true, inStock: true, tier: 'recommended' }; },
  getMaterialEntryUom() { return 'sheet'; },
  getInventoryItem(id) { return Number(id) === 212 ? item : null; },
  resetMaterialForm() {},
  syncInkSelectionWithMaterial() {},
  showStaffAlert() {},
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
  posMaterialQuantityError(materials = []) {
    for (const material of materials) {
      if (!this.isValidPosMaterialQuantity(material.qty ?? material.quantity, material.item_id)) {
        return 'Enter a valid positive actual quantity in each material inventory unit.';
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

const api = fs.readFileSync(path.join(__dirname, '..', 'admin', 'job_orders_api.php'), 'utf8');
assert.match(api, /getStockOnHandForUpdate/, 'Server rechecks stock before material assignment persistence');
assert.match(api, /Insufficient %s stock for this branch/, 'Server insufficient-stock message names material and quantities');

console.log('production_assignment_one_click_material_test: PASS');
