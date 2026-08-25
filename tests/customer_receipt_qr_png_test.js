'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const match = source.match(/function receiptQrPngDataUrl\(payload\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptWaitForImages/);
assert(match, 'receiptQrPngDataUrl() must remain available');
const functionSource = match[0].replace(/\r?\n\r?\nfunction receiptWaitForImages$/, '');

const operations = [];
const qrCanvas = {width: 116, height: 116};
const host = {
    style: {cssText: ''},
    querySelector(selector) { return selector === 'canvas' ? qrCanvas : null; },
    remove() { this.removed = true; }
};
const outputContext = {
    fillStyle: '',
    imageSmoothingEnabled: true,
    fillRect(...args) { operations.push(['fillRect', ...args]); },
    drawImage(...args) { operations.push(['drawImage', ...args]); },
    getImageData() {
        const pixels = new Uint8ClampedArray(132 * 132 * 4).fill(255);
        for (let pixelIndex = 0; pixelIndex < 200; pixelIndex++) {
            const channelIndex = pixelIndex * 4;
            pixels[channelIndex] = 0;
            pixels[channelIndex + 1] = 0;
            pixels[channelIndex + 2] = 0;
        }
        return {data: pixels};
    }
};
const outputCanvas = {
    width: 0,
    height: 0,
    getContext() { return outputContext; },
    toDataURL(type) {
        assert.strictEqual(type, 'image/png');
        return 'data:image/png;base64,VALID_QR';
    }
};
let createCount = 0;
function QRCode(target, options) {
    assert.strictEqual(target, host);
    assert.strictEqual(options.text, 'PF1:ORDER:11280');
    assert.strictEqual(options.width, 116);
    assert.strictEqual(options.height, 116);
}
QRCode.CorrectLevel = {M: 0};

const context = vm.createContext({
    document: {
        body: {appendChild(node) { assert.strictEqual(node, host); }},
        createElement(type) {
            assert.strictEqual(type, createCount++ === 0 ? 'div' : 'canvas');
            return type === 'div' ? host : outputCanvas;
        }
    },
    window: {requestAnimationFrame(callback) { callback(); }},
    QRCode,
    Error,
    Promise,
    Uint8ClampedArray
});
vm.runInContext(functionSource, context);

(async () => {
    const result = await context.receiptQrPngDataUrl('PF1:ORDER:11280');
    assert.strictEqual(result, 'data:image/png;base64,VALID_QR');
    assert.strictEqual(outputCanvas.width, 132);
    assert.strictEqual(outputCanvas.height, 132);
    assert.strictEqual(outputContext.fillStyle, '#ffffff');
    assert.strictEqual(outputContext.imageSmoothingEnabled, false);
    assert.deepStrictEqual(operations[0], ['fillRect', 0, 0, 132, 132]);
    assert.deepStrictEqual(operations[1], ['drawImage', qrCanvas, 8, 8]);
    assert.strictEqual(host.removed, true);
    console.log('Customer receipt QR PNG tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
