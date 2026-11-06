'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.join(__dirname, '..');
const customSource = fs.readFileSync(path.join(root, 'staff/customizations.php'), 'utf8');
const normalizerSource = fs.readFileSync(path.join(root, 'includes/customization_normalizer.php'), 'utf8');
const pickerSource = fs.readFileSync(path.join(root, 'public/assets/js/production_material_picker.js'), 'utf8');

function extractObjectMethod(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notStrictEqual(start, -1, `missing method: ${startMarker}`);
    const end = source.indexOf(endMarker, start);
    assert.notStrictEqual(end, -1, `missing end: ${endMarker}`);
    return source.slice(start, end).trim().replace(/,$/, '');
}

// --- Hide internal Pos Tarpaulin Selection from customer specs ---
assert.match(customSource, /'pos_tarpaulin_selection'/, 'JS internal-key list hides pos_tarpaulin_selection');
assert.match(normalizerSource, /'pos_tarpaulin_selection'/, 'PHP field meta hides pos_tarpaulin_selection');
assert.match(normalizerSource, /linear_ft_v1/, 'PHP value text suppresses linear_ft_v1 selection payloads');

const valueTextMethod = extractObjectMethod(
    customSource,
    'staffCustomizationValueText(value) {',
    'staffCustomizationValueFingerprint(value) {'
);
const internalKeyMethod = extractObjectMethod(
    customSource,
    'staffCustomizationIsInternalKey(key) {',
    'staffCustomizationIsInternalLabel(label) {'
);
const internalValueMethod = extractObjectMethod(
    customSource,
    'staffCustomizationIsInternalValue(value) {',
    'staffCustomizationValueText(value) {'
);
const internalLabelMethod = extractObjectMethod(
    customSource,
    'staffCustomizationIsInternalLabel(label) {',
    'staffCustomizationIsInternalValue(value) {'
);
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
    ${internalKeyMethod},
    ${internalLabelMethod},
    ${internalValueMethod},
    ${valueTextMethod},
    ${extractMethod},
    ${formatMethod},
    ${stripKeysMethod},
    ${enrichMethod},
    staffCustomizationKeyToken(key) {
        return String(key || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    },
    staffResolveItemServiceId(item) { return Number(item && item.service_id ? item.service_id : 40); },
    staffGetServiceSpecProfile() { return { dimensionField: { key: 'dimensions', unit: 'ft' } }; },
    staffMeaningfulSpecValue(value) {
        const text = this.staffCustomizationValueText(value);
        return !!text && !['none', 'n/a', '0'].includes(text.toLowerCase());
    },
    staffCustomizationFieldMeta(key) {
        const token = this.staffCustomizationKeyToken(key);
        if (token === 'width' || token === 'width_ft') return { group: 'width', label: 'Width', hidden: false };
        if (token === 'height' || token === 'height_ft') return { group: 'height', label: 'Height', hidden: false };
        if (token === 'dimensions') return { group: 'dimensions', label: 'Dimensions', hidden: false };
        if (token === 'pos_tarpaulin_selection') return { group: 'pos_tarpaulin_selection', label: 'Pos Tarpaulin Selection', hidden: false };
        return { group: token, label: key, hidden: false };
    },
    staffCanonicalSpecBucket(key, group) {
        if (group === 'width' || group === 'height') return 'dimensions_part';
        if (group === 'dimensions') return 'dimensions';
        return group || '';
    },
    staffDeduplicateDisplaySpecRows(rows) { return rows; }
});`)();

assert.strictEqual(helpers.staffCustomizationIsInternalKey('pos_tarpaulin_selection'), true);
assert.strictEqual(helpers.staffCustomizationIsInternalLabel('Pos Tarpaulin Selection'), true);
assert.strictEqual(
    helpers.staffCustomizationValueText({
        version: 'linear_ft_v1',
        width_ft: 2,
        height_ft: 2,
        finished_quantity: 1,
        print_area_sqft: 4,
        roll_width_ft: 3,
        orientation: 'original',
        run_length_per_piece_ft: 2,
        required_linear_ft: 2,
        item_id: 13,
        item_name: '3ft Tarpaulin',
        branch_id: 1
    }),
    '',
    'selection object is blanked from customer-facing value text'
);
assert.strictEqual(
    helpers.staffCustomizationValueText('linear_ft_v1, 2, 2, 1, 4, 3, original, 2, 2, 13, 3ft Tarpaulin, 1'),
    '',
    'flattened selection string is blanked from customer-facing value text'
);

const displayed = helpers.staffEnrichDimensionSpecs({
    width: '2',
    height: '2',
    dimensions_unit: 'ft',
    pos_tarpaulin_selection: {
        version: 'linear_ft_v1',
        width_ft: 2,
        height_ft: 2,
        item_id: 13,
        required_linear_ft: 2,
        roll_width_ft: 3
    },
    layout: 'without_layout'
}, { service_id: 40 });
assert.strictEqual(displayed.Dimensions, '2 × 2 ft', 'customer dimensions remain visible');
assert.ok(!Object.keys(displayed).some((key) => /tarpaulin selection/i.test(key)),
    'Pos Tarpaulin Selection label is not emitted by dimension enrich');

// PHP normalizer contract
const php = spawnSync('php', ['-r', `
require '${path.join(root, 'includes/customization_normalizer.php').replace(/\\/g, '/')}';
$payload = [
  'width' => '2',
  'height' => '2',
  'dimensions_unit' => 'ft',
  'pos_tarpaulin_selection' => [
    'version' => 'linear_ft_v1',
    'width_ft' => 2,
    'height_ft' => 2,
    'finished_quantity' => 1,
    'print_area_sqft' => 4,
    'roll_width_ft' => 3,
    'orientation' => 'original',
    'run_length_per_piece_ft' => 2,
    'required_linear_ft' => 2,
    'item_id' => 13,
    'item_name' => '3ft Tarpaulin',
    'branch_id' => 1,
  ],
];
$specs = printflow_customization_display_specs($payload, ['include_quantity' => true]);
echo json_encode($specs);
`], { encoding: 'utf8' });
assert.strictEqual(php.status, 0, php.stderr || 'php normalizer failed');
const phpSpecs = JSON.parse(php.stdout || '{}');
assert.ok(!Object.keys(phpSpecs).some((key) => /tarpaulin selection/i.test(key)),
    'PHP display specs hide Pos Tarpaulin Selection');
assert.ok(
    Object.values(phpSpecs).some((value) => /2/.test(String(value))) ,
    'PHP display specs still expose customer dimension values'
);

// --- Assignment Width/Height + linear-ft Actual Usage ---
assert.match(customSource, /handlePendingTarpaulinDimensionInput/, 'assignment row supports editable Tarpaulin Width/Height');
assert.match(customSource, /customer_width_ft/, 'pending materials store customer width separately from Actual Usage');
assert.match(customSource, /customer_height_ft/, 'pending materials store customer height separately from Actual Usage');
assert.match(customSource, /recalculatePendingTarpaulinFromDimensions/, 'editing dimensions recalculates/revalidates roll fit');
assert.match(customSource, /cannot fit/, 'incompatible dimensions remove/clear the selected roll');
assert.match(customSource, /Actual Usage is linear roll length/, 'UI copy keeps Actual Usage distinct from Width/Height');
assert.match(pickerSource, /required_linear_ft/, 'picker calculates linear feet consumption');
assert.match(pickerSource, /unit_of_measure|TARPAULIN \(FT\)|rollWidth/, 'roll fit uses inventory roll width semantics');

const recalcMethod = extractObjectMethod(
    customSource,
    'recalculatePendingTarpaulinFromDimensions(material) {',
    'persistedPosTarpaulinSelection() {'
);
const isTarpMethod = extractObjectMethod(
    customSource,
    'isPendingTarpaulinMaterial(material) {',
    'handlePendingTarpaulinDimensionInput(material, field, value) {'
);

const runtime = new Function(`return ({
    ${isTarpMethod},
    ${recalcMethod},
    materialCompatibilityContext: { serviceId: 40, serviceKind: 'tarpaulin', customerQuantity: 1 },
    pendingMaterials: [],
    productionErrors: { material: '' },
    getInventoryItem(id) {
        return { id: Number(id), name: id === 13 ? '3ft Tarpaulin' : '6ft Tarpaulin', unit_of_measure: 'ft' };
    },
    tarpaulinCalculationForItem(itemId, width, height) {
        const roll = Number(itemId) === 13 ? 3 : 6;
        const w = Number(width);
        const h = Number(height);
        if (!(w > 0) || !(h > 0)) return null;
        if (w > roll && h > roll) return null;
        const run = w <= roll ? h : w;
        return { required_linear_ft: run, roll_width_ft: roll, orientation: w <= roll ? 'original' : 'rotated' };
    },
    clearPendingMaterialConfirmationError() {},
    showStaffAlert() { this._alerted = true; },
});`)();

runtime.pendingMaterials = [{
    item_id: 13,
    name: '3ft Tarpaulin',
    qty: 2,
    uom: 'ft',
    customer_width_ft: 2,
    customer_height_ft: 2,
    usage_method: 'tarpaulin_linear_ft',
    manual_confirmation_required: true,
    manual_quantity_confirmed: true,
    metadata: {}
}];
const material = runtime.pendingMaterials[0];
assert.strictEqual(runtime.isPendingTarpaulinMaterial(material), true);
assert.strictEqual(runtime.recalculatePendingTarpaulinFromDimensions(material), true);
assert.strictEqual(material.qty, 2, '2×2 on 3ft roll keeps Actual Usage as 2 linear ft');
assert.strictEqual(material.customer_width_ft, 2);
assert.strictEqual(material.customer_height_ft, 2);

material.customer_width_ft = 6;
material.customer_height_ft = 6;
assert.strictEqual(runtime.recalculatePendingTarpaulinFromDimensions(material), false,
    'incompatible dimensions reject the selected 3ft roll');
assert.strictEqual(runtime.pendingMaterials.length, 0, 'incompatible roll is removed from the assignment queue');
assert.ok(runtime._alerted, 'staff receives a clear unfit-roll alert');

console.log(JSON.stringify({
    selection_source: 'pos_tarpaulin_selection written by POS stock validation / checkout (linear_ft_v1 roll fit + required_linear_ft)',
    inventory_uom: 'ft linear roll length (required_linear_ft); Width/Height remain customer finished dimensions',
    status: 'POS Tarpaulin assignment dimensions + display regression tests passed.'
}, null, 2));
