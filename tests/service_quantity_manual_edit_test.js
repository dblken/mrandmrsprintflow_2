const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const renderer = fs.readFileSync(path.join(__dirname, '../includes/service_field_renderer.php'), 'utf8');
const start = renderer.indexOf('function pfServiceQuantityInput(root) {');
const end = renderer.indexOf('\nfunction serviceFieldEventProxy', start);
assert.ok(start >= 0 && end > start, 'shared quantity functions are present');

let estimateCalls = 0;
const prevented = [];
const context = {
  window: { calculateEstimatedPrice() { estimateCalls += 1; } },
  document: {
    querySelector() { return null; },
    addEventListener() {},
  },
  Event: class Event {
    constructor(type, init = {}) {
      this.type = type;
      this.bubbles = !!init.bubbles;
    }
  },
  Number,
  String,
  Math,
};
vm.runInNewContext(
  renderer.slice(start, end) + '\nglobalThis.quantityTest = { validateQuantity, pfChangeQty, pfRejectNonDigitServiceQuantity, pfServiceQuantityInvalidMessage };',
  context
);

function input(value, posQuantityMax = null) {
  return {
    value,
    dataset: posQuantityMax ? { posQuantityMax: String(posQuantityMax) } : {},
    validityMessage: '',
    classList: { contains() { return true; } },
    matches(selector) {
      return String(selector).includes('pf-service-quantity-input') || String(selector).includes('#quantity-input');
    },
    setCustomValidity(message) { this.validityMessage = message; },
    dispatchEvent() {},
  };
}

function keyEvent(key, extras = {}) {
  return {
    type: 'keydown',
    key,
    ctrlKey: !!extras.ctrlKey,
    metaKey: !!extras.metaKey,
    altKey: !!extras.altKey,
    target: extras.target || input('1'),
    prevented: false,
    preventDefault() { this.prevented = true; prevented.push(['keydown', key]); },
  };
}

function beforeInputEvent(data, target) {
  return {
    type: 'beforeinput',
    data,
    target,
    prevented: false,
    preventDefault() { this.prevented = true; prevented.push(['beforeinput', data]); },
  };
}

function pasteEvent(text, target) {
  return {
    type: 'paste',
    clipboardData: { getData() { return text; } },
    target,
    prevented: false,
    preventDefault() { this.prevented = true; prevented.push(['paste', text]); },
  };
}

for (const value of ['1', '5', '20', '50', '100', '150', '500', '999', '1000000']) {
  const field = input(value, 1000000);
  assert.equal(context.quantityTest.validateQuantity(field), true, `manual value ${value} is accepted`);
  assert.equal(field.value, value, `manual value ${value} remains visible`);
  assert.equal(field.validityMessage, '', `manual value ${value} clears validation`);
}

const replaced = input('');
assert.equal(context.quantityTest.validateQuantity(replaced), false, 'blank edit is temporarily invalid');
assert.equal(replaced.value, '', 'typing handler does not force blank back to one');
assert.match(replaced.validityMessage, /positive whole number/i, 'empty input shows a clear inline error');
replaced.value = '5';
assert.equal(context.quantityTest.validateQuantity(replaced), true, 'typing after clearing works');
assert.equal(replaced.value, '5', 'replacement value stays visible');

for (const value of ['0', '00', '-1', '+1', '1.5', '1e3', 'h5tgj', '12abc', '12!', '1000001', '']) {
  const field = input(value, 1000000);
  assert.equal(context.quantityTest.validateQuantity(field), false, `invalid value ${JSON.stringify(value)} is rejected`);
  assert.equal(field.value, value, `invalid value ${JSON.stringify(value)} is not silently converted`);
  assert.ok(field.validityMessage, `invalid value ${JSON.stringify(value)} receives a validation message`);
}

for (const [raw] of [[''], ['0'], ['1000001']]) {
  const field = input(raw, 1000000);
  assert.equal(context.quantityTest.validateQuantity(field, true), false, `blur does not coerce ${raw || 'blank'} into another quantity`);
  assert.equal(field.value, raw, `${raw || 'blank'} remains unchanged on commit`);
}

const invalidOnBlur = input('5e1', 1000000);
assert.equal(context.quantityTest.validateQuantity(invalidOnBlur, true), false, 'scientific notation remains invalid on blur');
assert.equal(invalidOnBlur.value, '5e1', 'invalid text is not coerced on blur');

const letterField = input('1');
const letterKey = keyEvent('h', { target: letterField });
context.quantityTest.pfRejectNonDigitServiceQuantity(letterKey);
assert.equal(letterKey.prevented, true, 'letter keystrokes are prevented while typing');
assert.match(letterField.validityMessage, /positive whole number/i, 'rejected letter shows an inline error');

const digitKey = keyEvent('7', { target: input('1') });
context.quantityTest.pfRejectNonDigitServiceQuantity(digitKey);
assert.equal(digitKey.prevented, false, 'digit keystrokes remain allowed');

const selectAll = keyEvent('a', { target: input('12'), ctrlKey: true });
context.quantityTest.pfRejectNonDigitServiceQuantity(selectAll);
assert.equal(selectAll.prevented, false, 'Ctrl/Cmd shortcuts remain available');

