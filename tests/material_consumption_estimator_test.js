const assert = require('node:assert/strict');
const { estimate, parseMaterialQuantity } = require('../public/assets/js/material_consumption_estimator.js');

for (const method of ['per_piece', 'sheet_yield', 'dimension_sheet', 'by_length', 'by_area', 'manual']) {
    assert.deepEqual(
        estimate({ consumption_method: method, qty_multiplier: 4 }, { quantity: 20, width_ft: 2, height_ft: 3 }),
        { status: 'manual', quantity: null, method: 'manual', reason: 'Staff enters actual usage in the inventory unit.' },
        `The ${method} rule does not calculate assignment usage.`
    );
}

for (const uom of ['pcs', 'pc', 'piece', 'sheet', 'sheets', 'roll', 'unit', 'set', 'bottle', 'pack']) {
    assert.equal(parseMaterialQuantity('1', { unit_of_measure: uom }), 1, `${uom} accepts a positive whole quantity`);
    assert.equal(parseMaterialQuantity('0.5', { unit_of_measure: uom }), null, `${uom} rejects fractional discrete quantities`);
}
for (const uom of ['ft', 'm', 'cm', 'mm', 'l', 'liter', 'ml', 'kg', 'g']) {
    assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: uom, consumption_method: 'dimension_sheet' }), 0.25, `${uom} preserves staff-entered fractions independent of method`);
}
for (const invalid of ['', '0', '-1', 'NaN', 'Infinity', '1e2', '1.234', '100000000']) {
    assert.equal(parseMaterialQuantity(invalid, { unit_of_measure: 'ft' }), null, `${JSON.stringify(invalid)} is rejected`);
}
assert.equal(parseMaterialQuantity('1.5', { unit_of_measure: 'pcs' }), null);
assert.equal(parseMaterialQuantity('1', { unit_of_measure: '' }), null);
console.log('PASS: staff-entered material quantity validation uses UOM only; estimators remain manual');
