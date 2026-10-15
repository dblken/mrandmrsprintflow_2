const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../includes/reports_analytics_scripts.php'), 'utf8');
const start = source.indexOf('        var heatmapController = null;');
const end = source.indexOf('    })();', start);
const requests = [];
let observe, changes, rendered = [];
const sel = {value:'2026', disabled:false, addEventListener(event, callback){changes=callback;}};
const mount = {dataset:{pfLazyHeatmap:'1'}, isConnected:true};
const context = {
    sel, mount, yearChip:{}, box:null,
    showLoading(){}, showHeatmapEmpty(year){rendered.push(year);},
    document:{getElementById(){return {elements:{branch_id:{value:'2'}}};}},
    window:{IntersectionObserver:function(){}},
    IntersectionObserver:class {constructor(callback){observe=callback;} observe(){} disconnect(){}},
    PF_HEATMAP_API:'/admin/api_reports_heatmap.php', AbortController, console,
    fetch(url, options){return new Promise(resolve=>requests.push({url, options, resolve}));}
};
vm.runInNewContext(source.slice(start,end), context);
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const empty=year=>({ok:true,json:async()=>({ok:true,year,yearValid:true,empty:true})});
(async()=>{
    assert.equal(requests.length,0,'Offscreen heatmap must not fetch');
    observe([{isIntersecting:true}]);
    assert.equal(requests.length,1);
    changes();
    assert.equal(requests.length,1,'Same-year in-flight request must be reused');
    assert.match(requests[0].url,/branch_id=2/);
    sel.value='2025'; changes();
    assert.equal(requests[0].options.signal.aborted,true);
    requests[0].resolve(empty(2026)); requests[1].resolve(empty(2025));
    await settle();
    assert.deepEqual(rendered,[2025],'Older years must not overwrite latest selection');
    sel.value='2024'; changes(); mount.isConnected=false;
    requests[2].resolve(empty(2024)); await settle();
    assert.deepEqual(rendered,[2025],'Detached branch/filter results must not render');
    console.log('Reports heatmap visibility, deduplication, branch binding and stale-year tests passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
