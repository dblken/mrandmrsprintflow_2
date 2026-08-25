'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const metricsMatch = source.match(/function receiptQrContrastMetrics\(pixels\) \{[\s\S]*?\r?\n\}/);
const drawMatch = source.match(/function receiptDrawQrOnCanvas\(canvas, capture, qrImage\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptCanvasHasVisibleContent/);
assert(metricsMatch && drawMatch, 'receipt QR contrast and canvas helpers must remain available');
const functionSource = metricsMatch[0] + '\n\n' + drawMatch[0].replace(/\r?\n\r?\nfunction receiptCanvasHasVisibleContent$/, '');

const drawCalls = [];
let monochrome = false;
const context2d = {
    fillStyle: '',
    imageSmoothingEnabled: true,
    fillRect(...args) { drawCalls.push(['fillRect', ...args]); },
    drawImage(...args) { drawCalls.push(args); },
    getImageData(x, y, width, height) {
        const pixels = new Uint8ClampedArray(width * height * 4).fill(255);
        if (!monochrome) {
            for (let pixelIndex = 0; pixelIndex < Math.floor(width * height / 2); pixelIndex++) {
                const channelIndex = pixelIndex * 4;
                pixels[channelIndex] = 0;
                pixels[channelIndex + 1] = 0;
                pixels[channelIndex + 2] = 0;
            }
        }
        return {data: pixels};
    }
};
const canvas = {
    width: 660,
    height: 1320,
    getContext() { return context2d; }
};
const capture = {getBoundingClientRect() { return {left: 0, top: 0, width: 220, height: 440}; }};
const qrImage = {getBoundingClientRect() { return {left: 52, top: 40, width: 116, height: 116}; }};

const context = vm.createContext({Error, Math, Uint8ClampedArray});
vm.runInContext(functionSource, context);

const antialiasedPixels = new Uint8ClampedArray(1000 * 4);
for (let pixelIndex = 0; pixelIndex < 1000; pixelIndex++) {
    const channelIndex = pixelIndex * 4;
    const luminance = pixelIndex < 400 ? 55 : 248;
    antialiasedPixels[channelIndex] = luminance;
    antialiasedPixels[channelIndex + 1] = luminance;
    antialiasedPixels[channelIndex + 2] = luminance;
    antialiasedPixels[channelIndex + 3] = 255;
}
const antialiasedMetrics = context.receiptQrContrastMetrics(antialiasedPixels);
assert.strictEqual(antialiasedMetrics.valid, true, 'near-black and near-white antialiased pixels remain valid');
assert.strictEqual(antialiasedMetrics.contrastRange, 193);

const placement = context.receiptDrawQrOnCanvas(canvas, capture, qrImage);
assert.deepStrictEqual({...placement}, {x: 156, y: 120, size: 348, contrastRange: 255});
assert.strictEqual(context2d.fillStyle, '#ffffff');
assert.strictEqual(context2d.imageSmoothingEnabled, false);
assert.deepStrictEqual(drawCalls[0], ['fillRect', 156, 120, 348, 348]);
assert.deepStrictEqual(drawCalls[1], [qrImage, 156, 120, 348, 348]);

monochrome = true;
assert.throws(
    () => context.receiptDrawQrOnCanvas(canvas, capture, qrImage),
    /insufficient contrast/,
    'a blank QR region must abort PDF generation'
);

console.log('Customer receipt QR canvas embedding tests passed.');
