'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pos = fs.readFileSync(path.join(__dirname, '../staff/pos.php'), 'utf8');
const validation = fs.readFileSync(path.join(__dirname, '../includes/pos_service_stock_validation.php'), 'utf8');
const early = fs.readFileSync(path.join(__dirname, '../includes/pos_brochure_poster_early_stock.php'), 'utf8');
const cart = fs.readFileSync(path.join(__dirname, '../staff/api/pos_cart_handler.php'), 'utf8');

assert.match(validation, /printflow_pos_brochure_poster_early_demand/, 'backend early path uses Brochure/Poster demand helper');
assert.match(early, /67 => 211/, 'Brochure maps to inventory item 211');
assert.match(early, /64 => 212/, 'Poster Printing maps to inventory item 212');
assert.match(early, /printflow_pos_material_demand_for_items/, 'discrete sheet\/pcs papers use per-piece configured demand');
assert.match(early, /printflow_pos_brochure_poster_uom_is_discrete/, 'pcs\/sheet UOM drives per-piece capacity');

assert.match(pos, /Number\(serviceId\) === 67 \? 211/, 'POS UI prefers Brochure Special Paper by ID');
assert.match(pos, /Number\(serviceId\) === 64 \? 212/, 'POS UI prefers Poster C2s Board by ID');
assert.match(pos, /paperEarlyStock/, 'Brochure\/Poster skip unrelated incomplete-field deferral once dims are ready');
assert.match(pos, /paperDimsReady/, 'Dimensions gate Brochure\/Poster stock checks');
assert.match(pos, /\[67, 64\]\.includes\(Number\(serviceId\)\)/, 'Brochure\/Poster mapping and stock failures stay visible');
assert.match(pos, /preferred \.\+ material\|inventory item #\|material stock/, 'Brochure\/Poster config failures are allowlisted in stock feedback');
assert.match(pos, /Required \$\{materialName\}/, 'modal shows required material usage');
assert.match(pos, /Available: \$\{formatQty\(safeAvailable\)\}/, 'modal shows available stock');
assert.match(pos, /Insufficient \$\{issue\.name \|\| materialName\} stock/, 'insufficient paper stock shows a clear shortage');
assert.match(pos, /posBindServiceStockValidation\(serviceId\)/, 'opening the service form binds live stock validation');
assert.match(pos, /scheduleValidation\(\)/, 'quantity, dimension, layout, and branch edits revalidate stock');
assert.match(pos, /customization\.service_id = serviceId/, 'stock payload includes service_id');
assert.match(cart, /empty\(\$customization\['service_id'\]\)/, 'server stamps service_id on validate_service_stock');

console.log('POS Brochure/Poster early stock UI tests passed.');
