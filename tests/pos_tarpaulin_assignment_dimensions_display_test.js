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

const wideCustomDisplayed = helpers.staffEnrichDimensionSpecs({
    width: '10', height: '5', dimensions: 'Others', dimensions_unit: 'ft',
    pos_tarpaulin_selection: {
        version: 'roll_fit_v2', width_ft: 10, height_ft: 5, finished_quantity: 10,
        roll_allocations_by_item: {
            15: [{ roll_id: 157, roll_width_ft: 5, length_ft: 50 }],
            16: [{ roll_id: 156, roll_width_ft: 6, length_ft: 50 }]
        }
    }
}, { service_id: 40 });
assert.strictEqual(wideCustomDisplayed.Dimensions, '10 × 5 ft',
    'Customer-facing Custom Orders details retain 10×5 ft rather than displaying a configured roll width or stale job width');
assert.ok(!Object.keys(wideCustomDisplayed).some((key) => /tarpaulin selection/i.test(key)),
    'The multi-roll allocation remains internal and is not presented as a customer specification');

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

const phpSticker = spawnSync('php', ['-r', `
require '${path.join(root, 'includes/customization_normalizer.php').replace(/\\/g, '/')}';
$payload = [
  'dimensions' => 'Others',
  'dimensions_width' => '5',
  'dimensions_height' => '5',
  'finish' => 'Matte',
  'sticker_type' => 'Printed',
  'pos_sticker_laminate_selection' => [
    'version' => 'sticker_laminate_v1',
    'item_id' => 21,
    'item_name' => 'Matte Laminate',
    'width_ft' => 5,
    'height_ft' => 5,
    'finished_quantity' => 1,
    'roll_width_ft' => 6,
    'required_linear_ft' => 5,
    'roll_allocations' => [['roll_id' => 158, 'length_ft' => 5]],
  ],
];
$specs = printflow_customization_display_specs($payload, ['include_quantity' => true]);
echo json_encode($specs);
`], { encoding: 'utf8' });
assert.strictEqual(phpSticker.status, 0, phpSticker.stderr || 'php sticker normalizer failed');
const stickerSpecs = JSON.parse(phpSticker.stdout || '{}');
assert.ok(!Object.keys(stickerSpecs).some((key) => /sticker laminate selection/i.test(key)),
    'PHP display specs hide POS Sticker Laminate Selection');
assert.ok(Object.values(stickerSpecs).some((value) => /matte/i.test(String(value))),
    'customer finish remains visible');

// --- Assignment keeps print size visible; width/height are editable customer fields ---
assert.match(customSource, /handlePendingTarpaulinDimensionInput/, 'assignment row recalculates from print width/height editors');
assert.match(customSource, /pos-tarp-assignment-dims/, 'Tarpaulin assignment card has a dedicated dimension block');
assert.match(customSource, /Customer print size:/, 'customer print dimensions are explicitly labeled');
assert.match(customSource, /Order quantity:/, 'finished-piece quantity is visible to staff');
assert.match(customSource, /Configured roll width:/, 'inventory roll width is distinct from print width');
assert.doesNotMatch(customSource, /Planned footage:/, 'planned linear footage stays in metadata, not on the card');
assert.match(customSource, /Print width \(customer spec, ft\)/, 'print width is editable and labeled separately from roll width');
assert.match(customSource, /Print height \(customer spec, ft\)/, 'print height is editable and labeled separately from roll width');
assert.match(customSource, /Tarpaulin customer print width in feet/, 'width input exposes an accessible label');
assert.match(customSource, /handlePendingStickerLaminateDimensionInput/, 'sticker laminate exposes the same width/height editors');
assert.match(customSource, /recalculatePendingStickerLaminateFromDimensions/, 'sticker edits revalidate against the saved POS plan');
assert.match(customSource, />Tarpaulin plan</, 'staff guidance explains allocation and deduction timing');
assert.match(customSource, /x-show="!isPendingTarpaulinMaterial\(pm\) && !isPendingStickerLaminateMaterial\(pm\)"/,
    'generic Actual Usage input is hidden for sticker laminate rows');
