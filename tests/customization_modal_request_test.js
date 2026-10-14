'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(require('path').join(__dirname, '../admin/customizations.php'), 'utf8');
const script = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(m => m[1]).find(s => s.includes('function custModal()'))
    .replace(/<\?php[\s\S]*?\?>/g, 'null');
const pending = [];
const context = vm.createContext({
    window: {}, console, document: {readyState: 'loading', addEventListener() {}},
    fetch: () => new Promise((resolve, reject) => pending.push({ resolve, reject }))
});
new vm.Script(script).runInContext(context);
const modal = context.custModal();
const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = (index, service) => pending[index].resolve({ text: async () => JSON.stringify({success: true, data: {service_type: service}}) });
(async () => {
    modal.openModal(1, 'OLD');
    modal.openModal(2, 'NEW');
    assert.strictEqual(modal.job, null);
    reply(1, 'Tarpaulin');
    await flush();
    reply(0, 'T-shirt');
    await flush();
    assert.strictEqual(modal.job.service_type, 'Tarpaulin', 'late response must not replace the selected order');
    modal.openModal(3);
    modal.closeCustomizationModal();
    reply(2, 'Mugs');
    await flush();
    assert.strictEqual(modal.job, null, 'closing must invalidate the outstanding request');
    modal.openModal(4);
    modal.openModal(5);
    pending[3].reject(new Error('old request failed'));
    await flush();
    assert.strictEqual(modal.loading, true, 'old failures must not stop the current loading state');
    reply(4, 'Stickers Decals');
    await flush();
    assert.strictEqual(modal.job.service_type, 'Stickers Decals');
    console.log('Admin modal JavaScript syntax and stale response regression tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
