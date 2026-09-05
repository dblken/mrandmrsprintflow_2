'use strict';

const assert = require('assert');
const picker = require('../public/assets/js/production_material_picker.js');

let nextId = 1;
const item = (name, stock = 10, extra = {}) => {
    if (nextId === 63) nextId += 1;
    return { id: nextId++, name, current_stock: stock, unit_of_measure: 'pcs', category_name: 'MATERIALS', ...extra };
};

const inventory = [
    item('MUG'), item('BOX MUG'), item('3ft Tarpaulin'), item('4ft Tarpaulin', 0),
    item('VINYL BLACK'), item('VINYL WHITE'), item('NEXJET'), item('PP STKR MATTE 98'),
    item('HOLOGRAM'), item('TRANSPARENT'), item('GLOSS LAMINATE'), item('MATTE LAMINATE'),
    item('STICKER BLACK'), item('STICKER SILVER'), item('3M Reflective'), item('AC EURO'),
    item('SP HOME'), item('PVC ID'), item('Unmapped Supply'),
    item('INK L120 BLUE', 10, { category_name: 'INK L120' }),
    item('Sintra 3mm 32', 10, { category_name: 'PLATE' }), item('Sintra 5mm', 10, { category_name: 'PLATE' }),
    item('C2s Board'), item('C2s Special Paper'), item('Subli Paper'), item('Photo Paper'),
    item('Sticker Paper'), item('Eyelet'), item('Holographic'), item('Matte Black'),
    item('Cyno', 10, { category_name: 'INK L120' }), item('Standard Item'), item('AC THAI'),
    { id: 63, name: 'test garbage nonsense material', current_stock: 99, unit_of_measure: 'pcs', category_name: 'TEST' }
];

function context(serviceType, customization = {}) {
    return { serviceType, serviceLabel: serviceType, customization };
}

function byName(rows, name) {
    return rows.find(row => row.name === name);
}

const mugRows = picker.rankItems(inventory, context('Souvenirs', { souvenir_type: 'Mug' }), [], '');
assert.strictEqual(mugRows[0].name, 'MUG');
assert.strictEqual(byName(mugRows, 'MUG').compatibility.tier, 'recommended');
assert.strictEqual(byName(mugRows, 'Subli Paper').compatibility.tier, 'recommended');
assert.strictEqual(byName(mugRows, 'BOX MUG').compatibility.tier, 'optional');
assert.strictEqual(byName(mugRows, '3ft Tarpaulin').compatibility.selectable, false);
assert.strictEqual(byName(mugRows, 'PVC ID').compatibility.tier, 'unrelated');
assert.strictEqual(byName(mugRows, 'test garbage nonsense material'), undefined);
assert.strictEqual(byName(mugRows, 'INK L120 BLUE'), undefined);

const tarpRows = picker.rankItems(inventory, context('Tarpaulin Printing'), [], '');
assert.strictEqual(byName(tarpRows, '3ft Tarpaulin').compatibility.tier, 'recommended');
assert.strictEqual(byName(tarpRows, 'Eyelet').compatibility.tier, 'optional');
assert.match(picker.descriptionFor(byName(tarpRows, 'Eyelet')), /4 standard eyelets included/i);
assert.strictEqual(byName(tarpRows, '4ft Tarpaulin').compatibility.selectable, false);
assert.strictEqual(byName(tarpRows, '4ft Tarpaulin').compatibility.reason, 'Out of stock');
assert.strictEqual(picker.inkModeFor(byName(tarpRows, '3ft Tarpaulin')), 'tarp');

const shirtRows = picker.rankItems(inventory, context('T-Shirt Printing'), [], '');
assert.strictEqual(byName(shirtRows, 'VINYL BLACK').compatibility.tier, 'recommended');
assert.strictEqual(byName(shirtRows, 'Holographic').compatibility.tier, 'recommended');
assert.strictEqual(byName(shirtRows, 'Matte Black').compatibility.tier, 'recommended');
assert.strictEqual(byName(shirtRows, 'MUG').compatibility.selectable, false);
assert.strictEqual(byName(shirtRows, 'PVC ID').compatibility.selectable, false);
assert.strictEqual(byName(shirtRows, 'STICKER BLACK').compatibility.selectable, false);
assert.strictEqual(picker.inkModeFor(byName(shirtRows, 'VINYL BLACK')), 'none');

