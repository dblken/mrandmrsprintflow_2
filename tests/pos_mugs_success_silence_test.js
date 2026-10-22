const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const source = execFileSync('git', ['show', ':staff/pos.php'], { encoding: 'utf8' });
assert.match(source, /const indicator = Number\(serviceId\) === 63 \? '' : materials\.map\(/,
    'Successful Mugs stock checks stay silent without changing other services\' display behavior.');
assert.match(source, /if \(!validation\.ok \|\| issues\.length\)/,
    'Mugs insufficiency still uses the existing warning path.');
assert.match(source, /posSetServiceStockState\(true, indicator, message\)/,
    'Insufficient Mugs stock remains visible as a warning.');

console.log('POS Mugs successful stock checks remain silent.');
