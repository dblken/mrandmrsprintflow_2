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
let measuredCanvas = canvas;
const saved = [];
const pdf = {
    internal: {pageSize: {getWidth() { return 58; }, getHeight() { return 116.5; }}},
    getNumberOfPages() { return 1; },
    save(filename) { saved.push(filename); }
};
const measurementWorker = {
    options: {},
    set(options) {
        this.options = {...this.options, ...options};
        return this;
    },
    from(value) {
        assert.strictEqual(value, capture);
        assert.strictEqual(this.options.jsPDF.format[0], 58, 'capture worker uses receipt width before toCanvas');
        assert.strictEqual(this.options.jsPDF.format[1], 2000, 'capture worker uses a tall temporary receipt page');
        assert.strictEqual(this.options.html2canvas.width, 220, 'capture viewport is 58mm in CSS pixels');
        return this;
    },
    async toCanvas() {},
    async get(key) {
        assert.strictEqual(key, 'canvas');
        return measuredCanvas;
    }
};
const pdfWorker = {
    options: {},
    set(options) {
        this.options = {...this.options, ...options};
        return this;
    },
    from(value, type) {
        assert.strictEqual(value, canvas);
        assert.strictEqual(type, 'canvas');
        assert.strictEqual(this.options.jsPDF.format[0], 58, 'custom width is configured before PDF input');
        assert.strictEqual(this.options.jsPDF.format[1], 116.5, 'dynamic height is configured before PDF input');
        return this;
    },
    async toPdf() {
        assert.strictEqual(this.options.jsPDF.format[0], 58);
        assert.strictEqual(this.options.jsPDF.format[1], 116.5);
    },
    async get(key) {
        assert.strictEqual(key, 'pdf');
        return pdf;
    }
};
let workerCalls = 0;
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
    window: {html2pdf() { return workerCalls++ === 0 ? measurementWorker : pdfWorker; }},
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
    assert.strictEqual(workerCalls, 2, 'measurement and PDF use separate workers');
    assert.deepStrictEqual(saved, ['ONL-123.pdf']);
    assert.strictEqual(button.disabled, false);
    assert.strictEqual(button.textContent, 'Download Receipt');
    assert.strictEqual(captureHost.removed, true);
    assert.deepStrictEqual(toastMessages, []);

    measuredCanvas = {width: 2382, height: 2553};
    workerCalls = 0;
    await context.downloadReceiptPdf();
    assert.deepStrictEqual(saved, ['ONL-123.pdf'], 'A4-width production capture must not be saved');
    assert.strictEqual(workerCalls, 1, 'invalid capture fails before PDF worker creation');
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'html-capture');
    assert.strictEqual(toastMessages.at(-1), 'Unable to generate the receipt PDF right now. Please try again.');

    context.window.html2pdf = undefined;
    await context.downloadReceiptPdf();
    assert.strictEqual(toastMessages.length, 2);
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'initialization');
    assert.strictEqual(button.disabled, false);

    console.log('Customer receipt PDF download runtime tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
