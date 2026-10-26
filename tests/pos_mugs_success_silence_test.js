const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'staff', 'pos.php'), 'utf8');
assert.match(source, /const indicator = \[40, 63\]\.includes\(Number\(serviceId\)\) \? '' : materials\.map\(/,
    'Successful Mugs and Tarpaulin stock checks stay silent without changing other services\' display behavior.');
assert.match(source, /if \(!validation\.ok \|\| issues\.length\)/,
    'Mugs insufficiency still uses the existing warning path.');
assert.match(source, /posSetServiceStockState\(true, indicator, message\)/,
    'Insufficient Mugs stock remains visible as a warning.');

console.log('POS Mugs successful stock checks remain silent.');
