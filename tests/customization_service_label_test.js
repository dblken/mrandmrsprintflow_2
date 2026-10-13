'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const root = require('path').join(__dirname, '..');
const page = fs.readFileSync(require('path').join(root, 'staff/customizations.php'), 'utf8');
const api = fs.readFileSync(require('path').join(root, 'admin/job_orders_api.php'), 'utf8');
const inlineScripts = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert(inlineScripts.length >= 2, 'Staff Customizations main script must exist');
const mainScript = inlineScripts[1][1].replace(/<\?php[\s\S]*?\?>/g, 'null');
new vm.Script(mainScript);

function extractMethod(name) {
    const marker = `${name}(jo) {`;
    const start = mainScript.indexOf(marker);
    assert(start >= 0, `${name} method must exist`);
    const open = mainScript.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < mainScript.length; i += 1) {
        if (mainScript[i] === '{') depth += 1;
        if (mainScript[i] === '}') depth -= 1;
        if (depth === 0) return mainScript.slice(start, i + 1);
    }
    throw new Error(`${name} method is not closed`);
}

const serviceMethod = extractMethod('getCorrectServiceType');
const getCorrectServiceType = new Function(`return ({${serviceMethod}}).getCorrectServiceType`)();
const helpers = {
    isGenericServiceLabel(label) {
        return ['service', 'custom service', 'custom order', 'order item'].includes(String(label).trim().toLowerCase());
    }
};
for (const expected of ['Tarpaulin', 'T-shirt', 'Sticker Service', 'Mugs']) {
    assert.strictEqual(getCorrectServiceType.call(helpers, { service_type: expected }), expected);
}
assert.strictEqual(
    getCorrectServiceType.call(helpers, { service_type: '', job_title: '', items: [{ product_name: 'Tarpaulin' }], customization_details: { tshirt_size: 'M' } }),
    'Tarpaulin',
    'saved line product name wins over specifications from another service'
);
assert.strictEqual(
    getCorrectServiceType.call(helpers, {
        linked_order_item_id: 20,
        items: [{ order_item_id: 10, product_name: 'T-shirt' }, { order_item_id: 20, product_name: 'Tarpaulin' }]
    }),
    'Tarpaulin',
    'the selected linked item wins when an order contains multiple services'
);
assert.strictEqual(
    getCorrectServiceType.call(helpers, {
        items: [{ order_item_id: 10, product_name: 'T-shirt' }, { order_item_id: 20, product_name: 'Tarpaulin' }]
    }),
    '',
    'an ambiguous multi-item order must not choose a service by array position'
);
assert.strictEqual(
    getCorrectServiceType.call(helpers, { service_type: '', job_title: '', items: [], customization_details: { tshirt_size: 'M' } }),
    '',
    'service type must not be inferred from customization fields'
);

assert(api.includes('cust.order_item_id,') && api.includes('AS saved_service_name,'), 'customization list must expose its linked order item and saved service name');
assert(api.includes('saved_service.name') && api.includes('AS saved_service_id,'), 'detail API must resolve catalog services by saved service ID');
assert(api.includes('prod_label.name') && api.includes('p.name) AS saved_product_name,'), 'list and detail APIs must resolve saved product names');
assert(api.includes('LEFT JOIN order_items oi ON oi.order_item_id = cust.order_item_id'), 'detail API must join the exact customization order item');
assert(api.includes('if ($ownOrderItemIdForService > 0) {'), 'exact line matching must run for single and multiple items');
assert(!api.includes('count($items) > 1) {\n                    foreach ($items as $candidateItem)'), 'line matching must not depend on item count');
assert(page.includes('orderItemId: order && order.order_item_id ? order.order_item_id : 0'), 'modal request must carry the selected customization line ID');
assert(/this\.currentJo = \{\};\s*this\.detailRetryPayload = \{ id, orderType \};/.test(page), 'opening another order must clear the previous detail state');
assert(!serviceMethod.includes('customization_details') && !serviceMethod.includes('T-SHIRT PRINTING'), 'service label must not be inferred from specifications');

console.log('Customization service label tests passed for Tarpaulin, T-shirt, Sticker Service, Mugs, and line-specific resolution.');