const beforeBad = beforeInputEvent('h5tgj', input('1'));
context.quantityTest.pfRejectNonDigitServiceQuantity(beforeBad);
assert.equal(beforeBad.prevented, true, 'beforeinput blocks non-digit insertions');

const beforeGood = beforeInputEvent('12', input(''));
context.quantityTest.pfRejectNonDigitServiceQuantity(beforeGood);
assert.equal(beforeGood.prevented, false, 'beforeinput allows digit insertions');

const pasteBad = pasteEvent('h5tgj', input('1'));
context.quantityTest.pfRejectNonDigitServiceQuantity(pasteBad);
assert.equal(pasteBad.prevented, true, 'paste rejects letters/symbols');

const pasteDecimal = pasteEvent('1.5', input(''));
context.quantityTest.pfRejectNonDigitServiceQuantity(pasteDecimal);
assert.equal(pasteDecimal.prevented, true, 'paste rejects decimals');

const pasteGood = pasteEvent('4', input(''));
context.quantityTest.pfRejectNonDigitServiceQuantity(pasteGood);
assert.equal(pasteGood.prevented, false, 'paste accepts a whole-number digit string');
const pasteLargeValid = pasteEvent('999', input('', 1000000));
context.quantityTest.pfRejectNonDigitServiceQuantity(pasteLargeValid);
assert.equal(pasteLargeValid.prevented, false, 'paste accepts valid three-digit service quantities');
const pasteTooLong = pasteEvent('10000000', input('', 1000000));
context.quantityTest.pfRejectNonDigitServiceQuantity(pasteTooLong);
assert.equal(pasteTooLong.prevented, true, 'paste rejects quantities beyond the supported seven-digit range');

const boundary = input('100', 1000000);
const container = { querySelector() { return boundary; } };
const button = { closest() { return container; }, parentElement: container };
context.quantityTest.pfChangeQty(button, 1);
assert.equal(boundary.value, '101', 'plus button increments beyond the old 100-item cap');
context.quantityTest.pfChangeQty(button, -1);
assert.equal(boundary.value, '100', 'minus button decrements and refreshes validation');
const largeBoundary = input('999999', 1000000);
const largeContainer = { querySelector() { return largeBoundary; } };
context.quantityTest.pfChangeQty({ closest() { return largeContainer; }, parentElement: largeContainer }, 1);
assert.equal(largeBoundary.value, '1000000', 'plus control accepts the server maximum');
context.quantityTest.pfChangeQty({ closest() { return largeContainer; }, parentElement: largeContainer }, 1);
assert.equal(largeBoundary.value, '1000000', 'plus control clamps at the server maximum');
const fiveHundred = input('500', 1000000);
const fiveHundredContainer = { querySelector() { return fiveHundred; } };
const fiveHundredButton = { closest() { return fiveHundredContainer; }, parentElement: fiveHundredContainer };
context.quantityTest.pfChangeQty(fiveHundredButton, 1);
assert.equal(fiveHundred.value, '501', 'plus control increments a manually entered 500 quantity');
context.quantityTest.pfChangeQty(fiveHundredButton, -1);
assert.equal(fiveHundred.value, '500', 'minus control returns the value to 500 and refreshes the estimate');
const nonPosBoundedField = input('101');
assert.equal(context.quantityTest.validateQuantity(nonPosBoundedField), false, 'shared renderer retains its 100-item default outside the POS override');
assert.ok(estimateCalls >= 7, 'valid edits and +/- controls recalculate the estimate');

assert.match(renderer, /input type="text" inputmode="numeric"[^\n]*pattern="\[0-9\]\*" maxlength="3"[^\n]*pf-service-quantity-input/);
assert.doesNotMatch(renderer, /pf-service-quantity-input[^\n]*onkeydown=/, 'quantity field does not block select-all via inline onkeydown');
assert.match(renderer, /addEventListener\('beforeinput',\s*pfRejectNonDigitServiceQuantity/, 'digit rejection is bound for dynamically rendered forms');
assert.match(renderer, /addEventListener\('paste',\s*pfRejectNonDigitServiceQuantity/, 'paste rejection is bound for service quantity inputs');
assert.match(renderer, /addEventListener\('focusout'[\s\S]*validateQuantity\(e\.target, true\)/, 'blur still validates quantity');
assert.match(renderer, /window\.pfChangeQty\(this, -1\)/, 'minus control uses shared quantity handling');
assert.match(renderer, /window\.pfChangeQty\(this, 1\)/, 'plus control uses shared quantity handling');
assert.doesNotMatch(
  renderer.slice(renderer.indexOf('function validateQuantity'), renderer.indexOf('function pfRejectNonDigitServiceQuantity')),
  /input\.value\s*=\s*'1'/,
  'validateQuantity no longer silently forces quantity 1'
);

const fallback = fs.readFileSync(path.join(__dirname, '../staff/api/pos_service_fields.php'), 'utf8');
assert.match(fallback, /pf-service-quantity-input/, 'unconfigured POS services use the shared quantity input class');
assert.match(fallback, /inputmode="numeric"/, 'unconfigured POS services use numeric inputmode');
assert.doesNotMatch(fallback, /type="number"[^\n]*id="quantity-input"/, 'fallback quantity is no longer a free-form number input');

console.log('Shared service quantity manual-edit tests passed.');
