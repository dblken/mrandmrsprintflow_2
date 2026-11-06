'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const posSource = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const estimateSource = fs.readFileSync(path.join(root, 'public/assets/js/service_estimated_price.js'), 'utf8');
const stockSource = fs.readFileSync(path.join(root, 'includes/pos_service_stock_validation.php'), 'utf8');
const calcSource = fs.readFileSync(path.join(root, 'includes/tarpaulin_material_calculation.php'), 'utf8');

assert.doesNotMatch(posSource, /posEnsureTarpaulinJoiningControls/, 'POS no longer injects Tarpaulin joining controls');
assert.doesNotMatch(posSource, /Joining Direction|Vertical seams|Horizontal seams|Joining Allowance|pos-tarpaulin-joining-controls|name="joining_direction"|name="joining_allowance_mode"|name="joining_allowance_ft"|Additional Allowance \(ft\)/,
    'POS Tarpaulin order form no longer renders joining direction/seams/allowance inputs');
assert.match(posSource, /posEnsureTarpaulinDimensionInputs/, 'POS Tarpaulin restores separate Width/Height dimension inputs');
assert.match(posSource, /data-pos-tarp-width="1"/, 'POS Tarpaulin Width input is present');
assert.match(posSource, /data-pos-tarp-height="1"/, 'POS Tarpaulin Height input is present');
assert.doesNotMatch(estimateSource, /joining_direction|joining_allowance|Vertical seams|Horizontal seams/,
    'Estimated Price does not depend on removed Tarpaulin joining fields');
assert.match(stockSource, /\$customization\['joining_direction'\] \?\? 'automatic'/,
    'Stock validation remains backward-compatible with historical joining_direction values');
assert.match(stockSource, /\$customization\['joining_allowance_mode'\] \?\? 'included'/,
    'Stock validation remains backward-compatible with historical joining_allowance_mode values');
assert.match(calcSource, /joining_direction|joining_allowance_mode|joining_allowance_ft/,
    'Historical Tarpaulin joining values remain readable in material/checkout helpers');

const collectStart = posSource.indexOf('        function posCollectTarpaulinStockCustomization() {');
const collectEnd = posSource.indexOf('\n        async function posValidateServiceStockNow(', collectStart);
assert.ok(collectStart >= 0 && collectEnd > collectStart, 'Tarpaulin stock collector remains available for dimensions/quantity');
const collectSource = posSource.slice(collectStart, collectEnd);
assert.match(collectSource, /delete customization\.joining_direction/,
    'New Tarpaulin POS payloads omit joining_direction');
assert.match(collectSource, /delete customization\.joining_allowance_mode/,
    'New Tarpaulin POS payloads omit joining_allowance_mode');
assert.match(collectSource, /delete customization\.joining_allowance_ft/,
    'New Tarpaulin POS payloads omit joining_allowance_ft');

const fieldsBody = {
    querySelectorAll(selector) {
        if (selector.includes('data-dimension-role')) return [];
        return [];
    },
    querySelector(selector) {
        if (selector === 'select[name="dimensions"]') {
            return {
                tagName: 'SELECT',
                name: 'dimensions',
                value: '3x5 Ft',
                classList: { contains() { return false; } },
                dataset: {}
            };
        }
        if (selector.includes('.shopee-opt-btn.active[data-width]')) {
            return { dataset: { width: '3', height: '5' } };
        }
        return null;
    }
};
const context = vm.createContext({
    document: {
        getElementById(id) {
            if (id === 'sm-fields-body') return fieldsBody;
            return null;
        }
    },
    posCollectServiceStockCustomization() {
        return { finish: 'Matte', service_note: 'keep me' };
    },
    posServiceQuantityInput() { return { value: '2' }; },
    pfPrepareServiceFormDimensions() {}
});
vm.runInContext(collectSource, context);
const tarp = context.posCollectTarpaulinStockCustomization();
assert.equal(tarp.width, '3', 'Tarpaulin size width remains collected');
assert.equal(tarp.height, '5', 'Tarpaulin size height remains collected');
assert.equal(tarp.quantity, '2', 'Tarpaulin quantity remains collected');
assert.equal(tarp.finish, 'Matte', 'Non-joining Tarpaulin options remain collected');
assert.equal(Object.hasOwn(tarp, 'joining_direction'), false, 'joining_direction is absent from new Tarpaulin payloads');
assert.equal(Object.hasOwn(tarp, 'joining_allowance_mode'), false, 'joining_allowance_mode is absent from new Tarpaulin payloads');
assert.equal(Object.hasOwn(tarp, 'joining_allowance_ft'), false, 'joining_allowance_ft is absent from new Tarpaulin payloads');

assert.match(posSource, /if \(Number\(serviceId\) !== 71\) return \[\];/,
    'Other services keep their existing material-selection behavior');
assert.match(posSource, /posCollectServiceStockCustomization/,
    'Non-Tarpaulin services continue using the shared stock collector');
assert.match(posSource, /posCollectTarpaulinStockCustomization\(\)/,
    'Tarpaulin still uses its dimension/quantity collector without joining inputs');

console.log('PASS: Tarpaulin POS joining fields removed; pricing and other services unaffected');
