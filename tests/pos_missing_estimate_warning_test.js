'use strict';

/**
 * POS missing-estimate warning must follow the final computed line total,
 * not unit price alone. Fee-only totals (Without Layout ₱200) are valid.
 *
 * Covers the Add to Order confirmation path used by confirmServiceModal():
 * recalculate → applyServicePricing → lineEstimate → needsConfirm.
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

function makeScope({ qty, withoutLayoutPrice, layoutValue }) {
  const qtyInput = {
    classList: { contains: (c) => c === 'pf-service-quantity-input' },
    value: String(qty),
  };
  const value = layoutValue == null ? 'Without Layout' : layoutValue;
  const withoutRadio = {
    matches: (sel) =>
      sel === 'input[type="radio"]:checked'
      || sel === 'input[type="radio"]'
      || sel === 'select, input[type="radio"]',
    value,
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

/**
 * Mirror staff/pos.php confirmServiceModal pricing → warning decision:
 * resolveServiceModalPricing (recalculate) → applyServicePricingToCustomization
 * → Number(calculated_estimated_price) → posNeedsMissingEstimatedPriceConfirm.
 */
function simulateConfirmServiceModalWarning({ basePrice, qty, withoutLayoutPrice, layoutValue }) {
  const scope = makeScope({ qty, withoutLayoutPrice, layoutValue });
  const totalOut = { textContent: '' };
  const qtyOut = { textContent: '' };
  const controller = sandbox.window.printflowInitServiceEstimatedPrice(scope, {
    basePrice,
    form: scope,
    estimatedTotalEl: totalOut,
    qtyDisplayEl: qtyOut,
  });
  const pricing = controller.recalculate();

  // applyServicePricingToCustomization (pos.php)
  const safeUnit = Math.round(Number(pricing.unitPrice) * 100) / 100;
  const safeQty = pricing.quantity;
  const safeDesignFee = Math.max(0, Math.round((Number(pricing.designFee) || 0) * 100) / 100);
  const customization = {
    calculated_unit_price: safeUnit.toFixed(2),
    calculated_design_fee: safeDesignFee.toFixed(2),
    design_fee: safeDesignFee.toFixed(2),
    calculated_estimated_price: (Math.round((safeUnit * safeQty + safeDesignFee) * 100) / 100).toFixed(2),
    quantity: String(safeQty),
  };

  // Mirror staff/pos.php posResolveFinalLineEstimate (display recalculate first).
  function posResolveFinalLineEstimate(pricingObj, customizationObj) {
    const candidates = [
      pricingObj && pricingObj.estimatedTotal,
      customizationObj && customizationObj.calculated_estimated_price,
    ];
    for (let i = 0; i < candidates.length; i++) {
      const value = candidates[i];
      if (value == null || value === '') continue;
      const amount = typeof value === 'number' ? value : Number(value);
      if (Number.isFinite(amount)) return amount;
    }
    return NaN;
  }

  const lineEstimate = posResolveFinalLineEstimate(pricing, customization);
  const warn = needsConfirm(lineEstimate);

  return {
    displayedText: totalOut.textContent,
    displayedQty: qtyOut.textContent,
    unitPrice: pricing.unitPrice,
    designFee: pricing.designFee,
    estimatedTotal: pricing.estimatedTotal,
    calculatedEstimatedPrice: customization.calculated_estimated_price,
    lineEstimate,
    warn,
  };
}

// Exact live case: Mugs qty 1, Without Layout ₱200, unconfigured unit price.
const mugsFeeOnly = simulateConfirmServiceModalWarning({
  basePrice: 0,
  qty: 1,
  withoutLayoutPrice: 200,
});
assert.equal(mugsFeeOnly.unitPrice, 0, 'Mugs fee-only keeps unit at 0');
assert.equal(mugsFeeOnly.designFee, 200, 'Without Layout fee is 200');
assert.equal(mugsFeeOnly.estimatedTotal, 200, 'recalculate line total is 200');
assert.equal(mugsFeeOnly.calculatedEstimatedPrice, '200.00', 'Add to Order stores calculated_estimated_price 200.00');
assert.equal(mugsFeeOnly.lineEstimate, 200, 'confirmServiceModal lineEstimate is 200');
assert.match(mugsFeeOnly.displayedText, /200/, 'form displays ₱200');
assert.equal(mugsFeeOnly.displayedQty, '1', 'form qty display is 1');
assert.equal(mugsFeeOnly.warn, false, 'Add to Order must NOT warn for ₱200 fee-only estimate');
// Prove the old origin/main gate would still wrongly warn.
assert.equal(mugsFeeOnly.unitPrice <= 0, true, 'old unitPrice<=0 gate would still be true for fee-only');
assert.notEqual(mugsFeeOnly.warn, mugsFeeOnly.unitPrice <= 0, 'warning must not follow unitPrice alone');

// True ₱0 estimate still warns.
const zeroEstimate = simulateConfirmServiceModalWarning({
  basePrice: 0,
  qty: 1,
  withoutLayoutPrice: 0,
  layoutValue: 'With Layout',
});
assert.equal(zeroEstimate.estimatedTotal, 0, 'With Layout and no unit yields ₱0');
assert.equal(zeroEstimate.lineEstimate, 0, 'Add to Order lineEstimate is 0');
assert.equal(zeroEstimate.warn, true, '₱0 estimate still shows the warning');

