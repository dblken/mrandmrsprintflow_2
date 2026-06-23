<?php
/**
 * Staff: Customizations V2  (BRAND NEW IMPLEMENTATION)
 * --------------------------------------------------------------------------
 * Clean-architecture rebuild of the customizations review screen.
 *
 *   Page (this file)  ->  staff/api/customizations_v2.php  (JSON)
 *                     ->  includes/CustomizationService.php (logic + parser)
 *                     ->  includes/CustomizationRepository.php (data access)
 *
 * Goal: display EXACTLY what the customer submitted (online OR POS), reading
 * order_items as the source of truth and decoding customization_data with a
 * universal parser — no hard-coded fields, no whitelists.
 *
 * The legacy staff/customizations.php is intentionally left UNTOUCHED and this
 * page is NOT linked anywhere yet (kept hidden until V2 is verified).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : ''));
}

require_role(['Admin', 'Staff', 'Manager']);
if (function_exists('printflow_require_staff_module')) {
    printflow_require_staff_module('customizations');
}

$page_title = 'Customizations V2 - PrintFlow';
$csrf_token = generate_csrf_token();
$api_base = BASE_URL . '/staff/api/customizations_v2.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-visit-control" content="reload">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        :root {
            --cv2-bg: #0a2530;
            --cv2-accent: #53c5e0;
            --cv2-line: rgba(83,197,224,0.18);
        }
        .cv2-toolbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:20px; }
        .cv2-tabs { display:inline-flex; background:#f1f5f9; border-radius:12px; padding:4px; gap:4px; }
        .cv2-tab { border:none; background:transparent; padding:8px 16px; border-radius:9px; font-weight:700; font-size:13px; color:#475569; cursor:pointer; transition:all .15s; display:flex; align-items:center; gap:6px; }
        .cv2-tab .cv2-count { background:#e2e8f0; color:#475569; border-radius:999px; font-size:11px; padding:1px 8px; font-weight:700; }
        .cv2-tab.active { background:#fff; color:#0a2530; box-shadow:0 2px 6px rgba(0,0,0,0.08); }
        .cv2-tab.active .cv2-count { background:var(--cv2-accent); color:#fff; }
        .cv2-search { position:relative; }
        .cv2-search input { width:280px; max-width:60vw; padding:10px 14px 10px 38px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; outline:none; transition:border-color .15s; }
        .cv2-search input:focus { border-color:var(--cv2-accent); }
        .cv2-search svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:#94a3b8; }

        .cv2-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:18px; }
        .cv2-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; cursor:pointer; transition:transform .15s, box-shadow .15s, border-color .15s; display:flex; flex-direction:column; }
        .cv2-card:hover { transform:translateY(-3px); box-shadow:0 12px 28px rgba(2,12,18,0.12); border-color:var(--cv2-accent); }
        .cv2-card-top { display:flex; gap:14px; padding:16px; }
        .cv2-thumb { width:74px; height:74px; border-radius:12px; object-fit:cover; background:#0a2530; flex-shrink:0; border:1px solid var(--cv2-line); }
        .cv2-thumb-fallback { width:74px; height:74px; border-radius:12px; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#0a2530,#0f3340); color:var(--cv2-accent); font-weight:800; font-size:24px; }
        .cv2-card-title { font-size:15px; font-weight:800; color:#0f172a; line-height:1.3; margin:0 0 4px; }
        .cv2-card-meta { font-size:12px; color:#64748b; display:flex; flex-wrap:wrap; gap:6px 12px; }
        .cv2-pill { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; padding:2px 8px; border-radius:999px; }
        .cv2-pill-online { background:#e0f2fe; color:#0369a1; }
        .cv2-pill-pos { background:#fef3c7; color:#b45309; }
        .cv2-card-foot { margin-top:auto; padding:10px 16px; border-top:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; background:#fbfdff; }
        .cv2-status { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; padding:3px 10px; border-radius:999px; background:#f1f5f9; color:#475569; }
        .cv2-order-id { font-size:12px; color:#94a3b8; font-weight:700; }

        .cv2-empty, .cv2-loading { text-align:center; padding:60px 20px; color:#94a3b8; font-size:15px; }
        .cv2-spinner { width:34px; height:34px; border:3px solid #e2e8f0; border-top-color:var(--cv2-accent); border-radius:50%; animation:cv2spin .8s linear infinite; margin:0 auto 14px; }
        @keyframes cv2spin { to { transform:rotate(360deg); } }

        /* Detail drawer */
        .cv2-overlay { position:fixed; inset:0; background:rgba(2,12,18,0.55); backdrop-filter:blur(3px); z-index:9000; display:none; }
        .cv2-overlay.open { display:block; }
        .cv2-drawer { position:fixed; top:0; right:0; height:100%; width:min(680px, 100vw); background:#f8fafc; z-index:9001; transform:translateX(100%); transition:transform .28s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; box-shadow:-12px 0 40px rgba(0,0,0,0.25); }
        .cv2-drawer.open { transform:translateX(0); }
        .cv2-drawer-head { padding:18px 22px; background:#0a2530; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:12px; }
        .cv2-drawer-head h2 { margin:0; font-size:18px; font-weight:800; }
        .cv2-drawer-head .cv2-sub { font-size:12px; color:#9fc4d4; margin-top:2px; }
        .cv2-close { background:rgba(255,255,255,0.12); border:none; color:#fff; width:36px; height:36px; border-radius:10px; cursor:pointer; font-size:20px; line-height:1; flex-shrink:0; }
        .cv2-close:hover { background:rgba(255,255,255,0.22); }
        .cv2-drawer-body { padding:20px 22px 30px; overflow-y:auto; flex:1; }
        .cv2-drawer-foot { padding:14px 22px; background:#fff; border-top:1px solid #e5e7eb; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }

        .cv2-section { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px; margin-bottom:16px; }
        .cv2-section h3 { margin:0 0 14px; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#0a2530; display:flex; align-items:center; gap:8px; }
        .cv2-section h3 .cv2-dot { width:7px; height:7px; border-radius:50%; background:var(--cv2-accent); }
        .cv2-kv { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:12px; }
        .cv2-kv-item .cv2-k { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin-bottom:3px; }
        .cv2-kv-item .cv2-v { font-size:14px; font-weight:700; color:#0f172a; word-break:break-word; }

        .cv2-item { border:1px solid #e5e7eb; border-radius:14px; overflow:hidden; margin-bottom:16px; background:#fff; }
        .cv2-item-head { background:#0a2530; color:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        .cv2-item-head .cv2-it-title { font-size:15px; font-weight:800; margin:0; }
        .cv2-item-head .cv2-it-cat { font-size:11px; font-weight:700; color:var(--cv2-accent); text-transform:uppercase; letter-spacing:.05em; }
        .cv2-item-body { padding:16px; }
        .cv2-specs { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); gap:10px; }
        .cv2-spec { background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:9px 11px; }
        .cv2-spec .cv2-sl { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.02em; color:#94a3b8; margin-bottom:3px; line-height:1.2; }
        .cv2-spec .cv2-sv { font-size:13.5px; font-weight:700; color:#0f172a; word-break:break-word; line-height:1.3; }
        .cv2-nospec { font-size:13px; color:#94a3b8; font-style:italic; }

        .cv2-media-row { display:flex; flex-wrap:wrap; gap:14px; margin-top:14px; }
        .cv2-media { flex:1 1 150px; }
        .cv2-media .cv2-ml { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:6px; }
        .cv2-media img { width:100%; max-width:200px; border-radius:10px; border:1px solid #e5e7eb; cursor:zoom-in; background:#f1f5f9; display:block; transition:transform .15s; }
        .cv2-media img:hover { transform:scale(1.02); }

        .cv2-notes { margin-top:14px; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:10px; padding:12px 14px; }
        .cv2-notes .cv2-nl { font-size:11px; font-weight:800; text-transform:uppercase; color:#92400e; margin-bottom:5px; }
        .cv2-notes .cv2-nv { font-size:13.5px; color:#b45309; line-height:1.5; font-weight:600; white-space:pre-wrap; word-break:break-word; }

        .cv2-btn { border:none; border-radius:10px; padding:10px 18px; font-size:13px; font-weight:800; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:7px; }
        .cv2-btn:disabled { opacity:.55; cursor:not-allowed; }
        .cv2-btn-approve { background:#16a34a; color:#fff; }
        .cv2-btn-approve:hover:not(:disabled) { background:#15803d; }
        .cv2-btn-revise { background:#f59e0b; color:#fff; }
        .cv2-btn-revise:hover:not(:disabled) { background:#d97706; }
        .cv2-btn-close { background:#e2e8f0; color:#334155; }
        .cv2-btn-close:hover:not(:disabled) { background:#cbd5e1; }

        .cv2-revise-panel { display:none; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:14px; margin-bottom:14px; }
        .cv2-revise-panel.open { display:block; }
        .cv2-revise-panel select, .cv2-revise-panel textarea { width:100%; padding:9px 11px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; margin-bottom:10px; outline:none; box-sizing:border-box; }
        .cv2-revise-panel label { font-size:12px; font-weight:800; color:#9a3412; display:block; margin-bottom:6px; }

        .cv2-banner { border-radius:10px; padding:11px 14px; font-size:13px; font-weight:700; margin-bottom:14px; display:none; }
        .cv2-banner.show { display:block; }
        .cv2-banner-ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
        .cv2-banner-err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
        .cv2-banner-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }

        .cv2-lightbox { position:fixed; inset:0; background:rgba(2,12,18,0.85); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; cursor:zoom-out; }
        .cv2-lightbox.open { display:flex; }
        .cv2-lightbox img { max-width:94vw; max-height:92vh; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.5); }

        @media (max-width:640px) {
            .cv2-grid { grid-template-columns:1fr; }
            .cv2-search input { width:100%; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Customizations <span style="font-size:12px; background:#53c5e0; color:#fff; padding:3px 9px; border-radius:999px; vertical-align:middle; margin-left:6px;">V2</span></h1>
            <p style="color:#64748b; font-size:14px; margin-top:4px;">Reviews exactly what the customer submitted — online &amp; POS.</p>
        </header>

        <main>
            <div class="cv2-toolbar">
                <div class="cv2-tabs" id="cv2Tabs">
                    <button class="cv2-tab active" data-source="all">All <span class="cv2-count" data-count="all">0</span></button>
                    <button class="cv2-tab" data-source="online">Online <span class="cv2-count" data-count="online">0</span></button>
                    <button class="cv2-tab" data-source="pos">POS <span class="cv2-count" data-count="pos">0</span></button>
                </div>
                <div class="cv2-search">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="cv2Search" placeholder="Search by name, service, order #…" autocomplete="off">
                </div>
            </div>

            <div id="cv2List">
                <div class="cv2-loading"><div class="cv2-spinner"></div>Loading customizations…</div>
            </div>
        </main>
    </div>
</div>

<!-- Detail drawer -->
<div class="cv2-overlay" id="cv2Overlay" onclick="CV2.closeDrawer()"></div>
<div class="cv2-drawer" id="cv2Drawer" aria-hidden="true">
    <div class="cv2-drawer-head">
        <div>
            <h2 id="cv2DrawerTitle">Order</h2>
            <div class="cv2-sub" id="cv2DrawerSub"></div>
        </div>
        <button class="cv2-close" onclick="CV2.closeDrawer()" aria-label="Close">&times;</button>
    </div>
    <div class="cv2-drawer-body" id="cv2DrawerBody"></div>
    <div class="cv2-drawer-foot" id="cv2DrawerFoot"></div>
</div>

<!-- Lightbox -->
<div class="cv2-lightbox" id="cv2Lightbox" onclick="this.classList.remove('open')">
    <img id="cv2LightboxImg" src="" alt="Preview">
</div>

<script>
const CV2 = (function () {
    const API = <?php echo json_encode($api_base); ?>;
    const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    let allRows = [];
    let currentSource = 'all';
    let currentDetail = null;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return esc(s);
        return d.toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
    }

    function initials(name) {
        const parts = String(name || 'C').trim().split(/\s+/);
        return (parts[0]?.[0] || 'C').toUpperCase();
    }

    async function loadList() {
        const list = document.getElementById('cv2List');
        list.innerHTML = '<div class="cv2-loading"><div class="cv2-spinner"></div>Loading customizations…</div>';
        try {
            const res = await fetch(`${API}?action=list&source=all&_=${Date.now()}`, { cache: 'no-store' });
            const json = await res.json();
            if (!json.success) throw new Error(json.message || 'Failed to load');
            allRows = json.data || [];
            updateCounts();
            render();
        } catch (e) {
            list.innerHTML = `<div class="cv2-empty">Could not load customizations.<br><small>${esc(e.message)}</small></div>`;
        }
    }

    function updateCounts() {
        const counts = { all: allRows.length, online: 0, pos: 0 };
        allRows.forEach(r => r.is_pos ? counts.pos++ : counts.online++);
        document.querySelectorAll('.cv2-count').forEach(el => {
            el.textContent = counts[el.getAttribute('data-count')] ?? 0;
        });
    }

    function filtered() {
        const q = document.getElementById('cv2Search').value.trim().toLowerCase();
        return allRows.filter(r => {
            if (currentSource === 'online' && r.is_pos) return false;
            if (currentSource === 'pos' && !r.is_pos) return false;
            if (q) {
                const hay = `${r.title} ${r.service_name} ${r.customer_name} ${r.order_id} ${r.category}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }

    function render() {
        const list = document.getElementById('cv2List');
        const rows = filtered();
        if (!rows.length) {
            list.innerHTML = '<div class="cv2-empty">No customizations found.</div>';
            return;
        }
        const grid = document.createElement('div');
        grid.className = 'cv2-grid';
        rows.forEach(r => grid.appendChild(card(r)));
        list.innerHTML = '';
        list.appendChild(grid);
    }

    function card(r) {
        const el = document.createElement('div');
        el.className = 'cv2-card';
        el.onclick = () => openDrawer(r.order_id);
        const thumb = r.thumb_url
            ? `<img class="cv2-thumb" src="${esc(r.thumb_url)}" alt="" onerror="this.outerHTML='<div class=\\'cv2-thumb-fallback\\'>${esc(initials(r.title))}</div>'">`
            : `<div class="cv2-thumb-fallback">${esc(initials(r.title))}</div>`;
        const pill = r.is_pos
            ? '<span class="cv2-pill cv2-pill-pos">POS</span>'
            : '<span class="cv2-pill cv2-pill-online">Online</span>';
        const extra = r.extra_items > 0 ? ` <span style="color:#94a3b8;">+${r.extra_items} more</span>` : '';
        el.innerHTML = `
            <div class="cv2-card-top">
                ${thumb}
                <div style="min-width:0; flex:1;">
                    <h4 class="cv2-card-title">${esc(r.title)}${extra}</h4>
                    <div class="cv2-card-meta">
                        ${pill}
                        <span>👤 ${esc(r.customer_name)}</span>
                        <span>🔢 Qty: ${esc(r.quantity)}</span>
                        ${r.branch_name ? `<span>📍 ${esc(r.branch_name)}</span>` : ''}
                    </div>
                </div>
            </div>
            <div class="cv2-card-foot">
                <span class="cv2-order-id">Order #${esc(r.order_id)}</span>
                <span class="cv2-status">${esc(r.status || '—')}</span>
            </div>`;
        return el;
    }

    async function openDrawer(orderId) {
        const drawer = document.getElementById('cv2Drawer');
        const overlay = document.getElementById('cv2Overlay');
        const body = document.getElementById('cv2DrawerBody');
        const foot = document.getElementById('cv2DrawerFoot');
        document.getElementById('cv2DrawerTitle').textContent = `Order #${orderId}`;
        document.getElementById('cv2DrawerSub').textContent = 'Loading…';
        body.innerHTML = '<div class="cv2-loading"><div class="cv2-spinner"></div>Loading details…</div>';
        foot.innerHTML = '';
        overlay.classList.add('open');
        drawer.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');

        try {
            const res = await fetch(`${API}?action=detail&order_id=${encodeURIComponent(orderId)}&_=${Date.now()}`, { cache: 'no-store' });
            const json = await res.json();
            if (!json.success) throw new Error(json.message || 'Failed');
            currentDetail = json.data;
            renderDetail(json.data);
        } catch (e) {
            body.innerHTML = `<div class="cv2-empty">Could not load order.<br><small>${esc(e.message)}</small></div>`;
        }
    }

    function renderDetail(d) {
        document.getElementById('cv2DrawerSub').textContent =
            `${d.source_label} · ${fmtDate(d.order_date)}`;

        const c = d.customer;
        let html = '<div class="cv2-banner" id="cv2Banner"></div>';

        // Customer details
        html += `<div class="cv2-section">
            <h3><span class="cv2-dot"></span>Customer Details</h3>
            <div class="cv2-kv">
                <div class="cv2-kv-item"><div class="cv2-k">Name</div><div class="cv2-v">${esc(c.name)}</div></div>
                ${c.contact ? `<div class="cv2-kv-item"><div class="cv2-k">Contact</div><div class="cv2-v">${esc(c.contact)}</div></div>` : ''}
                ${c.email ? `<div class="cv2-kv-item"><div class="cv2-k">Email</div><div class="cv2-v">${esc(c.email)}</div></div>` : ''}
                ${c.type ? `<div class="cv2-kv-item"><div class="cv2-k">Customer Type</div><div class="cv2-v">${esc(c.type)}</div></div>` : ''}
                ${c.address ? `<div class="cv2-kv-item" style="grid-column:1/-1;"><div class="cv2-k">Address</div><div class="cv2-v">${esc(c.address)}</div></div>` : ''}
            </div>
        </div>`;

        // Order details
        const price = d.estimated_price > 0 ? d.estimated_price : d.total_amount;
        html += `<div class="cv2-section">
            <h3><span class="cv2-dot"></span>Order Details</h3>
            <div class="cv2-kv">
                <div class="cv2-kv-item"><div class="cv2-k">Order #</div><div class="cv2-v">${esc(d.order_id)}</div></div>
                <div class="cv2-kv-item"><div class="cv2-k">Status</div><div class="cv2-v">${esc(d.status || '—')}</div></div>
                <div class="cv2-kv-item"><div class="cv2-k">Payment</div><div class="cv2-v">${esc(d.payment_status || '—')}</div></div>
                <div class="cv2-kv-item"><div class="cv2-k">Source</div><div class="cv2-v">${esc(d.source_label)}</div></div>
                ${d.branch.name ? `<div class="cv2-kv-item"><div class="cv2-k">Branch</div><div class="cv2-v">${esc(d.branch.name)}</div></div>` : ''}
                ${d.needed_date ? `<div class="cv2-kv-item"><div class="cv2-k">Needed Date</div><div class="cv2-v">${esc(d.needed_date)}</div></div>` : ''}
                <div class="cv2-kv-item"><div class="cv2-k">Placed</div><div class="cv2-v">${fmtDate(d.order_date)}</div></div>
                ${price > 0 ? `<div class="cv2-kv-item"><div class="cv2-k">${d.estimated_price > 0 ? 'Estimated' : 'Total'}</div><div class="cv2-v">₱${Number(price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div></div>` : ''}
            </div>
            ${d.revision_reason ? `<div class="cv2-notes" style="background:#eff6ff;border-color:#bfdbfe;border-left-color:#3b82f6;"><div class="cv2-nl" style="color:#1d4ed8;">Revision Requested</div><div class="cv2-nv" style="color:#1e40af;">${esc(d.revision_reason)}</div></div>` : ''}
        </div>`;

        // Items
        (d.items || []).forEach((it, idx) => {
            html += renderItem(it, (d.items.length > 1) ? (idx + 1) : 0);
        });

        // Global order notes
        if (d.order_notes) {
            html += `<div class="cv2-section"><h3><span class="cv2-dot"></span>Order Notes</h3>
                <div class="cv2-notes"><div class="cv2-nl">Customer Notes</div><div class="cv2-nv">${esc(d.order_notes)}</div></div></div>`;
        }

        // Revision panel (hidden until requested)
        html += `<div class="cv2-revise-panel" id="cv2RevisePanel">
            <label>Reason for revision</label>
            <select id="cv2ReviseSelect" onchange="CV2.onReviseSelect()">
                <option value="">Select a reason…</option>
                <option>Design quality is too low / blurry</option>
                <option>Incorrect dimensions or specifications</option>
                <option>Missing required information</option>
                <option>File format not supported</option>
                <option value="__other">Other (specify)</option>
            </select>
            <textarea id="cv2ReviseText" rows="3" placeholder="Add details for the customer…" style="display:none;"></textarea>
        </div>`;

        document.getElementById('cv2DrawerBody').innerHTML = html;
        renderFooter(d);
    }

    function renderItem(it, num) {
        const title = num ? `${num}. ${esc(it.name)}` : esc(it.name);
        let specs = '';
        if (it.specs && it.specs.length) {
            specs = '<div class="cv2-specs">' + it.specs.map(s =>
                `<div class="cv2-spec"><div class="cv2-sl">${esc(s.label)}</div><div class="cv2-sv">${esc(s.value)}</div></div>`
            ).join('') + '</div>';
        } else {
            specs = '<div class="cv2-nospec">No specifications submitted.</div>';
        }

        let media = '';
        const blocks = [];
        if (it.has_design && it.design_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Uploaded Design</div><img src="${esc(it.design_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        if (it.has_reference && it.reference_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Reference Image</div><img src="${esc(it.reference_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        if (it.product_image_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Product / Service Image</div><img src="${esc(it.product_image_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        if (blocks.length) media = `<div class="cv2-media-row">${blocks.join('')}</div>`;

        const notes = it.notes
            ? `<div class="cv2-notes"><div class="cv2-nl">Special Instructions &amp; Notes</div><div class="cv2-nv">${esc(it.notes)}</div></div>`
            : '';

        return `<div class="cv2-item">
            <div class="cv2-item-head">
                <div style="flex:1; min-width:0;">
                    <div class="cv2-it-cat">${esc(it.category)}</div>
                    <h4 class="cv2-it-title">${title}</h4>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; color:#9fc4d4;">Qty</div>
                    <div style="font-size:16px; font-weight:800;">${esc(it.quantity)}</div>
                </div>
            </div>
            <div class="cv2-item-body">
                ${specs}
                ${media}
                ${notes}
            </div>
        </div>`;
    }

    function renderFooter(d) {
        const foot = document.getElementById('cv2DrawerFoot');
        foot.innerHTML = `
            <button class="cv2-btn cv2-btn-revise" onclick="CV2.toggleRevise()">↩ Request Revision</button>
            <button class="cv2-btn cv2-btn-close" onclick="CV2.act('close')">✓ Close</button>
            <button class="cv2-btn cv2-btn-approve" onclick="CV2.act('approve')">✓ Approve</button>`;
    }

    function onReviseSelect() {
        const sel = document.getElementById('cv2ReviseSelect');
        const txt = document.getElementById('cv2ReviseText');
        txt.style.display = (sel.value === '__other') ? 'block' : 'none';
    }

    function toggleRevise() {
        const panel = document.getElementById('cv2RevisePanel');
        const open = panel.classList.toggle('open');
        if (open) {
            // Second click on the button confirms submission.
            const btn = document.querySelector('.cv2-btn-revise');
            btn.textContent = '↩ Submit Revision Request';
            btn.onclick = () => CV2.submitRevise();
            panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function submitRevise() {
        const sel = document.getElementById('cv2ReviseSelect');
        let reason = sel.value;
        if (reason === '__other') reason = document.getElementById('cv2ReviseText').value.trim();
        if (!reason) { banner('Please choose or enter a revision reason.', 'err'); return; }
        act('request_revision', { reason });
    }

    async function act(action, extra = {}) {
        if (!currentDetail) return;
        const orderId = currentDetail.order_id;
        const btns = document.querySelectorAll('.cv2-drawer-foot .cv2-btn');
        btns.forEach(b => b.disabled = true);
        banner('Working…', 'info');
        try {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('order_id', orderId);
            fd.append('csrf_token', CSRF);
            Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
            const res = await fetch(API, { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.success) throw new Error(json.message || 'Action failed');
            banner(json.message || 'Done.', 'ok');
            setTimeout(() => { closeDrawer(); loadList(); }, 700);
        } catch (e) {
            banner(e.message, 'err');
            btns.forEach(b => b.disabled = false);
        }
    }

    function banner(msg, kind) {
        const el = document.getElementById('cv2Banner');
        if (!el) return;
        el.className = 'cv2-banner show cv2-banner-' + (kind === 'ok' ? 'ok' : kind === 'err' ? 'err' : 'info');
        el.textContent = msg;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeDrawer() {
        document.getElementById('cv2Overlay').classList.remove('open');
        const drawer = document.getElementById('cv2Drawer');
        drawer.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        currentDetail = null;
    }

    function zoom(src) {
        document.getElementById('cv2LightboxImg').src = src;
        document.getElementById('cv2Lightbox').classList.add('open');
    }

    function bind() {
        document.getElementById('cv2Tabs').addEventListener('click', (e) => {
            const tab = e.target.closest('.cv2-tab');
            if (!tab) return;
            document.querySelectorAll('.cv2-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentSource = tab.getAttribute('data-source');
            render();
        });
        document.getElementById('cv2Search').addEventListener('input', render);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.getElementById('cv2Lightbox').classList.remove('open');
                closeDrawer();
            }
        });

        // Deep-link support: ?order_id=123 opens the drawer directly.
        const params = new URLSearchParams(location.search);
        const deepId = parseInt(params.get('order_id') || '0', 10);
        loadList().then(() => { if (deepId > 0) openDrawer(deepId); });
    }

    document.addEventListener('DOMContentLoaded', bind);

    return { openDrawer, closeDrawer, act, toggleRevise, submitRevise, onReviseSelect, zoom };
})();
</script>
</body>
</html>
