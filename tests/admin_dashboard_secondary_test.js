const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/admin-dashboard-secondary.js'), 'utf8');
const frames = [], requests = [];
let initialized = 0;
const context = {
    window: {printflowInitDashboardCharts() { initialized++; }}, AbortController,
    requestAnimationFrame(fn) { frames.push(fn); },
    fetch(url, options) { return new Promise((resolve, reject) => requests.push({url, options, resolve, reject})); },
    DOMParser: class { parseFromString(html) { return {getElementById() { return html.includes('pf-dashboard-branch-chart-data') ? {} : null; }}; } },
    document: {createElement() { return {}; }}
};
vm.runInNewContext(source, context);
const container = url => ({dataset: {url}, isConnected: true, setAttribute(k,v) { this[k] = v; }, appendChild(child) { this.child = child; }});
const paint = () => { while (frames.length) frames.shift()(); };
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    const first = container('/dashboard.php?branch_id=1&dashboard_section=secondary');
    context.window.pfDashLoadSecondary(first);
    context.window.pfDashLoadSecondary(first);
    assert.equal(requests.length, 0, 'No request before summary paint');
    paint();
    assert.equal(requests.length, 1, 'Repeated initialization does not duplicate fetch');
    const second = container('/dashboard.php?branch_id=2&dashboard_section=secondary');
    context.window.pfDashLoadSecondary(second);
    assert.equal(requests[0].options.signal.aborted, true, 'Changing context aborts old request');
    paint();
    requests[0].resolve({ok:true, text: async () => 'pf-dashboard-branch-chart-data OLD'});
    requests[1].resolve({ok:true, text: async () => 'pf-dashboard-branch-chart-data NEW'});
    await settle();
    assert.equal(first.innerHTML, undefined, 'Stale response never renders');
    assert.match(second.innerHTML, /NEW/);
    assert.equal(initialized, 1);
    const failed = container('/dashboard.php?branch_id=3');
    context.window.pfDashLoadSecondary(failed); paint();
    requests[2].resolve({ok:true, redirected:true}); await settle();
    assert.equal(failed.innerHTML, undefined, 'Login redirect is never inserted as dashboard markup');
    assert.equal(failed.child.textContent, 'Retry');
    failed.child.onclick(); paint();
    assert.equal(requests.length, 4, 'Failed fetch can be retried');
    requests[3].resolve({ok:true, text:async () => 'pf-dashboard-branch-chart-data RETRY'}); await settle();
    assert.match(failed.innerHTML, /RETRY/);
    const cancelled = container('/dashboard.php?branch_id=4');
    context.window.pfDashLoadSecondary(cancelled);
    context.window.pfDashCancelSecondary(); paint();
    assert.equal(requests.length, 4, 'Cancellation before paint prevents a request');
    assert.equal(cancelled.dataset.loading, 'false');
    console.log('Admin dashboard secondary loader tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
