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
    assert.equal(parseMaterialQuantity('1.00', { unit_of_measure: uom }), 1, `${uom} accepts DECIMAL-stored whole quantities`);
    assert.equal(parseMaterialQuantity('0.5', { unit_of_measure: uom }), null, `${uom} rejects fractional discrete quantities`);
}
assert.equal(
    parseMaterialQuantity('0.5', { id: 55, name: 'Subli Paper', unit_of_measure: 'sheet' }),
    0.5,
    'Subli Paper accepts half-sheet usage in sheet UOM'
);
assert.equal(
    parseMaterialQuantity('1.5', { id: 55, name: 'Subli Paper', unit_of_measure: 'sheets' }),
    1.5,
    'Subli Paper accepts multi half-sheet totals'
);
assert.equal(
    parseMaterialQuantity('0.5', { id: 18, name: 'Vinyl Matte', unit_of_measure: 'sheets' }),
    null,
    'Non-Subli sheet materials still reject fractional quantities'
);
for (const uom of ['ft', 'm', 'cm', 'mm', 'l', 'liter', 'ml', 'kg', 'g']) {
    assert.equal(parseMaterialQuantity('0.25', { unit_of_measure: uom, consumption_method: 'dimension_sheet' }), 0.25, `${uom} preserves staff-entered fractions independent of method`);
    assert.equal(parseMaterialQuantity('1.00', { unit_of_measure: uom }), 1, `${uom} accepts whole measured amounts`);
}
assert.equal(parseMaterialQuantity('10000', { unit_of_measure: 'sheet' }), 10000, 'Discrete units accept the 10,000 cap');
assert.equal(parseMaterialQuantity('10001', { unit_of_measure: 'sheet' }), null, 'Discrete units reject above 10,000');
assert.equal(parseMaterialQuantity('20000000', { unit_of_measure: 'sheet' }), null, 'Very large discrete quantities are rejected');
assert.equal(parseMaterialQuantity('340.0.00', { unit_of_measure: 'ft' }), null, 'Malformed decimal strings are rejected');
for (const invalid of ['', '0', '-1', 'NaN', 'Infinity', '1e2', '1.234', '100000000']) {
    assert.equal(parseMaterialQuantity(invalid, { unit_of_measure: 'ft' }), null, `${JSON.stringify(invalid)} is rejected`);
}
assert.equal(parseMaterialQuantity('1.5', { unit_of_measure: 'pcs' }), null);
assert.equal(parseMaterialQuantity('1.01', { unit_of_measure: 'pcs' }), null);
assert.equal(parseMaterialQuantity('1', { unit_of_measure: '' }), null);
console.log('PASS: staff-entered material quantity validation uses UOM only; estimators remain manual');
