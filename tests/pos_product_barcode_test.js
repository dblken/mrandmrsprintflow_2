'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'staff', 'pos.php'), 'utf8');
const runtimeMatch = source.match(/function isBarcodeTerminatorKey\(key\) \{[\s\S]*?\r?\n        \}\r?\n        async function addToCart/);
assert(runtimeMatch, 'POS barcode capture, queue, and processing functions must remain available');
const runtimeSource = runtimeMatch[0].replace(/\r?\n        async function addToCart$/, '');

const input = {
    disabled: false,
    value: 'TSH-0004',
    offsetWidth: 220,
    offsetHeight: 40,
    getClientRects() { return [{}]; },
    focus() {},
    select() {}
};
const notices = [];
const requests = [];
const additions = [];
let capturedKeydown = null;
let fakeNow = 1000;

function responseForSku(sku) {
    if (sku === 'UNKNOWN-SKU') return {success: true, product: null};
    if (sku === 'OUT-0005') {
        return {
            success: true,
            availability: 'available',
            product: {product_id: 5, product_name: 'No Stock Product', sku, status: 'Activated', stock_quantity: 0}
        };
    }
    return {
        success: true,
        availability: 'available',
        product: {
            product_id: sku === 'TSH-0004' ? 4 : 100 + additions.length,
            product_name: sku === 'TSH-0004' ? 'Super Mario T Shirt' : sku,
            sku,
            status: 'Activated',
            stock_quantity: 100,
            has_variant_stock: sku === 'TSH-0004',
            variant_stock_options: sku === 'TSH-0004' ? {Small: {stock_quantity: 40}, Large: {stock_quantity: 60}} : undefined
        }
    };
}

const context = vm.createContext({
    barcodeScanBusy: false,
    barcodeScanQueueRunning: false,
    barcodeScanQueue: [],
    products: [],
    cart: [],
    document: {
        body: {},
        getElementById() { return input; },
        querySelectorAll() { return [input]; },
        addEventListener(type, handler) { if (type === 'keydown') capturedKeydown = handler; }
    },
    window: {
        __printflowPosProductScannerCaptureInstalled: false,
        setTimeout() {},
        location: {assign() {}},
        console
    },
    staffUrl(value) { return '/' + value; },
    async fetch(url) {
        requests.push(url);
        const sku = decodeURIComponent(String(url).split('sku=')[1] || '');
        return {ok: true, async json() { return responseForSku(sku); }};
    },
    showPOSScanNotice(title, message, type) { notices.push({title, message, type}); },
    clearBarcodeInputs() { input.value = ''; },
    focusBarcodeInput() {},
    finishBarcodeScan() { input.value = ''; },
    posBarcodeDebug() {},
    scannedCartQuantity() { return 0; },
    async addToCart(product) { additions.push(product); return {success: true}; },
    renderProducts() {},
    encodeURIComponent,
    decodeURIComponent,
    String,
    Number,
    Date: {now() { return fakeNow; }},
    Math,
    Array,
    Error,
    Promise,
    parseInt,
    console
});
vm.runInContext(runtimeSource, context);

function scannerEvent(key, target = {tagName: 'BUTTON', closest() { return null; }}) {
    return {
        key,
        target,
        defaultPrevented: false,
        ctrlKey: false,
        altKey: false,
        metaKey: false,
        preventDefault() { this.defaultPrevented = true; },
        stopImmediatePropagation() { this.stopped = true; }
    };
}

