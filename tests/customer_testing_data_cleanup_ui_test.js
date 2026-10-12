const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const profile = fs.readFileSync('customer/profile.php', 'utf8');
const api = fs.readFileSync('customer/api_testing_data_cleanup.php', 'utf8');
const scriptMatch = profile.match(/<script>\s*\(function\(\)\{\s*const endpoint =[\s\S]*?<\/script>\s*<\?php endif/);
assert.ok(scriptMatch, 'cleanup modal script exists');
let script = scriptMatch[0].replace(/^<script>|<\/script>[\s\S]*$/g, '');
script = script
    .replace(/const endpoint = .*?\?>;/, "const endpoint = '/customer/api_testing_data_cleanup.php';")
    .replace(/const csrf = .*?\?>;/, "const csrf = 'test-csrf';");

class Element {
    constructor() { this.listeners = {}; this.disabled = true; this.checked = false; this.value = ''; this.textContent = ''; this.style = {}; this.children = []; }
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    dispatch(type) { for (const callback of this.listeners[type] || []) callback({ target: this }); }
    focus() { this.focused = true; }
    replaceChildren() { this.children = []; }
    appendChild(child) { this.children.push(child); }
}

const ids = [
    'pf-testing-cleanup-modal', 'pf-testing-cleanup-status', 'pf-testing-cleanup-counts',
    'pf-testing-cleanup-protected', 'pf-testing-cleanup-order', 'pf-testing-cleanup-acknowledged',
    'pf-testing-cleanup-submit', 'pf-open-testing-cleanup', 'pf-testing-cleanup-close',
];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
const requests = [];
const document = {
    getElementById(id) { return elements[id] ||= new Element(); },
    createElement() { return new Element(); },
};
const fetch = async (_url, options = {}) => {
    requests.push(options);
    return { ok: true, json: async () => ({ success: true, preview_token: 'verified-preview', counts: { orders: 2 }, protected: {}, delete_order: ['orders'] }) };
};

vm.runInNewContext(script, { document, fetch, console, Object, JSON });
const opener = elements['pf-open-testing-cleanup'];
const modal = elements['pf-testing-cleanup-modal'];
const acknowledgment = elements['pf-testing-cleanup-acknowledged'];
const submit = elements['pf-testing-cleanup-submit'];
const close = elements['pf-testing-cleanup-close'];

async function run() {
    opener.dispatch('click');
    assert.equal(modal.style.display, 'flex', 'opening displays the modal');
    assert.equal(acknowledgment.focused, true, 'opening focuses the checkbox');
    assert.equal(acknowledgment.checked, false, 'opening clears prior acknowledgment');
    assert.equal(submit.disabled, true, 'button starts disabled');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests.length, 1, 'opening makes only the read-only preview request');
    assert.equal(requests[0].method, undefined, 'preview uses GET');

    acknowledgment.checked = true;
    acknowledgment.dispatch('change');
    assert.equal(submit.disabled, false, 'button enables after preview and required acknowledgment');
    acknowledgment.checked = false;
    acknowledgment.dispatch('change');
    assert.equal(submit.disabled, true, 'unchecking disables the button');
    acknowledgment.checked = true;
    acknowledgment.dispatch('change');
    assert.equal(submit.disabled, false, 'rechecking enables the button');

    close.dispatch('click');
    assert.equal(modal.style.display, 'none', 'cancel closes the modal');
    assert.equal(acknowledgment.checked, false, 'cancel clears acknowledgment');
    assert.equal(submit.disabled, true, 'cancel leaves deletion disabled');
    assert.equal(requests.length, 1, 'no deletion request was sent');

    opener.dispatch('click');
    assert.equal(acknowledgment.checked, false, 'reopening starts unchecked');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests.length, 2, 'reopening requests a new read-only preview only');
    assert.ok(!requests.some(request => request.method === 'POST'), 'test did not submit deletion');

    assert.match(api, /\(\$input\['acknowledged'\]\?\?false\)!==true/);
    assert.match(api, /verify_csrf_token\(/);
    assert.match(api, /\$_SESSION\['user_type'\].*get_user_id\(\).*PF_JENNY_CLEANUP_CUSTOMER_ID/);
    assert.match(api, /begin_transaction\(/);
    assert.match(api, /rollback\(/);
    console.log('PASS: checkbox/button, open/focus, toggle, cancel/reopen, preview-only requests, and server guard contracts');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
