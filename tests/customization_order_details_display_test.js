'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'staff/customizations.php'), 'utf8');
const modalApi = fs.readFileSync(path.join(root, 'staff/get_order_for_modal.php'), 'utf8');
const orderService = fs.readFileSync(path.join(root, 'includes/JobOrderService.php'), 'utf8');
const scripts = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert(scripts.length >= 2, 'Custom Orders main script exists');
const script = scripts[1][1].replace(/<\?php[\s\S]*?\?>/g, 'null');

function extractMethod(name, args) {
    const marker = `${name}(${args}) {`;
    const start = script.indexOf(marker);
    assert(start >= 0, `${name} exists`);
    const signatureEnd = script.indexOf(') {', start);
    const open = signatureEnd + 2;
    let depth = 0;
    for (let i = open; i < script.length; i += 1) {
        if (script[i] === '{') depth += 1;
        if (script[i] === '}') depth -= 1;
        if (depth === 0) return script.slice(start, i + 1);
    }
    throw new Error(`${name} method is unclosed`);
}

const methods = [
    extractMethod('staffCustomizationKeyToken', 'key'),
    extractMethod('staffCustomizationIsInternalKey', 'key'),
    extractMethod('staffCustomizationIsInternalValue', 'value'),
    extractMethod('staffCustomizationValueText', 'value'),
    extractMethod('staffCustomizationValueFingerprint', 'value'),
    extractMethod('staffCustomizationSemanticValueFingerprint', 'group, value'),
    extractMethod('staffCustomizationFieldMeta', 'key'),
    extractMethod('staffCustomizationDisplaySpecs', 'obj, options = {}'),
    extractMethod('formatCustomValuePlain', 'v'),
];
const api = new Function(`return ({${methods.join(',')}});`)();
api.staffValueLooksLikeUploadFilename = () => false;
api.staffBasename = value => String(value || '').split('/').pop();
api.formatCustomerPriorityDisplay = value => String(value || '');
api.staffDeduplicateDisplaySpecRows = value => value;

for (const internalKey of [
    'pos_selected_material_ids',
    'pos_material_dimension_unit',
    'pos_material_consumption',
    'pos_selected_material_consumption',
]) {
    assert.strictEqual(api.staffCustomizationFieldMeta(internalKey).hidden, true, `${internalKey} is filtered from customer specifications`);
}

const submittedSpecs = {
    Quantity: 1,
    Layout: 'Without Layout',
    Dimensions: { field_label: 'Dimensions', selected_value: '1 x 2 ft' },
    'Board Thickness': { field_label: 'Board Thickness', selected_value: 'Sintra 3mm' },
    'Needed Date': '2026-11-12',
    'Order Priority': 'Urgent Order',
    pos_selected_material_ids: [207],
    pos_material_dimension_unit: 'ft',
    pos_material_consumption: [{ item_id: 207, quantity: 2, uom: 'pcs' }],
};
const display = api.staffCustomizationDisplaySpecs(submittedSpecs, { includeService: false, includeNotes: true });
assert.deepStrictEqual(display, {
    Quantity: '1',
    Layout: 'Without Layout',
    Dimensions: '1 x 2 ft',
    'Board Thickness': 'Sintra 3mm',
    'Needed Date': 'Nov 12, 2026',
    'Order Priority': 'Urgent Order',
});
assert(!JSON.stringify(display).includes('[object Object]'), 'Structured customer choices are rendered as values, never object coercions');
assert.strictEqual(api.formatCustomValuePlain(submittedSpecs.pos_material_consumption), '', 'Internal material snapshots never render as specifications');

const posAndOnlineCustomerSpecs = [
    { source: 'walk-in POS', payload: { customization_data: JSON.stringify(submittedSpecs) } },
    { source: 'online order', payload: { specifications: submittedSpecs } },
];
for (const fixture of posAndOnlineCustomerSpecs) {
    const data = fixture.payload.customization_data ? JSON.parse(fixture.payload.customization_data) : fixture.payload.specifications;
    assert.deepStrictEqual(api.staffCustomizationDisplaySpecs(data, { includeService: false, includeNotes: true }), display,
        `${fixture.source} uses the same customer-specification normalization`);
}
assert(page.includes('getDisplayableCustom(item.customization, item)'), 'The modal renders the normalized per-line specification object');
assert(modalApi.includes('printflow_job_order_find_store_line_from_references') && modalApi.includes("'items' => $items_out"), 'The order detail API scopes submitted specs to the matching order line');
assert(orderService.includes("'customization' => $customization") || orderService.includes("'customization' => $itemCustomization"), 'Internal and customer data remain available in the backend order payload');

console.log('Custom Orders customer-specification display tests passed for POS and online payloads.');
