'use strict';

const assert = require('assert');

const displayBadge = {
    dataset: {}, style: {}, textContent: '', attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; }
};
const visibilityBadge = {
    dataset: { chatBadgeMode: 'visibility' }, style: {}, textContent: '', attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; }
};
const badges = [displayBadge, visibilityBadge];
let fetchCalls = 0;

global.window = {
    addEventListener() {},
    PFConfig: { basePath: '' }
};
global.document = {
    hidden: false,
    currentScript: { dataset: { baseUrl: '', initialCount: '3' } },
    querySelectorAll(selector) { return selector === '[data-chat-unread-badge]' ? badges : []; },
    addEventListener() {}
};
global.fetch = async function (url, options) {
    fetchCalls++;
    assert.strictEqual(url, '/public/api/chat/unread_count.php');
    assert.strictEqual(options.credentials, 'same-origin');
    return { ok: true, json: async () => ({ success: true, unread_count: 4 }) };
};
global.setTimeout = function () { return 1; };
global.clearTimeout = function () {};

require('../public/assets/js/chat_unread_badges.js');

assert.strictEqual(fetchCalls, 0, 'Fresh server-rendered counts should avoid a duplicate immediate request.');
assert.strictEqual(displayBadge.textContent, '3');
assert.strictEqual(displayBadge.style.display, 'inline-flex');
assert.strictEqual(visibilityBadge.style.visibility, 'visible');

window.PrintFlowChatUnread.set(0);
assert.strictEqual(displayBadge.style.display, 'none');
assert.strictEqual(visibilityBadge.style.visibility, 'hidden');
assert.strictEqual(displayBadge.textContent, '');

window.PrintFlowChatUnread.refresh().then(() => {
    assert.strictEqual(fetchCalls, 1);
    assert.strictEqual(displayBadge.textContent, '4');
    assert.strictEqual(visibilityBadge.textContent, '4');
    console.log('Chat unread badge test passed.');
}).catch(error => {
    console.error(error);
    process.exitCode = 1;
});