const printedRows = picker.rankItems(inventory, context('Stickers Decals', { sticker_type: 'Printed Sticker' }), [], '');
['NEXJET', 'PP STKR MATTE 98', 'HOLOGRAM', 'TRANSPARENT', 'Sticker Paper'].forEach(name => {
    assert.strictEqual(byName(printedRows, name).compatibility.tier, 'recommended');
});
['GLOSS LAMINATE', 'MATTE LAMINATE'].forEach(name => {
    assert.strictEqual(byName(printedRows, name).compatibility.tier, 'optional');
});
assert.strictEqual(picker.inkModeFor(byName(printedRows, 'NEXJET')), 'standard');

const cutRows = picker.rankItems(inventory, context('Cut Sticker'), [], '');
assert.strictEqual(byName(cutRows, 'STICKER BLACK').compatibility.tier, 'recommended');
assert.strictEqual(picker.inkModeFor(byName(cutRows, 'STICKER BLACK')), 'none');

const reflectiveStickerRows = picker.rankItems(inventory, context('Stickers Decals', { sticker_type: 'Reflective Sticker' }), [], '');
assert.strictEqual(byName(reflectiveStickerRows, '3M Reflective').compatibility.tier, 'recommended');
assert.strictEqual(byName(reflectiveStickerRows, 'STICKER BLACK').compatibility.tier, 'optional');
const unmappedStickerRows = picker.rankItems(inventory, context('Stickers Decals'), [], '');
assert.strictEqual(byName(unmappedStickerRows, 'NEXJET').compatibility.tier, 'unverified');
assert.strictEqual(byName(unmappedStickerRows, 'NEXJET').compatibility.selectable, false);

const sintraRows = picker.rankItems(inventory, context('Sintraboard Standees'), [], '');
assert.strictEqual(byName(sintraRows, 'Sintra 3mm 32').compatibility.tier, 'recommended');
assert.strictEqual(byName(sintraRows, 'Sintra 5mm').compatibility.tier, 'recommended');
assert.strictEqual(byName(sintraRows, 'SP HOME').compatibility.selectable, false);
assert.strictEqual(picker.inkModeFor(byName(sintraRows, 'Sintra 3mm 32')), 'none');

const brochureRows = picker.rankItems(inventory, context('Brochure'), [], '');
assert.strictEqual(byName(brochureRows, 'C2s Special Paper').compatibility.tier, 'recommended');
assert.strictEqual(byName(brochureRows, 'C2s Board').compatibility.selectable, false);

const raffleRows = picker.rankItems(inventory, context('Raffle Ticket Printing'), [], '');
assert.strictEqual(byName(raffleRows, 'C2s Board').compatibility.tier, 'recommended');
assert.strictEqual(byName(raffleRows, 'C2s Special Paper').compatibility.tier, 'optional');

const posterRows = picker.rankItems(inventory, context('Poster Printing'), [], '');
assert.strictEqual(byName(posterRows, 'C2s Board').compatibility.tier, 'recommended');
assert.strictEqual(byName(posterRows, 'C2s Special Paper').compatibility.tier, 'recommended');
assert.strictEqual(byName(posterRows, 'Photo Paper').compatibility.tier, 'optional');

const signageRows = picker.rankItems(inventory, context('Reflectorized Signage'), [], '');
assert.strictEqual(byName(signageRows, 'Sintra 3mm 32').compatibility.tier, 'recommended');
assert.strictEqual(byName(signageRows, '3M Reflective').compatibility.tier, 'optional');
assert.strictEqual(byName(signageRows, 'STICKER BLACK').compatibility.tier, 'optional');
assert.strictEqual(byName(signageRows, 'AC EURO').compatibility.selectable, false);

const plateRows = picker.rankItems(inventory, context('Reflectorized', { product_type: 'Plate Number / Temporary Plate' }), [], '');
assert.strictEqual(byName(plateRows, 'AC EURO').compatibility.tier, 'recommended');
assert.strictEqual(byName(plateRows, 'SP HOME').compatibility.tier, 'recommended');
assert.strictEqual(byName(plateRows, '3M Reflective').compatibility.tier, 'optional');
assert.strictEqual(byName(plateRows, 'STICKER SILVER').compatibility.tier, 'optional');
assert.strictEqual(byName(plateRows, 'VINYL BLACK').compatibility.selectable, false);
assert.strictEqual(byName(plateRows, 'Sintra 3mm 32').compatibility.selectable, false);
assert.strictEqual(picker.inkModeFor(byName(plateRows, 'AC EURO')), 'none');

