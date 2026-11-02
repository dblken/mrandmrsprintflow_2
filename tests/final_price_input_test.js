'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../staff/customizations.php'), 'utf8');
assert.match(source, /Number\(currentJo\.estimated_total \|\| 0\)\.toLocaleString\('en-US', \{minimumFractionDigits: 2, maximumFractionDigits: 2\}\)/,
    'estimated total display is fixed to two decimal places');
const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.ok(scripts.length >= 2, 'Staff Custom Orders main script exists');
const script = scripts[1][1].replace(/<\?php[\s\S]*?\?>/g, 'null');

function extractMethod(name, args) {
    const marker = `${name}(${args}) {`;
    const start = script.indexOf(marker);
    assert.ok(start >= 0, `${name} method exists`);
    const end = script.indexOf('\n            },', start);
    assert.ok(end > start, `${name} method has a complete object-method boundary`);
    return script.slice(start, end + '\n            }'.length);
}

const methodNames = [
    ['parseFinalPriceCentavos', 'value'],
    ['isValidPosFinalPrice', 'value'],
    ['finalPriceDecimalFromCentavos', 'cents'],
    ['formatFinalPriceCentavos', 'cents'],
    ['finalPriceValidationMessage', ''],
    ['handleJobPriceInput', 'event'],
    ['formatJobPriceInputOnBlur', 'event'],
];
const methods = methodNames.map(([name, args]) => extractMethod(name, args)).join(',\n');
const priceUi = new Function(`return ({${methods}});`)();

const valid = new Map([
    ['0.01', 1],
    ['1,000.00', 100000],
    ['10,000.00', 1000000],
    ['250,000.50', 25000050],
    ['1,000,000.00', 100000000],
    ['9,999,999.99', 999999999],
]);
for (const [input, cents] of valid) {
    assert.equal(priceUi.parseFinalPriceCentavos(input), cents, `${input} parses to exact centavos`);
    assert.equal(priceUi.isValidPosFinalPrice(input), true, `${input} is valid`);
    assert.equal(priceUi.formatFinalPriceCentavos(cents), input, `${input} formats without changing its value`);
}

for (const input of ['', '0', '0.00', '-1', '-0.01', 'letters', '1.001', '1.000', '10,000,000.00', '10000000', '1,00', '1,,000']) {
    assert.equal(priceUi.isValidPosFinalPrice(input), false, `${JSON.stringify(input)} is rejected`);
}

const input = {
    value: '1000.5',
    selectionStart: 4,
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
};
priceUi.jobPriceInput = '';
priceUi.handleJobPriceInput({ target: input });
assert.equal(input.value, '1,000.5', 'typing inserts grouping commas');
assert.equal(priceUi.jobPriceInput, '1000.5', 'the model stores an ungrouped decimal without scaling it');
assert.equal(priceUi.parseFinalPriceCentavos(priceUi.jobPriceInput), 100050, 'typing preserves the exact entered amount');
assert.equal(input.selectionStart, 5, 'the caret follows the same significant characters after comma insertion');

input.value = '1,00';
input.selectionStart = 4;
priceUi.handleJobPriceInput({ target: input });
assert.equal(input.value, '100', 'editing a grouped value normalizes leftover commas');
assert.equal(priceUi.jobPriceInput, '100', 'the normalized model contains no grouping characters');
assert.equal(priceUi.isValidPosFinalPrice(priceUi.jobPriceInput), true, 'the edited numeric value remains valid');

input.value = '1,24';
input.selectionStart = 3;
priceUi.handleJobPriceInput({ target: input });
assert.equal(input.value, '124', 'deleting a digit next to an inserted comma does not strand malformed grouping');
assert.equal(input.selectionStart, 2, 'the caret remains after the same significant digits after normalization');

const integerBlurInput = { value: '400' };
priceUi.jobPriceInput = '400';
priceUi.formatJobPriceInputOnBlur({ target: integerBlurInput });
assert.equal(integerBlurInput.value, '400.00', 'blur formats an integer price to two decimal places');
assert.equal(priceUi.jobPriceInput, '400.00', 'formatted integer remains the same numeric amount');

const blurInput = { value: '250000.5' };
priceUi.jobPriceInput = '250000.5';
priceUi.formatJobPriceInputOnBlur({ target: blurInput });
assert.equal(blurInput.value, '250,000.50', 'blur adds grouping and pads only the display to two decimals');
assert.equal(priceUi.jobPriceInput, '250000.50', 'blur keeps the numeric model ungrouped');
assert.equal(priceUi.finalPriceValidationMessage(), 'Enter a final price from ₱0.01 to ₱9,999,999.99 with no more than two decimal places.');

console.log('Final price input validation and formatting tests passed.');
