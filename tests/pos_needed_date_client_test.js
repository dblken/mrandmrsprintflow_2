'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const pos = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');

assert.match(pos, /const POS_BUSINESS_TODAY =/, 'POS injects Manila business today');
assert.match(pos, /function posBusinessToday\(/, 'POS exposes a business-today helper');
assert.match(pos, /function posNeededDateIsNotBeforeToday\(/, 'POS exposes a Needed Date comparator');
assert.match(pos, /function posEnforceNeededDateMinimum\(/, 'POS enforces min after dynamic form load');
assert.doesNotMatch(pos, /function posAllowPastNeededDateInputs\(/, 'past-date min stripper is removed');
assert.doesNotMatch(pos, /toISOString\(\)\.split\('T'\)\[0\]/, 'Needed Date validation no longer uses UTC');
assert.match(pos, /Needed date cannot be in the past\./, 'past Needed Date shows a clear message');

const businessTodayFn = pos.slice(
  pos.indexOf('function posBusinessToday() {'),
  pos.indexOf('function posNeededDateIsNotBeforeToday(')
);
const neededDateFn = pos.slice(
  pos.indexOf('function posNeededDateIsNotBeforeToday(value) {'),
  pos.indexOf('\n        function posCatalogImageUrl(', pos.indexOf('function posNeededDateIsNotBeforeToday(value) {'))
);
assert.ok(businessTodayFn.includes('POS_BUSINESS_TODAY'), 'business-today helper reads the server stamp');
assert.ok(neededDateFn.includes('posBusinessToday()'), 'Needed Date comparator uses business today');

const today = '2026-11-07';
const context = { POS_BUSINESS_TODAY: today };
vm.runInNewContext(
  `${businessTodayFn}\n${neededDateFn}\nglobalThis.api = { posBusinessToday, posNeededDateIsNotBeforeToday };`,
  context
);

assert.equal(context.api.posBusinessToday(), today, 'business today comes from the server stamp');
assert.equal(context.api.posNeededDateIsNotBeforeToday('2026-11-06'), false, 'yesterday is rejected');
assert.equal(context.api.posNeededDateIsNotBeforeToday('2026-10-31'), false, 'manually entered past date is rejected');
assert.equal(context.api.posNeededDateIsNotBeforeToday('2026-11-07'), true, 'today is accepted');
assert.equal(context.api.posNeededDateIsNotBeforeToday('2026-11-08'), true, 'future date is accepted');
assert.equal(context.api.posNeededDateIsNotBeforeToday(''), false, 'empty value is not treated as valid today');

const enforceStart = pos.indexOf('function posEnforceNeededDateMinimum(root) {');
const enforceEnd = pos.indexOf('\n        async function processCheckout()', enforceStart);
assert.ok(enforceStart >= 0 && enforceEnd > enforceStart, 'enforce helper exists');
assert.match(pos.slice(enforceStart, enforceEnd), /input\.min = today/, 'enforce sets the picker min to business today');
assert.match(pos.slice(enforceStart, enforceEnd), /Needed date cannot be in the past\./, 'enforce marks past values invalid inline');
assert.match(pos, /posEnforceNeededDateMinimum\(body\)/, 'openServiceModal enforces Needed Date min on dynamically loaded forms');

console.log('POS Needed Date client validation tests passed.');
