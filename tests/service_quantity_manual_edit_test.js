const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const renderer = fs.readFileSync(path.join(__dirname, '../includes/service_field_renderer.php'), 'utf8');
const start = renderer.indexOf('function validateQuantity(input, commit = false) {');
const end = renderer.indexOf('\nfunction serviceFieldEventProxy', start);
assert.ok(start >= 0 && end > start, 'shared quantity functions are present');

let estimateCalls = 0;
const context = {
  window: { calculateEstimatedPrice() { estimateCalls += 1; } },
  document: { querySelector() { return null; } },
  Number,
  String,
  Math,
};
vm.runInNewContext(renderer.slice(start, end) + '\nglobalThis.quantityTest = { validateQuantity, pfChangeQty };', context);

function input(value) {
  return {
    value,
    validityMessage: '',
    setCustomValidity(message) { this.validityMessage = message; },
  };
}

for (const value of ['1', '5', '20', '50', '100']) {
  const field = input(value);
  assert.equal(context.quantityTest.validateQuantity(field), true, `manual value ${value} is accepted`);
  assert.equal(field.value, value, `manual value ${value} remains visible`);
  assert.equal(field.validityMessage, '', `manual value ${value} clears validation`);
}

const replaced = input('');
assert.equal(context.quantityTest.validateQuantity(replaced), false, 'blank edit is temporarily invalid');
assert.equal(replaced.value, '', 'typing handler does not force blank back to one');
replaced.value = '5';
assert.equal(context.quantityTest.validateQuantity(replaced), true, 'typing after clearing works');
assert.equal(replaced.value, '5', 'replacement value stays visible');

const invalid = input('12abc');
assert.equal(context.quantityTest.validateQuantity(invalid), false, 'mixed alphanumeric value is rejected');
assert.equal(invalid.value, '12abc', 'malformed input is not silently converted');
assert.ok(invalid.validityMessage, 'malformed input receives a validation message');

for (const [raw, committed] of [['', '1'], ['0', '1'], ['101', '100']]) {
  const field = input(raw);
  assert.equal(context.quantityTest.validateQuantity(field, true), true, `blur normalization accepts ${raw || 'blank'} by clamping to the allowed range`);
  assert.equal(field.value, committed, `${raw || 'blank'} normalizes to ${committed} on commit`);
}

const invalidOnBlur = input('5e1');
assert.equal(context.quantityTest.validateQuantity(invalidOnBlur, true), false, 'scientific notation remains invalid on blur');
assert.equal(invalidOnBlur.value, '5e1', 'invalid text is not coerced on blur');

const boundary = input('100');
const container = { querySelector() { return boundary; } };
const button = { closest() { return container; }, parentElement: container };
context.quantityTest.pfChangeQty(button, 1);
assert.equal(boundary.value, '100', 'plus button clamps at the configured maximum');
context.quantityTest.pfChangeQty(button, -1);
assert.equal(boundary.value, '99', 'minus button decrements and refreshes validation');
assert.ok(estimateCalls >= 7, 'valid edits and +/- controls recalculate the estimate');

assert.match(renderer, /input type="text" inputmode="numeric"[^\n]*pf-service-quantity-input/);
assert.doesNotMatch(renderer, /pf-service-quantity-input[^\n]*onkeydown=/, 'quantity field does not block select-all keyboard shortcuts');
assert.match(renderer, /addEventListener\('focusout'[\s\S]*validateQuantity\(e\.target, true\)/, 'blur commits and normalizes quantity');
assert.match(renderer, /window\.pfChangeQty\(this, -1\)/, 'minus control uses shared quantity handling');
assert.match(renderer, /window\.pfChangeQty\(this, 1\)/, 'plus control uses shared quantity handling');

console.log('Shared service quantity manual-edit tests passed.');
