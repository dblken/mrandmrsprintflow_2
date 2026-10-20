'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../staff/pos.php'), 'utf8');
const renderSource = source.slice(source.indexOf('        function renderProducts()'), source.indexOf('        function focusBarcodeInput('));
// Extract the complete fetch block without executing the rest of the POS page.
const fetchEnd = source.indexOf('\n        //', source.indexOf('        async function fetchProducts('));
const refreshSource = source.slice(source.indexOf('        let posProductFetchId ='), fetchEnd);
const events = {};
const grid = {
    children: [], scrollTop: 42,
    set innerHTML(value) { this.html = value; this.children = []; },
    appendChild(card) { this.children.push(card); }
};
const controls = { 'pos-products-grid': grid, 'pos-search': { value: '' }, 'pos-category': { value: '' } };
let timer;
let requests = 0;
let payload = [];
let fail = false;
const context = vm.createContext({
    products: [], currentMode: 'products', POS_DEFAULT_CATALOG_IMG: '/default.png',
    console: { log() {}, error() {} },
    document: {
        hidden: false, activeElement: null,
        getElementById(id) { return controls[id]; },
        createElement() { return { dataset: {}, focus() { context.document.activeElement = this; } }; },
        addEventListener(name, callback) { events[name] = callback; }
    },
    window: {
        addEventListener(name, callback) { events[name] = callback; },
        setInterval(callback, interval) { timer = callback; assert.equal(interval, 30000); }
    },
    staffUrl: value => '/' + value,
    escapeHtml: value => String(value).replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
    posCatalogImageUrl: () => '/product.png', formatMoney: value => 'PHP ' + value,
    async addToCart() {},
    async fetch(url, options) {
        requests++;
        assert.equal(url, '/staff/api/get_products.php');
        assert.equal(options.cache, 'no-store');
        if (fail) throw new Error('offline');
        return { ok: true, async json() { return { success: true, products: payload }; } };
    }
});
vm.runInContext(renderSource + '\n' + refreshSource, context);
const product = (id, stock, low = 5) => ({ product_id: id, product_name: 'Product ' + id, category: 'Stickers', stock_quantity: stock, low_stock_level: low, price: 50 });

(async () => {
    payload = [product(1, 20), product(2, 0), product(3, 3), product(4, 1, 0), product(5, 9, 9)];
    await context.fetchProducts();
    assert.equal(grid.children.length, 5);
    for (const card of grid.children) assert.match(card.innerHTML, /Available Stock:/);
    assert.doesNotMatch(grid.children[0].innerHTML, /Low stock|Out of stock/);
    assert.match(grid.children[1].innerHTML, /<strong>0<\/strong> · Out of stock/);
    assert.equal(grid.children[1].onclick, undefined, 'zero-stock purchase behavior stays blocked');
    assert.match(grid.children[2].innerHTML, /<strong>3<\/strong> · Low stock/);
    assert.doesNotMatch(grid.children[3].innerHTML, /Low stock/, 'zero threshold must not default to ten');
    assert.match(grid.children[4].innerHTML, /Low stock/, 'threshold boundary is low stock');
    assert.equal(typeof grid.children[0].onclick, 'function');

    const originalCard = grid.children[0];
    await context.fetchProducts({ background: true });
    assert.equal(grid.children[0], originalCard, 'unchanged response does not rebuild cards');
    context.document.activeElement = originalCard;
    payload = [product(1, 19), product(2, 10)];
    await context.fetchProducts({ background: true });
    assert.match(grid.children[0].innerHTML, /<strong>19<\/strong>/, 'sale/deduction response updates quantity');
    assert.equal(typeof grid.children[1].onclick, 'function', 'restocked product becomes selectable');
    assert.equal(grid.scrollTop, 42);
    assert.equal(context.document.activeElement, grid.children[0], 'keyboard focus survives refresh');
    fail = true;
    const beforeFailure = grid.children[0];
    await context.fetchProducts({ background: true });
    assert.equal(grid.children[0], beforeFailure, 'network failure does not erase catalog');
    fail = false;
    const count = requests;
    context.document.hidden = true;
    timer();
    assert.equal(requests, count, 'hidden tabs do not poll');
    context.document.hidden = false;
    context.currentMode = 'services';
    timer();
    assert.equal(requests, count, 'services do not poll product inventory');
    context.currentMode = 'products';
    events.focus();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests, count + 1, 'returning to the tab refreshes inventory');
    controls['pos-search'].value = 'Product 2';
    context.renderProducts();
    assert.equal(grid.children.length, 1, 'search filter is preserved');

    controls['pos-search'].value = '';
    const waiting = [];
    context.fetch = () => new Promise(resolve => waiting.push(resolve));
    const olderRequest = context.fetchProducts();
    const newerRequest = context.fetchProducts();
    waiting[1]({ ok: true, async json() { return { success: true, products: [product(1, 50)] }; } });
    await newerRequest;
    waiting[0]({ ok: true, async json() { return { success: true, products: [product(1, 2)] }; } });
    await olderRequest;
    assert.match(grid.children[0].innerHTML, /<strong>50<\/strong>/, 'late response cannot overwrite newer inventory');
    console.log('POS catalog stock rendering and refresh tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
