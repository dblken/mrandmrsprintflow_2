'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'staff', 'pos.php'), 'utf8');
const terminatorMatch = source.match(/function isBarcodeTerminatorKey\(key\) \{[\s\S]*?\r?\n        \}/);
const handlerMatch = source.match(/async function handleBarcodeScan\(code, sourceInput = null\) \{[\s\S]*?\r?\n        \}\r?\n        async function addToCart/);
assert(terminatorMatch, 'POS barcode terminator helper must exist');
assert(handlerMatch, 'POS barcode handler must exist');

const handlerSource = handlerMatch[0].replace(/\r?\n        async function addToCart$/, '');
const input = {disabled: false, value: 'TSH-0004'};
const notices = [];
const requests = [];
const additions = [];
let productResponse = {
    success: true,
    availability: 'available',
    product: {
        product_id: 4,
        product_name: 'Super Mario T Shirt',
        sku: 'TSH-0004',
        status: 'Activated',
        stock_quantity: 8,
        has_variant_stock: true,
        variant_stock_options: {Small: {stock_quantity: 3}, Large: {stock_quantity: 5}}
    }
};

const context = vm.createContext({
    barcodeScanBusy: false,
    products: [],
    cart: [],
    document: {
        getElementById() { return input; },
        querySelectorAll() { return [input]; }
    },
    window: {setTimeout() {}, location: {assign() {}}},
    staffUrl(value) { return '/' + value; },
    async fetch(url) {
        requests.push(url);
        return {ok: true, async json() { return productResponse; }};
    },
    showPOSScanNotice(title, message, type) { notices.push({title, message, type}); },
    finishBarcodeScan(target) { target.value = ''; },
    scannedCartQuantity() { return 0; },
    async addToCart(product) { additions.push(product); return {success: true}; },
    renderProducts() {},
    encodeURIComponent,
    String,
    parseInt,
    console
});
vm.runInContext(terminatorMatch[0] + '\n' + handlerSource, context);

(async () => {
    for (const key of ['Enter', 'Tab', '\r', '\n']) {
        assert.strictEqual(context.isBarcodeTerminatorKey(key), true, key + ' must terminate a scan');
    }
    assert.strictEqual(context.isBarcodeTerminatorKey('Space'), false);

    await context.handleBarcodeScan('TSH-0004\r\n', input);
    assert.strictEqual(requests[0], '/staff/api/get_product_by_sku.php?sku=TSH-0004');
    assert.strictEqual(additions.length, 1, 'one scan must add exactly once');
    assert.strictEqual(additions[0].product_name, 'Super Mario T Shirt');
    assert.strictEqual(additions[0].has_variant_stock, true, 'variant metadata must remain intact');
    assert.strictEqual(notices.at(-1).title, 'Added to Cart');

    let releaseAdd;
    const pendingAdd = new Promise(resolve => { releaseAdd = resolve; });
    context.addToCart = async product => {
        additions.push(product);
        await pendingAdd;
        return {success: true};
    };
    const firstTerminator = context.handleBarcodeScan('TSH-0004', input);
    await Promise.resolve();
    await Promise.resolve();
    const duplicateTerminator = context.handleBarcodeScan('TSH-0004', input);
    releaseAdd();
    await Promise.all([firstTerminator, duplicateTerminator]);
    assert.strictEqual(additions.length, 2, 'duplicate terminator during one scan must not add twice');

    context.addToCart = async product => { additions.push(product); return {success: true}; };
    await context.handleBarcodeScan('TSH-0004', input);
    assert.strictEqual(additions.length, 3, 'a deliberate later scan retains normal quantity-increase behavior');

    productResponse = {success: true, product: null};
    await context.handleBarcodeScan('UNKNOWN-SKU', input);
    assert.strictEqual(additions.length, 3, 'unknown SKU must not add a cart item');
    assert.strictEqual(notices.at(-1).title, 'Product Not Found');

    productResponse = {
        success: true,
        availability: 'available',
        product: {product_id: 5, product_name: 'No Stock Product', sku: 'OUT-0005', status: 'Activated', stock_quantity: 0}
    };
    await context.handleBarcodeScan('OUT-0005', input);
    assert.strictEqual(additions.length, 3, 'out-of-stock SKU must not add a cart item');
    assert.strictEqual(notices.at(-1).title, 'Out of Stock');

    console.log('POS product barcode runtime tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