const fuzzyCases = {
    tarpolin: '3ft Tarpaulin',
    nexjt: 'NEXJET',
    'mug bx': 'BOX MUG',
    reflect: '3M Reflective',
    'pp matte': 'PP STKR MATTE 98',
    'sp home': 'SP HOME',
    sintra: 'Sintra 3mm 32',
    c2s: 'C2s Board',
    'special papr': 'C2s Special Paper',
    subli: 'Subli Paper',
    'photo papr': 'Photo Paper',
    'stiker paper': 'Sticker Paper',
    'ac thai': 'AC THAI',
    hologram: 'HOLOGRAM',
    holographic: 'Holographic',
    'mat blk': 'Matte Black'
};
Object.entries(fuzzyCases).forEach(([query, expected]) => {
    const rows = picker.rankItems(inventory, context('Souvenirs', { souvenir_type: 'Mug' }), [], query);
    assert.strictEqual(rows[0].name, expected, `${query} should rank ${expected} first`);
});
const unrelatedSearch = picker.rankItems(inventory, context('Souvenirs', { souvenir_type: 'Mug' }), [], 'tarpaulin');
assert.strictEqual(unrelatedSearch[0].name, '3ft Tarpaulin');
assert.strictEqual(unrelatedSearch[0].compatibility.selectable, false);
const plateSearchOnMugs = picker.rankItems(inventory, context('Mugs'), [], 'SP HOME');
assert.strictEqual(plateSearchOnMugs[0].name, 'SP HOME');
assert.strictEqual(plateSearchOnMugs[0].compatibility.selectable, false);

const unknown = context('Verified Legacy Service');
const rules = [{ service_type: 'Verified Legacy Service', item_id: inventory[18].id, rule_type: 'REQUIRED' }];
assert.strictEqual(picker.classifyItem(inventory[18], unknown, rules).tier, 'recommended');
assert.strictEqual(picker.classifyItem(item('Random Active Material'), context('Unmapped Service'), []).selectable, false);
assert.strictEqual(picker.classifyItem(byName(inventory, 'Cyno'), context('Mugs'), []).tier, 'unverified');
assert.strictEqual(picker.classifyItem(byName(inventory, 'Cyno'), context('Mugs'), []).selectable, false);
assert.strictEqual(picker.classifyItem(byName(inventory, 'Standard Item'), context('Mugs'), []).tier, 'unverified');
assert.strictEqual(picker.classifyItem(byName(inventory, 'PVC ID'), context('Mugs'), []).tier, 'unrelated');
assert.notStrictEqual(picker.familyFor(byName(inventory, 'HOLOGRAM')), picker.familyFor(byName(inventory, 'Holographic')));
assert.strictEqual(picker.searchScore(byName(inventory, 'Holographic'), 'hologram'), 0);
assert.strictEqual(picker.searchScore(byName(inventory, 'HOLOGRAM'), 'holographic'), 0);
assert.strictEqual(picker.inkModeFor(byName(inventory, 'C2s Board')), 'standard');
assert.strictEqual(picker.inkModeFor(byName(inventory, 'Holographic')), 'none');
const conflictingPlateRule = [{ service_type: 'Plates', item_id: byName(inventory, 'Sintra 3mm 32').id, rule_type: 'REQUIRED' }];
assert.strictEqual(picker.classifyItem(byName(inventory, 'Sintra 3mm 32'), context('Plates'), conflictingPlateRule).selectable, false);
assert.strictEqual(picker.classifyItem(byName(inventory, 'AC EURO'), context('Plates'), []).tier, 'recommended');

['AC EURO', 'AC HOME', 'AC MC', 'AC NMC', 'AC PH', 'AC THAI', 'SP EURO', 'SP HOME', 'SP MC', 'SP NMC', 'SP PH', 'SP THAI']
    .forEach(name => assert.strictEqual(picker.familyFor(item(name)), 'plate'));
['VINYL BLACK', 'VINYL BLUE', 'VINYL GREEN', 'VINYL ORANGE', 'VINYL PINK', 'VINYL RED', 'VINYL WHITE', 'VINYL YELLOW']
    .forEach(name => assert.strictEqual(picker.familyFor(item(name)), 'heat_vinyl'));
['STICKER BLACK', 'STICKER BLUE', 'STICKER GOLD', 'STICKER GREEN', 'STICKER RED', 'STICKER SILVER', 'STICKER WHITE', 'STICKER YELLOW']
    .forEach(name => assert.strictEqual(picker.familyFor(item(name)), 'colored_sticker'));
['3ft Tarpaulin', '4ft Tarpaulin', '5FT Tarpaulin', '6FT Tarpaulin', '7ft Tarpaulin']
    .forEach(name => assert.strictEqual(picker.familyFor(item(name)), 'tarpaulin'));

process.stdout.write('production_material_picker_test: PASS\n');
