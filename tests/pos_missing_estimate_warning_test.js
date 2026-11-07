'use strict';

/**
 * POS missing-estimate warning must follow the final computed line total,
 * not unit price alone. Fee-only totals (Without Layout ₱200) are valid.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.join(__dirname, '..');
const estimatorSource = fs.readFileSync(
  path.join(root, 'public/assets/js/service_estimated_price.js'),
  'utf8'
);
const posSource = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');

const sandbox = {
  window: {},
  console,
  AbortController,
  Number,
  Math,
  parseFloat,
  isNaN,
  Infinity,
  NaN,
};
vm.runInNewContext(estimatorSource, sandbox);
const needsConfirm = sandbox.window.printflowNeedsMissingEstimatedPriceConfirm;
assert.equal(typeof needsConfirm, 'function', 'warning helper is exported');

assert.equal(needsConfirm(0), true, '₱0 estimate shows the warning');
assert.equal(needsConfirm(0.0), true, 'numeric 0 shows the warning');
assert.equal(needsConfirm('0'), true, 'string "0" shows the warning');
assert.equal(needsConfirm('0.00'), true, 'string "0.00" shows the warning');
assert.equal(needsConfirm(200), false, '₱200 estimate does not show the warning');
assert.equal(needsConfirm(200.0), false, 'numeric 200 does not show the warning');
assert.equal(needsConfirm('200.00'), false, 'string "200.00" does not show the warning');
assert.equal(needsConfirm(null), true, 'null estimate is unavailable and shows the warning');
assert.equal(needsConfirm(undefined), true, 'undefined estimate shows the warning');
assert.equal(needsConfirm(NaN), true, 'NaN is not a valid positive estimate');
assert.equal(needsConfirm(''), true, 'empty estimate shows the warning');
assert.equal(needsConfirm('abc'), true, 'non-numeric estimate shows the warning');

// Live estimator: fee-only Without Layout yields ₱200 and must not look "missing".
function makeScope({ qty, withoutLayoutPrice }) {
  const qtyInput = {
    classList: { contains: (c) => c === 'pf-service-quantity-input' },
    value: String(qty),
  };
  const withoutRadio = {
    matches: (sel) => sel === 'input[type="radio"]:checked' || sel === 'input[type="radio"]' || sel === 'select, input[type="radio"]',
    value: 'Without Layout',
    hasAttribute: (name) => name === 'data-design-fee-field' || name === 'data-price',
    getAttribute: (name) => {
      if (name === 'data-price') return String(withoutLayoutPrice);
      if (name === 'data-field-key') return 'layout';
      return null;
    },
    closest: () => ({
      querySelector: () => ({ textContent: 'Layout' }),
    }),
  };
  return {
    querySelector: (sel) => {
      if (sel === '.pf-service-quantity-input') return qtyInput;
      return null;
    },
    querySelectorAll: (sel) => {
      if (sel === 'select, input[type="radio"]') return [withoutRadio];
      if (sel === 'input[type="radio"].pricing-field:checked') return [];
      if (sel === 'select.pricing-field') return [];
      if (sel === '.nested-fields-container') return [];
      return [];
    },
    addEventListener: () => {},
  };
}

const totalOut = { textContent: '' };
const qtyOut = { textContent: '' };
const scope = makeScope({ qty: 1, withoutLayoutPrice: 200 });
const controller = sandbox.window.printflowInitServiceEstimatedPrice(scope, {
  basePrice: 0,
  form: scope,
  estimatedTotalEl: totalOut,
  qtyDisplayEl: qtyOut,
});
const feeOnly = controller.recalculate();
assert.equal(feeOnly.unitPrice, 0, 'fee-only line keeps unit at 0');
assert.equal(feeOnly.designFee, 200, 'Without Layout fee is 200');
assert.equal(feeOnly.estimatedTotal, 200, 'displayed estimate is 200');
assert.equal(needsConfirm(feeOnly.estimatedTotal), false, '₱200 fee-only estimate does not warn');

const zeroScope = makeScope({ qty: 3, withoutLayoutPrice: 0 });
// With Layout path: design fee helper returns 0 for With Layout; simulate no fee selected by empty radios.
zeroScope.querySelectorAll = (sel) => {
  if (sel === 'select, input[type="radio"]') return [];
  if (sel === 'input[type="radio"].pricing-field:checked') return [];
  if (sel === 'select.pricing-field') return [];
  if (sel === '.nested-fields-container') return [];
  return [];
};
const zeroTotal = { textContent: '' };
const zeroController = sandbox.window.printflowInitServiceEstimatedPrice(zeroScope, {
  basePrice: 0,
  form: zeroScope,
  estimatedTotalEl: zeroTotal,
});
const zeroResult = zeroController.recalculate();
assert.equal(zeroResult.estimatedTotal, 0, 'no unit and no fee yields ₱0');
assert.equal(needsConfirm(zeroResult.estimatedTotal), true, '₱0 estimate shows the warning');

// Changing qty with a fee updates the estimate and keeps the warning off.
const qtyScope = makeScope({ qty: 4, withoutLayoutPrice: 200 });
const qtyTotal = { textContent: '' };
const qtyController = sandbox.window.printflowInitServiceEstimatedPrice(qtyScope, {
  basePrice: 0,
  form: qtyScope,
  estimatedTotalEl: qtyTotal,
});
const qtyResult = qtyController.recalculate();
assert.equal(qtyResult.estimatedTotal, 200, 'qty change keeps fee once (0×4+200)');
assert.equal(needsConfirm(qtyResult.estimatedTotal), false, 'qty change with fee > 0 does not warn');

assert.match(posSource, /posNeedsMissingEstimatedPriceConfirm\(lineEstimate\)/, 'POS Add to Order uses final line estimate');
assert.doesNotMatch(posSource, /priced\.unitPrice <= 0\) \{\s*const proceed = await confirmMissingEstimatedPrice/, 'POS no longer warns from unit price alone');
assert.doesNotMatch(posSource, /Mugs Price Required/, 'POS no longer hard-blocks Mugs fee-only estimates');

console.log('POS missing-estimate warning regression tests passed.');
