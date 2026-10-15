const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../admin/dashboard.php'), 'utf8');
const block = source.slice(source.indexOf('window.__pfDashFilterTimer = null;'), source.indexOf('window.debouncedSubmitDashboardFilter ='));
const fields = {reportsFilterForm:{}, fp_from:{value:'2026-10-01'}, fp_to:{value:'2026-10-01'}, dash_preset:{value:''}, 'pf-dashboard-content':{innerHTML:'ORIGINAL'}};
const requests = [];
let initializations = 0;
const context = {
    window: {location:{pathname:'/admin/dashboard.php'}, history:{replaceState(){}}, printflowInitDashboardCharts(){initializations++;}},
    document:{getElementById(id){return fields[id];}, querySelector(selector){return fields[selector.slice(1)];}},
    FormData:class { *[Symbol.iterator](){yield ['from',fields.fp_from.value]; yield ['to',fields.fp_to.value]; yield ['preset',fields.dash_preset.value];}},
    URLSearchParams, AbortController, console,
    fetch(url, options){return new Promise(resolve => requests.push({url,options,resolve}));},
    DOMParser:class { parseFromString(html){return {querySelector(selector){return selector === '#pf-dashboard-content' ? {innerHTML:html} : null;}};} }
};
vm.runInNewContext(block, context);
const settle = () => new Promise(resolve => setImmediate(resolve));
(async () => {
    context.window.pfDashApplyFilterAjax();
    context.window.pfDashApplyFilterAjax();
    assert.equal(requests.length, 1);
    fields.fp_from.value = fields.fp_to.value = '2026-10-02';
    context.window.pfDashApplyFilterAjax();
    fields.fp_from.value = fields.fp_to.value = '2026-10-03';
    context.window.pfDashApplyFilterAjax();
    requests[0].resolve({ok:true,text:async ()=>'STALE'});
    await settle();
    assert.equal(fields['pf-dashboard-content'].innerHTML, 'ORIGINAL', 'Superseded response must not render');
    assert.equal(requests.length, 2, 'Latest queued range must actually be requested');
    assert.match(requests[1].url, /from=2026-10-03/);
    requests[1].resolve({ok:true,text:async ()=>'LATEST'});
    await settle();
    assert.equal(fields['pf-dashboard-content'].innerHTML, 'LATEST');
    assert.equal(initializations, 1);
    context.window.pfDashApplyFilterAjax();
    assert.equal(requests.length, 2, 'Identical settled range is not requested twice');
    console.log('Dashboard filter queue and stale-response tests passed.');
})().catch(error => {console.error(error); process.exitCode=1;});
