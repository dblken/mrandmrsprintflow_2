<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['Admin']);
if (!is_admin()) { http_response_code(403); exit('Admin authorization is required.'); }
$csrfToken = generate_csrf_token();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><title>Jenny Ferr Customer Archive</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#172b4d}.pf-jenny-wrap{max-width:1050px;margin:32px auto;padding:24px;background:#fff;border:1px solid #dce5ee;border-radius:12px}.pf-jenny-wrap h1{margin-top:0}.pf-jenny-callout{padding:14px;background:#eef8fb;border-left:4px solid #168da5;margin:18px 0}.pf-jenny-actions{display:flex;gap:12px;margin:20px 0}.pf-jenny-actions button{border:0;border-radius:6px;padding:11px 18px;font-weight:700;cursor:pointer}.pf-jenny-actions button:disabled{opacity:.5;cursor:not-allowed}.pf-jenny-hide{background:#9d2332;color:#fff}.pf-jenny-restore{background:#16734a;color:#fff}.pf-jenny-status{padding:12px;border-radius:6px;background:#f0f2f5;margin:14px 0;white-space:pre-wrap}.pf-jenny-status.error{background:#fff0f0;color:#881b1b}.pf-jenny-status.ok{background:#eaf8ef;color:#14542c}.pf-jenny-counts{border-collapse:collapse;width:100%;margin-top:18px}.pf-jenny-counts th,.pf-jenny-counts td{text-align:left;padding:8px;border-bottom:1px solid #e3e9ef}.pf-jenny-counts td:nth-child(2){text-align:right}.pf-jenny-small{color:#52677d;font-size:.93rem}
</style></head><body>
<div class="dashboard-container"><?php include __DIR__ . '/../includes/admin_sidebar.php'; ?><main class="main-content"><section class="pf-jenny-wrap">
<h1>Jenny Ferr: reversible customer archive</h1>
<p>This hides customer 25 and related order records from application views using the customer exclusion helper. It does not delete or edit order, payment, inventory, file, webhook, audit, or other-customer records. The Admin can restore visibility from this page.</p>
<div class="pf-jenny-callout"><strong>Scope:</strong> authenticated Admin action, fixed customer ID 25, live database ownership check, CSRF-protected activation and restoration. Financial and inventory ledger rows remain stored; their related views are filtered by the exclusion predicates.</div>
<div id="pfJennyStatus" class="pf-jenny-status" role="status">Loading live preview…</div>
<p id="pfJennyState" class="pf-jenny-small"></p>
<div class="pf-jenny-actions"><button id="pfJennyHide" class="pf-jenny-hide" disabled>Hide Jenny’s customer data</button><button id="pfJennyRestore" class="pf-jenny-restore" disabled>Restore Jenny’s data</button></div>
<h2>Current live preview counts</h2><p class="pf-jenny-small">Preview is read-only. Counts are refreshed before either action; physical file contents and all transaction records are preserved.</p>
<table class="pf-jenny-counts"><thead><tr><th>Table / record group</th><th>Count</th><th>Classification</th></tr></thead><tbody id="pfJennyCounts"><tr><td colspan="3">Waiting for preview</td></tr></tbody></table>
</section></main></div>
<script>
(() => {
 const statusEl=document.getElementById('pfJennyStatus'), stateEl=document.getElementById('pfJennyState'), rowsEl=document.getElementById('pfJennyCounts'), hideBtn=document.getElementById('pfJennyHide'), restoreBtn=document.getElementById('pfJennyRestore');
 const csrf=document.querySelector('meta[name="csrf-token"]').content; let currentStatus='inactive',ready=false;
 const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 async function refresh(){ready=false;hideBtn.disabled=true;restoreBtn.disabled=true;statusEl.className='pf-jenny-status';statusEl.textContent='Loading live preview…';
  try{const [controlResp,previewResp]=await Promise.all([fetch('api_jenny_customer_exclusion.php',{credentials:'same-origin',cache:'no-store'}),fetch('api_jenny_cleanup_preview.php',{credentials:'same-origin',cache:'no-store'})]);const control=await controlResp.json(),preview=await previewResp.json();
   currentStatus=control.exclusion?.status||'inactive';stateEl.textContent=`Live exclusion status: ${currentStatus}. Customer-owned current order count: ${control.orders_currently_owned}. Control version: ${control.version||'unknown'}.`;
   const rec=preview.records||{};rowsEl.innerHTML=Object.entries(rec).map(([name,item])=>`<tr><td>${esc(name)}</td><td>${Number(item.count)||0}</td><td>${esc(item.classification||'unclassified')}</td></tr>`).join('')||'<tr><td colspan="3">No scope records returned</td></tr>';
   const valid=controlResp.ok&&previewResp.ok&&control.success===true&&preview.success===true&&control.customer_id===25&&preview.target?.customer_id===25&&preview.database?.name==='u618446170_printflow';
   if(!valid)throw new Error(preview.error||control.error||`Preview unavailable (control HTTP ${controlResp.status}, preview HTTP ${previewResp.status}).`);
   ready=true;hideBtn.disabled=currentStatus==='active';restoreBtn.disabled=currentStatus!=='active';statusEl.className='pf-jenny-status ok';statusEl.textContent=`Verified live target customer_id 25 in ${preview.database.name}. Preview ${preview.diagnostic_version||'available'} completed. No transaction data has been modified.`;
  }catch(e){statusEl.className='pf-jenny-status error';statusEl.textContent=`Could not verify target and preview: ${e.message}. Actions are disabled.`;}}
 async function change(action){if(!ready)return;const message=action==='activate'?'Hide Jenny Ferr’s customer-owned data from application views? This is reversible and does not delete or edit transaction records.':'Restore Jenny Ferr’s records to application views?';if(!window.confirm(message))return;hideBtn.disabled=true;restoreBtn.disabled=true;statusEl.className='pf-jenny-status';statusEl.textContent='Applying Admin-authorized change…';
  try{const response=await fetch('api_jenny_customer_exclusion.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,csrf_token:csrf})});const body=await response.json();if(!response.ok||!body.success)throw new Error(body.error||`HTTP ${response.status}`);statusEl.className='pf-jenny-status ok';statusEl.textContent=body.message;await refresh();}
  catch(e){statusEl.className='pf-jenny-status error';statusEl.textContent=`Change failed: ${e.message}`;await refresh();}}
 hideBtn.addEventListener('click',()=>change('activate'));restoreBtn.addEventListener('click',()=>change('restore'));refresh();
})();
</script></body></html>
