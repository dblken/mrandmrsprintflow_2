<?php
/**
 * Staff: Customizations V2  (BRAND NEW IMPLEMENTATION)
 * --------------------------------------------------------------------------
 * Clean-architecture rebuild of the customizations review screen. The layout
 * intentionally mirrors the legacy staff/customizations.php (KPI cards + a
 * tabbed "Customization List" table with a View action) so the workflow feels
 * identical — but every record is powered by the corrected V2 backend:
 *
 *   Page (this file)  ->  staff/api/customizations_v2.php  (JSON detail/actions)
 *                     ->  includes/CustomizationService.php (universal parser)
 *                     ->  includes/CustomizationRepository.php (data access)
 *
 * Source of truth = order_items (online + POS), decoded with a universal
 * parser so ALL specifications render — fixing the legacy page where many
 * specs disappeared. The legacy staff/customizations.php is left UNTOUCHED.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/CustomizationService.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : ''));
}

require_role(['Admin', 'Staff', 'Manager']);
if (function_exists('printflow_require_staff_module')) {
    printflow_require_staff_module('customizations');
}

$page_title = 'Customizations V2 - PrintFlow';
$csrf_token = generate_csrf_token();
$api_base   = BASE_URL . '/staff/api/customizations_v2.php';

$branchFilter = function_exists('printflow_branch_filter_for_user')
    ? printflow_branch_filter_for_user()
    : null;

$service = new CustomizationService();
$rows = $service->listOrderSummaries($branchFilter, null, 400);

// ---- Aggregate counts for KPI cards & list tabs --------------------------
$buckets = ['INQUIRY' => 0, 'PAYMENT' => 0, 'PRODUCTION' => 0, 'TO_PICKUP' => 0, 'COMPLETED' => 0, 'CANCELLED' => 0];
foreach ($rows as $r) {
    $b = $r['status_bucket'] ?? 'INQUIRY';
    if (isset($buckets[$b])) {
        $buckets[$b]++;
    }
}
$totalRows = count($rows);

$tabs = [
    'all'        => ['label' => 'All',               'count' => $totalRows],
    'INQUIRY'    => ['label' => 'Inquiry & Design',  'count' => $buckets['INQUIRY']],
    'PAYMENT'    => ['label' => 'Payment',           'count' => $buckets['PAYMENT']],
    'PRODUCTION' => ['label' => 'Production',         'count' => $buckets['PRODUCTION']],
    'TO_PICKUP'  => ['label' => 'To Pickup',         'count' => $buckets['TO_PICKUP']],
    'COMPLETED'  => ['label' => 'Completed',         'count' => $buckets['COMPLETED']],
    'CANCELLED'  => ['label' => 'Cancelled',         'count' => $buckets['CANCELLED']],
];

function cv2_status_class(string $bucket): string
{
    return [
        'INQUIRY'    => 'cv2-badge-amber',
        'PAYMENT'    => 'cv2-badge-blue',
        'PRODUCTION' => 'cv2-badge-indigo',
        'TO_PICKUP'  => 'cv2-badge-cyan',
        'COMPLETED'  => 'cv2-badge-green',
        'CANCELLED'  => 'cv2-badge-red',
    ][$bucket] ?? 'cv2-badge-gray';
}

function cv2_fmt_date(string $raw): string
{
    $ts = strtotime($raw);
    return $ts ? date('M j, Y', $ts) : '—';
}
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
        :root { --cv2-accent:#53c5e0; }

        /* KPI cards */
        .cv2-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
        .cv2-kpi { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px 20px; border-top:3px solid #cbd5e1; }
        .cv2-kpi.k-inquiry { border-top-color:#f59e0b; }
        .cv2-kpi.k-payment { border-top-color:#3b82f6; }
        .cv2-kpi.k-production { border-top-color:#6366f1; }
        .cv2-kpi.k-cancelled { border-top-color:#ef4444; }
        .cv2-kpi-title { font-size:13px; font-weight:800; color:#0f172a; }
        .cv2-kpi-num { font-size:30px; font-weight:900; color:#0f172a; margin:6px 0 2px; line-height:1; }
        .cv2-kpi-sub { font-size:11.5px; color:#94a3b8; }
        @media (max-width:900px){ .cv2-kpis { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:520px){ .cv2-kpis { grid-template-columns:1fr; } }

        /* List card */
        .cv2-listcard { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; }
        .cv2-listhead { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; padding:18px 20px 0; }
        .cv2-listhead h2 { margin:0; font-size:17px; font-weight:800; color:#0f172a; }
        .cv2-search { position:relative; }
        .cv2-search input { width:260px; max-width:60vw; padding:9px 12px 9px 34px; border:1px solid #d1d5db; border-radius:9px; font-size:13.5px; outline:none; }
        .cv2-search input:focus { border-color:var(--cv2-accent); }
        .cv2-search svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#94a3b8; }

        .cv2-tabs { display:flex; flex-wrap:wrap; gap:4px; padding:14px 20px 0; border-bottom:1px solid #eef2f7; }
        .cv2-tab { border:none; background:transparent; padding:9px 14px; font-size:12.5px; font-weight:800; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; display:inline-flex; align-items:center; gap:7px; }
        .cv2-tab .cv2-c { background:#e2e8f0; color:#475569; font-size:11px; border-radius:999px; padding:1px 8px; font-weight:800; }
        .cv2-tab.active { color:#0a2530; border-bottom-color:var(--cv2-accent); }
        .cv2-tab.active .cv2-c { background:var(--cv2-accent); color:#fff; }

        .cv2-table { width:100%; border-collapse:collapse; }
        .cv2-table thead th { text-align:left; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; padding:14px 20px; border-bottom:1px solid #eef2f7; white-space:nowrap; }
        .cv2-table tbody td { padding:14px 20px; border-bottom:1px solid #f1f5f9; font-size:13.5px; color:#0f172a; vertical-align:middle; }
        .cv2-table tbody tr { transition:background .12s; }
        .cv2-table tbody tr:hover { background:#f8fafc; }
        .cv2-code { font-weight:800; color:#0f172a; }
        .cv2-info-main { font-weight:700; }
        .cv2-info-sub { font-size:11.5px; color:#94a3b8; margin-top:2px; }
        .cv2-src { display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; padding:1px 7px; border-radius:999px; margin-left:6px; }
        .cv2-src-online { background:#e0f2fe; color:#0369a1; }
        .cv2-src-pos { background:#fef3c7; color:#b45309; }

        .cv2-badge { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.02em; padding:4px 11px; border-radius:999px; white-space:nowrap; }
        .cv2-badge-amber { background:#fef3c7; color:#b45309; }
        .cv2-badge-blue { background:#dbeafe; color:#1d4ed8; }
        .cv2-badge-indigo { background:#e0e7ff; color:#4338ca; }
        .cv2-badge-cyan { background:#cffafe; color:#0e7490; }
        .cv2-badge-green { background:#dcfce7; color:#15803d; }
        .cv2-badge-red { background:#fee2e2; color:#b91c1c; }
        .cv2-badge-gray { background:#f1f5f9; color:#475569; }

        .cv2-view-btn { border:1px solid var(--cv2-accent); background:#fff; color:#0e7490; font-weight:800; font-size:12.5px; padding:7px 16px; border-radius:9px; cursor:pointer; transition:all .15s; }
        .cv2-view-btn:hover { background:var(--cv2-accent); color:#fff; }

        .cv2-empty { text-align:center; padding:50px 20px; color:#94a3b8; font-size:14px; }
        .cv2-table-wrap { overflow-x:auto; }

        /* Detail drawer */
        .cv2-overlay { position:fixed; inset:0; background:rgba(2,12,18,0.55); backdrop-filter:blur(3px); z-index:9000; display:none; }
        .cv2-overlay.open { display:block; }
        .cv2-drawer { position:fixed; top:0; right:0; height:100%; width:min(680px,100vw); background:#f8fafc; z-index:9001; transform:translateX(100%); transition:transform .28s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; box-shadow:-12px 0 40px rgba(0,0,0,0.25); }
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
        .cv2-kv { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
        .cv2-kv-item .cv2-k { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin-bottom:3px; }
        .cv2-kv-item .cv2-v { font-size:14px; font-weight:700; color:#0f172a; word-break:break-word; }

        .cv2-item { border:1px solid #e5e7eb; border-radius:14px; overflow:hidden; margin-bottom:16px; background:#fff; }
        .cv2-item-head { background:#0a2530; color:#fff; padding:14px 16px; display:flex; align-items:center; gap:12px; }
        .cv2-item-head .cv2-it-title { font-size:15px; font-weight:800; margin:0; }
        .cv2-item-head .cv2-it-cat { font-size:11px; font-weight:700; color:var(--cv2-accent); text-transform:uppercase; letter-spacing:.05em; }
        .cv2-item-body { padding:16px; }
        .cv2-specs { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
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

        .cv2-spinner { width:32px; height:32px; border:3px solid #e2e8f0; border-top-color:var(--cv2-accent); border-radius:50%; animation:cv2spin .8s linear infinite; margin:30px auto; }
        @keyframes cv2spin { to { transform:rotate(360deg); } }

        .cv2-lightbox { position:fixed; inset:0; background:rgba(2,12,18,0.85); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; cursor:zoom-out; }
        .cv2-lightbox.open { display:flex; }
        .cv2-lightbox img { max-width:94vw; max-height:92vh; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.5); }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Customizations <span style="font-size:12px; background:#53c5e0; color:#fff; padding:3px 9px; border-radius:999px; vertical-align:middle; margin-left:6px;">V2</span></h1>
            <p style="color:#64748b; font-size:14px; margin-top:4px;">Track and manage all custom jobs — shows exactly what the customer submitted (online &amp; POS).</p>
        </header>

        <main>
            <!-- KPI cards -->
            <div class="cv2-kpis">
                <div class="cv2-kpi k-inquiry">
                    <div class="cv2-kpi-title">Inquiry &amp; Design</div>
                    <div class="cv2-kpi-num"><?php echo $buckets['INQUIRY']; ?></div>
                    <div class="cv2-kpi-sub">Review, revisions, materials, pricing</div>
                </div>
                <div class="cv2-kpi k-payment">
                    <div class="cv2-kpi-title">Payment</div>
                    <div class="cv2-kpi-num"><?php echo $buckets['PAYMENT']; ?></div>
                    <div class="cv2-kpi-sub">To pay and for verification</div>
                </div>
                <div class="cv2-kpi k-production">
                    <div class="cv2-kpi-title">Production</div>
                    <div class="cv2-kpi-num"><?php echo $buckets['PRODUCTION']; ?></div>
                    <div class="cv2-kpi-sub">Printing, pickup, completed</div>
                </div>
                <div class="cv2-kpi k-cancelled">
                    <div class="cv2-kpi-title">Cancelled</div>
                    <div class="cv2-kpi-num"><?php echo $buckets['CANCELLED']; ?></div>
                    <div class="cv2-kpi-sub">Rejected or cancelled</div>
                </div>
            </div>

            <!-- Customization list -->
            <div class="cv2-listcard">
                <div class="cv2-listhead">
                    <h2>Customization List</h2>
                    <div class="cv2-search">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" id="cv2Search" placeholder="Search order #, name, customer…" autocomplete="off">
                    </div>
                </div>

                <div class="cv2-tabs" id="cv2Tabs">
                    <?php $first = true; foreach ($tabs as $key => $tab): ?>
                    <button class="cv2-tab <?php echo $first ? 'active' : ''; ?>" data-bucket="<?php echo htmlspecialchars($key); ?>">
                        <?php echo htmlspecialchars($tab['label']); ?>
                        <span class="cv2-c"><?php echo (int)$tab['count']; ?></span>
                    </button>
                    <?php $first = false; endforeach; ?>
                </div>

                <div class="cv2-table-wrap">
                    <table class="cv2-table">
                        <thead>
                            <tr>
                                <th>Order Code</th>
                                <th>Customization Info</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th>Created</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="cv2Body">
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="6"><div class="cv2-empty">No customizations found.</div></td></tr>
                            <?php else: foreach ($rows as $r):
                                $bucket = $r['status_bucket'] ?? 'INQUIRY';
                                $extra  = (int)($r['extra_items'] ?? 0);
                                $hay = strtolower(trim(($r['order_code'] ?? '') . ' ' . ($r['title'] ?? '') . ' ' . ($r['customer_name'] ?? '') . ' ' . ($r['order_id'] ?? '')));
                            ?>
                            <tr class="cv2-row"
                                data-bucket="<?php echo htmlspecialchars($bucket); ?>"
                                data-search="<?php echo htmlspecialchars($hay); ?>"
                                data-order-id="<?php echo (int)$r['order_id']; ?>">
                                <td><span class="cv2-code"><?php echo htmlspecialchars($r['order_code']); ?></span></td>
                                <td>
                                    <div class="cv2-info-main">
                                        <?php echo htmlspecialchars($r['title']); ?>
                                        <span class="cv2-src <?php echo $r['is_pos'] ? 'cv2-src-pos' : 'cv2-src-online'; ?>"><?php echo $r['is_pos'] ? 'POS' : 'Online'; ?></span>
                                    </div>
                                    <div class="cv2-info-sub">
                                        Qty: <?php echo (int)$r['quantity']; ?><?php echo $extra > 0 ? ' · +' . $extra . ' more item' . ($extra > 1 ? 's' : '') : ''; ?>
                                        <?php if (!empty($r['branch_name'])): ?> · <?php echo htmlspecialchars($r['branch_name']); ?><?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="cv2-badge <?php echo cv2_status_class($bucket); ?>"><?php echo htmlspecialchars($r['status'] ?: '—'); ?></span></td>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars(cv2_fmt_date((string)$r['order_date'])); ?></td>
                                <td style="text-align:right;"><button class="cv2-view-btn" onclick="CV2.openDrawer(<?php echo (int)$r['order_id']; ?>)">View</button></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <div id="cv2NoMatch" class="cv2-empty" style="display:none;">No customizations match this filter.</div>
                </div>
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
    let currentBucket = 'all';
    let currentDetail = null;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return esc(s);
        return d.toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
    }

    function applyFilter() {
        const q = document.getElementById('cv2Search').value.trim().toLowerCase();
        const rows = document.querySelectorAll('.cv2-row');
        let visible = 0;
        rows.forEach(row => {
            const bucketOk = currentBucket === 'all' || row.getAttribute('data-bucket') === currentBucket;
            const searchOk = !q || row.getAttribute('data-search').includes(q);
            const show = bucketOk && searchOk;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        document.getElementById('cv2NoMatch').style.display = visible === 0 ? 'block' : 'none';
    }

    async function openDrawer(orderId) {
        const drawer = document.getElementById('cv2Drawer');
        const overlay = document.getElementById('cv2Overlay');
        const body = document.getElementById('cv2DrawerBody');
        const foot = document.getElementById('cv2DrawerFoot');
        document.getElementById('cv2DrawerTitle').textContent = `Order #${orderId}`;
        document.getElementById('cv2DrawerSub').textContent = 'Loading…';
        body.innerHTML = '<div class="cv2-spinner"></div>';
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
        document.getElementById('cv2DrawerSub').textContent = `${d.source_label} · ${fmtDate(d.order_date)}`;
        const c = d.customer;
        let html = '<div class="cv2-banner" id="cv2Banner"></div>';

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

        (d.items || []).forEach((it, idx) => { html += renderItem(it, (d.items.length > 1) ? (idx + 1) : 0); });

        if (d.order_notes) {
            html += `<div class="cv2-section"><h3><span class="cv2-dot"></span>Order Notes</h3>
                <div class="cv2-notes"><div class="cv2-nl">Customer Notes</div><div class="cv2-nv">${esc(d.order_notes)}</div></div></div>`;
        }

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
        let specs;
        if (it.specs && it.specs.length) {
            specs = '<div class="cv2-specs">' + it.specs.map(s =>
                `<div class="cv2-spec"><div class="cv2-sl">${esc(s.label)}</div><div class="cv2-sv">${esc(s.value)}</div></div>`
            ).join('') + '</div>';
        } else {
            specs = '<div class="cv2-nospec">No specifications submitted.</div>';
        }

        const blocks = [];
        if (it.has_design && it.design_url) blocks.push(`<div class="cv2-media"><div class="cv2-ml">Uploaded Design</div><img src="${esc(it.design_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        if (it.has_reference && it.reference_url) blocks.push(`<div class="cv2-media"><div class="cv2-ml">Reference Image</div><img src="${esc(it.reference_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        if (it.product_image_url) blocks.push(`<div class="cv2-media"><div class="cv2-ml">Product / Service Image</div><img src="${esc(it.product_image_url)}" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        const media = blocks.length ? `<div class="cv2-media-row">${blocks.join('')}</div>` : '';

        const notes = it.notes ? `<div class="cv2-notes"><div class="cv2-nl">Special Instructions &amp; Notes</div><div class="cv2-nv">${esc(it.notes)}</div></div>` : '';

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
            <div class="cv2-item-body">${specs}${media}${notes}</div>
        </div>`;
    }

    function renderFooter(d) {
        document.getElementById('cv2DrawerFoot').innerHTML = `
            <button class="cv2-btn cv2-btn-revise" onclick="CV2.toggleRevise()">↩ Request Revision</button>
            <button class="cv2-btn cv2-btn-close" onclick="CV2.act('close')">✓ Close</button>
            <button class="cv2-btn cv2-btn-approve" onclick="CV2.act('approve')">✓ Approve</button>`;
    }

    function onReviseSelect() {
        const sel = document.getElementById('cv2ReviseSelect');
        document.getElementById('cv2ReviseText').style.display = (sel.value === '__other') ? 'block' : 'none';
    }

    function toggleRevise() {
        const panel = document.getElementById('cv2RevisePanel');
        const open = panel.classList.toggle('open');
        if (open) {
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
            setTimeout(() => location.reload(), 800);
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
            currentBucket = tab.getAttribute('data-bucket');
            applyFilter();
        });
        document.getElementById('cv2Search').addEventListener('input', applyFilter);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.getElementById('cv2Lightbox').classList.remove('open');
                closeDrawer();
            }
        });

        const params = new URLSearchParams(location.search);
        const deepId = parseInt(params.get('order_id') || '0', 10);
        if (deepId > 0) openDrawer(deepId);
    }

    document.addEventListener('DOMContentLoaded', bind);

    return { openDrawer, closeDrawer, act, toggleRevise, submitRevise, onReviseSelect, zoom };
})();
</script>
</body>
</html>
