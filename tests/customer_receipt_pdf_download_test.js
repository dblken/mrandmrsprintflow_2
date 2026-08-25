'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const match = source.match(/async function downloadReceiptPdf\(\) \{[\s\S]*?\r?\n\}\r?\n\r?\nlet currentOrderItemsRequest/);
assert(match, 'downloadReceiptPdf() must remain available');
const functionSource = match[0].replace(/\r?\n\r?\nlet currentOrderItemsRequest$/, '');

const button = {disabled: false, textContent: 'Download Receipt'};
const capture = {
    id: '',
    classList: {add() {}},
    querySelector() { return null; },
    scrollWidth: 220
};
const printArea = {cloneNode() { return capture; }};
const captureHost = {
    style: {cssText: ''},
    appendChild() {},
    remove() { this.removed = true; }
};
const canvas = {width: 660, height: 1320};
const saved = [];
const pdf = {save(filename) { saved.push(filename); }};
const worker = {
    options: {},
    set(options) {
        this.options = {...this.options, ...options};
        return this;
    },
    from(value) {
        assert.strictEqual(value, capture);
        return this;
    },
    async toCanvas() {},
    async toPdf() {
        assert.strictEqual(this.options.jsPDF.format[0], 58);
        assert.strictEqual(this.options.jsPDF.format[1], 116);
    },
    async get(key) {
        return key === 'canvas' ? canvas : pdf;
    }
};
const toastMessages = [];
const diagnostics = [];
const context = vm.createContext({
    activeReceiptData: {receipt_number: 'ONL-123', qr_payload: ''},
    document: {
        body: {appendChild() {}},
        createElement() { return captureHost; },
        fonts: {ready: Promise.resolve()},
        getElementById(id) { return id === 'receipt-print-area' ? printArea : null; },
        querySelector() { return button; }
    },
    window: {html2pdf() { return worker; }},
    receiptWaitForImages: async () => {},
    receiptQrPngDataUrl: async () => '',
    showToast(message) { toastMessages.push(message); },
    console: {error(message, detail) { diagnostics.push({message, detail}); }},
    Error,
    Promise
});

vm.runInContext(functionSource, context);

(async () => {
    assert.strictEqual(context.window.jspdf, undefined, 'test intentionally provides no jsPDF global');
    await context.downloadReceiptPdf();
    assert.deepStrictEqual(saved, ['ONL-123.pdf']);
    assert.strictEqual(button.disabled, false);
    assert.strictEqual(button.textContent, 'Download Receipt');
    assert.strictEqual(captureHost.removed, true);
    assert.deepStrictEqual(toastMessages, []);

    context.window.html2pdf = undefined;
    await context.downloadReceiptPdf();
    assert.deepStrictEqual(toastMessages, ['Unable to generate the receipt PDF right now. Please try again.']);
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'initialization');
    assert.strictEqual(button.disabled, false);

    console.log('Customer receipt PDF download runtime tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
