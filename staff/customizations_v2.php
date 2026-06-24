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

        /* Detail modal (centered, like legacy customizations) */
        .cv2-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9000; display:none; align-items:center; justify-content:center; padding:16px; }
        .cv2-overlay.open { display:flex; }
        .cv2-modal { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:680px; max-height:88vh; display:none; flex-direction:column; overflow:hidden; position:relative; }
        .cv2-modal.open { display:flex; }
        .cv2-modal-head { padding:20px 24px; border-bottom:1px solid #f3f4f6; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; background:#fff; flex-shrink:0; }
        .cv2-modal-head h2 { margin:0; font-size:18px; font-weight:700; color:#1f2937; }
        .cv2-modal-head .cv2-sub { font-size:12px; color:#6b7280; margin-top:2px; }
        .cv2-close { background:transparent; border:none; color:#6b7280; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:22px; line-height:1; flex-shrink:0; }
        .cv2-close:hover { background:#f3f4f6; color:#374151; }
        .cv2-modal-body { padding:20px 24px 24px; overflow-y:auto; flex:1; background:#fff; }
        .cv2-modal-foot { padding:16px 24px; background:#fff; border-top:1px solid #f3f4f6; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; flex-shrink:0; }

        .cv2-section { background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:16px; }
        .cv2-section h3 { margin:0 0 12px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:#9ca3af; display:flex; align-items:center; gap:8px; }
        .cv2-section h3 .cv2-dot { display:none; }
        .cv2-kv { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
        .cv2-kv-item .cv2-k { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin-bottom:3px; }
        .cv2-kv-item .cv2-v { font-size:14px; font-weight:700; color:#0f172a; word-break:break-word; }

        .cv2-item { border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; margin-bottom:16px; background:#fff; }
        .cv2-item-head { background:#fff; color:#1f2937; padding:12px 14px; display:flex; align-items:center; gap:12px; border-bottom:1px solid #e5e7eb; }
        .cv2-item-head .cv2-it-title { font-size:14px; font-weight:700; margin:0; color:#1f2937; }
        .cv2-item-head .cv2-it-cat { font-size:10px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
        .cv2-item-body { padding:14px; }
        .cv2-specs { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
        .cv2-spec { background:#f8fafc; border:1px solid #eef2f7; border-radius:10px; padding:9px 11px; }
        .cv2-spec .cv2-sl { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.02em; color:#94a3b8; margin-bottom:3px; line-height:1.2; }
        .cv2-spec .cv2-sv { font-size:13.5px; font-weight:700; color:#0f172a; word-break:break-word; line-height:1.3; }
        .cv2-nospec { font-size:13px; color:#94a3b8; font-style:italic; }

        .cv2-media-row { display:flex; flex-wrap:wrap; gap:14px; margin-top:14px; }
        .cv2-media { flex:1 1 150px; }
        .cv2-media .cv2-ml { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:6px; }
        .cv2-media img { width:100%; max-width:280px; max-height:320px; object-fit:contain; border-radius:10px; border:1px solid #e5e7eb; cursor:zoom-in; background:#f8fafc; display:block; transition:transform .15s; box-shadow:0 4px 6px -1px rgba(0,0,0,0.08); }
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
        .cv2-btn-pos { background:#0d9488; color:#fff; flex:1; justify-content:center; }
        .cv2-btn-pos:hover:not(:disabled) { background:#0f766e; }

        .cv2-pos-price { margin-bottom:14px; padding:16px; border-radius:12px; border:1px solid #99f6e4; background:#f0fdfa; }
        .cv2-pos-price label { display:block; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f766e; margin-bottom:10px; }
        .cv2-pos-price-wrap { position:relative; }
        .cv2-pos-price-wrap span { position:absolute; left:14px; top:50%; transform:translateY(-50%); font-weight:800; color:#0f766e; font-size:18px; }
        .cv2-pos-price input { width:100%; box-sizing:border-box; height:44px; padding:0 12px 0 38px; border:1px solid #5eead4; border-radius:10px; font-size:18px; font-weight:700; color:#0f766e; outline:none; background:#fff; }
        .cv2-pos-price input:focus { border-color:#0d9488; box-shadow:0 0 0 3px rgba(6,161,161,0.08); }
        .cv2-pos-price-hint { margin-top:8px; font-size:12px; color:#0f766e; line-height:1.45; }
        .cv2-drawer-foot.pos-mode,
        .cv2-modal-foot.pos-mode { flex-direction:column; align-items:stretch; gap:10px; }

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

<!-- Detail modal -->
<div class="cv2-overlay" id="cv2Overlay" onclick="CV2.closeDrawer()">
    <div class="cv2-modal" id="cv2Drawer" aria-hidden="true" onclick="event.stopPropagation()">
        <div class="cv2-modal-head">
            <div>
                <h2 id="cv2DrawerTitle">Order</h2>
                <div class="cv2-sub" id="cv2DrawerSub"></div>
            </div>
            <button class="cv2-close" onclick="CV2.closeDrawer()" aria-label="Close">&times;</button>
        </div>
        <div class="cv2-modal-body" id="cv2DrawerBody"></div>
        <div class="cv2-modal-foot" id="cv2DrawerFoot"></div>
    </div>
</div>

<!-- Lightbox -->
<div class="cv2-lightbox" id="cv2Lightbox" onclick="this.classList.remove('open')">
    <img id="cv2LightboxImg" src="" alt="Preview">
</div>

<script>
const CV2 = (function () {
    const API = <?php echo json_encode($api_base); ?>;
    const STAFF_BASE = <?php echo json_encode(BASE_PATH . '/staff/'); ?>;
    const ADMIN_API = <?php echo json_encode(BASE_PATH . '/admin/'); ?>;
    const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    let currentBucket = 'all';
    let currentDetail = null;
    let returnToPos = false;
    let posCustomizationId = 0;

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
        const modal = document.getElementById('cv2Drawer');
        const overlay = document.getElementById('cv2Overlay');
        const body = document.getElementById('cv2DrawerBody');
        const foot = document.getElementById('cv2DrawerFoot');
        document.getElementById('cv2DrawerTitle').textContent = `Order #${orderId}`;
        document.getElementById('cv2DrawerSub').textContent = 'Loading…';
        body.innerHTML = '<div class="cv2-spinner"></div>';
        foot.innerHTML = '';
        overlay.classList.add('open');
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

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

        if (isPosSetPriceMode(d)) {
            html += renderPosPricePanel(d);
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
        renderFooter(d, isPosSetPriceMode(d));
    }

    function isPosSetPriceMode(d) {
        if (!returnToPos || !d) return false;
        const source = String(d.order_source || '').toLowerCase();
        return d.is_pos || source === 'pos_draft' || source === 'pos' || source === 'walk-in';
    }

    function renderPosPricePanel(d) {
        const current = d.total_amount > 0 ? d.total_amount : (d.estimated_price > 0 ? d.estimated_price : '');
        const formatted = current ? Number(current).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
        return `<div class="cv2-pos-price">
            <label>Set Final Price</label>
            ${d.estimated_price > 0 ? `<div style="font-size:12px;color:#0f766e;margin-bottom:10px;">Estimated: ₱${Number(d.estimated_price).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>` : ''}
            <div class="cv2-pos-price-wrap">
                <span>₱</span>
                <input type="text" id="cv2PosPrice" inputmode="decimal" placeholder="0.00" value="${esc(formatted)}"
                    oninput="this.value=this.value.replace(/[^0-9.,]/g,'')"
                    onkeydown="if(event.key==='Enter'){event.preventDefault();CV2.submitPosPrice();}">
            </div>
            <div class="cv2-pos-price-hint">Enter the amount for this POS service, then save to return to checkout.</div>
        </div>`;
    }

    function filterDisplaySpecs(specs, notes, it) {
        const notesNorm = String(notes || '').trim().toLowerCase();
        const skipLabel = /^(notes?|job\s*notes?|special\s*instructions?|other\s*instructions?|additional\s*notes?|upload\s*design|reference\s*(attachment|image|upload))$/i;
        const filename = /\.(jpe?g|png|gif|webp|pdf|ai|psd|svg|bmp|tiff?|heic)$/i;

        return (specs || []).filter(s => {
            const label = String(s.label || '').trim();
            const value = String(s.value || '').trim();
            if (!label || !value) return false;
            if (skipLabel.test(label)) return false;
            if (notesNorm && value.toLowerCase() === notesNorm) return false;
            if (it.design_url && filename.test(value) && /design|upload/i.test(label)) return false;
            if (it.reference_url && filename.test(value) && /reference|upload/i.test(label)) return false;
            return true;
        });
    }

    function renderItem(it, num) {
        const title = num ? `${num}. ${esc(it.name)}` : esc(it.name);
        const displaySpecs = filterDisplaySpecs(it.specs, it.notes, it);
        let specs;
        if (displaySpecs.length) {
            specs = '<div class="cv2-specs">' + displaySpecs.map(s =>
                `<div class="cv2-spec"><div class="cv2-sl">${esc(s.label)}</div><div class="cv2-sv">${esc(s.value)}</div></div>`
            ).join('') + '</div>';
        } else {
            specs = '<div class="cv2-nospec">No specifications submitted.</div>';
        }

        const blocks = [];
        if (it.design_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Design Preview</div><img src="${esc(it.design_url)}" alt="Uploaded design" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        if (it.reference_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Reference Image</div><img src="${esc(it.reference_url)}" alt="Reference image" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        if (it.product_image_url && !it.design_url) {
            blocks.push(`<div class="cv2-media"><div class="cv2-ml">Product Image</div><img src="${esc(it.product_image_url)}" alt="Product image" onclick="CV2.zoom(this.src)" onerror="this.closest('.cv2-media').style.display='none'"></div>`);
        }
        const media = blocks.length ? `<div class="cv2-media-row">${blocks.join('')}</div>` : '';

        const notes = it.notes ? `<div class="cv2-notes"><div class="cv2-nl">Notes</div><div class="cv2-nv">${esc(it.notes)}</div></div>` : '';

        return `<div class="cv2-item">
            <div class="cv2-item-head">
                <div style="flex:1; min-width:0;">
                    <div class="cv2-it-cat">${esc(it.category)}</div>
                    <h4 class="cv2-it-title">${title}</h4>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; color:#6b7280;">Qty</div>
                    <div style="font-size:16px; font-weight:700; color:#1f2937;">${esc(it.quantity)}</div>
                </div>
            </div>
            <div class="cv2-item-body">${specs}${media}${notes}</div>
        </div>`;
    }

    function renderFooter(d, posMode = false) {
        const foot = document.getElementById('cv2DrawerFoot');
        foot.classList.toggle('pos-mode', posMode);

        if (posMode) {
            foot.innerHTML = `
                <button class="cv2-btn cv2-btn-pos" onclick="CV2.submitPosPrice()">Save Price & Return to POS</button>
                <button class="cv2-btn cv2-btn-close" onclick="CV2.closeDrawer()">Cancel</button>`;
            return;
        }

        foot.innerHTML = `
            <button class="cv2-btn cv2-btn-revise" onclick="CV2.toggleRevise()">↩ Request Revision</button>
            <button class="cv2-btn cv2-btn-close" onclick="CV2.act('close')">✓ Close</button>
            <button class="cv2-btn cv2-btn-approve" onclick="CV2.act('approve')">✓ Approve</button>`;
    }

    async function submitPosPrice() {
        if (!currentDetail) return;

        const raw = String(document.getElementById('cv2PosPrice')?.value || '').replace(/,/g, '');
        const price = parseFloat(raw);
        if (!price || price <= 0 || Number.isNaN(price)) {
            banner('Please enter a valid price greater than 0.', 'err');
            return;
        }

        const customizationId = posCustomizationId || parseInt(currentDetail.customization_id || 0, 10);
        if (customizationId <= 0) {
            banner('Could not find the linked customization record.', 'err');
            return;
        }

        const btns = document.querySelectorAll('.cv2-modal-foot .cv2-btn');
        btns.forEach(b => b.disabled = true);
        banner('Saving price…', 'info');

        try {
            const fd = new FormData();
            fd.append('action', 'update_customization');
            fd.append('id', customizationId);
            fd.append('status', 'APPROVED');
            fd.append('price', price);
            const res = await fetch(`${ADMIN_API}job_orders_api.php`, { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.success) {
                throw new Error(json.error || json.message || 'Failed to save price.');
            }

            const savedState = sessionStorage.getItem('pos_cart_state');
            if (savedState) {
                const state = JSON.parse(savedState);
                const itemIndex = state.item_index;
                await fetch(`${STAFF_BASE}api/pos_cart_handler.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_price', index: itemIndex, price })
                });
                await fetch(`${STAFF_BASE}api/pos_cart_handler.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_service_link',
                        index: itemIndex,
                        pending_order_id: parseInt(currentDetail.order_id || 0, 10) || 0,
                        customization_id: customizationId
                    })
                });
            }

            clearDeepLinkParams();
            window.location.href = `${STAFF_BASE}pos.php?from_customizations=1`;
        } catch (e) {
            banner(e.message, 'err');
            btns.forEach(b => b.disabled = false);
        }
    }

    function clearDeepLinkParams() {
        try {
            const url = new URL(window.location.href);
            ['order_id', 'return_to_pos', 'customization_id', 'status', 'job_type', 'source_order_id'].forEach(key => url.searchParams.delete(key));
            window.history.replaceState({}, document.title, url.toString());
        } catch (_e) {}
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
        const btns = document.querySelectorAll('.cv2-modal-foot .cv2-btn');
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
        const modal = document.getElementById('cv2Drawer');
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        currentDetail = null;
        document.body.style.overflow = '';
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
        returnToPos = params.get('return_to_pos') === '1';
        posCustomizationId = parseInt(params.get('customization_id') || '0', 10);
        const deepId = parseInt(params.get('order_id') || '0', 10);
        if (deepId > 0) openDrawer(deepId);
    }

    document.addEventListener('DOMContentLoaded', bind);

    return { openDrawer, closeDrawer, act, toggleRevise, submitRevise, onReviseSelect, zoom, submitPosPrice };
})();
</script>
</body>
</html>