assert.match(customSource, /'pos_sticker_laminate_selection'/, 'JS hides pos_sticker_laminate_selection');
assert.match(normalizerSource, /'pos_sticker_laminate_selection'/, 'PHP hides pos_sticker_laminate_selection');
assert.match(normalizerSource, /sticker_laminate_v1/, 'PHP suppresses sticker laminate payload values');
assert.match(customSource, /isPendingStickerLaminateMaterial/, 'sticker assignment uses dedicated plan cards');
assert.match(customSource, /sticker_laminate_ft/, 'sticker assignment stores a dedicated usage method');
assert.match(customSource, />Laminate plan</, 'sticker staff guidance is concise');
assert.doesNotMatch(customSource, /tarpaulinAllocationSummary\(pm\)/, 'internal AUTO roll codes stay out of the UI');
assert.doesNotMatch(customSource, /tarpaulinAllocationSummary\(pm\)/, 'internal AUTO roll allocation text is not rendered');
assert.doesNotMatch(customSource, />Width \(ft\)</, 'legacy Width (ft) label is not used on the assignment card');
assert.match(customSource, /customer_width_ft/, 'pending materials store customer width separately from inventory qty');
assert.match(customSource, /customer_height_ft/, 'pending materials store customer height separately from inventory qty');
assert.match(customSource, /recalculatePendingTarpaulinFromDimensions/, 'editing dimensions recalculates/revalidates roll fit');
assert.match(customSource, /tarpaulinCalcFromPersistedPlan/, 'multi-roll jobs reuse saved per-item footage when dimensions are unchanged');
assert.match(customSource, /cannot fit/, 'incompatible dimensions remove/clear the selected roll');
assert.match(customSource, /x-show="!isPendingTarpaulinMaterial\(pm\) && !isPendingStickerLaminateMaterial\(pm\)"[\s\S]*Actual Usage:/,
    'visible Actual Usage input is hidden for Tarpaulin and sticker laminate rows');
assert.match(customSource, /calculated_linear_ft/, 'assignment metadata audits calculated linear-ft deduction separately from dimensions');
assert.match(pickerSource, /required_linear_ft/, 'picker calculates linear feet consumption');
assert.match(pickerSource, /unit_of_measure|TARPAULIN \(FT\)|rollWidth/, 'roll fit uses inventory roll width semantics');
assert.match(customSource, /pendingMaterialStockError/, 'branch stock check runs against calculated usage before save');
assert.match(customSource, /validate_service_materials/, 'save path revalidates assigned material stock before persisting');

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

const planHelpersSource = `
    tarpaulinFinishedQuantityForValidation() {
        const contextQty = Number((this.materialCompatibilityContext || {}).customerQuantity || 0);
        const jobQty = Number(this.currentJo?.quantity ?? this.currentJo?.qty ?? 0);
        return contextQty > 0 ? contextQty : (jobQty > 0 ? jobQty : 0);
    },
    tarpaulinDimensionsMatchPersistedPlan(width, height, quantity = null) {
        const persisted = this.persistedPosTarpaulinSelection();
        if (!persisted) return false;
        const qty = Number(quantity ?? this.tarpaulinFinishedQuantityForValidation());
        if (!(width > 0) || !(height > 0) || !(qty > 0)) return false;
        return Math.abs(Number(persisted.width_ft || 0) - Number(width)) < 0.000001
            && Math.abs(Number(persisted.height_ft || 0) - Number(height)) < 0.000001
            && Number(persisted.finished_quantity || 0) === Number(qty);
    },
    tarpaulinCalcFromPersistedPlan(material, width, height, quantity = null) {
        const persisted = this.persistedPosTarpaulinSelection();
        if (!persisted || !material) return null;
        const itemId = Number(material.item_id || 0);
        if (itemId <= 0 || !this.tarpaulinDimensionsMatchPersistedPlan(width, height, quantity)) return null;
        if (persisted.version === 'roll_fit_v2' && persisted.roll_allocations_by_item) {
            const plannedByItem = persisted.roll_allocations_by_item[itemId] || [];
            const plannedRollAllocations = plannedByItem.filter(row => row && Number(row.roll_id) > 0 && Number(row.length_ft) > 0);
            const plannedFootage = plannedRollAllocations.reduce((sum, row) => sum + Number(row.length_ft), 0);
            if (!(plannedFootage > 0)) return null;
            const materialMeta = persisted.material_items?.[itemId] || {};
            return {
                calc: {
                    required_linear_ft: plannedFootage,
                    roll_width_ft: Number(materialMeta.roll_width_ft || plannedRollAllocations[0]?.roll_width_ft || 0),
                    orientation: persisted.orientation || ''
                },
                plannedRollAllocations
            };
        }
        return null;
    },
    persistedPosTarpaulinSelection() { return null; }
`;

