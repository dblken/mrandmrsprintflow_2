const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '..', 'staff', 'pos.php'), 'utf8');
assert.match(source, /Available \$\{mugMaterial\.name \|\| 'Mug'\}: \$\{formatQty\(safeAvailable\)\} \$\{mugMaterial\.uom \|\| 'pcs'\}/,
    'Successful Mugs stock checks show available Mug quantity without inventing materials for other services.');
assert.match(source, /if \(!validation\.ok \|\| issues\.length \|\| qty > maxAllowed\)/,
    'Mugs insufficiency still uses the existing warning path and quantity-cap check.');
assert.match(source, /posSetServiceStockState\(true, availabilityText, posServiceStockDisplayMessage\(message, serviceId\) \|\| message\)/,
    'Insufficient Mugs stock remains visible as a warning while available stock stays shown.');
assert.match(source, /Number\(serviceId\) === 40/,
    'Tarpaulin keeps its dedicated early stock path separate from Mugs.');

console.log('POS Mugs successful stock checks show available quantity.');
