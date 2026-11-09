const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const pos = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const cartHandler = fs.readFileSync(path.join(root, 'staff/api/pos_cart_handler.php'), 'utf8');
const quantityPhp = fs.readFileSync(path.join(root, 'includes/pos_quantity_validation.php'), 'utf8');
const fieldRenderer = fs.readFileSync(path.join(root, 'includes/service_field_renderer.php'), 'utf8');
const fieldsEndpoint = fs.readFileSync(path.join(root, 'staff/api/pos_service_fields.php'), 'utf8');

assert.match(pos, /function posReadServiceOrderQuantity\(/, 'POS has a strict service-order quantity parser');
const serviceQuantityParser = pos.slice(pos.indexOf('function posReadServiceOrderQuantity('), pos.indexOf('function applyServicePricingToCustomization'));
assert.doesNotMatch(serviceQuantityParser, /parsed\s*>\s*100/, 'service modal parser no longer imposes the obsolete 100-item limit');
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
const parserStart = pos.indexOf('const POS_MAX_SERVICE_ORDER_QUANTITY = 10000;');
const parserEnd = pos.indexOf('\n        function applyServicePricingToCustomization', parserStart);
assert.ok(parserStart >= 0 && parserEnd > parserStart, 'shared POS service quantity parser can be exercised');
const parserContext = { Number, String, Math };
vm.runInNewContext(pos.slice(parserStart, parserEnd) + '\nglobalThis.quantityParsers = { posReadPositiveWholeQuantity, posReadServiceOrderQuantity };', parserContext);
for (const value of ['150', '500', '999']) {
  assert.equal(parserContext.quantityParsers.posReadServiceOrderQuantity(value), Number(value), `modal accepts valid quantity ${value}`);
}
for (const value of ['', '0', '-1', '1.5', 'abc', '12abc', '10001', '10000000']) {
  assert.equal(parserContext.quantityParsers.posReadServiceOrderQuantity(value), null, `modal rejects invalid quantity ${JSON.stringify(value)}`);
}
assert.equal(parserContext.quantityParsers.posReadServiceOrderQuantity('10000'), 10000, 'modal accepts the 10,000 service cap');
assert.match(pos, /posValidateServiceStockNow\(serviceId, quantity \? quantity\.value : ''\)/, 'manual and button quantity edits retain service stock revalidation');
assert.match(pos, /input\.dataset\.posQuantityMax = String\(POS_MAX_SERVICE_ORDER_QUANTITY\)/, 'POS binds the 10,000 service cap into modal quantity inputs');
assert.match(pos, /Maximum quantity is 10,000\./, 'POS service modal reports the shared maximum-quantity message');
assert.match(fieldsEndpoint, /id="quantity-input"[^>]*onfocus="this\.select\(\)"/, 'Mugs and other endpoint-rendered service quantity selects its current value on focus');
assert.match(fieldsEndpoint, /pointer-events:auto;user-select:text;cursor:text;/, 'endpoint quantity input remains directly interactive');
assert.match(fieldRenderer, /aria-label="Quantity" onfocus="this\.select\(\)"/, 'shared service quantity renderer selects its current value on focus');
assert.match(fieldRenderer, /pointer-events: auto; user-select: text; cursor: text;/, 'shared quantity input remains interactive inside option styling');
assert.match(fieldRenderer, /function pfChangeQty\(btn, delta\)/, '+/- controls still update the same quantity input');
assert.match(fieldRenderer, /input\.dispatchEvent\(new Event\('input', \{ bubbles: true \}\)\)/, '+/- changes still trigger estimate and validation updates');

assert.match(cartHandler, /pos_cart_parse_positive_quantity\(/, 'POS cart handler parses quantity through the shared helper');
assert.match(cartHandler, /case 'validate_service_stock':[\s\S]*pos_cart_parse_positive_quantity\(\$data\['qty'\]/, 'stock validation rejects invalid qty server-side');
assert.match(cartHandler, /case 'add':[\s\S]*pos_cart_parse_positive_quantity\(/, 'add rejects invalid qty server-side');
assert.match(pos, /qty: priced\.quantity,/, 'the manually entered validated quantity is submitted unchanged');
assert.match(quantityPhp, /function printflow_pos_parse_cart_quantity/, 'shared cart quantity parser exists');
assert.match(quantityPhp, /function printflow_pos_parse_service_order_quantity/, 'server exposes the 10,000 service-order parser');
assert.match(quantityPhp, /PRINTFLOW_POS_MAX_SERVICE_ORDER_QUANTITY/, 'server service cap constant exists');
assert.match(cartHandler, /printflow_pos_parse_service_order_quantity/, 'cart handler validates service lines separately from products');

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
  foreach (['150', '500', '999'] as $valid) {
    if (printflow_pos_parse_cart_quantity($valid) !== (int)$valid) { fwrite(STDERR, "valid failed:$valid\\n"); exit(3); }
  }
  echo "server-ok\\n";
  `,
], { encoding: 'utf8' });

assert.equal(php.status, 0, `server quantity rejection script failed: ${php.stderr || php.stdout}`);
assert.match(php.stdout, /server-ok/, 'server rejects letters, zero, decimals, signs, and symbols');

console.log('POS service quantity validation tests passed.');
