'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const match = source.match(/function receiptDrawQrOnCanvas\(canvas, capture, qrImage\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptCanvasHasVisibleContent\(canvas\) \{[\s\S]*?\r?\n\}\r?\n\r?\nasync function downloadReceiptPdf\(\) \{[\s\S]*?\r?\n\}\r?\n\r?\nlet currentOrderItemsRequest/);
assert(match, 'downloadReceiptPdf() must remain available');
const functionSource = match[0].replace(/\r?\n\r?\nlet currentOrderItemsRequest$/, '');

const button = {disabled: false, textContent: 'Download Receipt'};
const capture = {
    id: '',
    classList: {add() {}},
    style: {cssText: '', setProperty() {}},
    querySelector(selector) { return selector === '#customer-receipt-qr' ? qrTarget : null; },
    offsetWidth: 219,
    scrollWidth: 219,
    offsetHeight: 440,
    scrollHeight: 440,
    getBoundingClientRect() { return {width: 219, height: 440}; }
};
const qrImage = {
    src: '',
    alt: '',
    width: 0,
    height: 0,
    naturalWidth: 396,
    naturalHeight: 396
};
const qrTarget = {
    removeAttribute() {},
    replaceChildren(image) { assert.strictEqual(image, qrImage); }
};
const printArea = {cloneNode() { return capture; }};
const captureHost = {
    style: {cssText: ''},
    appendChild() {},
    remove() { this.removed = true; }
};
const canvas = {
    width: 660,
    height: 1320,
    getContext() {
        return {getImageData() { return {data: new Uint8ClampedArray(660 * 1320 * 4).fill(100)}; }};
    },
    toDataURL() { return `data:image/png;base64,${'a'.repeat(1200)}`; }
};
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
        assert.strictEqual(this.options.html2canvas.width, undefined, 'capture does not crop an off-screen source to a viewport width');
        assert.strictEqual(this.options.html2canvas.windowWidth, undefined, 'capture does not override the browser viewport');
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
    activeReceiptData: {receipt_number: 'ONL-123', qr_payload: 'PF1:ORDER:123'},
    document: {
        body: {appendChild() {}},
        createElement(type) { return type === 'img' ? qrImage : captureHost; },
        fonts: {ready: Promise.resolve()},
        getElementById(id) { return id === 'receipt-print-area' ? printArea : null; },
        querySelector() { return button; }
    },
    window: {
        html2pdf() { return workerCalls++ === 0 ? measurementWorker : pdfWorker; },
        requestAnimationFrame(callback) { callback(); },
        getComputedStyle() {
            return {display: 'block', visibility: 'visible', opacity: '1', transform: 'none', overflow: 'visible'};
        }
    },
    receiptWaitForImages: async () => {},
    receiptQrPngDataUrl: async () => `data:image/png;base64,${'a'.repeat(1200)}`,
    showToast(message) { toastMessages.push(message); },
    console: {
        error(message, detail) { diagnostics.push({message, detail}); },
        info(message, detail) { diagnostics.push({message, detail}); }
    },
    Error,
    Promise,
    Uint8ClampedArray
});

vm.runInContext(functionSource, context);
context.receiptDrawQrOnCanvas = () => ({x: 100, y: 100, size: 396, contrastRange: 255});

(async () => {
    assert.strictEqual(context.window.jspdf, undefined, 'test intentionally provides no jsPDF global');
    await context.downloadReceiptPdf();
    assert.strictEqual(workerCalls, 2, 'measurement and PDF use separate workers');
    assert.deepStrictEqual(saved, ['ONL-123.pdf']);
    assert.strictEqual(button.disabled, false);
    assert.strictEqual(button.textContent, 'Download Receipt');
    assert.strictEqual(captureHost.removed, true);
    assert.deepStrictEqual(toastMessages, []);

    measuredCanvas = {
        width: 660,
        height: 1320,
        getContext() {
            const pixels = new Uint8ClampedArray(660 * 1320 * 4).fill(255);
            return {getImageData() { return {data: pixels}; }};
        },
        toDataURL() { return `data:image/png;base64,${'a'.repeat(1200)}`; }
    };
    workerCalls = 0;
    await context.downloadReceiptPdf();
    assert.deepStrictEqual(saved, ['ONL-123.pdf'], 'blank canvas must not be inserted into a PDF');
    assert.strictEqual(workerCalls, 1, 'blank capture fails before PDF worker creation');
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'html-capture');

    measuredCanvas = {width: 2382, height: 2553};
    workerCalls = 0;
    await context.downloadReceiptPdf();
    assert.deepStrictEqual(saved, ['ONL-123.pdf'], 'A4-width production capture must not be saved');
    assert.strictEqual(workerCalls, 1, 'invalid capture fails before PDF worker creation');
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'html-capture');
    assert.strictEqual(toastMessages.at(-1), 'Unable to generate the receipt PDF right now. Please try again.');

    context.activeReceiptData.qr_payload = '';
    workerCalls = 0;
    await context.downloadReceiptPdf();
    assert.strictEqual(workerCalls, 0, 'missing QR payload fails before receipt rendering');
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'asset-preparation');

    context.activeReceiptData.qr_payload = 'PF1:ORDER:123';
    context.window.html2pdf = undefined;
    await context.downloadReceiptPdf();
    assert.strictEqual(toastMessages.length, 4);
    assert.strictEqual(diagnostics.at(-1).detail.stage, 'initialization');
    assert.strictEqual(button.disabled, false);

    console.log('Customer receipt PDF download runtime tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
