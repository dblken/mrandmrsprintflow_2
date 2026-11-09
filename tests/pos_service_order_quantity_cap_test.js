const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const pos = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const renderer = fs.readFileSync(path.join(root, 'includes/service_field_renderer.php'), 'utf8');
const fieldsApi = fs.readFileSync(path.join(root, 'staff/api/pos_service_fields.php'), 'utf8');
const cartHandler = fs.readFileSync(path.join(root, 'staff/api/pos_cart_handler.php'), 'utf8');
const customizations = fs.readFileSync(path.join(root, 'staff/customizations.php'), 'utf8');

assert.match(pos, /POS_MAX_SERVICE_ORDER_QUANTITY = 10000/, 'POS service modal uses the 10,000 cap');
assert.match(pos, /Maximum quantity is 10,000\./, 'POS surfaces the shared maximum-quantity message');
assert.match(pos, /dataset\.posQuantityMax = String\(POS_MAX_SERVICE_ORDER_QUANTITY\)/, 'POS binds the cap into quantity inputs');
assert.match(renderer, /data-pos-quantity-max="10000"/, 'Shared renderer stamps the 10,000 cap');
assert.match(renderer, /maxlength="5"/, 'Renderer allows up to five digits (10,000)');
assert.match(fieldsApi, /data-pos-quantity-max="10000"/, 'Endpoint-rendered Mugs quantity uses the cap');
assert.match(cartHandler, /printflow_pos_parse_service_order_quantity/, 'Cart API validates service quantity separately from products');
assert.match(customizations, /pendingMaterialUsageErrorMessage\(pm\)/, 'Production Assignment uses one field-level usage message');

const parserStart = pos.indexOf('const POS_MAX_SERVICE_ORDER_QUANTITY = 10000;');
const parserEnd = pos.indexOf('function applyServicePricingToCustomization', parserStart);
assert.ok(parserStart >= 0 && parserEnd > parserStart, 'POS service quantity helpers are extractable');
const parserContext = { Number, String, Math };
vm.runInNewContext(pos.slice(parserStart, parserEnd) + '\nglobalThis.qty = { posReadServiceOrderQuantity, posServiceOrderQuantityInvalidMessage };', parserContext);
const { posReadServiceOrderQuantity, posServiceOrderQuantityInvalidMessage } = parserContext.qty;

assert.equal(posReadServiceOrderQuantity('10000'), 10000, 'Service modal accepts 10,000');
assert.equal(posReadServiceOrderQuantity('10001'), null, 'Service modal rejects 10,001');
assert.equal(posReadServiceOrderQuantity('10000000'), null, 'Service modal rejects pasted huge values');
assert.equal(posServiceOrderQuantityInvalidMessage('10001'), 'Maximum quantity is 10,000.', 'Over-limit shows the maximum message');
assert.equal(posServiceOrderQuantityInvalidMessage('abc'), 'Enter a positive whole number.', 'Non-numeric still uses the whole-number message');

const rendererStart = renderer.indexOf('function pfServiceQuantityMax(input) {');
const rendererEnd = renderer.indexOf('function validateQuantity(input', rendererStart);
assert.ok(rendererStart >= 0 && rendererEnd > rendererStart, 'Renderer quantity helpers are extractable');
const rendererFns = new Function(`${renderer.slice(rendererStart, rendererEnd)}\nreturn { pfServiceQuantityMax, pfServiceQuantityInvalidMessage };`)();
const qtyInput = { dataset: { posQuantityMax: '10000' }, getAttribute: () => '10000' };
assert.equal(rendererFns.pfServiceQuantityMax(qtyInput), 10000, 'Spinner max honors data-pos-quantity-max');
assert.equal(rendererFns.pfServiceQuantityInvalidMessage('10001', qtyInput), 'Maximum quantity is 10,000.', 'Renderer reports maximum message');

const php = spawnSync(process.env.PHP_BINARY || 'php', [
  '-r',
  `
  require '${path.join(root, 'includes/pos_quantity_validation.php').replace(/\\/g, '/')}';
  foreach (['10000', '1', '999'] as $ok) {
    if (printflow_pos_parse_service_order_quantity($ok) !== (int)$ok) { fwrite(STDERR, "bad:$ok\\n"); exit(2); }
  }
  foreach (['10001', '10000000', 'abc', '1.5'] as $bad) {
    try {
      printflow_pos_parse_service_order_quantity($bad);
      fwrite(STDERR, "accepted:$bad\\n");
      exit(3);
    } catch (InvalidArgumentException $e) {}
  }
  if (printflow_pos_parse_cart_quantity('1000000') !== 1000000) { fwrite(STDERR, "product-cap\\n"); exit(4); }
  echo "server-cap-ok\\n";
  `,
], { encoding: 'utf8' });
assert.equal(php.status, 0, php.stderr || php.stdout);
assert.match(php.stdout, /server-cap-ok/, 'Server keeps product cart cap at 1,000,000 but service cap at 10,000');

console.log('PASS: POS and Production Assignment 10,000 quantity cap');
