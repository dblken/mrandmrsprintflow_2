'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const match = source.match(/function receiptQrSourceMetrics\(pixels, width, height\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptQrPngDataUrl\(payload\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptWaitForImages/);
assert(match, 'receiptQrPngDataUrl() must remain available');
const functionSource = match[0].replace(/\r?\n\r?\nfunction receiptWaitForImages$/, '');

const operations = [];
const qrCanvas = {width: 300, height: 300};
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
        const pixels = new Uint8ClampedArray(396 * 396 * 4).fill(255);
        for (let pixelIndex = 0; pixelIndex < 50000; pixelIndex++) {
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
const generatedPayloads = [];
function QRCode(target, options) {
    assert.strictEqual(target, host);
    generatedPayloads.push(options.text);
    assert.strictEqual(options.width, 300);
    assert.strictEqual(options.height, 300);
    assert.strictEqual(options.colorDark, '#000000');
    assert.strictEqual(options.colorLight, '#ffffff');
}
QRCode.CorrectLevel = {M: 0};

const context = vm.createContext({
    document: {
        body: {appendChild(node) { assert.strictEqual(node, host); }},
        createElement(type) {
            if (type === 'div') return host;
            if (type === 'canvas') return outputCanvas;
            throw new Error('Unexpected element: ' + type);
        }
    },
    window: {requestAnimationFrame(callback) { callback(); }},
    QRCode,
    Error,
    Promise,
    Uint8ClampedArray
});
vm.runInContext(functionSource, context);

const blankPixels = new Uint8ClampedArray(396 * 396 * 4).fill(255);
const blankMetrics = context.receiptQrSourceMetrics(blankPixels, 396, 396);
assert.strictEqual(blankMetrics.valid, false, 'a genuinely blank generated QR is rejected before PDF capture');
assert.strictEqual(blankMetrics.darkPixels, 0);

const antialiasedPixels = new Uint8ClampedArray(396 * 396 * 4).fill(255);
for (let pixelIndex = 0; pixelIndex < 1000; pixelIndex++) {
    const channelIndex = pixelIndex * 4;
    antialiasedPixels[channelIndex] = 55;
    antialiasedPixels[channelIndex + 1] = 55;
    antialiasedPixels[channelIndex + 2] = 55;
}
assert.strictEqual(
    context.receiptQrSourceMetrics(antialiasedPixels, 396, 396).valid,
    true,
    'near-black modules and near-white background remain valid'
);

(async () => {
    const payloads = ['PF1:ORDER:11280', 'PF1:ORDER:11281', 'PF1:ORDER:11282'];
    const results = [];
    for (const payload of payloads) results.push(await context.receiptQrPngDataUrl(payload));
    assert.deepStrictEqual(generatedPayloads, payloads, 'each completed order generates from its own current payload');
    assert(results.every(result => result.dataUrl === 'data:image/png;base64,VALID_QR'));
    assert.deepStrictEqual(results.map(result => result.payload), payloads);
    assert(results.every(result => result.width === 396 && result.height === 396));
    assert(results.every(result => result.metrics.valid === true));
    assert.strictEqual(outputCanvas.width, 396);
    assert.strictEqual(outputCanvas.height, 396);
    assert.strictEqual(outputContext.fillStyle, '#ffffff');
    assert.strictEqual(outputContext.imageSmoothingEnabled, false);
    assert.deepStrictEqual(operations.slice(0, 2), [
        ['fillRect', 0, 0, 396, 396],
        ['drawImage', qrCanvas, 48, 48]
    ]);
    assert.strictEqual(operations.length, 6);
    assert.strictEqual(host.removed, true);
    console.log('Customer receipt QR PNG tests passed.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
