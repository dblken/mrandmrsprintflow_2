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
    dispatch(type) { return Promise.all((this.listeners[type] || []).map(callback => callback({ target: this }))); }
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
    if (options.method === 'POST') return { ok: true, json: async () => ({ success: true, deleted: { orders: 2 }, protected: { payments: 3 }, remaining: { protected_orders: 4 } }) };
    return { ok: true, json: async () => ({ success: true, preview_token: 'verified-preview', counts: { orders: 2 }, protected: { payments: 3 }, delete_order: ['orders'] }) };
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

    acknowledgment.checked = true;
    acknowledgment.dispatch('change');
    await submit.dispatch('click');
    assert.equal(requests.length, 4, 'mocked confirmation performs POST then refreshes with a read-only GET');
    assert.equal(requests[2].method, 'POST', 'mocked delete request occurs only after checkbox confirmation');
    assert.deepEqual(JSON.parse(requests[2].body), { csrf_token: 'test-csrf', preview_token: 'verified-preview', acknowledged: true });
    assert.equal(requests[3].method, undefined, 'post-success refresh is a read-only GET');
    assert.equal(submit.disabled, true, 'success clears acknowledgment and disables the button again');
    assert.match(elements['pf-testing-cleanup-status'].textContent, /Deletion completed and verified.*preview below is refreshed/);
    assert.equal(elements['pf-testing-cleanup-protected'].textContent.includes('payments'), true, 'refreshed preview replaces protected counts');

    assert.match(api, /\(\$input\['acknowledged'\]\?\?false\)!==true/);
    assert.match(api, /verify_csrf_token\(/);
    assert.match(api, /\$_SESSION\['user_type'\].*get_user_id\(\).*PF_JENNY_CLEANUP_CUSTOMER_ID/);
    assert.match(api, /begin_transaction\(/);
    assert.match(api, /rollback\(/);
    assert.match(api, /'deletion_available'=>false/);
    assert.match(api, /'safe_remaining'\s*=>\s*\$completedPreview\['safe_remaining'\]/);
    assert.doesNotMatch(api, /'missing_ids'\s*=>|'unexpected_ids'\s*=>/);

    const completedElements = Object.fromEntries(ids.map(id => [id, new Element()]));
    const completedDocument = {
        getElementById(id) { return completedElements[id] ||= new Element(); },
        createElement() { return new Element(); },
    };
    const completedFetch = async (_url, options = {}) => {
        assert.notEqual(options.method, 'POST', 'completed-state preview never submits deletion');
        return { ok: true, json: async () => ({
            success: true, preview_token: null, deletion_available: false,
            safe_remaining: 0, protected_remaining: 2, already_deleted: 59,
            counts: { orders: 0 }, protected: { orders: 2 }, delete_order: ['orders'],
        }) };
    };
    vm.runInNewContext(script, { document: completedDocument, fetch: completedFetch, console, Object, JSON });
    completedElements['pf-open-testing-cleanup'].dispatch('click');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(completedElements['pf-testing-cleanup-submit'].disabled, true, 'completed cleanup has no deletion token');
    assert.match(completedElements['pf-testing-cleanup-status'].textContent, /Safe remaining: 0.*Protected orders remaining: 2.*Already deleted: 59.*No further deletion/);
    console.log('PASS: checkbox/button, open/focus, toggle, cancel/reopen, preview-only requests, and server guard contracts');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