const runtime = new Function(`return ({
    ${isTarpMethod},
    ${recalcMethod},
    ${planHelpersSource},
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
        return {
            required_linear_ft: run,
            roll_width_ft: roll,
            orientation: w <= roll ? 'original' : 'rotated',
            run_length_per_piece_ft: run
        };
    },
    clearPendingMaterialConfirmationError() {},
    showStaffAlert() { this._alerted = true; },
    pendingMaterialStockError(material) {
        const available = 1;
        const needed = Number(material.qty || 0);
        if (needed > available) {
            return 'Insufficient 3ft Tarpaulin stock: requested ' + needed + ' ft, available ' + available + ' ft in this branch.';
        }
        return '';
    }
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
assert.strictEqual(material.qty, 2, '2×2 on 3ft roll saves/deducts 2 linear ft, not 4 sq ft');
assert.strictEqual(material.metadata.calculated_linear_ft, 2, 'audit metadata stores calculated linear-ft deduction');
assert.strictEqual(material.metadata.customer_width_ft, 2, 'audit metadata stores customer width separately');
assert.strictEqual(material.metadata.customer_height_ft, 2, 'audit metadata stores customer height separately');
assert.notStrictEqual(material.qty, 2 * 2, 'deduction quantity is not Width × Height area');

material.customer_width_ft = 3;
material.customer_height_ft = 4;
assert.strictEqual(runtime.recalculatePendingTarpaulinFromDimensions(material), true,
    'dimension edits recalculate usage while the roll still fits');
assert.strictEqual(material.qty, 4, '3×4 on 3ft roll consumes 4 linear ft along the roll');

assert.match(runtime.pendingMaterialStockError({ qty: 2, uom: 'ft' }), /Insufficient/,
    'insufficient branch stock blocks using calculated linear-ft demand');

material.customer_width_ft = 6;
material.customer_height_ft = 6;
assert.strictEqual(runtime.recalculatePendingTarpaulinFromDimensions(material), false,
    'incompatible dimensions reject the selected 3ft roll');
assert.strictEqual(runtime.pendingMaterials.length, 0, 'incompatible roll is removed from the assignment queue');
assert.ok(runtime._alerted, 'staff receives a clear unfit-roll alert');

const multiRollPlan = {
    version: 'roll_fit_v2', branch_id: 1, width_ft: 10, height_ft: 5, finished_quantity: 10,
    orientation: 'original',
    material_items: {
        15: { name: '5ft Tarpaulin', roll_width_ft: 5 },
        16: { name: '6ft Tarpaulin', roll_width_ft: 6 },
    },
    roll_allocations_by_item: {
        15: [{ roll_id: 157, roll_width_ft: 5, length_ft: 50 }],
        16: [{ roll_id: 156, roll_width_ft: 6, length_ft: 50 }],
    },
};
const multiRuntime = new Function(`return ({
    ${isTarpMethod},
    ${recalcMethod},
    ${planHelpersSource},
    materialCompatibilityContext: { serviceId: 40, serviceKind: 'tarpaulin', customerQuantity: 10 },
    pendingMaterials: [],
    productionErrors: { material: '' },
    currentJo: { quantity: 10 },
    persistedPosTarpaulinSelection() { return ${JSON.stringify(multiRollPlan)}; },
    getInventoryItem(id) {
        const names = { 15: '5ft Tarpaulin', 16: '6ft Tarpaulin' };
        return { id: Number(id), name: names[id] || 'Tarpaulin', unit_of_measure: 'ft', current_stock: 50.1 };
    },
    tarpaulinCalculationForItem() {
        return { required_linear_ft: 100, roll_width_ft: 5, orientation: 'original', run_length_per_piece_ft: 10 };
    },
    clearPendingMaterialConfirmationError() {},
    showStaffAlert() {}
});`)();
multiRuntime.pendingMaterials = [
    { item_id: 15, name: '5ft Tarpaulin', customer_width_ft: 10, customer_height_ft: 5, usage_method: 'tarpaulin_linear_ft', metadata: {} },
    { item_id: 16, name: '6ft Tarpaulin', customer_width_ft: 10, customer_height_ft: 5, usage_method: 'tarpaulin_linear_ft', metadata: {} },
];
assert.strictEqual(multiRuntime.recalculatePendingTarpaulinFromDimensions(multiRuntime.pendingMaterials[0]), true,
    're-entering saved 10×5 dimensions keeps the 5ft material on its 50 ft plan');
assert.strictEqual(multiRuntime.pendingMaterials[0].qty, 50,
    '5ft Tarpaulin stays at 50 ft, not 100 ft full-width miscalculation');
assert.strictEqual(multiRuntime.recalculatePendingTarpaulinFromDimensions(multiRuntime.pendingMaterials[1]), true,
    're-entering saved 10×5 dimensions keeps the 6ft material on its 50 ft plan');
assert.strictEqual(multiRuntime.pendingMaterials[1].qty, 50,
    '6ft Tarpaulin stays at 50 ft after dimension input events');

assert.match(customSource, /x-show="!isPendingTarpaulinMaterial\(pm\) && !isPendingStickerLaminateMaterial\(pm\)"[\s\S]{0,240}Actual Usage:/,
    'non-plan services retain the Actual Usage input');

const stickerPlan = {
    version: 'sticker_laminate_v1',
    item_id: 21,
    branch_id: 1,
    width_ft: 5,
    height_ft: 5,
    finished_quantity: 1,
    roll_width_ft: 6,
    required_linear_ft: 5,
    roll_allocations: [{ roll_id: 158, roll_code: 'MATTE-1', roll_width_ft: 6, length_ft: 5 }],
};
const stickerRecalc = extractObjectMethod(
    customSource,
    'recalculatePendingStickerLaminateFromDimensions(material) {',
    'stickerAssignmentPrintSizeLabel(material) {'
);
const stickerPlanHelpers = `
    stickerFinishedQuantityForValidation() { return 1; },
    stickerDimensionsMatchPersistedPlan(width, height, quantity = null) {
        const persisted = this.persistedPosStickerLaminateSelection();
        if (!persisted) return false;
        const qty = Number(quantity ?? 1);
        return Math.abs(Number(persisted.width_ft || 0) - Number(width)) < 0.000001
            && Math.abs(Number(persisted.height_ft || 0) - Number(height)) < 0.000001
            && Number(persisted.finished_quantity || 0) === Number(qty);
    },
    stickerCalcFromPersistedPlan(material, width, height, quantity = null) {
        const persisted = this.persistedPosStickerLaminateSelection();
        if (!persisted || !material || Number(persisted.item_id) !== Number(material.item_id)) return null;
        if (!this.stickerDimensionsMatchPersistedPlan(width, height, quantity)) return null;
        const plannedRollAllocations = (persisted.roll_allocations || []).filter(row => row && Number(row.roll_id) > 0);
        const plannedFootage = plannedRollAllocations.reduce((sum, row) => sum + Number(row.length_ft), 0);
        if (!(plannedFootage > 0)) return null;
        return {
            calc: { required_linear_ft: plannedFootage, roll_width_ft: Number(persisted.roll_width_ft || 6) },
            plannedRollAllocations,
        };
    },
    persistedPosStickerLaminateSelection() { return ${JSON.stringify(stickerPlan)}; },
    isPendingStickerLaminateMaterial(material) {
        return !!(material && material.usage_method === 'sticker_laminate_ft');
    }
`;
const stickerRuntime = new Function(`return ({
    ${stickerRecalc},
    ${stickerPlanHelpers},
    clearPendingMaterialConfirmationError() {}
});`)();
const stickerMaterial = {
    item_id: 21,
    name: 'Matte Laminate',
    customer_width_ft: 5,
    customer_height_ft: 5,
    usage_method: 'sticker_laminate_ft',
    metadata: {},
};
assert.strictEqual(stickerRuntime.recalculatePendingStickerLaminateFromDimensions(stickerMaterial), true,
    'unchanged sticker dimensions keep saved roll IDs and footage');
assert.strictEqual(stickerMaterial.qty, 5);
assert.strictEqual(stickerMaterial.metadata.sticker_roll_allocations[0].roll_id, 158);
stickerMaterial.customer_width_ft = 6;
assert.strictEqual(stickerRuntime.recalculatePendingStickerLaminateFromDimensions(stickerMaterial), false,
    'edited sticker width cannot keep a stale saved allocation');
assert.strictEqual(stickerMaterial.metadata.sticker_roll_allocations.length, 0);
assert.match(stickerMaterial.dimension_fit_error || '', /no longer match/i);

console.log(JSON.stringify({
    formula: 'required_linear_ft = min run length after fitting either finished dimension across roll width (linear_ft_v1)',
    example_2x2_on_3ft: 'required_linear_ft = 2',
    inventory_uom: 'ft linear roll length saved as assignment quantity (manual_v1 deduction)',
    status: 'POS Tarpaulin assignment dimensions + display regression tests passed.'
}, null, 2));
