const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

// Run the real page's initializer against DOM/fetch fixtures; this is not rendered browser proof.
const source = fs.readFileSync(path.join(__dirname, '../admin/settings.php'), 'utf8');
const start = source.indexOf('function printflowInitDemoSeedTools() {');
const end = source.indexOf("\nif (document.readyState === 'loading')", start);
const code = source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, '"fixture"');
class Element {
    constructor() { this.listeners = {}; this.dataset = {}; this.style = {}; this.children = []; this.value = ''; this.checked = false; this.disabled = false; this.open = false; this.textContent = ''; }
    addEventListener(event, listener) { (this.listeners[event] ||= []).push(listener); }
    emit(event) { for (const listener of this.listeners[event] || []) listener(); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this.children = children; this.textContent = ''; }
    showModal() { this.open = true; }
    close() { this.open = false; }
}
const ids = ['demo-seed-validate-btn', 'demo-seed-import-btn', 'demo-seed-delete-btn', 'demo-seed-delete-preview-btn', 'demo-seed-delete-batch', 'demo-seed-delete-verified-only', 'demo-seed-delete-confirm', 'demo-seed-delete-backup', 'demo-seed-delete-dialog', 'demo-seed-delete-final', 'demo-seed-delete-cancel', 'demo-seed-delete-preview', 'demo-seed-delete-status', 'demo-seed-delete-result', 'demo-seed-delete-dialog-counts', 'demo-seed-delete-phrase'];
const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
const get = id => elements['demo-seed-' + id];
get('delete-batch').value = 'batch-one';
get('delete-batch').selectedIndex = 0;
get('delete-batch').options = [{value: 'batch-one', textContent: 'batch-one'}, {value: 'batch-two', textContent: 'batch-two'}];
const requests = [];
let deletionResolve;
let deleted = false;
function preview(batch, verifiedOnly) {
    return {
        batch_id: batch, registry_rows: deleted ? 0 : 1, confirmation_phrase: 'DELETE MEETING DATA',
        safe_to_delete: !deleted, no_records: deleted, verified_only: verifiedOnly,
        counts: deleted ? {} : {orders: 1, demo_seed_rows: 1},
        delete_order: deleted ? [] : ['demo_seed_rows', 'orders'],
        tables: deleted ? {} : {orders: [{id: 42, proof: 'Exact registry'}], demo_seed_rows: [{id: 9, proof: 'Exact batch'}]},
        protected: {customers: [{id: 70, reason: 'Shared customer'}]},
        diagnostics: [], foreign_keys: [], schema: {}, registry_records: [], inventory_rows: 0, inventory_transaction_rows: 0,
    };
}
const context = vm.createContext({
    document: {getElementById: id => elements[id] || null, querySelector: () => ({value: 'csrf-fixture'}), createElement: () => new Element()},
    FormData, Promise, Error, Object, Number, String, JSON,
    fetch: async (url, options) => {
        const fields = Object.fromEntries(options.body.entries()); requests.push(fields);
        if (fields.action === 'delete_batch') return new Promise(resolve => { deletionResolve = resolve; });
        return {json: async () => ({success: true, preview: preview(fields.batch_id, fields.verified_only === '1'), preview_token: 'token-' + requests.length})};
    },
});
vm.runInContext(code + '\nprintflowInitDemoSeedTools();', context);
const settle = () => new Promise(resolve => setImmediate(resolve));
const text = element => element.textContent + element.children.map(text).join(' ');
let passed = 0;
const pass = (ok, name) => { assert.ok(ok, name); passed++; process.stdout.write('PASS: ' + name + '\n'); };

(async () => {
    pass(get('delete-btn').disabled && requests.length === 0, 'page load cannot delete or silently run a dry run');
    get('delete-preview-btn').emit('click'); await settle();
    pass(requests[0].batch_id === 'batch-one' && requests[0].action === 'delete_preview' && get('delete-btn').disabled, 'dry run submits exact selected batch and keeps confirmation gate');
    pass(text(get('delete-preview')).includes('42') && text(get('delete-preview')).includes('70') && text(get('delete-preview')).includes('Shared customer'), 'preview renders verified IDs and protected reasons');
    get('delete-confirm').value = 'DELETING MEETING DATA'; get('delete-confirm').emit('input'); get('delete-backup').checked = true; get('delete-backup').emit('change');
    pass(get('delete-btn').disabled, 'screenshot phrase cannot enable deletion');
    get('delete-confirm').value = 'DELETE MEETING DATA'; get('delete-confirm').emit('input');
    pass(!get('delete-btn').disabled, 'exact phrase and backup acknowledge enable a valid preview');
    get('delete-btn').emit('click');
    pass(get('delete-dialog').open && get('delete-dialog-counts').textContent.includes('Total rows to delete: 2') && get('delete-dialog-counts').textContent.includes('Protected customers: 1') && requests.length === 1, 'final modal shows complete per-table counts before any deletion');
    get('delete-cancel').emit('click');
    pass(!get('delete-dialog').open && requests.length === 1, 'cancel performs no deletion');
    get('delete-batch').value = 'batch-two'; get('delete-batch').selectedIndex = 1; get('delete-batch').emit('change');
    pass(get('delete-btn').disabled, 'changing selected batch invalidates the preview');
    get('delete-preview-btn').emit('click'); await settle();
    get('delete-verified-only').checked = true; get('delete-verified-only').emit('change');
    pass(get('delete-btn').disabled, 'changing verified-only mode invalidates the preview');
    get('delete-preview-btn').emit('click'); await settle();
    pass(requests.at(-1).verified_only === '1' && requests.at(-1).batch_id === 'batch-two', 'new dry run binds selected batch and recovery mode');
    get('delete-btn').emit('click'); get('delete-final').emit('click'); get('delete-final').emit('click'); await settle();
    const deletions = requests.filter(request => request.action === 'delete_batch');
    pass(deletions.length === 1 && get('delete-btn').disabled && get('delete-batch').disabled, 'duplicate clicks issue only one deletion request and controls stay disabled');
    pass(deletions[0].batch_id === 'batch-two' && deletions[0].preview_token.startsWith('token-') && deletions[0].confirm_text === 'DELETE MEETING DATA' && deletions[0].csrf_token === 'csrf-fixture' && deletions[0].backup_confirmed === '1' && deletions[0].verified_only === '1', 'final API request contains bound token, exact phrase, CSRF, backup, and mode');
    deleted = true;
    deletionResolve({json: async () => ({success: true, completed: true, message: 'Demo batch deleted and verified.', result: {batch_id: 'batch-two', deleted_counts: {orders: 1}, protected_counts: {customers: 1}, partial: false, remaining_registry_rows: 0}})});
    await settle(); await settle();
    pass(get('delete-result').style.display === 'block' && get('delete-result').textContent.includes('deleted_counts') && get('delete-status').textContent === 'Demo batch deleted and verified.', 'deleted/protected counts remain visible after result');
    pass(get('delete-btn').disabled && text(get('delete-preview')).includes('Zero verified demo rows remain') && !get('delete-batch').disabled, 'post-delete dry run is refreshed and cannot enable another deletion');
    process.stdout.write('Demo deletion UI tests: ' + passed + ' passed (DOM/fetch fixtures; no rendered browser).\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
