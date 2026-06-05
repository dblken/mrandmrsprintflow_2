<?php
require_once __DIR__ . '/favicon_links.php';
$__pf_base_path = defined('BASE_PATH') ? (string) BASE_PATH : (defined('BASE_URL') ? (string) BASE_URL : '/printflow');
$__pf_base_path = rtrim($__pf_base_path, '/');
$__pf_asset_path = $__pf_base_path . '/public/assets';
$__pf_output_css_file = __DIR__ . '/../public/assets/css/output.css';
$__pf_output_css_ver = file_exists($__pf_output_css_file) ? (string) filemtime($__pf_output_css_file) : '1';
$__pf_admin_mobile_css_file = __DIR__ . '/../public/assets/css/admin-mobile.css';
$__pf_admin_mobile_css_ver = file_exists($__pf_admin_mobile_css_file) ? (string) filemtime($__pf_admin_mobile_css_file) : '1';
?>
<link rel="stylesheet"
    href="<?php echo htmlspecialchars($__pf_asset_path . '/css/output.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo $__pf_output_css_ver; ?>">
<link rel="stylesheet"
    href="<?php echo htmlspecialchars($__pf_asset_path . '/css/admin-mobile.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo $__pf_admin_mobile_css_ver; ?>">
<script>
    (function () {
        var basePath = <?php echo json_encode($__pf_base_path); ?>;
        window.PF_BASE_PATH = basePath;

        function normalizePrintflowPath(value) {
            if (typeof value !== 'string') return value;
            if (value === '/printflow') return basePath || '';
            if (value.indexOf('/printflow/') !== 0) return value;
            return (basePath || '') + value.slice('/printflow'.length);
        }

        if (!window.__pfPathCompatFetch && window.fetch) {
            window.__pfPathCompatFetch = true;
            var nativeFetch = window.fetch;
            window.fetch = function (input, init) {
                if (typeof input === 'string') {
                    input = normalizePrintflowPath(input);
                }
                return nativeFetch.call(this, input, init);
            };
        }

        if (!window.__pfPathCompatXhr && window.XMLHttpRequest) {
            window.__pfPathCompatXhr = true;
            var nativeOpen = window.XMLHttpRequest.prototype.open;
            window.XMLHttpRequest.prototype.open = function (method, url) {
                arguments[1] = normalizePrintflowPath(url);
                return nativeOpen.apply(this, arguments);
            };
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[href^="/printflow"], [src^="/printflow"], [action^="/printflow"]').forEach(function (el) {
                ['href', 'src', 'action'].forEach(function (attr) {
                    if (el.hasAttribute(attr)) el.setAttribute(attr, normalizePrintflowPath(el.getAttribute(attr)));
                });
            });
        });
    })();
</script>
<?php
/**
 * Alpine.js Core Loading (admin / manager / staff shell).
 * Turbo Drive removed for stability - using standard page navigation.
 */

// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '/printflow');
}

if (empty($GLOBALS['__printflow_shell_core_js'])) {
    $GLOBALS['__printflow_shell_core_js'] = true;
    $__pf_asset_js = $base_path . '/public/assets/js';
    $__pf_admin_mobile_js_file = __DIR__ . '/../public/assets/js/admin-mobile.js';
    $__pf_admin_mobile_js_ver = file_exists($__pf_admin_mobile_js_file) ? (string) filemtime($__pf_admin_mobile_js_file) : '1';
    ?>
    <?php /* Single pinned UMD bundle — avoids unstable "latest" and duplicate loads breaking doughnut charts. */ ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js" defer></script>
    <script src="<?php echo $__pf_asset_js; ?>/alpine.min.js" defer></script>
    <script src="<?php echo $__pf_asset_js; ?>/admin-mobile.js?v=<?php echo $__pf_admin_mobile_js_ver; ?>" defer></script>
    <?php
    unset($__pf_asset_js, $__pf_admin_mobile_js_file, $__pf_admin_mobile_js_ver);
}
unset($__pf_admin_mobile_css_file, $__pf_admin_mobile_css_ver);
?>

<?php if (strpos($_SERVER['REQUEST_URI'] ?? '', '/staff/') !== false): ?>
    <?php
        $__pf_staff_theme_class = 'printflow-staff-online';
        if (function_exists('printflow_get_staff_access_meta') && (($_SESSION['user_type'] ?? '') === 'Staff')) {
            $__pf_staff_theme_class = (string)(printflow_get_staff_access_meta()['theme_class'] ?? $__pf_staff_theme_class);
        }
    ?>
    <script>(function () { document.documentElement.classList.add('printflow-staff', <?php echo json_encode($__pf_staff_theme_class); ?>); })();</script>
    <?php include __DIR__ . '/staff_theme.php'; ?>
<?php endif; ?>
<?php if (strpos($_SERVER['REQUEST_URI'] ?? '', '/manager/') !== false): ?>
    <script>(function () { document.documentElement.classList.add('printflow-manager'); })();</script>
    <?php include __DIR__ . '/manager_theme.php'; ?>
<?php endif; ?>

<!-- PrintFlow Call & Signaling System (Global for Admin/Staff/Manager) -->
<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
    <?php
        $__pf_call_css_file = __DIR__ . '/../public/assets/css/printflow_call.css';
        $__pf_call_css_ver = is_file($__pf_call_css_file) ? (string) filemtime($__pf_call_css_file) : '1';
        $__pf_call_js_file = __DIR__ . '/../public/assets/js/printflow_call.js';
        $__pf_call_js_ver = is_file($__pf_call_js_file) ? (string) filemtime($__pf_call_js_file) : '1';
    ?>
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js" defer></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($__pf_base_path, '/') . '/public/assets/css/printflow_call.css', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo $__pf_call_css_ver; ?>">
    <script src="<?php echo htmlspecialchars(rtrim($__pf_base_path, '/') . '/public/assets/js/printflow_call.js', ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo $__pf_call_js_ver; ?>" defer></script>



    <script>
        (function() {
            function initPFCallGlobal() {
                if (window.__PFCallBootstrapped) return;
                if (window.PFCall && typeof window.PFCall.init === "function") {
                    window.__PFCallBootstrapped = true;
                    <?php 
                        $uid = $_SESSION['user_id'] ?? null;
                        $uname = $_SESSION['user_name'] ?? 'User';
                        $utype = $_SESSION['user_type'] ?? null;
                        $uavatar = $_SESSION['user_profile_picture'] ?? '';
                        // Canonicalize Admin/Manager/Staff to 'Staff' for signaling
                        $call_utype = ($utype === 'Customer') ? 'Customer' : 'Staff';
                    ?>
                    window.PFCall.init({
                        userId: <?php echo json_encode($uid); ?>,
                        userType: <?php echo json_encode($call_utype); ?>,
                        userName: <?php echo json_encode($uname); ?>,
                        userAvatar: <?php echo json_encode(function_exists('get_profile_image') ? get_profile_image($uavatar) : $uavatar); ?>,
                        basePath: <?php echo json_encode($__pf_base_path); ?>
                    });
                } else {
                    setTimeout(initPFCallGlobal, 500);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPFCallGlobal);
            } else {
                initPFCallGlobal();
            }
        })();
    </script>
