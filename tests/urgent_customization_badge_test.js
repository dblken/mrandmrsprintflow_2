'use strict';

const assert = require('assert');

const displayBadge = {
    dataset: {}, style: {}, textContent: '', attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; }
};
const visibilityBadge = {
    dataset: { urgentBadgeMode: 'visibility' }, style: {}, textContent: '', attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; }
};
const badges = [displayBadge, visibilityBadge];
let fetchCalls = 0;
let dispatched = 0;
let lastEventCount = null;
global.window = {
    addEventListener() {},
    dispatchEvent(event) {
        if (event.type === 'printflow:urgent-customizations-count') {
            dispatched++;
            lastEventCount = event.detail.count;
        }
    },
    PFConfig: { basePath: '/printflow' }
};
global.document = {
    hidden: false,
    currentScript: {
        dataset: { baseUrl: '/printflow', source: 'online', initialCount: '2' }
    },
    querySelectorAll(selector) {
        return selector === '[data-urgent-customizations-badge]' ? badges : [];
    },
    addEventListener() {}
};
global.fetch = async function (url, options) {
    fetchCalls++;
    assert.strictEqual(url, '/printflow/admin/job_orders_api.php?action=customization_counts&source=online');
    assert.strictEqual(options.credentials, 'same-origin');
    return {
        ok: true,
        json: async () => ({ success: true, data: { URGENT: 5 } })
    };
};
global.setTimeout = function () { return 1; };
global.clearTimeout = function () {};

require('../public/assets/js/urgent_customization_badges.js');

assert.strictEqual(displayBadge.textContent, '2');
assert.strictEqual(visibilityBadge.style.visibility, 'visible');
assert.strictEqual(lastEventCount, 2);

window.PrintFlowUrgentCustomizations.refresh().then(() => {
    assert.strictEqual(fetchCalls, 1);
    assert.strictEqual(displayBadge.textContent, '5');
    assert.strictEqual(visibilityBadge.textContent, '5');
    window.PrintFlowUrgentCustomizations.set(0);
    assert.strictEqual(displayBadge.textContent, '');
    assert.strictEqual(visibilityBadge.style.visibility, 'hidden');
    console.log('Urgent customizations badge test passed.');
}).catch(error => {
    console.error(error);
    process.exitCode = 1;
});
