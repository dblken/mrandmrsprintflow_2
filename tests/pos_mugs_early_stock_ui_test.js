'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pos = fs.readFileSync(path.join(__dirname, '../staff/pos.php'), 'utf8');
const validation = fs.readFileSync(path.join(__dirname, '../includes/pos_service_stock_validation.php'), 'utf8');

assert.match(validation, /printflow_pos_mugs_release_material_rule\(/, 'backend early path uses the confirmed Mugs rule');
assert.match(validation, /InventoryManager::getItem\(37\)/, 'backend resolves Mug item 37 by ID');
assert.match(validation, /if \(\$serviceId === 63\) \{/, 'early capacity check is Mugs-scoped');

assert.match(pos, /Available \$\{mugMaterial\.name \|\| 'Mug'\}/, 'successful Mugs checks show available quantity');
assert.match(pos, /Number\(serviceId\) === 63 && !validation\.tracked/, 'Mugs never treats missing stock tracking as unlimited');
assert.match(pos, /input\.max = String\(Math\.max\(0, maxAllowed\)\)/, 'quantity max follows available Mug stock');
assert.match(pos, /Insufficient \$\{issue\.name \|\| 'Mug'\} stock/, 'over-limit quantity shows a clear shortage message');
assert.match(pos, /posBindServiceStockValidation\(serviceId\)/, 'opening the Mugs form binds live stock validation');
assert.match(pos, /scheduleValidation\(\)/, 'quantity typing and +/- revalidate Mug stock');
assert.match(
  pos,
  /Number\(serviceId\) !== 63\s*&&\s*!\(paperEarlyStock && paperDimsReady\)\s*&&\s*!plateTypeSelected\s*&&\s*!sintraThicknessSelected\) \{\s*posSetServiceStockState\(false, '', ''\);\s*return \{ valid: true, deferred: true \};/,
  'incomplete-form deferral skips other services but permits early checks for Mugs, valid paper dimensions, and selected Plate or Sintraboard types'
);

const silence = fs.readFileSync(path.join(__dirname, 'pos_mugs_success_silence_test.js'), 'utf8');
assert.match(
  silence,
  /Successful Mugs|Available|insufficient/i,
  'legacy silence contract remains present for coordinated updates'
);

console.log('POS Mugs early stock UI tests passed.');
