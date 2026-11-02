const assert = require('node:assert/strict');
const { estimate, parseMaterialQuantity } = require('../public/assets/js/material_consumption_estimator.js');

const cases = [
    ['configured piece use follows order quantity', estimate({ consumption_method: 'per_piece', unit_of_measure: 'pcs' }, { quantity: 34 }), { status: 'estimated', quantity: 34, method: 'PER_PIECE' }],
    ['configured sheet yield rounds 34 / 4 upward', estimate({ consumption_method: 'sheet_yield', designs_per_unit: 4, unit_of_measure: 'pcs' }, { quantity: 34 }), { status: 'estimated', quantity: 9, method: 'SHEET_YIELD' }],
    ['sheet yield applies configured wastage before rounding', estimate({ consumption_method: 'sheet_yield', designs_per_unit: 4, wastage_percent: 10 }, { quantity: 34 }), { status: 'estimated', quantity: 10, method: 'SHEET_YIELD' }],
    ['missing yield stays manual', estimate({ consumption_method: 'sheet_yield' }, { quantity: 34 }).status, 'manual'],
    ['legacy unconfigured material stays manual', estimate({}, { quantity: 34 }).status, 'manual'],
    ['dimension sheet uses physical fit, not area division', estimate({ consumption_method: 'dimension_sheet', material_format: 'sheet', material_width: 8.27, material_height: 11.69, material_dimension_unit: 'in', usable_margin_width: 0, usable_margin_height: 0 }, { quantity: 34, width_ft: 0.33, height_ft: 0.33 }), { status: 'estimated', quantity: 9, method: 'DIMENSION_SHEET', designs_per_sheet: 4 }],
    ['dimension sheet rejects a no-fit orientation', estimate({ consumption_method: 'dimension_sheet', material_format: 'sheet', material_width: 8.27, material_height: 11.69, material_dimension_unit: 'in', usable_margin_width: 0, usable_margin_height: 0 }, { quantity: 1, width_ft: 1, height_ft: 1 }).status, 'no_fit'],
    ['dimension sheet applies margins', estimate({ consumption_method: 'dimension_sheet', material_format: 'sheet', material_width: 8.27, material_height: 11.69, material_dimension_unit: 'in', usable_margin_width: 1, usable_margin_height: 1 }, { quantity: 34, width_ft: 0.33, height_ft: 0.33 }).designs_per_sheet, 2],
    ['verified length is used only when supplied', estimate({ consumption_method: 'by_length', unit_of_measure: 'ft' }, { quantity: 2 }).status, 'manual'],
    ['explicit verified length supports length estimate', estimate({ consumption_method: 'by_length', unit_of_measure: 'ft' }, { quantity: 2, required_material_length: 7.5 }).quantity, 7.5],
    ['small measured length remains fractional instead of consuming a whole piece', estimate({ consumption_method: 'by_length', unit_of_measure: 'ft' }, { quantity: 2, required_material_length: 0.25 }).quantity, 0.25],
    ['large measured length preserves configured precision', estimate({ consumption_method: 'by_length', unit_of_measure: 'ft' }, { quantity: 2, required_material_length: 48.756 }).quantity, 48.76],
    ['linear-foot estimate refuses a conflicting piece UOM', estimate({ consumption_method: 'by_length', unit_of_measure: 'pcs' }, { quantity: 2, required_material_length: 0.25 }).status, 'manual'],
    ['area is manual when UOM cannot express area', estimate({ consumption_method: 'by_area', unit_of_measure: 'pcs' }, { quantity: 1, width_ft: 1, height_ft: 1 }).status, 'manual'],
];

for (const [name, actual, expected] of cases) assert.deepEqual(actual, expected, name);
assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: 'ft', consumption_method: 'by_length' }), 0.25);
assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: 'ft', consumption_method: 'manual' }), 0.25);
assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: 'pcs', consumption_method: 'by_length' }), null);
assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: 'ft', consumption_method: 'per_piece' }), null);
assert.equal(parseMaterialQuantity('1.234', { unit_of_measure: 'ft', consumption_method: 'by_length' }), null);
assert.equal(parseMaterialQuantity('100000000', { unit_of_measure: 'pcs', consumption_method: 'per_piece' }), null);
console.log(`PASS: ${cases.length} material consumption estimator cases plus measured quantity validation`);
