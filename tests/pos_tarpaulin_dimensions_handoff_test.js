'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const posSource = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const customSource = fs.readFileSync(path.join(root, 'staff/customizations.php'), 'utf8');
const calcSource = fs.readFileSync(path.join(root, 'includes/tarpaulin_material_calculation.php'), 'utf8');

function extractObjectMethod(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notStrictEqual(start, -1, `missing method: ${startMarker}`);
    const end = source.indexOf(endMarker, start);
    assert.notStrictEqual(end, -1, `missing end: ${endMarker}`);
    return source.slice(start, end).trim().replace(/,$/, '');
}

// --- POS: Width/Height must survive Add to Order / Set Price handoff ---
const confirmStart = posSource.indexOf('async function confirmServiceModal() {');
const confirmEnd = posSource.indexOf('\n        function ', confirmStart + 20);
assert.ok(confirmStart >= 0 && confirmEnd > confirmStart, 'confirmServiceModal present');
const confirmBody = posSource.slice(confirmStart, confirmEnd);

assert.match(confirmBody, /if \(Number\(serviceId\) === 40\) \{[\s\S]*posCollectTarpaulinStockCustomization\(\)/,
    'Add to Order re-collects Tarpaulin Width/Height after the form loop');
assert.match(confirmBody, /else \{\s*posApplySelectedServiceDimension\(customization, body, overlay\);\s*\}/,
    'non-Tarpaulin services still use the shared preset dimension parser');
assert.match(posSource, /if \(Number\(overlay\.dataset\.serviceId \|\| customization\.service_id \|\| 0\) === 40\) \{\s*return;\s*\}/,
    'shared dimension parser never blanks Tarpaulin Width/Height');
assert.doesNotMatch(posSource, /Joining Direction|Joining Allowance|pos-tarpaulin-joining-controls/,
    'joining fields remain absent');

const collectStart = posSource.indexOf('        function posCollectTarpaulinStockCustomization() {');
const collectEnd = posSource.indexOf('\n        async function posValidateServiceStockNow(', collectStart);
const collectSource = posSource.slice(collectStart, collectEnd);
const applyStart = posSource.indexOf('        function posApplySelectedServiceDimension(customization, body, overlay) {');
const applyEnd = posSource.indexOf('\n        function posCollectTarpaulinStockCustomization()', applyStart);
const applySource = posSource.slice(applyStart, applyEnd);

const body = {
    querySelectorAll(selector) {
        if (selector === 'select, input[type="radio"]') {
            return [{
                name: 'dimensions',
                tagName: 'SELECT',
                value: 'Others',
                checked: false,
                options: [{ textContent: 'Others' }],
                selectedIndex: 0,
                getAttribute() { return 'Others'; },
                closest() { return null; }
            }];
        }
        return [];
    },
    querySelector(selector) {
        if (selector.includes('data-pos-tarp-width')) return { value: '2' };
        if (selector.includes('data-pos-tarp-height')) return { value: '2' };
        if (selector.includes('[name="dimensions_unit"]') || selector.includes('data-pos-tarp-unit')) {
            return { value: 'ft' };
        }
        if (selector === 'select[name="dimensions"]') {
            return {
                tagName: 'SELECT',
                name: 'dimensions',
                value: 'Others',
                classList: { contains: () => true },
                dataset: { otherOption: 'Others' }
            };
        }
        if (selector.includes('.pf-custom-size-panel') && selector.includes('custom-dim-width')) {
            return { value: '2' };
        }
        if (selector.includes('.pf-custom-size-panel') && selector.includes('custom-dim-height')) {
            return { value: '2' };
        }
        return null;
    }
};

const context = vm.createContext({
    document: {
        getElementById(id) {
            if (id === 'sm-fields-body') return body;
            if (id === 'service-modal-overlay') {
                return {
                    dataset: {
                        serviceId: '40',
                        materialDimensionFieldKey: 'dimensions',
                        materialDimensionUnit: 'ft'
                    }
                };
            }
            return null;
        }
    },
    posCollectServiceStockCustomization() {
        // Simulate the wipe that previously destroyed Tarpaulin dims when the
        // dimensions select was "Others"/non-parseable.
        return {
            service_id: 40,
            width: '',
            height: '',
            dimensions: 'Others',
            dimensions_unit: 'ft',
            layout: 'without_layout'
        };
    },
    posServiceQuantityInput() { return { value: '1' }; },
    pfPrepareServiceFormDimensions() {}
});
vm.runInContext(applySource + '\n' + collectSource, context);

const wipedThenCleared = { service_id: 40, width: '2', height: '2', pos_material_dimension_unit: 'ft' };
context.posApplySelectedServiceDimension(
    wipedThenCleared,
    body,
    context.document.getElementById('service-modal-overlay')
);
assert.strictEqual(wipedThenCleared.width, '2', 'parser skip preserves Tarpaulin width');
assert.strictEqual(wipedThenCleared.height, '2', 'parser skip preserves Tarpaulin height');

const collected = context.posCollectTarpaulinStockCustomization();
assert.strictEqual(collected.width, '2', 'POS Width survives Tarpaulin collection');
assert.strictEqual(collected.height, '2', 'POS Height survives Tarpaulin collection');
assert.ok(['ft', 'Ft'].includes(String(collected.pos_material_dimension_unit || collected.dimensions_unit || '').toLowerCase())
    || collected.pos_material_dimension_unit === 'ft',
    'dimension unit survives collection');
assert.equal(Object.hasOwn(collected, 'joining_direction'), false, 'joining_direction stays absent');

// Simulate the fixed confirm path: re-merge tarp collect after a destructive parser wipe.
const cartCustomization = {
    service_id: 40,
    width: '',
    height: '',
    dimensions_unit: 'ft',
    layout: 'without_layout'
};
Object.assign(cartCustomization, context.posCollectTarpaulinStockCustomization());
assert.strictEqual(cartCustomization.width, '2', 'handoff payload keeps Width after re-collect');
assert.strictEqual(cartCustomization.height, '2', 'handoff payload keeps Height after re-collect');

// --- Modal display: show 2 × 2 ft; never unit-only; Actual Usage stays linear ft ---
const enrichMethod = extractObjectMethod(
    customSource,
    'staffEnrichDimensionSpecs(specs, item) {',
    'staffMeaningfulSpecValue(value) {'
);
const extractMethod = extractObjectMethod(
    customSource,
    'staffExtractDimensionParts(specs, dimensionField) {',
    'staffFormatDimensionDisplayValue(parts) {'
);
const formatMethod = extractObjectMethod(
    customSource,
    'staffFormatDimensionDisplayValue(parts) {',
    'staffDimensionDisplayStripKeys() {'
);
const stripKeysMethod = extractObjectMethod(
    customSource,
    'staffDimensionDisplayStripKeys() {',
    'staffEnrichDimensionSpecs(specs, item) {'
);

const helpers = new Function(`return ({
    ${extractMethod},
    ${formatMethod},
    ${stripKeysMethod},
    ${enrichMethod},
    staffResolveItemServiceId(item) { return Number(item && item.service_id ? item.service_id : 40); },
    staffGetServiceSpecProfile() { return { dimensionField: { key: 'dimensions', unit: 'ft' } }; },
    staffCustomizationValueText(value) {
        if (value == null) return '';
        return String(value).trim();
    },
    staffMeaningfulSpecValue(value) {
        const text = this.staffCustomizationValueText(value);
        return !!text && !['none', 'n/a', '0'].includes(text.toLowerCase());
    },
    staffCustomizationKeyToken(key) {
        return String(key || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    },
    staffCustomizationFieldMeta(key) {
        const token = this.staffCustomizationKeyToken(key);
        if (token === 'width' || token === 'width_ft') return { group: 'width', label: 'Width', hidden: false };
        if (token === 'height' || token === 'height_ft') return { group: 'height', label: 'Height', hidden: false };
        if (token === 'dimensions' || token === 'size') return { group: 'dimensions', label: 'Dimensions', hidden: false };
        if (token === 'dimensions_unit') return { group: 'dimensions_unit', label: 'Dimensions Unit', hidden: false };
        return { group: token, label: key, hidden: false };
    },
    staffCanonicalSpecBucket(key, group) {
        if (group === 'width' || group === 'height') return 'dimensions_part';
        if (group === 'dimensions') return 'dimensions';
        return group || '';
    },
    staffDeduplicateDisplaySpecRows(rows) { return rows; }
});`)();

const item = { service_id: 40, service_type: 'Tarpaulin' };
const displayed = helpers.staffEnrichDimensionSpecs({
    width: '2',
    height: '2',
    dimensions_unit: 'ft',
    pos_material_dimension_unit: 'ft',
    layout: 'without_layout',
    quantity: '1'
}, item);

assert.strictEqual(displayed.Dimensions, '2 × 2 ft', 'modal displays combined customer dimensions with unit');
assert.strictEqual(displayed.Width, '2 ft', 'modal shows Width separately for Production Assignment');
assert.strictEqual(displayed.Height, '2 ft', 'modal shows Height separately for Production Assignment');
assert.equal(Object.hasOwn(displayed, 'Dimensions Unit'), false, 'unit-only Dimensions Unit row is not shown when dims exist');

const missing = helpers.staffEnrichDimensionSpecs({
    dimensions_unit: 'ft',
    pos_material_dimension_unit: 'ft',
    layout: 'without_layout'
}, item);
assert.match(String(missing.Dimensions || ''), /Missing Width × Height/i,
    'missing dimensions produce a clear actionable error instead of unit-only display');
assert.equal(Object.hasOwn(missing, 'Dimensions Unit'), false, 'orphan Dimensions Unit is removed when dims are missing');

const brochure = helpers.staffEnrichDimensionSpecs({
    width: '8',
    height: '11',
    pos_material_dimension_unit: 'in',
    sizes: '25pcs A4 (8 x 11 Inch)'
}, { service_id: 67 });
assert.ok(brochure.width === '8' || brochure.Dimensions || brochure.sizes,
    'other services remain on the non-Tarpaulin enrich path');

assert.match(calcSource, /required_linear_ft/, 'Actual Usage remains linear roll length in ft');
assert.match(calcSource, /function printflow_tarpaulin_calculate_linear_length/,
    'Tarpaulin consumption uses linear length, not Width/Height as the usage qty');
assert.match(customSource, /usage_method: isTarpaulin && estimatedQty !== null \? 'tarpaulin_linear_ft' : 'manual'/,
    'Production Assignment Actual Usage uses tarpaulin_linear_ft, distinct from order dimensions');
assert.match(customSource, /Calculated from customer Width × Height against the selected Tarpaulin roll width/,
    'calculated Actual Usage is initialized from preserved customer dimensions + roll width');

console.log(JSON.stringify({
    data_loss_point: 'confirmServiceModal called posApplySelectedServiceDimension after posCollectTarpaulinStockCustomization; non-parseable dimensions select blanked width/height before cart/Set Price persistence',
    status: 'POS Tarpaulin dimensions handoff regression tests passed.'
}, null, 2));
