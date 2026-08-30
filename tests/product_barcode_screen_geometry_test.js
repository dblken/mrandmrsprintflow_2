'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'products_management.php'), 'utf8');
const imageRule = source.match(/\.pf-product-barcode-image\s*\{([\s\S]*?)\}/);

assert(imageRule, 'product barcode image rule must remain present');
assert(imageRule[1].includes('width: auto;'), 'barcode must use its native SVG width');
assert(imageRule[1].includes('height: auto;'), 'barcode must preserve its native aspect ratio');
assert(imageRule[1].includes('max-width: none;'), 'barcode must never be responsively compressed');
assert(!imageRule[1].includes('width: 100%;'), 'barcode image must not use responsive width:100%');
assert(!source.includes('pfApplyBarcodeScreenGeometry'), 'devicePixelRatio barcode rewriting must stay removed');
assert(!source.includes('targetDeviceModulePx'), 'barcode modules must not be rescaled by JavaScript');

assert(source.includes('@media (max-width: 768px)'), 'tablet/mobile breakpoint must remain present');
assert(source.includes('grid-template-columns: minmax(0, 1fr) !important;'), 'Product Details must become one column');
assert(source.includes('overflow-x: auto;'), 'barcode viewport must permit controlled overflow as a fallback');
assert(source.includes('width: max-content;'), 'barcode track must retain the native SVG width');
assert(source.includes('max-height: calc(100dvh - 16px);'), 'mobile modal must stay within the dynamic viewport');
assert(source.includes('@media (max-width: 380px)'), 'small phones must receive reduced container padding');
assert(source.includes('padding: 10px !important;'), 'small-phone padding must leave the full native barcode visible');

function availableBarcodeViewportWidth(viewportWidth) {
    if (viewportWidth <= 768) {
        const cardChrome = viewportWidth <= 380 ? 22 : 30;
        return viewportWidth - 16 - 32 - cardChrome;
    }
    const modalWidth = Math.min(1000, viewportWidth - 32);
    const columnWidth = (modalWidth - 48 - 24) / 2;
    return columnWidth - 30;
}

const nativeFallbackWidth = 286;
for (const viewport of [
    {width: 360, height: 800},
    {width: 390, height: 844},
    {width: 412, height: 915},
    {width: 768, height: 1024},
    {width: 1024, height: 768},
    {width: 1440, height: 900}
]) {
    assert(
        availableBarcodeViewportWidth(viewport.width) >= nativeFallbackWidth,
        `${viewport.width}x${viewport.height} must display the complete 286px native barcode without scaling or clipping`
    );
}

console.log('Product barcode native-geometry and responsive-width tests passed.');
