const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/service_estimated_price.js'), 'utf8');
const window = {};
vm.runInNewContext(source, { window, AbortController, Number, Math, String, parseFloat, parseInt });

function makeElement(attrs = {}, value = '', checked = false) {
  return {
    value,
    checked,
    textContent: '',
    hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? String(attrs[name]) : null; },
    matches(selector) {
      if (selector === 'select') return attrs.tag === 'select';
      if (selector === 'input[type="radio"]:checked') return attrs.tag !== 'select' && checked;
      return false;
    },
    closest() { return null; },
  };
}

function runEstimate({ quantity, layout, fee, configured = true, marker = false }) {
  const attrs = { name: 'layout', tag: 'radio', 'data-price': fee };
  if (marker) attrs['data-design-fee-field'] = '1';
  if (configured) attrs['data-design-fee-configured'] = '1';
  const layoutChoice = makeElement(attrs, layout, true);
  const qty = makeElement({ tag: 'input' }, String(quantity));
  const unitOut = makeElement();
  const totalOut = makeElement();
  const estimatedInput = makeElement();
  const qtyDisplay = makeElement();
  const feeOut = makeElement();
  const scope = {
    addEventListener() {},
    querySelectorAll(selector) {
      if (selector === 'input[type="radio"].pricing-field:checked') return [layoutChoice];
      if (selector === 'select.pricing-field') return [];
      if (selector === 'button.shopee-opt-btn.pricing-field.active[data-price]') return [];
      if (selector === '.nested-fields-container') return [];
      if (selector === 'select, input[type="radio"]') return [layoutChoice];
      return [];
    },
    querySelector(selector) {
      if (selector === '.pf-service-quantity-input') return qty;
      return null;
    },
  };
  const result = window.printflowInitServiceEstimatedPrice(scope, {
    form: scope,
    basePrice: 120,
    unitPriceInputEl: unitOut,
    estimatedTotalEl: totalOut,
    estimatedPriceInputEl: estimatedInput,
    qtyDisplayEl: qtyDisplay,
    designFeeInputEl: feeOut,
  }).recalculate();
  return { result, unitOut, totalOut, estimatedInput, qtyDisplay, feeOut, qty };
}

for (const quantity of [1, 3, 10]) {
  for (const fee of [0, 200, 350]) {
    const { result } = runEstimate({ quantity, layout: 'Without Layout', fee, marker: false });
    assert.equal(result.unitPrice, 120, `layout price is excluded from unit price at qty ${quantity}, fee ${fee}`);
    assert.equal(result.designFee, fee, `configured fee retained once at qty ${quantity}, fee ${fee}`);
    assert.equal(result.estimatedTotal, 120 * quantity + fee, `line estimate at qty ${quantity}, fee ${fee}`);
  }
  const withLayout = runEstimate({ quantity, layout: 'With Layout', fee: 200, marker: false });
  assert.equal(withLayout.result.designFee, 0, `With Layout has no fee at qty ${quantity}`);
  assert.equal(withLayout.result.estimatedTotal, 120 * quantity, `With Layout estimate excludes fee at qty ${quantity}`);
}

const missingPrice = runEstimate({ quantity: 3, layout: 'Without Layout', fee: 0, configured: false, marker: false });
assert.equal(missingPrice.result.designFee, 0, 'missing configured fee never invents a default');
assert.equal(missingPrice.result.estimatedTotal, 360, 'missing fee estimates base times quantity only');

const liveRegression = runEstimate({ quantity: 3, layout: 'Without Layout', fee: 200, marker: false });
assert.equal(liveRegression.result.estimatedTotal, 560, 'Tarpaulin base 120 x qty 3 plus one 200 fee equals 560');
assert.equal(liveRegression.totalOut.textContent, '₱560.00', 'POS/customer estimate displays the corrected live regression total');

liveRegression.qty.value = '';
const clearedEstimate = window.calculateEstimatedPrice();
assert.equal(liveRegression.qty.value, '', 'clearing quantity remains an editable empty state');
assert.equal(clearedEstimate.quantity, null, 'empty quantity is not silently priced as one');
assert.equal(liveRegression.totalOut.textContent, '—', 'estimate indicates a pending quantity while the field is blank');
assert.equal(liveRegression.estimatedInput.value, '', 'invalid quantity clears the submitted estimated total');
liveRegression.qty.value = '5';
const replacedEstimate = window.calculateEstimatedPrice();
assert.equal(replacedEstimate.quantity, 5, 'typing a replacement quantity recalculates the estimate');
assert.equal(replacedEstimate.estimatedTotal, 800, 'replacement quantity retains the one-time design fee');

for (const quantity of [1, 5, 20, 100]) {
  const manuallyEntered = runEstimate({ quantity, layout: 'Without Layout', fee: 200, marker: false });
  assert.equal(manuallyEntered.result.quantity, quantity, `manual quantity ${quantity} reaches the estimator`);
  assert.equal(manuallyEntered.result.estimatedTotal, 120 * quantity + 200, `manual quantity ${quantity} keeps the design fee one-time`);
}

console.log('Service estimated price design-fee quantity regression tests passed.');