// Missing / invalid estimates remain warned (and must not be coerced via Number(null)===0 alone).
assert.equal(needsConfirm(null), true, 'missing estimate stays warned');
assert.equal(needsConfirm(NaN), true, 'NaN estimate stays warned');
assert.ok(Number.isNaN((() => {
  const candidates = [null, undefined, ''];
  for (const value of candidates) {
    if (value == null || value === '') continue;
    const amount = Number(value);
    if (Number.isFinite(amount)) return amount;
  }
  return NaN;
})()), 'posResolveFinalLineEstimate returns NaN for missing candidates, not 0');

// Qty / layout changes update estimate and warning together.
const qtyChanged = simulateConfirmServiceModalWarning({
  basePrice: 0,
  qty: 4,
  withoutLayoutPrice: 200,
});
assert.equal(qtyChanged.estimatedTotal, 200, 'qty 4 keeps one-time fee (0×4+200)');
assert.equal(qtyChanged.lineEstimate, 200, 'qty change keeps lineEstimate 200');
assert.equal(qtyChanged.warn, false, 'qty change with fee > 0 does not warn');
assert.equal(qtyChanged.displayedQty, '4', 'qty display updates with options');

const layoutCleared = simulateConfirmServiceModalWarning({
  basePrice: 0,
  qty: 4,
  withoutLayoutPrice: 0,
  layoutValue: 'With Layout',
});
assert.equal(layoutCleared.estimatedTotal, 0, 'switching away from Without Layout drops fee');
assert.equal(layoutCleared.warn, true, 'layout change to ₱0 turns the warning on');

const unitPlusFee = simulateConfirmServiceModalWarning({
  basePrice: 50,
  qty: 2,
  withoutLayoutPrice: 200,
});
assert.equal(unitPlusFee.estimatedTotal, 300, 'configured unit×qty + fee = 300');
assert.equal(unitPlusFee.warn, false, 'positive combined estimate does not warn');

// Static: Add to Order button wires confirmServiceModal, which must check lineEstimate.
assert.match(
  posSource,
  /id="sm-add-to-order-btn"[^>]*onclick="confirmServiceModal\(\)"/,
  'Add to Order button calls confirmServiceModal()'
);
assert.match(posSource, /window\.confirmServiceModal\s*=\s*confirmServiceModal/, 'confirmServiceModal is exported');

function extractFunctionBody(source, functionName) {
  const startRe = new RegExp(`async function ${functionName}\\(\\)\\s*\\{`);
  const startMatch = startRe.exec(source);
  assert.ok(startMatch, `function ${functionName} exists`);
  let i = startMatch.index + startMatch[0].length;
  let depth = 1;
  while (i < source.length && depth > 0) {
    const ch = source[i++];
    if (ch === '{') depth += 1;
    else if (ch === '}') depth -= 1;
  }
  return source.slice(startMatch.index, i);
}

assert.match(
  posSource,
  /function posResolveFinalLineEstimate\(pricing, customization\)/,
  'POS defines shared final-line-estimate resolver for Add to Order'
);
assert.match(
  posSource,
  /pricing && pricing\.estimatedTotal/,
  'resolver prefers recalculated estimatedTotal (same value that painted the display)'
);

for (const fnName of ['confirmServiceModal', 'confirmServiceModalLegacy']) {
  const body = extractFunctionBody(posSource, fnName);
  assert.match(
    body,
    /posResolveFinalLineEstimate\(pricing, customization\)/,
    `${fnName} resolves lineEstimate via posResolveFinalLineEstimate`
  );
  assert.match(
    body,
    /posNeedsMissingEstimatedPriceConfirm\(lineEstimate\)/,
    `${fnName} warns from final lineEstimate`
  );
  assert.match(
    body,
    /resolveServiceModalPricing\(\)/,
    `${fnName} refreshes pricing via resolveServiceModalPricing before warning`
  );
  assert.doesNotMatch(
    body,
    /priced\.unitPrice\s*<=\s*0/,
    `${fnName} must not gate the warning on unitPrice alone`
  );
}

assert.match(posSource, /posNeedsMissingEstimatedPriceConfirm\(lineEstimate\)/, 'POS Add to Order uses final line estimate');
assert.doesNotMatch(
  posSource,
  /priced\.unitPrice\s*<=\s*0\s*\)\s*\{\s*(?:[\s\S]*?)confirmMissingEstimatedPrice/,
  'POS no longer warns from unit price alone before confirmMissingEstimatedPrice'
);
assert.doesNotMatch(posSource, /Mugs Price Required/, 'POS no longer hard-blocks Mugs fee-only estimates');
assert.match(
  posSource,
  /service_estimated_price\.js'\);\s*\?>\?v=<\?php echo \(int\) \$pos_estimated_price_ver; \?>/,
  'POS script tag appends ?v=filemtime outside the hashed path'
);
assert.match(
  posSource,
  /filemtime\(__DIR__\s*\.\s*'\/\.\.\/public\/assets\/js\/service_estimated_price\.js'\)/,
  'POS estimator version comes from deployed file mtime'
);

console.log('POS missing-estimate warning regression tests passed.');
