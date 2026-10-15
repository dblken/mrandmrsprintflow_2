const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../includes/reports_analytics_scripts.php'), 'utf8');
const block = source.slice(source.indexOf('window.fetchUpdatedDashboard ='), source.indexOf('window.debouncedUpdateDashboard ='));
const elements = {from:{value:'2026-10-01'}, to:{value:'2026-10-14'}, branch_id:{value:'1'}};
const form = {elements};
const container = {innerHTML:'ORIGINAL', isConnected:true, style:{}, classList:{add(){},remove(){}}, setAttribute(){},removeAttribute(){}};
const requests = [];
const context = {
    isDashboardFetching:false,
    window:{location:{pathname:'/admin/reports.php', search:'?branch_id=1'}, history:{replaceState(){}}, printflowSyncReportsBranchContext(){}},
    document:{getElementById(id){return id === 'reportsFilterForm' ? form : id === 'pf-reports-dashboard-container' ? container : null;}, querySelector(){return null;}},
    FormData:class {*[Symbol.iterator](){for (const [k,v] of Object.entries(elements)) yield [k,v.value];}},
    URLSearchParams, console:{error(){}},
    fetch(url){return new Promise(resolve => requests.push({url,resolve}));}
};
vm.runInNewContext(block, context);
const settle = () => new Promise(resolve => setImmediate(resolve));
const response = html => ({ok:true, redirected:false, json:async()=>({success:true,html})});
(async () => {
    context.window.fetchUpdatedDashboard();
    context.window.fetchUpdatedDashboard();
    assert.equal(requests.length,1,'Duplicate in-flight filter is coalesced');
    elements.from.value='2026-10-02';
    context.window.fetchUpdatedDashboard();
    elements.from.value='2026-10-03';
    context.window.fetchUpdatedDashboard();
    requests[0].resolve(response('STALE'));
    await settle();
    assert.equal(container.innerHTML,'ORIGINAL');
    assert.equal(requests.length,2);
    assert.match(requests[1].url,/from=2026-10-03/);
    requests[1].resolve(response('LATEST'));
    await settle();
    assert.equal(container.innerHTML,'LATEST');
    // A changed field must invalidate an old response even before debounce fires.
    context.window.fetchUpdatedDashboard();
    elements.to.value='2026-10-13';
    requests[2].resolve(response('DEBOUNCE STALE'));
    await settle();
    assert.equal(container.innerHTML,'LATEST');
    assert.equal(requests.length,4);
    requests[3].resolve({ok:true,redirected:true,json:async()=>({})});
    await settle();
    assert.equal(container.innerHTML,'LATEST','Login redirects must not erase reports');
    assert.equal(context.isDashboardFetching,false);
    console.log('Reports filter deduplication, latest queue, debounce race and login redirect tests passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