(async () => {
    for (const key of ['Enter', 'Tab', '\r', '\n']) {
        assert.strictEqual(context.isBarcodeTerminatorKey(key), true, key + ' must terminate a scan');
    }
    assert.strictEqual(context.isBarcodeTerminatorKey('Space'), false);
    assert.strictEqual(context.normalizeProductBarcode(' TSH-0004\r\n'), 'TSH-0004');

    await context.handleBarcodeScan('TSH-0004\r\n', input, {terminator: 'Enter', source: 'barcode-input'});
    assert.strictEqual(requests[0], '/staff/api/get_product_by_sku.php?sku=TSH-0004');
    assert.strictEqual(additions.length, 1, 'one completed scan adds exactly once');
    assert.strictEqual(additions[0].product_name, 'Super Mario T Shirt');
    assert.strictEqual(additions[0].has_variant_stock, true, 'variant metadata remains intact');

    const manualStickerStart = additions.length;
    await context.handleBarcodeScan('STK-0001', input, {terminator: 'Enter', source: 'barcode-input'});
    assert.strictEqual(requests.at(-1), '/staff/api/get_product_by_sku.php?sku=STK-0001');
    assert.strictEqual(additions.length - manualStickerStart, 1, 'manual STK-0001 plus Enter adds exactly once');
    assert.strictEqual(additions.at(-1).sku, 'STK-0001', 'manual SKU lookup returns the exact requested product SKU');

    let releaseFirst;
    const firstBlockedAdd = new Promise(resolve => { releaseFirst = resolve; });
    context.addToCart = async product => {
        additions.push(product);
        if (product.sku === 'RAPID-A') await firstBlockedAdd;
        return {success: true};
    };
    const rapidStart = additions.length;
    const rapidA = context.handleBarcodeScan('RAPID-A', input);
    await Promise.resolve();
    await Promise.resolve();
    const rapidB = context.handleBarcodeScan('RAPID-B', input);
    const rapidC = context.handleBarcodeScan('RAPID-C', input);
    releaseFirst();
    await Promise.all([rapidA, rapidB, rapidC]);
    assert.deepStrictEqual(additions.slice(rapidStart).map(product => product.sku), ['RAPID-A', 'RAPID-B', 'RAPID-C'], 'rapid scans process in FIFO order');

    context.addToCart = async product => { additions.push(product); return {success: true}; };
    const repeatedStart = additions.length;
    await Promise.all([
        context.handleBarcodeScan('TSH-0004', input),
        context.handleBarcodeScan('TSH-0004', input)
    ]);
    assert.strictEqual(additions.length - repeatedStart, 2, 'two intentional completed scans increase quantity twice');

    const twentyStart = additions.length;
    const twentyScans = Array.from({length: 20}, (_, index) => context.handleBarcodeScan('LOAD-' + String(index + 1).padStart(2, '0'), input));
    await Promise.all(twentyScans);
    assert.strictEqual(additions.length - twentyStart, 20, '20 completed scans are all processed');

    await context.handleBarcodeScan('UNKNOWN-SKU', input);
    assert.strictEqual(notices.at(-1).title, 'Product Not Found');
    await context.handleBarcodeScan('OUT-0005', input);
    assert.strictEqual(notices.at(-1).title, 'Out of Stock');

    context.installPosBarcodeKeyboardCapture();
    assert.strictEqual(typeof capturedKeydown, 'function', 'POS keyboard capture is installed once');
    const globalScans = [];
    context.handleBarcodeScan = (raw, sourceInput, meta) => globalScans.push({raw, sourceInput, meta});
    for (const key of 'TSH-0004') {
        capturedKeydown(scannerEvent(key));
        fakeNow += 20;
    }
    const enterEvent = scannerEvent('Enter');
    capturedKeydown(enterEvent);
    assert.strictEqual(globalScans.length, 1, 'scanner input is captured when a non-editing POS control has focus');
    assert.strictEqual(globalScans[0].raw, 'TSH-0004');
    assert.strictEqual(enterEvent.defaultPrevented, true);

    for (const key of 'PF1:ORDER:11280') {
        capturedKeydown(scannerEvent(key));
        fakeNow += 20;
    }
    capturedKeydown(scannerEvent('Enter'));
    assert.strictEqual(globalScans.length, 1, 'receipt payload remains owned by the receipt scanner');

    const textInput = {tagName: 'INPUT', isContentEditable: false, closest() { return null; }};
    for (const key of 'TSH-0004') capturedKeydown(scannerEvent(key, textInput));
    capturedKeydown(scannerEvent('Enter', textInput));
    assert.strictEqual(globalScans.length, 1, 'ordinary typing in other form inputs is never hijacked');

    console.log('POS product barcode runtime tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