<?php 
unset($__pf_base_path, $__pf_asset_path, $__pf_output_css_file, $__pf_output_css_ver);
endif; ?>
<script>
    (function () {
        var root = document.documentElement;
        /* Skip boot-pending when persistent sidebar already exists (Turbo head merge) — avoids full-page hide flash */
        var shell = document.getElementById('printflow-persistent-sidebar');
        try {
            var v = localStorage.getItem('sidebarCollapsed');
            var collapsed = v === 'true' || v === '1';
            if (collapsed) {
                root.classList.add('sidebar-preload-collapsed');
            }
        } catch (e) { }
        setTimeout(function () {
            if (root.classList.contains('sidebar-boot-pending')) {
                root.classList.remove('sidebar-boot-pending');
                root.classList.add('sidebar-layout-ready', 'ready');
            }
        }, 400);
    })();
</script>
<style>
    /* Admin White Theme - Consistent Clean Design */
    :root {
        --bg-color: #ffffff;
        --text-main: #1f2937;
        --text-muted: #6b7280;
        --border-color: #f3f4f6;
        --border-hover: #e5e7eb;
        --accent-color: #3b82f6;
        --sidebar-w-expanded: 240px;
        --sidebar-w-collapsed: 72px;
        --sidebar-dur: 0.28s;
        --sidebar-ease: cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: var(--bg-color);
        color: var(--text-main);
    }

    .filter-panel .filter-panel-header {
        padding-right: 56px !important;
    }

    .filter-panel>.pf-filter-close {
        position: absolute !important;
        top: 10px !important;
        right: 10px !important;
        z-index: 3 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        max-width: 32px !important;
        padding: 0 !important;
        border-radius: 8px !important;
        border: 0 !important;
        background: transparent !important;
        color: #334155 !important;
        font-size: 18px !important;
        line-height: 1 !important;
        font-weight: 700 !important;
        cursor: pointer !important;
        box-shadow: none !important;
    }

    .filter-panel>.pf-filter-close:hover,
    .filter-panel>.pf-filter-close:focus-visible {
        background: transparent !important;
        border-color: transparent !important;
        color: #0f172a !important;
        outline: none !important;
    }

    .filter-panel>.pf-filter-close svg {
        width: 18px !important;
        height: 18px !important;
        pointer-events: none !important;
    }

    /*
     * Sidebar anti-flicker (collapsed nav):
     * - Head script sets sidebar-boot-pending only when localStorage says collapsed.
     * - sidebar_layout_boot.php (first in .dashboard-container) sets body.sidebar-collapsed + removes pending.
     * - Failsafe timeout clears pending if boot script never runs.
     */
    html.sidebar-boot-pending {
        visibility: visible;
    }

    html.sidebar-layout-ready,
    html.ready {
        visibility: visible;
    }

    /* No layout transition until first sync (sidebar + main must stay locked together) */
    html:not(.sidebar-transitions-enabled) aside.sidebar,
    html:not(.sidebar-transitions-enabled) .main-content {
        transition: none !important;
    }

    /* Layout */
    .dashboard-container {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: stretch;
        min-height: 100vh;
    }

    /* Turbo permanent wrapper: inner <aside> is position:fixed — no in-flow width.
       order:-1 keeps layout correct even if Turbo leaves this node after .main-content in the DOM. */
    #printflow-persistent-sidebar {
        order: -1;
        flex: 0 0 0;
        width: 0;
        min-width: 0;
        overflow: visible;
        position: relative;
        align-self: stretch;
    }

    /* z-index: keeps toolbar/buttons above the #printflow-persistent-sidebar flex shim (width:0; overflow:visible) in odd stacking cases */
    .main-content {
        flex: 1;
        margin-left: var(--sidebar-w-expanded);
        overflow-y: auto;
        position: relative;
        z-index: 1;
    }

    /* Keep main in sync with fixed sidebar width (same duration/easing = no “jump”) */
    @media (min-width: 769px) {
        .main-content {
            transition: margin-left var(--sidebar-dur) var(--sidebar-ease);
        }

        /* Avoid main column “sliding” on Turbo body swap (sidebar is fixed; only markup changes) */
        html.pf-turbo-nav .main-content {
            transition: none !important;
        }

        /* No animated nav/width churn on the persistent sidebar while Turbo swaps main */
        html.pf-turbo-nav #printflow-persistent-sidebar a.nav-item {
            transition: none !important;
        }

        html.pf-turbo-nav aside.sidebar {
            transition: none !important;
        }
    }

    /* Expanded (default): 240px sidebar — collapsed: 72px + main offset (before <aside> gets .collapsed) */
    html.sidebar-preload-collapsed aside.sidebar,
    body.sidebar-collapsed aside.sidebar {
        width: var(--sidebar-w-collapsed) !important;
    }

    html.sidebar-preload-collapsed .main-content,
    body.sidebar-collapsed .main-content {
        margin-left: var(--sidebar-w-collapsed) !important;
    }

    /* Common Headers */
    .top-bar,
    header {
        background: var(--bg-color);
        padding: 24px 32px;
        /* Increased top/bottom padding to match dashboard look */
        display: flex;
        justify-content: space-between;
        align-items: center;
        /* position: sticky;  <-- Removed sticky */
        /* top: 0; */
        /* z-index: 10; */
        margin-bottom: 8px;
    }

    .page-title,
    h1,
    h2 {
        font-size: 24px;
        font-weight: 600;
        color: var(--text-main);
    }

    .content-area,
    main {
        padding: 0 32px 32px 32px;
    }

    /* Cards */
    .card,
    .stat-card,
    .chart-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--border-color);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 24px;
    }

    .card:hover,
    .stat-card:hover,
    .chart-card:hover {
        border-color: var(--border-hover);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    }

    /* Inputs & Forms */
    .input-field,
    select,
    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="number"],
    input[type="search"] {
        width: 100%;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 14px;
        transition: all 0.2s;
        color: var(--text-main);
    }

    .input-field:focus,
    select:focus,
    input:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        outline: none;
    }

    label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-main);
        margin-bottom: 6px;
    }

    /* Buttons */
    .btn-primary {
        background: #1f2937;
        color: white;
        border-radius: 8px;
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 500;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .btn-primary:hover {
        background: #111827;
        transform: translateY(-1px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .btn-secondary {
        background: white;
        color: var(--text-main);
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .btn-secondary:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    /* Tables */
    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    th {
        text-align: left;
        padding: 12px 16px;
        font-size: 13px;
        color: var(--text-muted);
        font-weight: 600;
        border-bottom: 1px solid var(--border-color);
    }

    td {
        padding: 16px;
        font-size: 14px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
        text-transform: capitalize;
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:hover td {
        background-color: #fcfcfc;
    }

    /* Autocapslock for common labels */
    .stat-label,
    .kpi-label,
    .service-info,
    .chart-title,
    .tp-name,
    .om-value,
    .om-label {
        text-transform: capitalize;
    }

    .status-badge-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 120px;
        padding: 4px 12px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        transition: all 0.2s ease;
        border: none;
    }

    /* Global Status Colors (Pale Backgrounds) */
    .badge-fulfilled {
        background: #dcfce7;
        color: #166534 !important;
    }

    .badge-pending {
        background: #fef3c7;
        color: #92400e !important;
    }

    .badge-approved {
        background: #dbeafe;
        color: #1e40af !important;
    }

    .badge-topay {
        background: #dbeafe;
        color: #1e40af !important;
    }

    .badge-verify {
        background: #fef9c3;
        color: #854d0e !important;
    }

    .badge-production {
        background: #e0e7ff;
        color: #4338ca !important;
    }

    .badge-pickup {
        background: #dcfce7;
        color: #15803d !important;
    }

    .badge-cancelled {
        background: #fee2e2;
        color: #991b1b !important;
    }

    .badge-revision {
        background: #ffe4e6;
        color: #b91c1c !important;
    }

    /* Utilities */
    .badge {
        display: inline-flex;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }

    .text-sm {
        font-size: 13px;
    }

    .text-gray-500 {
        color: var(--text-muted);
    }

    .mb-6 {
        margin-bottom: 24px;
    }

    .mb-4 {
        margin-bottom: 16px;
    }

    .grid {
        display: grid;
        gap: 24px;
    }

    .grid-cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }

    .grid-cols-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .grid-cols-4 {
        grid-template-columns: repeat(4, 1fr);
    }

    /* Stats Grid - Single row on most screens */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        margin-bottom: 32px;
    }

    /* Dynamic column count based on number of children */
    .stats-grid:has(> :last-child:nth-child(3)) {
        grid-template-columns: repeat(3, 1fr);
    }

    @media (max-width: 1200px) {
        .stats-grid {
            gap: 16px;
        }
    }

    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 1024px) {

        .grid-cols-4,
        .grid-cols-3 {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {

        .grid-cols-2,
        .grid-cols-3,
        .grid-cols-4 {
            grid-template-columns: 1fr;
        }

        .dashboard-container {
            display: block;
            height: 100dvh;
            overflow: hidden;
        }

        .main-content {
            margin-left: 0 !important;
            padding-top: 64px !important;
            width: 100% !important;
            max-width: 100vw !important;
            height: 100dvh !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        .sidebar {
            width: min(86vw, 300px) !important;
            max-width: 320px !important;
            transform: translateX(-105%);
            z-index: 1000;
            box-shadow: none;
        }

        .sidebar.active {
            transform: translateX(0);
            box-shadow: 10px 0 30px rgba(0, 0, 0, 0.32);
        }

        .sidebar.collapsed {
            width: min(86vw, 300px) !important;
        }

        /* Ensure sidebar labels are ALWAYS visible on mobile, ignoring collapsed state */
        .sidebar.collapsed .nav-item {
            font-size: 14px !important;
            gap: 12px !important;
            justify-content: flex-start !important;
            margin: 0 10px !important;
            padding: 10px 16px !important;
            min-height: 40px !important;
        }

        .sidebar.collapsed .nav-section-title {
            text-align: left !important;
            font-size: 11px !important;
            padding: 0 20px !important;
            margin-bottom: 8px !important;
            display: block !important;
        }

        .sidebar.collapsed .nav-section-title::after {
            display: none !important;
        }

        .sidebar.collapsed .logo span,
        .sidebar.collapsed .user-info,
        .sidebar.collapsed .logout-btn-footer span {
            display: block !important;
        }

        .sidebar.collapsed .sidebar-header {
            padding: 24px 20px !important;
            flex-direction: row !important;
            justify-content: space-between !important;
            gap: 12px !important;
        }

        .sidebar.collapsed .logo {
            flex-direction: row !important;
            gap: 10px !important;
        }

        .sidebar.collapsed .sidebar-footer {
            padding: 16px !important;
            align-items: stretch !important;
        }

        .sidebar.collapsed .logout-btn-footer {
            width: 100% !important;
            justify-content: center !important;
        }

        .sidebar.collapsed .logout-btn-footer svg {
            margin-right: 8px !important;
        }

        .sidebar.collapsed.active {
            transform: translateX(0);
        }

        .sidebar.collapsed .nav-badge {
            position: static !important;
            min-width: 20px !important;
            height: auto !important;
            padding: 2px 6px !important;
            font-size: 11px !important;
            border-radius: 10px !important;
            margin-left: auto !important;
            display: inline-flex !important;
        }

        html.sidebar-preload-collapsed aside.sidebar,
        body.sidebar-collapsed aside.sidebar {
            width: min(86vw, 300px) !important;
        }

        html.sidebar-preload-collapsed .main-content,
        body.sidebar-collapsed .main-content {
            margin-left: 0 !important;
        }

        /* Show mobile burger menu */
        #mobileBurger {
            display: flex;
        }

        /* Hide collapse button on mobile */
        .sidebar-collapse-btn {
            display: none;
        }

        /* Ensure proper z-index stacking */
        .sidebar {
            z-index: 1020;
        }

        #mobileBurger {
            z-index: 1010;
        }

        #sidebarOverlay {
            z-index: 990;
        }

        /* Adjust content padding for mobile */
        .content-area,
        main {
            padding: 16px;
        }

        .top-bar,
        header {
            padding: 16px;
            margin-bottom: 8px;
        }

        /* Header sits below the fixed burger on mobile */
        .page-title,
        h1 {
            padding-left: 0;
        }

        /* Make tables horizontally scrollable */
        .overflow-x-auto {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Adjust KPI grid for mobile */
        .kpi-row {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px;
        }
    }

    /* Sidebar — full dark theme */
    .sidebar {
        width: var(--sidebar-w-expanded);
        background: linear-gradient(180deg, #000508 0%, #000d12 22%, #001018 55%, #001920 100%);
        border-right: 1px solid rgba(83, 197, 224, 0.12);
        display: flex;
        flex-direction: column;
        position: fixed;
        height: 100vh;
        height: 100dvh;
        top: 0;
        left: 0;
        z-index: 50;
        overflow-x: hidden;
        box-shadow: 4px 0 24px rgba(0, 0, 0, 0.12);
        -webkit-backface-visibility: hidden;
        backface-visibility: hidden;
        transform: translateZ(0);
        overscroll-behavior: contain;
        contain: layout style;
    }

    /* Desktop: animate width only (no transform) — avoids fighting mobile drawer + extra reflow */
    @media (min-width: 769px) {
        .sidebar {
            transition: width var(--sidebar-dur) var(--sidebar-ease);
        }
    }

    @media (max-width: 768px) {
        .sidebar {
            transition: transform 0.3s ease;
        }
    }

    .sidebar-header {
        padding: 24px 20px;
        border-bottom: 1px solid rgba(83, 197, 224, 0.12);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        background: transparent;
        flex-shrink: 0;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 18px;
        font-weight: 600;
        color: #e8f4f8;
        text-decoration: none;
        overflow: hidden;
        white-space: nowrap;
        flex: 1;
    }

    .sidebar-header .logo img {
        border-color: rgba(83, 197, 224, 0.35) !important;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.2);
    }

    .logo-icon {
        min-width: 32px;
        width: 32px;
        height: 32px;
        background: linear-gradient(135deg, #00232b, #124a58);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: bold;
        border: 1px solid rgba(83, 197, 224, 0.25);
    }

    /* Sidebar Collapse Button */
    .sidebar-collapse-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(83, 197, 224, 0.2);
        color: #9fd4e3;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }

    .sidebar-collapse-btn:hover {
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
        border-color: rgba(83, 197, 224, 0.35);
    }

    .sidebar-collapse-btn svg {
        width: 16px;
        height: 16px;
    }

    /* Mobile Burger Menu */
    #mobileBurger {
        display: none;
        position: fixed;
        top: 16px;
        left: 16px;
        z-index: 60;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #001018, #00232b);
        border: 1px solid rgba(83, 197, 224, 0.25);
        color: #e8f4f8;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
        transition: all 0.2s;
    }

    #mobileBurger:hover {
        background: linear-gradient(135deg, #00232b, #0a3d4d);
        border-color: rgba(83, 197, 224, 0.4);
        color: #fff;
    }

    /* Mobile Sidebar Overlay */
    #sidebarOverlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 90;
        opacity: 0;
        transition: opacity 0.3s;
    }

    #sidebarOverlay.active {
        display: block;
        opacity: 1;
    }

    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: 16px 0;
        overflow-anchor: none;
    }

    .nav-group {
        margin: 0 10px 6px;
    }

    .nav-item.nav-parent {
        width: 100%;
        margin: 0;
        border: none;
        background: transparent;
        font: inherit;
        text-align: left;
        cursor: pointer;
        box-sizing: border-box;
    }

    .nav-item.nav-parent .nav-label {
        flex: 1;
        min-width: 0;
    }

    .nav-chevron {
        width: 16px;
        height: 16px;
        margin-left: auto;
        flex-shrink: 0;
        opacity: 0.72;
        transition: transform 0.2s ease, opacity 0.18s ease;
    }

    .nav-item.nav-parent:hover .nav-chevron,
    .nav-item.nav-parent.active .nav-chevron {
        opacity: 1;
    }

    .nav-group.expanded .nav-chevron {
        transform: rotate(90deg);
    }

    .nav-subitems {
        position: relative;
        display: none;
        margin: 2px 0 6px 27px;
        padding: 2px 0 4px 14px;
        border-left: 1px solid rgba(148, 200, 212, 0.3);
    }

    .nav-group.expanded .nav-subitems {
        display: block;
    }

    .nav-subitem {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 7px 12px 7px 10px;
        margin: 2px 0;
        border-radius: 8px;
        color: rgba(200, 230, 238, 0.78);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.18s, color 0.18s;
        position: relative;
    }

    .nav-subitem::before {
        content: '';
        position: absolute;
        left: -14px;
        top: 50%;
        width: 10px;
        height: 1px;
        background: rgba(148, 200, 212, 0.3);
        transform: translateY(-50%);
    }

    .nav-subitem:hover {
        background: rgba(255, 255, 255, 0.05);
        color: #f0fafc;
    }

    .nav-subitem.active {
        background: linear-gradient(135deg, rgba(240, 253, 250, 0.14) 0%, rgba(224, 242, 244, 0.1) 100%);
        color: #f8fffe;
        box-shadow: inset 2px 0 0 rgba(240, 253, 250, 0.85);
    }

    .nav-subitem .nav-badge {
        margin-left: auto;
    }

    .sidebar.collapsed .nav-group .nav-subitems,
    .sidebar.collapsed .nav-group .nav-chevron {
        display: none;
    }

    .nav-section {
        margin-bottom: 24px;
    }

    .nav-section-title {
        font-size: 11px;
        font-weight: 600;
        color: rgba(148, 200, 212, 0.55);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 0 20px;
        margin-bottom: 8px;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        margin: 0 10px;
        border-radius: 10px;
        color: rgba(200, 230, 238, 0.88);
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.18s, color 0.18s, box-shadow 0.18s;
        position: relative;
        border-right: none;
        min-height: 40px;
        box-sizing: border-box;
    }

    .nav-item:hover {
        background: rgba(255, 255, 255, 0.06);
        color: #f0fafc;
    }

    /* Active: light pill (same font-weight as inactive — avoids reflow / “blink” when switching) */
    .nav-item.active {
        background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f4 45%, #d8eef2 100%);
        color: #00232b;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.85);
        border-right: none;
    }

    .nav-item.active .nav-icon {
        color: #00232b;
        stroke: #00232b;
    }

    .nav-item.active:hover {
        filter: none;
        background: linear-gradient(135deg, #f8fffe 0%, #e8f6f8 50%, #dff0f4 100%);
        color: #00151a;
    }

    .nav-icon {
        width: 20px;
        height: 20px;
    }

    .nav-badge {
        margin-left: auto;
        background: #ef4444;
        color: white;
        font-size: 11px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        min-width: 20px;
        text-align: center;
        line-height: 1.4;
    }

    /* Reserve badge space in sidebar so poll / Turbo doesn’t shift the row */
    #printflow-persistent-sidebar .nav-item .nav-badge[data-notif-badge] {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .sidebar-footer {
        padding: 16px;
        border-top: 1px solid rgba(83, 197, 224, 0.12);
        display: flex;
        flex-direction: column;
        gap: 8px;
        background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.2));
        flex-shrink: 0;
    }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px;
        border-radius: 10px;
    }

    .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        overflow: hidden;
        background: linear-gradient(135deg, #124a58 0%, #53C5E0 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 14px;
        border: 1px solid rgba(83, 197, 224, 0.35);
    }

    .user-info {
        flex: 1;
    }

    .user-name-display {
        font-size: 14px;
        font-weight: 500;
        color: #e8f4f8;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 120px;
    }

    .user-role {
        font-size: 12px;
        color: rgba(148, 200, 212, 0.75);
    }

    .logout-btn-footer {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px 12px;
        color: rgba(200, 230, 238, 0.9);
        font-size: 14px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(83, 197, 224, 0.18);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        width: 100%;
    }

    .logout-btn-footer:hover {
        color: #fecaca;
        background: rgba(239, 68, 68, 0.12);
        border-color: rgba(248, 113, 113, 0.35);
    }

    .logout-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        color: rgba(200, 230, 238, 0.85);
        font-size: 14px;
        text-decoration: none;
        margin-top: 8px;
        border-radius: 6px;
    }

    .logout-btn:hover {
        color: #f0fafc;
        background: rgba(255, 255, 255, 0.06);
    }

    a.user-profile:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    /* Collapsible Sidebar Support */
    .sidebar.collapsed {
        width: var(--sidebar-w-collapsed);
    }

    /* Legacy: aside was direct sibling of .main-content */
    .sidebar.collapsed~.main-content {
        margin-left: var(--sidebar-w-collapsed);
    }

    /*
     * Turbo shell: sidebar lives inside #printflow-persistent-sidebar, so .main-content is a sibling
     * of the wrapper — use :has() so collapsed width still pulls main content left (desktop).
     */
    @media (min-width: 769px) {
        #printflow-persistent-sidebar:has(.sidebar.collapsed)~.main-content {
            margin-left: var(--sidebar-w-collapsed);
        }
    }

    .sidebar.collapsed .sidebar-header {
        padding: 24px 12px;
        justify-content: center;
        flex-direction: column;
        gap: 12px;
    }

    .sidebar.collapsed .logo {
        flex-direction: column;
        gap: 4px;
    }

    .sidebar.collapsed .logo span {
        display: none;
    }

    .sidebar.collapsed .sidebar-collapse-btn {
        margin: 0;
    }

    .sidebar.collapsed .nav-section-title {
        text-align: center;
        font-size: 0;
        padding: 0;
        margin-bottom: 16px;
    }

    .sidebar.collapsed .nav-section-title::after {
        content: "•••";
        font-size: 12px;
        letter-spacing: 2px;
        color: rgba(148, 200, 212, 0.45);
    }

    /* Collapsed: no gap between icon and hidden label — otherwise icon sits left of center */
    .sidebar.collapsed .nav-item {
        padding: 12px;
        justify-content: center;
        margin: 0 8px;
        border-radius: 10px;
        border-right: none;
        gap: 0;
        font-size: 0;
        min-height: 0;
    }

    .sidebar.collapsed .nav-item.active {
        border-right: none;
        background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f4 50%, #d8eef2 100%);
        color: #00232b;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
    }

    .sidebar.collapsed .nav-item.active .nav-icon {
        color: #00232b;
        stroke: #00232b;
    }

    .sidebar.collapsed .nav-item text,
    .sidebar.collapsed .nav-item tooltip,
    .sidebar-nav a {
        position: relative;
    }

    .sidebar.collapsed .nav-icon {
        margin: 0;
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }

    .sidebar.collapsed .nav-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        min-width: 8px;
        height: 8px;
        padding: 0;
        font-size: 0;
        border-radius: 50%;
    }

    .sidebar.collapsed .sidebar-footer {
        padding: 16px 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }

    .sidebar.collapsed .user-info {
        display: none;
    }

    .sidebar.collapsed .user-avatar {
        margin: 0;
    }

    /* Center profile block like the logo (link was flex-start + full width) */
    .sidebar.collapsed .sidebar-footer a.user-profile {
        justify-content: center;
        width: 100%;
        gap: 0;
        padding-left: 0;
        padding-right: 0;
    }

    .sidebar.collapsed .logout-btn-footer {
        width: auto;
        padding: 10px;
        border-radius: 8px;
    }

    .sidebar.collapsed .logout-btn-footer span {
        display: none;
    }

    .sidebar.collapsed .logout-btn-footer svg {
        margin: 0;
    }

    /* Staff footer uses .logout-btn + text node — match icon-only centered layout */
    .sidebar.collapsed .logout-btn {
        width: auto;
        padding: 10px;
        justify-content: center;
        margin-top: 0;
        gap: 0;
        font-size: 0;
    }

    /* Custom Scrollbar */
    ::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    ::-webkit-scrollbar-track {
        background: transparent;
    }

    ::-webkit-scrollbar-thumb {
        background: #e5e7eb;
        border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #d1d5db;
    }

    /* Sidebar nav scrollbar — dark theme */
    .sidebar-nav {
        scrollbar-width: thin;
        scrollbar-color: rgba(83, 197, 224, 0.25) transparent;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(83, 197, 224, 0.2);
        border-radius: 4px;
    }

    .sidebar-nav:hover::-webkit-scrollbar-thumb {
        background: rgba(83, 197, 224, 0.35);
    }

    /* Strict Layout Enforcement */
    html,
    body {
        height: 100%;
        overflow: hidden;
    }

    /* Lock body scroll */
    .dashboard-container {
        height: 100%;
        overflow: hidden;
    }

    .main-content {
        height: 100%;
        overflow-y: scroll;
        scroll-behavior: smooth;
    }

    /* Always show scrollbar track */

    /* ── Global summary / KPI card accent (all admin-style pages) ───────── */
    .kpi-row {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        width: 100%;
    }

    .kpi-card {
        position: relative;
        display: block;
        overflow: hidden;
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 18px;
        min-height: 0;
        color: var(--text-main);
        text-decoration: none;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .kpi-card--link:hover {
        transform: translateY(-2px);
        border-color: rgba(6, 161, 161, 0.28);
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    }

    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        pointer-events: none;
    }

    .kpi-card-inner {
        display: flex;
        min-height: 0;
        flex-direction: column;
        gap: 8px;
    }

    .kpi-label {
        display: block;
        font-size: 13px;
        font-weight: 800;
        line-height: 1.25;
    }

    .kpi-sub {
        display: block;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.35;
    }

    .kpi-card-cta {
        display: block;
        margin-top: auto;
        color: var(--accent-color);
        font-size: 12px;
        font-weight: 800;
        line-height: 1.2;
    }

    /*
     * Equal-height KPI cards across admin/manager pages (desktop + mobile).
     * Page-local overrides can still apply, but these defaults keep rows aligned.
     */
    .kpi-row {
        align-items: stretch !important;
    }

    .kpi-card,
    .kpi-card-v2 {
        min-height: 0 !important;
        height: 100% !important;
        padding-bottom: 16px !important;
        display: flex !important;
        flex-direction: column !important;
    }

    .kpi-card-inner {
        min-height: 0 !important;
        height: 100% !important;
        display: flex !important;
        flex-direction: column !important;
    }

    .kpi-sub,
    .kpi-card-cta {
        margin-top: auto !important;
    }

    .kpi-card::before,
    .kpi-card.indigo::before,
    .kpi-card.emerald::before,
    .kpi-card.amber::before,
    .kpi-card.rose::before,
    .kpi-card.blue::before,
    .kpi-ind::before,
    .kpi-em::before,
    .kpi-amb::before,
    .kpi-vio::before {
        background: linear-gradient(90deg, #00232b, #53C5E0) !important;
    }

    .kpi-label,
    .kpi-lbl {
        background: linear-gradient(90deg, #00232b, #53C5E0) !important;
        -webkit-background-clip: text !important;
        background-clip: text !important;
        color: transparent !important;
        -webkit-text-fill-color: transparent !important;
    }

    /* Stat grid cards (staff dashboard, etc.) — top bar + readable title */
    .stats-grid .stat-card,
    .stat-card {
        position: relative;
        overflow: hidden;
    }

    .kpi-card,
    .stat-card,
    .kpi-card-v2 {
        min-width: 0 !important;
    }

    .kpi-card *,
    .stat-card *,
    .kpi-card-v2 * {
        min-width: 0;
    }

    .stats-grid .stat-card::before,
    .stat-card:not(.no-stat-accent)::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #00232b, #53C5E0);
        pointer-events: none;
    }

    /*
     * KPI figures — one canonical rule in this file (included on every admin/staff shell page).
     * Turbo Drive can leave previous pages’ <style> blocks in <head>; !important keeps weight
     * stable on first paint and across navigations.
     */
    .kpi-value {
        font-size: 26px !important;
        font-weight: 800 !important;
        color: #1f2937;
        font-variant-numeric: tabular-nums;
        line-height: 1.15 !important;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: break-word;
        display: block !important;
    }

    .stats-grid .stat-value,
    .stat-card>.stat-value {
        font-size: 32px !important;
        font-weight: 800 !important;
        color: #1f2937;
        font-variant-numeric: tabular-nums;
        line-height: 1.15 !important;
        margin-bottom: 4px;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: break-word;
        display: block !important;
    }

    .report-summary .summary-box .value {
        font-size: 24px !important;
        font-weight: 800 !important;
        color: #1f2937;
        font-variant-numeric: tabular-nums;
        line-height: 1.15 !important;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: break-word;
        display: block !important;
    }

    .inv-summary-card .value {
        font-weight: 800 !important;
        font-variant-numeric: tabular-nums;
        line-height: 1.15 !important;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: break-word;
        display: block !important;
    }

    .pf-wide-chart-canvas {
        position: relative;
        width: 100%;
        height: 100%;
        min-width: 0;
    }

    .pf-wide-chart-canvas canvas {
        display: block;
    }

    .pf-admin-scroll-top {
        display: none;
    }

    .stat-label {
        color: #00232b;
        font-weight: 600;
    }

    /* ── PrintFlow form guard: save overlay, unsaved modal, toast (portal lives in sidebar, turbo-permanent) ── */
    .pf-fg-portal {
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 10030;
    }

    .pf-fg-save-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 19, 28, 0.45);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.28s ease, visibility 0.28s ease;
        pointer-events: none;
    }

    .pf-fg-save-overlay--visible {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .pf-fg-spinner {
        display: inline-block;
        width: 1em;
        height: 1em;
        border: 2px solid rgba(83, 197, 224, 0.25);
        border-top-color: #53C5E0;
        border-radius: 50%;
        animation: pf-fg-spin 0.65s linear infinite;
        vertical-align: -0.12em;
        margin-right: 8px;
    }

    @keyframes pf-fg-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .pf-fg-save-highlight {
        box-shadow: 0 0 0 2px rgba(83, 197, 224, 0.9) !important;
        transition: box-shadow 0.2s ease;
    }

    .pf-fg-dirty-hint {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #b45309;
        flex: 0 0 100%;
        width: 100%;
        max-width: 100%;
        margin-top: 8px;
        text-align: right;
        box-sizing: border-box;
    }

    .pf-fg-dirty-hint[hidden] {
        display: none !important;
    }

    .pf-fg-nav-modal {
        position: fixed;
        inset: 0;
        z-index: 10050;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        pointer-events: none;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }

    .pf-fg-nav-modal--open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .pf-fg-nav-modal__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 19, 28, 0.5);
    }

    .pf-fg-nav-modal__panel {
        position: relative;
        width: 100%;
        max-width: 460px;
        background: #fff;
        border-radius: 14px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 24px 56px rgba(0, 35, 43, 0.22);
        padding: 22px 24px 20px;
        transform: scale(0.96) translateY(6px);
        opacity: 0;
        transition: transform 0.28s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.22s ease;
    }

    .pf-fg-nav-modal--open .pf-fg-nav-modal__panel {
        transform: scale(1) translateY(0);
        opacity: 1;
    }

    .pf-fg-nav-modal__title {
        font-size: 17px;
        font-weight: 700;
        color: #00232b;
        margin: 0 0 8px;
        letter-spacing: -0.02em;
    }

    .pf-fg-nav-modal__msg {
        font-size: 14px;
        color: #4b5563;
        margin: 0 0 12px;
        line-height: 1.45;
    }

    .pf-fg-nav-modal__sub {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #00232b;
        margin: 0 0 8px;
    }

    .pf-fg-nav-modal__list {
        list-style: none;
        margin: 0 0 16px;
        padding: 12px 14px;
        background: linear-gradient(135deg, rgba(83, 197, 224, 0.12), rgba(0, 35, 43, 0.06));
        border: 1px solid rgba(83, 197, 224, 0.5);
        border-radius: 10px;
        border-left: 4px solid #53C5E0;
    }

    .pf-fg-nav-modal__list li {
        font-size: 14px;
        font-weight: 600;
        color: #00232b;
        padding: 6px 0 6px 22px;
        position: relative;
        line-height: 1.35;
    }

    .pf-fg-nav-modal__list li+li {
        border-top: 1px solid rgba(0, 35, 43, 0.08);
    }

    .pf-fg-nav-modal__list li::before {
        content: '';
        position: absolute;
        left: 2px;
        top: 0.65em;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #53C5E0;
        box-shadow: 0 0 0 2px rgba(0, 35, 43, 0.15);
    }

    .pf-fg-nav-modal__err {
        font-size: 13px;
        color: #b91c1c;
        margin: 0 0 14px;
        padding: 10px 12px;
        background: #fef2f2;
        border-radius: 8px;
        border: 1px solid #fecaca;
    }

    .pf-fg-nav-modal__actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 10px;
    }

    .pf-fg-btn {
        height: 40px;
        padding: 0 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: background 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease;
    }

    .pf-fg-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .pf-fg-btn--accent {
        background: #53C5E0;
        color: #00232b;
        border: 2px solid #00232b;
        box-shadow: 0 2px 10px rgba(83, 197, 224, 0.4);
    }

    .pf-fg-btn--accent:hover:not(:disabled) {
        background: #6dceea;
        box-shadow: 0 4px 14px rgba(83, 197, 224, 0.45);
    }

    .pf-fg-btn--discard {
        background: #00232b;
        color: #53C5E0;
        border: 2px solid #00232b;
    }

    .pf-fg-btn--discard:hover:not(:disabled) {
        background: #003a47;
        color: #6dceea;
    }

    .pf-fg-btn--neutral {
        background: #fff;
        color: #00232b;
        border: 2px solid #53C5E0;
    }

    .pf-fg-btn--neutral:hover:not(:disabled) {
        background: rgba(83, 197, 224, 0.12);
    }

    .pf-fg-toast {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 10060;
        padding: 14px 20px;
        background: linear-gradient(135deg, #00232b, #0a3d4d);
        color: #e8f4f8;
        font-size: 14px;
        font-weight: 600;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 35, 43, 0.35);
        border: 1px solid rgba(83, 197, 224, 0.35);
        opacity: 0;
        transform: translateY(12px);
        transition: opacity 0.28s ease, transform 0.28s ease;
        pointer-events: none;
        max-width: min(360px, calc(100vw - 40px));
    }

    .pf-fg-toast--visible {
        opacity: 1;
        transform: translateY(0);
    }

    .btn-staff-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 11px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none !important;
        line-height: 1.2;
        background: transparent;
        border: 1.5px solid transparent;
    }

    .btn-staff-action-emerald {
        border-color: #059669;
        color: #059669 !important;
    }

    .btn-staff-action-emerald:hover {
        background: #059669;
        color: white !important;
        transform: translateY(-1px);
    }

    .btn-staff-action-indigo {
        border-color: #4f46e5;
        color: #4f46e5 !important;
    }

    .btn-staff-action-indigo:hover {
        background: #4f46e5;
        color: white !important;
        transform: translateY(-1px);
    }

    .btn-staff-action-blue {
        border-color: #06A1A1;
        color: #06A1A1 !important;
    }

    .btn-staff-action-blue:hover {
        background: #06A1A1;
        color: white !important;
        transform: translateY(-1px);
    }

    .btn-staff-action-red {
        border-color: #ef4444;
        color: #ef4444 !important;
    }

    .btn-staff-action-red:hover {
        background: #ef4444;
        color: white !important;
        transform: translateY(-1px);
    }

    /*
     * Final mobile shell overrides.
     * These sit after the base sidebar rules because the base fixed-sidebar
     * transform/z-index declarations otherwise win in the cascade on mobile.
     */
    @media (max-width: 768px) {
        .dashboard-container {
            display: block !important;
            height: 100dvh !important;
            overflow: hidden !important;
        }

        .main-content {
            margin-left: 0 !important;
            padding-top: 64px !important;
            width: 100% !important;
            max-width: 100vw !important;
            height: 100dvh !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        body.pf-burger-in-header .main-content {
            padding-top: 0 !important;
        }

        aside.sidebar,
        html.sidebar-preload-collapsed aside.sidebar,
        body.sidebar-collapsed aside.sidebar,
        aside.sidebar.collapsed {
            width: min(86vw, 300px) !important;
            max-width: 320px !important;
            transform: translateX(-105%) !important;
            z-index: 1020 !important;
            box-shadow: none !important;
        }

        aside.sidebar.active,
        aside.sidebar.collapsed.active {
            transform: translateX(0) !important;
            box-shadow: 10px 0 30px rgba(0, 0, 0, 0.32) !important;
        }

        html.sidebar-preload-collapsed .main-content,
        body.sidebar-collapsed .main-content {
            margin-left: 0 !important;
        }

        #mobileBurger {
            display: flex !important;
            position: fixed !important;
            top: 12px !important;
            left: 16px !important;
            z-index: 1010 !important;
        }

        .main-content>header,
        .main-content>.top-bar {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: space-between !important;
            flex-wrap: nowrap !important;
            gap: 10px !important;
            min-height: 64px !important;
            padding: 12px 14px 12px 72px !important;
            margin-bottom: 8px !important;
            position: static !important;
            top: auto !important;
            z-index: auto !important;
            background: #fff !important;
        }

        .main-content>header.pf-mobile-shell-header,
        .main-content>.top-bar.pf-mobile-shell-header {
            flex-wrap: wrap !important;
            padding-left: 14px !important;
        }

        .main-content>header.pf-mobile-shell-header>#mobileBurger,
        .main-content>.top-bar.pf-mobile-shell-header>#mobileBurger {
            position: static !important;
            top: auto !important;
            left: auto !important;
            flex: 0 0 44px !important;
            width: 44px !important;
            height: 44px !important;
            z-index: 1 !important;
        }

        .main-content>header .page-title,
        .main-content>header h1:first-child,
        .main-content>.top-bar .page-title,
        .main-content>.top-bar h1:first-child {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            padding-left: 0 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.2 !important;
        }

        .main-content>header .branch-selector-wrap,
        .main-content>.top-bar .branch-selector-wrap {
            display: flex !important;
            justify-content: flex-end !important;
            flex: 0 0 100% !important;
            max-width: 100% !important;
            margin-left: auto !important;
        }

        .main-content>header .branch-selector-btn,
        .main-content>header .branch-selector-static,
        .main-content>.top-bar .branch-selector-btn,
        .main-content>.top-bar .branch-selector-static {
            min-width: 0 !important;
            width: auto !important;
            max-width: 190px !important;
        }

        .main-content>header #branchSelectorLabel,
        .main-content>.top-bar #branchSelectorLabel,
        .main-content>header .branch-selector-static span:last-child,
        .main-content>.top-bar .branch-selector-static span:last-child {
            min-width: 0 !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }

        #sidebarOverlay {
            z-index: 990 !important;
            pointer-events: none;
        }

        #sidebarOverlay.active {
            display: block !important;
            pointer-events: auto;
        }

        .sidebar-collapse-btn,
        #global-sidebar-toggle {
            display: none !important;
        }

        .kpi-row {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
        }

        .kpi-card,
        .stat-card,
        .kpi-card-v2 {
            min-width: 0 !important;
        }

        .kpi-value,
        .stat-value,
        .kpi-v2-value {
            max-width: 100% !important;
            min-width: 0 !important;
            font-size: 20px !important;
            line-height: 1.15 !important;
            white-space: normal !important;
            overflow-wrap: anywhere !important;
            word-break: break-word;
        }

        .overflow-x-auto,
        .table-responsive,
        .pf-table-scroll,
        [id$="TableContainer"],
        [class*="table-wrap"] {
            overflow-x: auto !important;
            overflow-y: visible !important;
            -webkit-overflow-scrolling: touch;
            max-width: 100% !important;
            padding-bottom: 4px;
        }

        table {
            display: table !important;
            width: max-content !important;
            min-width: max(720px, 100%) !important;
            max-width: none !important;
        }

        table thead {
            display: table-header-group !important;
        }

        table tbody {
            display: table-row-group !important;
        }

        table tr {
            display: table-row !important;
            margin-bottom: 0 !important;
            background: transparent;
            border: 0 !important;
            border-radius: 0 !important;
            padding: 0 !important;
            box-shadow: none !important;
        }

        table tr[hidden],
        table tr.hidden,
        table tr[aria-hidden="true"],
        table tr[style*="display:none"],
        table tr[style*="display: none"] {
            display: none !important;
        }

        table th,
        table td {
            display: table-cell !important;
            padding: 12px 14px !important;
            border-bottom: 1px solid #f3f4f6 !important;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap !important;
        }

        table th:last-child,
        table td:last-child {
            padding-right: 18px !important;
            border-right: 1px solid #f3f4f6 !important;
        }

        table td::before {
            content: none !important;
            display: none !important;
        }

        table td:last-child a,
        table td:last-child button {
            width: auto !important;
        }

        .dash-card-title,
        .card-title,
        .table-title,
        .card-header,
        .table-header,
        .section-header {
            min-width: 0 !important;
            flex-wrap: wrap !important;
        }

        .card:has(table)>div:first-child:not(.pf-table-scroll),
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll),
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll),
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll),
        .table-header {
            display: flex !important;
            flex-wrap: wrap !important;
            justify-content: flex-start !important;
            align-items: flex-start !important;
            gap: 10px !important;
            overflow: visible !important;
        }

        .dash-card-title,
        .card-title,
        .table-title,
        .card:has(table)>div:first-child:not(.pf-table-scroll)>h1,
        .card:has(table)>div:first-child:not(.pf-table-scroll)>h2,
        .card:has(table)>div:first-child:not(.pf-table-scroll)>h3,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h1,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h2,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h3,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h1,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h2,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h3,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h1,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h2,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h3,
        .card-header h1,
        .card-header h2,
        .card-header h3,
        .table-header h1,
        .table-header h2,
        .table-header h3,
        .section-header h1,
        .section-header h2,
        .section-header h3 {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .card:has(table)>div:first-child:not(.pf-table-scroll)>h1,
        .card:has(table)>div:first-child:not(.pf-table-scroll)>h2,
        .card:has(table)>div:first-child:not(.pf-table-scroll)>h3,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h1,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h2,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h3,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h1,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h2,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>h3,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h1,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h2,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>h3 {
            flex: 0 1 100% !important;
            max-width: 100% !important;
        }

        .card:has(table)>div:first-child:not(.pf-table-scroll)>div,
        .card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>div,
        .dash-card:has(table)>div:first-child:not(.pf-table-scroll)>div,
        .dash-card:has(.pf-table-scroll)>div:first-child:not(.pf-table-scroll)>div,
        .table-header>div {
            flex: 0 1 100% !important;
            max-width: 100% !important;
            display: flex !important;
            justify-content: flex-start !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 8px !important;
            overflow: visible !important;
        }

        .card:has(table) .toolbar-btn,
        .card:has(.pf-table-scroll) .toolbar-btn,
        .dash-card:has(table) .toolbar-btn,
        .dash-card:has(.pf-table-scroll) .toolbar-btn,
        .table-header .toolbar-btn {
            flex: 0 1 auto !important;
            max-width: 100% !important;
            white-space: nowrap !important;
        }

        .modal,
        .modal-overlay,
        .modal-backdrop,
        #product-modal-overlay,
        #view-product-modal-overlay,
        #service-modal-overlay,
        #view-service-modal-overlay,
        #archive-storage-overlay,
        #items-archive-storage-overlay,
        #user-modal-backdrop,
        #serviceStatusConfirmModal,
        .cropper-modal-overlay,
        .upload-modal-overlay,
        .view-picture-modal,
        .pf-fg-nav-modal,
        .pf-fg-nav-modal__backdrop {
            align-items: flex-start !important;
            justify-content: center !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 12px !important;
            z-index: 11000 !important;
        }

        .modal>.modal-content,
        .modal>.modal-dialog,
        .modal-overlay>.modal-content,
        .modal-overlay>.modal-dialog,
        .modal-overlay>.modal-panel,
        .modal-overlay>.modal-box,
        .modal-backdrop>.modal-content,
        .modal-backdrop>.modal-dialog,
        #product-modal,
        #view-product-modal,
        #service-modal,
        #view-service-modal,
        #archive-storage-modal,
        #items-archive-storage-modal,
        #user-modal-box,
        #serviceStatusConfirmModal>[role="dialog"],
        .chat-modal-shell,
        .cropper-modal-panel,
        .upload-modal,
        .pf-fg-nav-modal__panel {
            width: min(100%, 560px) !important;
            max-width: calc(100vw - 24px) !important;
            margin: 64px auto 24px !important;
            border-radius: 8px !important;
            min-height: auto !important;
            max-height: calc(100dvh - 88px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            z-index: 11010 !important;
            box-sizing: border-box !important;
        }

        .modal-header,
        .modal-hdr,
        .chat-modal-header {
            padding: 16px !important;
            position: sticky !important;
            top: 0 !important;
            background: white !important;
            z-index: 10 !important;
            border-bottom: 1px solid #e5e7eb !important;
            flex-shrink: 0 !important;
            gap: 10px !important;
        }

        .modal-header h1,
        .modal-header h2,
        .modal-header h3,
        .modal-hdr h1,
        .modal-hdr h2,
        .modal-hdr h3,
        .modal-title,
        .chat-modal-title {
            min-width: 0 !important;
            max-width: 100% !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            line-height: 1.25 !important;
        }

        .modal-body,
        .modal-bdy,
        .modal-panel>.modal-content,
        .chat-modal-messages {
            padding: 16px !important;
            min-width: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }

        .modal-footer,
        .modal-ftr,
        .modal-actions,
        .pf-fg-nav-modal__actions {
            padding: 16px !important;
            position: sticky !important;
            bottom: 0 !important;
            background: white !important;
            border-top: 1px solid #e5e7eb !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            flex-shrink: 0 !important;
        }

        .modal-footer button,
        .modal-footer a,
        .modal-ftr button,
        .modal-ftr a,
        .modal-actions button,
        .modal-actions a,
        .pf-fg-nav-modal__actions button,
        .pf-fg-nav-modal__actions a,
        #serviceStatusConfirmModal button {
            width: 100% !important;
            justify-content: center !important;
        }

        #serviceStatusConfirmModal [style*="display:flex"] {
            flex-direction: column !important;
        }

        .modal-content .form-row,
        .modal-content .form-row-3,
        .modal-content .form-grid,
        .modal-content .field-row,
        .modal-content .mf-row,
        .modal-content .mf-row-3,
        .modal-content [style*="grid-template-columns"],
        .modal-panel .form-row,
        .modal-panel .form-row-3,
        .modal-panel .form-grid,
        .modal-panel .field-row,
        .modal-panel .mf-row,
        .modal-panel .mf-row-3,
        .modal-panel [style*="grid-template-columns"],
        .modal-box .form-row,
        .modal-box .form-row-3,
        .modal-box .form-grid,
        .modal-box .field-row,
        .modal-box .mf-row,
        .modal-box .mf-row-3,
        .modal-box [style*="grid-template-columns"],
        #user-modal-box .form-row,
        #user-modal-box .form-row-3,
        #user-modal-box .form-grid,
        #user-modal-box [style*="grid-template-columns"] {
            grid-template-columns: 1fr !important;
        }

        .modal-content input,
        .modal-content select,
        .modal-content textarea,
        .modal-panel input,
        .modal-panel select,
        .modal-panel textarea,
        .modal-box input,
        .modal-box select,
        .modal-box textarea,
        #user-modal-box input,
        #user-modal-box select,
        #user-modal-box textarea {
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }

        .modal-content table,
        .modal-panel table,
        .modal-box table,
        #user-modal-box table {
            min-width: 640px !important;
        }

        .modal-content img,
        .modal-panel img,
        .modal-box img,
        #user-modal-box img,
        .upload-modal img,
        .cropper-modal-panel img {
            max-width: 100% !important;
            height: auto;
        }

        .modal-content,
        .modal-panel,
        .modal-box,
        .details-modal-panel,
        .details-modal-content {
            min-width: 0 !important;
        }

        .modal-content *:not(svg):not(path),
        .modal-panel *:not(svg):not(path),
        .modal-box *:not(svg):not(path),
        .details-modal-panel *:not(svg):not(path),
        .details-modal-content *:not(svg):not(path) {
            min-width: 0 !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
        }

        .modal-content [style*="white-space:nowrap"],
        .modal-panel [style*="white-space:nowrap"],
        .modal-box [style*="white-space:nowrap"],
        .details-modal-panel [style*="white-space:nowrap"],
        .details-modal-content [style*="white-space:nowrap"] {
            white-space: normal !important;
        }

        .branch-dropdown,
        .sort-dropdown,
        .export-dropdown-wide,
        .dropdown-menu,
        [data-pf-profile-menu],
        [data-pf-notif-menu] {
            z-index: 11020 !important;
            max-width: calc(100vw - 24px) !important;
        }

        .sort-dropdown,
        .filter-panel {
            position: fixed !important;
            top: 96px !important;
            left: 50% !important;
            right: auto !important;
            bottom: auto !important;
            transform: translateX(-50%) !important;
            width: min(420px, calc(100vw - 32px)) !important;
            min-width: min(420px, calc(100vw - 32px)) !important;
            max-width: calc(100vw - 32px) !important;
            max-height: calc(100dvh - 128px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            border-radius: 8px !important;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.22) !important;
            z-index: 11030 !important;
        }

        .sort-dropdown:not(.export-dropdown-wide) {
            width: min(320px, calc(100vw - 32px)) !important;
            min-width: min(320px, calc(100vw - 32px)) !important;
        }

        .filter-panel .filter-section,
        .filter-panel .filter-actions,
        .filter-panel .filter-panel-header,
        .filter-panel .filter-panel-footer {
            padding-left: 18px !important;
            padding-right: 18px !important;
        }

        .filter-panel .filter-panel-header {
            padding-right: 56px !important;
        }

        .filter-panel .filter-date-row,
        .filter-panel .form-row,
        .filter-panel .filter-grid {
            display: grid !important;
            grid-template-columns: 1fr !important;
            gap: 10px !important;
        }

        .filter-panel input,
        .filter-panel select,
        .filter-panel button {
            max-width: 100% !important;
        }

        .main-content>header .branch-dropdown,
        .main-content>.top-bar .branch-dropdown {
            right: 0 !important;
            left: auto !important;
        }

        .pf-admin-scroll-top {
            position: fixed !important;
            right: 14px !important;
            bottom: 14px !important;
            z-index: 880 !important;
            width: 42px !important;
            height: 42px !important;
            border: 1px solid rgba(13, 148, 136, 0.35) !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: #0f172a !important;
            color: #ffffff !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22) !important;
            cursor: pointer !important;
            opacity: 1 !important;
            transform: translateY(0) scale(1) !important;
            transition: opacity 0.22s ease, transform 0.22s ease !important;
        }

        .pf-admin-scroll-top-hidden {
            opacity: 0 !important;
            pointer-events: none !important;
            transform: translateY(12px) scale(0.94) !important;
        }

        #dash-sales-chart-wrap,
        .trend12-chart {
            overflow-x: auto !important;
            overflow-y: hidden !important;
            -webkit-overflow-scrolling: touch;
        }

        .pf-wide-chart-canvas {
            width: 820px !important;
            min-width: 820px !important;
            max-width: none !important;
            height: 100% !important;
        }

        .pf-wide-chart-canvas canvas,
        #dash-sales-chart-wrap canvas,
        .trend12-chart canvas {
            min-width: 820px !important;
            width: 820px !important;
            max-width: none !important;
        }
    }
</style>
<script>
    (function () {
        function printflowBootCharts() {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    try {
                        if (document.getElementById('reportsFilterForm') && document.getElementById('salesChart') && typeof window.printflowInitReportsCharts === 'function') {
                            window.printflowInitReportsCharts();
                        } else if (document.getElementById('dashSalesChart') && typeof window.printflowInitDashboardCharts === 'function') {
                            window.printflowInitDashboardCharts();
                        } else if (document.getElementById('salesChart') && typeof window.printflowInitDashboardCharts === 'function') {
                            window.printflowInitDashboardCharts();
                        }
                    } catch (e) { }
                });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', printflowBootCharts);
        } else {
            printflowBootCharts();
        }
    })();
</script>
