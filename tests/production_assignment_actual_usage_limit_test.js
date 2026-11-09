const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialConsumption = require('../public/assets/js/material_consumption_estimator.js');

const sheetItem = { id: 212, name: 'C2s Board', unit_of_measure: 'sheet' };
const { parseMaterialQuantity, MAX_MATERIAL_QUANTITY } = materialConsumption;

assert.equal(MAX_MATERIAL_QUANTITY, 10000, 'Shared client max material quantity is 10,000');
assert.equal(parseMaterialQuantity('10000', sheetItem), 10000, '10,000 sheets is accepted');
assert.equal(parseMaterialQuantity('10001', sheetItem), null, '10,001 sheets is rejected');
assert.equal(parseMaterialQuantity('20000000', sheetItem), null, 'Absurd quantities are rejected');
assert.equal(parseMaterialQuantity('340.0.00', { unit_of_measure: 'ft' }), null, 'Non-numeric punctuation rejects parse');

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');
assert.match(page, /showProductionMaterialSectionError\(\)/, 'Section material error is deduplicated in the template');
assert.match(page, /max="10000"/, 'Actual Usage input advertises the 10,000 cap');
assert.doesNotMatch(
  page,
  /x-show="productionErrors\.material" x-text="productionErrors\.material"/,
  'Generic material error is not always duplicated at section level'
);

const sectionStart = page.indexOf('            showProductionMaterialSectionError() {');
const sectionEnd = page.indexOf('            /** Clears deferred Actual Usage UI state', sectionStart);
assert.ok(sectionStart >= 0 && sectionEnd > sectionStart, 'showProductionMaterialSectionError is defined');

const usageStart = page.indexOf('            showPendingMaterialUsageError(material) {');
const methods = new Function(`return ({
${page.slice(usageStart, sectionEnd)}
});`)();

const row = { item_id: 212, qty: '10001', manual_quantity_confirmed: false, dimension_fit_error: '' };
const ctx = {
  productionAssignmentSubmitAttempted: true,
  productionErrors: { material: 'Enter a valid actual quantity before saving.' },
  pendingMaterials: [row],
  isPendingTarpaulinMaterial: () => false,
  getInventoryItem: () => sheetItem,
  isValidPosMaterialQuantity(value, itemId) {
    const inv = this.getInventoryItem(itemId);
    return !!(inv && materialConsumption.parseMaterialQuantity(value, inv) !== null);
  },
};

const harness = Object.assign(Object.create(methods), ctx);
assert.equal(methods.showPendingMaterialUsageError.call(harness, row), true, 'Over-limit quantity shows the field error after submit');
assert.equal(methods.showProductionMaterialSectionError.call(harness), false, 'Section banner is hidden when field-level copy is visible');

const stockHarness = Object.assign(Object.create(methods), {
  ...ctx,
  productionErrors: { material: 'Insufficient C2s Board stock: requested 2 sheet, available 1 sheet in this branch.' },
});
assert.equal(methods.showProductionMaterialSectionError.call(stockHarness), true, 'Non-generic errors remain in the section banner');

console.log('PASS: Production Assignment Actual Usage limit and duplicate validation display');
