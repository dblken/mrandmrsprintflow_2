'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pos = fs.readFileSync(path.join(__dirname, '../staff/pos.php'), 'utf8');
const validation = fs.readFileSync(path.join(__dirname, '../includes/pos_service_stock_validation.php'), 'utf8');
const early = fs.readFileSync(path.join(__dirname, '../includes/pos_brochure_poster_early_stock.php'), 'utf8');

assert.match(validation, /printflow_pos_brochure_poster_early_demand/, 'backend early path uses Brochure/Poster demand helper');
assert.match(validation, /printflow_pos_brochure_poster_is_early_stock_service/, 'Brochure/Poster early path is service-scoped');
assert.match(early, /67 => 211/, 'Brochure maps to inventory item 211');
assert.match(early, /64 => 212/, 'Poster Printing maps to inventory item 212');
assert.match(early, /printflow_pos_material_demand_for_area/, 'configured area consumption remains the demand engine');

assert.match(pos, /Number\(serviceId\) === 67 \? 211/, 'POS UI prefers Brochure Special Paper by ID');
assert.match(pos, /Number\(serviceId\) === 64 \? 212/, 'POS UI prefers Poster C2s Board by ID');
assert.match(pos, /Required \$\{materialName\}/, 'successful Brochure/Poster checks show required quantity');
assert.match(pos, /Available: \$\{formatQty\(safeAvailable\)\}/, 'successful Brochure/Poster checks show available stock');
assert.match(pos, /Insufficient \$\{issue\.name \|\| materialName\} stock/, 'insufficient paper stock shows a clear shortage message');
assert.match(pos, /preferredPaperId > 0 && !validation\.tracked/, 'missing Brochure/Poster tracking is not treated as unlimited');
assert.match(pos, /posBindServiceStockValidation\(serviceId\)/, 'opening the service form binds live stock validation');
assert.match(pos, /scheduleValidation\(\)/, 'quantity and dimension edits revalidate stock');
assert.match(
  pos,
  /Number\(serviceId\) !== 63\) \{\s*posSetServiceStockState\(false, '', ''\);\s*return \{ valid: true, deferred: true \};/,
  'incomplete Brochure/Poster forms still defer until Dimensions and Qty are valid'
);

console.log('POS Brochure/Poster early stock UI tests passed.');
