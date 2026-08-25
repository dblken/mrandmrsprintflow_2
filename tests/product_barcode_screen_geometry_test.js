'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'products_management.php'), 'utf8');
const match = source.match(/function pfApplyBarcodeScreenGeometry\(img\) \{[\s\S]*?\r?\n\}/);
assert(match, 'pfApplyBarcodeScreenGeometry() must remain available');

const context = vm.createContext({window: {devicePixelRatio: 1}, Number, Math, String});
vm.runInContext(match[0], context);

function measureAt(devicePixelRatio) {
    context.window.devicePixelRatio = devicePixelRatio;
    const image = {naturalWidth: 286, naturalHeight: 72, style: {}, dataset: {}};
    const result = context.pfApplyBarcodeScreenGeometry(image);
    return {image, result};
}

function assertNear(actual, expected, message) {
    assert(Math.abs(actual - expected) < 0.0001, message + ': expected ' + expected + ', received ' + actual);
}

for (const expected of [
    {dpr: 1, cssWidth: 429, cssHeight: 108, moduleDevicePixels: 3, physicalWidth: 429},
    {dpr: 1.25, cssWidth: 343.2, cssHeight: 86.4, moduleDevicePixels: 3, physicalWidth: 429},
    {dpr: 1.5, cssWidth: 286, cssHeight: 72, moduleDevicePixels: 3, physicalWidth: 429},
    {dpr: 2, cssWidth: 286, cssHeight: 72, moduleDevicePixels: 4, physicalWidth: 572}
]) {
    const {image, result} = measureAt(expected.dpr);
    assertNear(result.cssWidth, expected.cssWidth, expected.dpr + ' DPR width');
    assertNear(result.cssHeight, expected.cssHeight, expected.dpr + ' DPR height');
    assert.strictEqual(result.moduleDevicePixels, expected.moduleDevicePixels, expected.dpr + ' DPR module width');
    assertNear(result.cssWidth * expected.dpr, expected.physicalWidth, expected.dpr + ' DPR physical width is integral');
    assert.strictEqual(image.style.maxWidth, 'none');
    assert.strictEqual(image.dataset.barcodeIntrinsicWidth, '286');
}

assert.strictEqual(context.pfApplyBarcodeScreenGeometry({naturalWidth: 0, naturalHeight: 0}), null);
console.log('Product barcode screen geometry tests passed.');
