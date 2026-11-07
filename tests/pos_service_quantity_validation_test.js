const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.join(__dirname, '..');
const pos = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const cartHandler = fs.readFileSync(path.join(root, 'staff/api/pos_cart_handler.php'), 'utf8');
const quantityPhp = fs.readFileSync(path.join(root, 'includes/pos_quantity_validation.php'), 'utf8');

assert.match(pos, /function posReadServiceOrderQuantity\(/, 'POS has a strict service-order quantity parser');
assert.match(pos, /posReadServiceOrderQuantity\(input\.value\)/, 'form validation uses the strict parser instead of parseInt');
assert.match(pos, /Enter a positive whole number\./, 'invalid quantity surfaces a clear message');
assert.match(pos, /earlyQty === null/, 'Add to Order blocks before submission when quantity is invalid');
assert.match(pos, /posSetServiceStockState\(true, '', 'Enter a positive whole number\.'\)/, 'invalid quantity keeps Add blocked via stock state');
assert.match(
  pos,
  /if \(input\.name === 'quantity'[\s\S]*?posReadServiceOrderQuantity\(input\.value\)/,
  'quantity validation uses the strict whole-number parser'
);
assert.doesNotMatch(
  pos,
  /if \(input\.name === 'quantity'[\s\S]*?parseInt\(value,\s*10\)/,
  'quantity validation must not use parseInt which accepts 12abc as 12'
);
assert.match(pos, /throw new Error\('Enter a positive whole number\.'\)/, 'pricing helper refuses to invent a quantity');

assert.match(cartHandler, /pos_cart_parse_positive_quantity\(/, 'POS cart handler parses quantity through the shared helper');
assert.match(cartHandler, /case 'validate_service_stock':[\s\S]*pos_cart_parse_positive_quantity\(\$data\['qty'\]/, 'stock validation rejects invalid qty server-side');
assert.match(cartHandler, /case 'add':[\s\S]*pos_cart_parse_positive_quantity\(/, 'add rejects invalid qty server-side');
assert.match(quantityPhp, /function printflow_pos_parse_cart_quantity/, 'shared cart quantity parser exists');

const php = spawnSync(process.env.PHP_BINARY || 'php', [
  '-r',
  `
  require '${path.join(root, 'includes/pos_quantity_validation.php').replace(/\\/g, '/')}';
  foreach (['h5tgj', '12abc', '', '0', '0.5', '-1', '+1', '1.5', '1e3', 'abc', '12!'] as $bad) {
    try {
      printflow_pos_parse_cart_quantity($bad);
      fwrite(STDERR, "accepted:$bad\\n");
      exit(2);
    } catch (InvalidArgumentException $e) {}
  }
  if (printflow_pos_parse_cart_quantity('4') !== 4) { fwrite(STDERR, "valid failed\\n"); exit(3); }
  echo "server-ok\\n";
  `,
], { encoding: 'utf8' });

assert.equal(php.status, 0, `server quantity rejection script failed: ${php.stderr || php.stdout}`);
assert.match(php.stdout, /server-ok/, 'server rejects letters, zero, decimals, signs, and symbols');

console.log('POS service quantity validation tests passed.');
