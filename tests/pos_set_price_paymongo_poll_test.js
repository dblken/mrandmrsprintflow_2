'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(require('path').join(__dirname, '../staff/customizations.php'), 'utf8');
const match = source.match(/schedulePayMongoPolling\(\) \{([\s\S]*?)\r?\n[ ]{12}\},\r?\n[ ]{12}async pollPayMongoPayment/);
assert(match, 'schedulePayMongoPolling must exist in the Staff Custom Orders component');

const timers = [];
const cleared = [];
const context = vm.createContext({
    window: {
        clearTimeout: (timer) => cleared.push(timer),
        setTimeout: (callback, delay) => {
            const timer = { callback, delay };
            timers.push(timer);
            return timer;
        }
    }
});
const schedule = new vm.Script(`({ schedulePayMongoPolling() {${match[1]}\n} })`).runInContext(context);

function run(overrides = {}) {
    const instance = {
        currentJo: {},
        paymongoPayment: null,
        paymongoPollTimer: null,
        isPosPricingMode: () => false,
        pollPayMongoPayment() {},
        ...overrides
    };
    schedule.schedulePayMongoPolling.call(instance);
    return instance;
}

const draft = run({
    currentJo: { order_source: 'pos_draft', status: 'APPROVED' },
    isPosPricingMode: () => true,
    paymongoPollTimer: 123
});
assert.strictEqual(draft.paymongoPollTimer, null, 'a Set Price draft without payment data must not schedule polling');
assert(cleared.includes(123), 'rescheduling a draft clears any stale poll timer');
assert.strictEqual(timers.length, 0, 'drafts without PayMongo payment data do not issue a later lookup');

const ordinary = run({ currentJo: { order_source: 'online', status: 'Pending' } });
assert.strictEqual(timers.length, 1, 'ordinary order polling remains enabled');
assert.strictEqual(ordinary.paymongoPollTimer.delay, 5000, 'ordinary order polling retains its interval');

console.log('POS Set Price PayMongo polling regression tests passed.');
