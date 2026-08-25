'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'customer', 'orders.php'), 'utf8');
const drawMatch = source.match(/function receiptDrawQrOnCanvas\(canvas, capture, qrImage\) \{[\s\S]*?\r?\n\}\r?\n\r?\nfunction receiptCanvasHasVisibleContent/);
assert(drawMatch, 'receipt QR canvas helper must remain available');
const functionSource = drawMatch[0].replace(/\r?\n\r?\nfunction receiptCanvasHasVisibleContent$/, '');

const drawCalls = [];
const context2d = {
    fillStyle: '',
    imageSmoothingEnabled: true,
    globalAlpha: 0,
    globalCompositeOperation: 'multiply',
    filter: 'blur(1px)',
    save() { drawCalls.push(['save']); },
    restore() { drawCalls.push(['restore']); },
    setTransform(...args) { drawCalls.push(['setTransform', ...args]); },
    fillRect(...args) { drawCalls.push(['fillRect', ...args]); },
    drawImage(...args) { drawCalls.push(['drawImage', ...args]); },
    getImageData() { throw new Error('post-resampling contrast validation must not run'); }
};
const canvas = {
    width: 660,
    height: 1320,
    getContext() { return context2d; }
};
const capture = {getBoundingClientRect() { return {left: 0, top: 0, width: 220, height: 440}; }};
const qrImage = {
    naturalWidth: 396,
    naturalHeight: 396,
    getBoundingClientRect() { return {left: 44, top: 40, width: 132, height: 132}; }
};

const context = vm.createContext({Error, Math, Uint8ClampedArray});
vm.runInContext(functionSource, context);

const placement = context.receiptDrawQrOnCanvas(canvas, capture, qrImage);
assert.deepStrictEqual({...placement}, {x: 132, y: 120, size: 396, sourceWidth: 396, sourceHeight: 396});
assert.strictEqual(context2d.fillStyle, '#ffffff');
assert.strictEqual(context2d.imageSmoothingEnabled, false);
assert.strictEqual(context2d.globalAlpha, 1);
assert.strictEqual(context2d.globalCompositeOperation, 'source-over');
assert.strictEqual(context2d.filter, 'none');
assert.deepStrictEqual(drawCalls, [
    ['save'],
    ['setTransform', 1, 0, 0, 1, 0, 0],
    ['fillRect', 132, 120, 396, 396],
    ['drawImage', qrImage, 132, 120, 396, 396],
    ['restore']
]);

console.log('Customer receipt deterministic QR canvas embedding tests passed.');
