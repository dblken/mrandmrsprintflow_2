const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialUsage = require('../public/assets/js/material_consumption_estimator.js');

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');
const api = fs.readFileSync(path.join(__dirname, '..', 'admin', 'job_orders_api.php'), 'utf8');

// A former AREA_SQFT rule implied a minimum of 2 sheets for 11 x 16 ft at
// 0.01 units/ft². Active assignment now accepts the staff-entered 1 sheet.
const item = { unit_of_measure: 'sheet', consumption_method: 'dimension_sheet', qty_multiplier: 0.01 };
assert.equal(materialUsage.parseMaterialQuantity('1', item), 1);
assert.equal(materialUsage.estimate(item, {
    quantity: 1, width_ft: 11, height_ft: 16, multiplier: 0.01, basis: 'AREA_SQFT'
}).status, 'manual', 'Dimensions and legacy rates do not generate an active estimate.');
assert.equal(materialUsage.estimate(item, {
    quantity: 4, width_ft: 11, height_ft: 16, multiplier: 999, basis: 'AREA_SQFT'
}).quantity, null, 'Order quantity and rate cannot override manual usage.');

const methodStart = page.indexOf('            posMaterialQuantityError(materials = []) {');
const methodEnd = page.indexOf('            tarpaulinCalculationForItem(itemId', methodStart);
assert.ok(methodStart >= 0 && methodEnd > methodStart, 'Manual assignment quantity validator exists.');
const methods = new Function(`return ({${page.slice(methodStart, methodEnd)}\n});`)();
const validationContext = {
    isPosSetPriceFlow: () => true,
    isValidPosMaterialQuantity: (value) => materialUsage.parseMaterialQuantity(value, item) !== null,
};
assert.equal(methods.posMaterialQuantityError.call(validationContext, [
    { item_id: 212, name: 'C2s Board', qty: 1, uom: 'sheet', required_minimum_qty: 2 }
]), '', 'A stale required_minimum_qty value cannot reject staff-entered 1 sheet.');
assert.match(page, /Enter the actual quantity used in the inventory unit\./);
assert.match(api, /printflow_pos_parse_positive_material_quantity\(\$rawQuantity, \$quantityItem\)/);
assert.doesNotMatch(api, /required_minimum_qty|below the required minimum/i);

console.log('brochure_area_minimum_sheet_ui_test: PASS');
