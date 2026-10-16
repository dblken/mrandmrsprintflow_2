<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_role('Admin');
if (!is_admin()) { http_response_code(403); exit('Admin authorization is required.'); }
$basePath = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$csrf = generate_csrf_token();
$ready = printflow_order_archive_tables_ready();
$defaultStart = '2026-09-01';
$defaultEnd = '2026-10-15';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><meta name="turbo-cache-control" content="no-cache"><title>Hide Pending/Unpaid Orders - Archived Orders</title>
<?php include __DIR__ . '/../includes/admin_style.php'; ?>
<style>
body{font-family:var(--pf-ui-font-sans,Arial,sans-serif);color:#172b4d;background:#f4f7fb}
.dashboard-container{min-height:100vh}
.main-content{min-width:0;max-width:1100px;padding:24px;box-sizing:border-box}
.pf-back{display:inline-flex;align-items:center;gap:6px;color:#0b6675;text-decoration:none;font-weight:600;margin-bottom:10px}
.pf-back:hover{text-decoration:underline}
.pf-card{background:#fff;padding:22px;margin:0 0 16px;border:1px solid #d8e1eb;border-radius:12px;box-shadow:0 4px 18px rgba(15,35,55,.06)}
.pf-card h1{margin:0 0 6px;font-size:24px;line-height:1.25}
.pf-card h2{margin:0 0 10px;font-size:17px}
.pf-lead{margin:0;color:#334155;font-size:15px}
.pf-muted{color:#536579;font-size:13px;margin:6px 0 0}
.pf-rules{margin:12px 0 0;padding-left:18px;color:#475569;font-size:13px;line-height:1.55}
.pf-step{display:flex;align-items:center;gap:10px;margin:0 0 12px}
.pf-step-num{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:#0b6675;color:#fff;font-weight:700;font-size:13px;flex:0 0 26px}
.pf-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr)) auto;gap:12px;align-items:end;margin:14px 0 0}
.pf-fields label{display:grid;gap:6px;font-weight:600;font-size:14px}
.pf-fields input{min-height:42px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:#fff}
.pf-fields input:focus{outline:3px solid rgba(14,165,233,.2);border-color:#0284c7}
.pf-button{min-height:42px;padding:9px 16px;border:0;border-radius:8px;background:#0b6675;color:#fff;font-weight:700;cursor:pointer;font:inherit;font-weight:700;display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap}
.pf-button.pf-danger{background:#b42318}
.pf-button.pf-secondary{background:#e2e8f0;color:#0f172a}
.pf-button:disabled{opacity:.45;cursor:not-allowed}
.pf-spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:pfspin .8s linear infinite;display:none}
.pf-button.is-loading .pf-spinner{display:inline-block}
@keyframes pfspin{to{transform:rotate(360deg)}}
.pf-status{padding:12px 14px;border-radius:8px;background:#f1f5f9;color:#334155;margin:14px 0 0;font-size:14px;line-height:1.5;border-left:4px solid #94a3b8}
.pf-status.info{background:#eff6ff;color:#1e3a8a;border-left-color:#3b82f6}
.pf-status.error{background:#fff1f2;color:#9f1239;border-left-color:#e11d48}
.pf-status.success{background:#ecfdf3;color:#166534;border-left-color:#16a34a}
.pf-status.loading{background:#f8fafc;color:#475569;border-left-color:#0b6675}
.pf-summary{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 10px}
.pf-count{display:inline-flex;align-items:baseline;gap:6px;padding:8px 14px;border-radius:10px;background:#f0fdfa;border:1px solid #99f6e4;color:#134e4a;font-weight:700}
.pf-count strong{font-size:22px;line-height:1}
.pf-ids{font-size:13px;color:#334155;overflow-wrap:anywhere}
.pf-ids code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:12px}
.pf-table-wrap{max-height:50vh;overflow:auto;border:1px solid #e2e8f0;border-radius:10px}
.pf-table{width:100%;border-collapse:collapse;font-size:14px}
.pf-table th,.pf-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.pf-table th{position:sticky;top:0;background:#f8fafc;font-size:12px;letter-spacing:.03em;text-transform:uppercase;color:#475569;z-index:1}
.pf-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.pf-chip{display:inline-block;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:700}
.pf-chip.pending{background:#fef3c7;color:#92400e}
.pf-chip.unpaid{background:#fee2e2;color:#991b1b}
.pf-empty{padding:28px 14px;text-align:center;color:#61758a}
.pf-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px}
.pf-actions .pf-hint{font-size:13px;color:#64748b;margin:0}
.pf-dialog{border:0;border-radius:12px;max-width:520px;width:calc(100% - 30px);padding:22px;box-shadow:0 20px 65px rgba(0,0,0,.25);color:#172b4d}
.pf-dialog::backdrop{background:rgba(15,23,42,.55)}
.pf-dialog h3{margin:0 0 8px;font-size:18px}
.pf-dialog p{margin:0 0 8px;font-size:14px;line-height:1.5}
.pf-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px;flex-wrap:wrap}
.pf-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
@media(max-width:768px){.main-content{padding:14px}.pf-card{padding:16px}.pf-card h1{font-size:21px}.pf-fields{grid-template-columns:1fr}.pf-fields .pf-button{width:100%}.pf-actions .pf-button{width:100%}.pf-table{font-size:13px}.pf-table th,.pf-table td{padding:8px 10px}.pf-table-wrap{max-height:60vh}.pf-dialog-actions .pf-button{width:100%}}
</style></head><body>
<div class="dashboard-container"><?php include __DIR__ . '/../includes/admin_sidebar.php'; ?><main class="main-content">

<section class="pf-card">
<h1>Hide Pending/Unpaid Orders</h1>
<p class="pf-lead">This temporarily removes matching pending and unpaid orders from operational order lists. No orders or customer data are deleted.</p>
<ul class="pf-rules">
<li>Dates are interpreted in Manila time and apply to the order date; both days are inclusive.</li>
<li>Only orders with status <strong>Pending</strong> and payment status <strong>Unpaid</strong> are matched.</li>
<li>Orders linked to a payment submission, provider payment, production activity, inventory transaction, receipt, or completed job are skipped automatically.</li>
<li>Hiding writes to the existing archive visibility map only. Running it again for the same range hides nothing new.</li>
</ul>
</section>

<?php if (!$ready): ?>
<section class="pf-card"><div class="pf-status error" role="alert">Archive storage is unavailable. The existing order-archive tables could not be found, so nothing can be previewed or hidden. No change was made.</div></section>
<?php else: ?>
<section class="pf-card" aria-labelledby="pfStepRange">
<div class="pf-step"><span class="pf-step-num">1</span><h2 id="pfStepRange" style="margin:0">Choose a date range</h2></div>
<form id="rangeForm" class="pf-fields" novalidate>
<label for="startDate">Start date<input id="startDate" name="start_date" type="date" value="<?= $defaultStart ?>" required></label>
<label for="endDate">End date<input id="endDate" name="end_date" type="date" value="<?= $defaultEnd ?>" required></label>
<button class="pf-button" id="previewButton" type="submit"><span class="pf-spinner" aria-hidden="true"></span><span class="pf-button-label">Preview Orders</span></button>
</form>
<p class="pf-muted">Default range: September 1 – October 15, 2026. Change the dates and preview again at any time.</p>
<div id="status" class="pf-status info" role="status" aria-live="polite">Choose dates and select <strong>Preview Orders</strong>. Nothing is hidden until you confirm the preview.</div>
</section>

<section class="pf-card" id="previewCard" hidden aria-labelledby="pfStepPreview">
<div class="pf-step"><span class="pf-step-num">2</span><h2 id="pfStepPreview" style="margin:0">Review the matching orders</h2></div>
<div class="pf-summary"><span class="pf-count"><strong id="matchCount">0</strong><span>matching order(s)</span></span><span id="rangeLabel" class="pf-muted" style="margin:0"></span></div>
<p class="pf-ids" id="idList"></p>
<div id="preview" class="pf-table-wrap"></div>
<div class="pf-actions">
<button class="pf-button pf-danger" id="hideButton" type="button" disabled><span class="pf-spinner" aria-hidden="true"></span><span class="pf-button-label">Hide Matching Orders</span></button>
<p class="pf-hint">Hides exactly the orders listed above. You will be asked to confirm first.</p>
</div>
</section>

<section class="pf-card" id="resultCard" hidden aria-labelledby="pfStepResult">
<div class="pf-step"><span class="pf-step-num">3</span><h2 id="pfStepResult" style="margin:0">Result</h2></div>
<div id="result" class="pf-status success" role="status" aria-live="polite"></div>
</section>

<dialog class="pf-dialog" id="confirmDialog" aria-labelledby="confirmTitle">
<h3 id="confirmTitle">Hide these orders?</h3>
<p id="confirmBody"></p>
<p class="pf-muted" style="margin:0">Orders stay in the database with their payments, files, and history intact. They are only removed from operational order lists.</p>
<div class="pf-dialog-actions"><button class="pf-button pf-secondary" id="confirmCancel" type="button">Cancel</button><button class="pf-button pf-danger" id="confirmApply" type="button">Yes, hide them</button></div>
</dialog>
<?php endif; ?>
</main></div>
<?php if ($ready): ?><script>
(() => {
  const api = 'api_temporary_pending_unpaid_archive.php';
  const $ = id => document.getElementById(id);
  const form = $('rangeForm'), start = $('startDate'), end = $('endDate');
  const previewButton = $('previewButton'), hideButton = $('hideButton');
  const status = $('status'), previewCard = $('previewCard'), preview = $('preview');
  const matchCount = $('matchCount'), rangeLabel = $('rangeLabel'), idList = $('idList');
  const resultCard = $('resultCard'), result = $('result');
  const dialog = $('confirmDialog'), confirmBody = $('confirmBody'), confirmCancel = $('confirmCancel'), confirmApply = $('confirmApply');
  let current = null;
  let csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const esc = x => String(x ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const fmtDate = s => { const d = new Date(String(s).replace(' ', 'T') + '+08:00'); return isNaN(d) ? esc(s) : d.toLocaleString('en-PH', {timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}); };
  const niceRange = (a, b) => { const f = s => new Date(s + 'T00:00:00+08:00').toLocaleDateString('en-PH', {timeZone: 'Asia/Manila', year: 'numeric', month: 'long', day: 'numeric'}); return `${f(a)} – ${f(b)} (Manila time)`; };
  const setStatus = (kind, html) => { status.className = 'pf-status ' + kind; status.innerHTML = html; };
  const setLoading = (btn, on) => { btn.classList.toggle('is-loading', on); btn.disabled = on; btn.setAttribute('aria-busy', on ? 'true' : 'false'); };

  async function request(url, options = {}) {
    const r = await fetch(url, {credentials: 'same-origin', cache: 'no-store', ...options});
    let body;
    try { body = await r.json(); } catch { throw new Error(`The server returned an unreadable response (HTTP ${r.status}).`); }
    if (!r.ok || body.success !== true) throw new Error(body.error || `Request failed (HTTP ${r.status}).`);
    return body;
  }

  function resetPreview() {
    current = null;
    hideButton.disabled = true;
    previewCard.hidden = true;
    preview.replaceChildren();
    idList.textContent = '';
  }

  function validRange() {
    if (!start.value || !end.value) { setStatus('error', 'Enter both a start date and an end date.'); return false; }
    if (start.value > end.value) { setStatus('error', 'The start date must be on or before the end date.'); return false; }
    return true;
  }

  function renderPreview(b) {
    const rows = b.orders || [];
    matchCount.textContent = String(b.count);
    rangeLabel.textContent = niceRange(b.start_date, b.end_date);
    if (!rows.length) {
      idList.textContent = '';
      preview.innerHTML = '<div class="pf-empty">No pending/unpaid orders match this date range. Nothing to hide.</div>';
    } else {
      idList.innerHTML = 'Exact order IDs: ' + b.order_ids.map(id => `<code>#${Number(id)}</code>`).join(' ');
      preview.innerHTML = `<table class="pf-table"><thead><tr><th>Order ID</th><th>Customer</th><th>Order date (Manila)</th><th>Branch</th><th>Total</th><th>Status</th><th>Payment</th></tr></thead><tbody>${rows.map(o => `<tr><td><strong>#${Number(o.order_id)}</strong></td><td>${esc(o.customer_name || '—')}</td><td>${fmtDate(o.order_date)}</td><td>${esc(o.branch_id ?? '—')}</td><td class="num">${peso(o.total_amount)}</td><td><span class="pf-chip pending">${esc(o.status)}</span></td><td><span class="pf-chip unpaid">${esc(o.payment_status)}</span></td></tr>`).join('')}</tbody></table>`;
    }
    previewCard.hidden = false;
    hideButton.disabled = rows.length < 1;
  }

  async function loadPreview(afterApply = null) {
    resetPreview();
    if (!validRange()) return;
    setLoading(previewButton, true);
    setStatus('loading', 'Checking current order, payment, production, and inventory records…');
    try {
      const q = new URLSearchParams({start_date: start.value, end_date: end.value});
      const b = await request(`${api}?${q}`);
      current = b;
      csrf = b.csrf_token || csrf;
      renderPreview(b);
      if (b.count > 0) {
        setStatus('success', `<strong>${b.count}</strong> matching order(s) found. Review the list below, then select <strong>Hide Matching Orders</strong>.`);
      } else if (afterApply) {
        setStatus('success', 'The refreshed preview now shows 0 matching orders for this range.');
      } else {
        setStatus('info', 'No matching orders in this range. Try different dates, or nothing needs to be hidden.');
      }
    } catch (e) {
      setStatus('error', `Preview failed: ${esc(e.message)}`);
    } finally {
      setLoading(previewButton, false);
    }
  }

  function openConfirm() {
    if (!current || current.count < 1) return;
    confirmBody.innerHTML = `You are about to hide <strong>${current.count}</strong> order(s) dated ${esc(niceRange(current.start_date, current.end_date))}:<br>${current.order_ids.map(id => `<code>#${Number(id)}</code>`).join(' ')}`;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else if (window.confirm(confirmBody.textContent)) apply();
  }

  async function apply() {
    if (!current || current.count < 1) return;
    const snapshot = current;
    setLoading(hideButton, true);
    previewButton.disabled = true;
    resultCard.hidden = true;
    setStatus('loading', 'Re-checking the previewed orders and applying archive visibility…');
    try {
      const b = await request(api, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({start_date: snapshot.start_date, end_date: snapshot.end_date, order_ids: snapshot.order_ids, preview_token: snapshot.preview_token, csrf_token: csrf})});
      result.className = 'pf-status success';
      result.innerHTML = `<strong>${b.count}</strong> order(s) hidden from operational lists. Order IDs: ${b.order_ids.map(id => `<code>#${Number(id)}</code>`).join(' ') || 'none'}.<br>Batch reference: <code>${esc(b.batch_id)}</code>. No order, payment, or customer records were changed.`;
      resultCard.hidden = false;
      resultCard.scrollIntoView({behavior: 'smooth', block: 'nearest'});
      await loadPreview(true);
    } catch (e) {
      result.className = 'pf-status error';
      result.textContent = `Hide action failed: ${e.message}. No orders were hidden.`;
      resultCard.hidden = false;
      setStatus('error', 'The hide action did not complete. Preview again before retrying.');
      resetPreview();
    } finally {
      setLoading(hideButton, false);
      hideButton.disabled = !(current && current.count > 0);
      previewButton.disabled = false;
    }
  }

  form.addEventListener('submit', e => { e.preventDefault(); loadPreview(); });
  hideButton.addEventListener('click', openConfirm);
  confirmCancel.addEventListener('click', () => dialog.close());
  confirmApply.addEventListener('click', () => { dialog.close(); apply(); });
  start.addEventListener('change', resetPreview);
  end.addEventListener('change', resetPreview);
})();
</script><?php endif; ?></body></html>
