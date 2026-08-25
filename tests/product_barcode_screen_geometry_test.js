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

assert(source.includes('@media (max-width: 768px)'), 'tablet/mobile breakpoint must remain present');
assert(source.includes('grid-template-columns: minmax(0, 1fr) !important;'), 'Product Details must become one column');
assert(source.includes('overflow-x: auto;'), 'barcode viewport must permit controlled horizontal scrolling');
assert(source.includes('width: max-content;'), 'barcode track must retain scanner-safe intrinsic width');
assert(source.includes('max-height: calc(100dvh - 16px);'), 'mobile modal must stay within the dynamic viewport');

function availableBarcodeViewportWidth(viewportWidth) {
    if (viewportWidth <= 768) {
        // 8px overlay gutters, 16px modal-body gutters, and the barcode card's
        // 14px padding plus 1px border on each side.
        return viewportWidth - 16 - 32 - 30;
    }
    const modalWidth = Math.min(1000, viewportWidth - 32);
    const columnWidth = (modalWidth - 48 - 24) / 2;
    return columnWidth - 30;
}

for (const viewport of [
    {width: 360, height: 800, dpr: 3},
    {width: 390, height: 844, dpr: 3},
    {width: 412, height: 915, dpr: 3},
    {width: 768, height: 1024, dpr: 1},
    {width: 1024, height: 768, dpr: 1},
    {width: 1440, height: 900, dpr: 1}
]) {
    const {image, result} = measureAt(viewport.dpr);
    const availableWidth = availableBarcodeViewportWidth(viewport.width);
    assert(result.cssWidth === Number.parseFloat(image.style.width), 'barcode width must be explicit and proportional');
    if (result.cssWidth > availableWidth) {
        assert(source.includes('overflow-x: auto;'), `${viewport.width}x${viewport.height} must scroll instead of shrinking`);
        assert.strictEqual(image.style.maxWidth, 'none', `${viewport.width}x${viewport.height} must not compress the barcode`);
    } else {
        assert(result.cssWidth <= availableWidth, `${viewport.width}x${viewport.height} must show the full barcode`);
    }
}

assert.strictEqual(context.pfApplyBarcodeScreenGeometry({naturalWidth: 0, naturalHeight: 0}), null);
console.log('Product barcode screen geometry and responsive-width tests passed.');
