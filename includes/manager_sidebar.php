<?php
// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_name'] ?? 'Manager';
$user_initial = strtoupper(substr($user_name, 0, 1));
require_once __DIR__ . '/shop_config.php';

// Load config for BASE_PATH
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
$base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$logout_url = $base_path . '/logout';

// Get profile picture for sidebar
$sidebar_profile_pic = '';
if (isset($_SESSION['user_id'])) {
    $sidebar_user = db_query("SELECT profile_picture FROM users WHERE user_id = ?", 'i', [$_SESSION['user_id']]);
    if (!empty($sidebar_user) && !empty($sidebar_user[0]['profile_picture'])) {
        $sidebar_profile_pic = function_exists('get_profile_image')
            ? get_profile_image($sidebar_user[0]['profile_picture'])
            : $base_path . '/public/assets/uploads/profiles/' . basename($sidebar_user[0]['profile_picture']);
    }

    // Get unread notification count
    $unread_notif_count = get_unread_notification_count((int)$_SESSION['user_id'], 'Manager');
} else {
    $unread_notif_count = 0;
}
?>
<div id="printflow-persistent-sidebar" data-turbo-permanent>
<?php include __DIR__ . '/sidebar_layout_boot.php'; ?>

<!-- Mobile Sidebar Overlay -->
<div id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

<!-- Mobile Burger Menu Button -->
<button id="mobileBurger" onclick="toggleMobileSidebar()" aria-label="Toggle menu">
    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
</button>

<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <a href="<?php echo $base_path; ?>/manager/dashboard.php" class="logo">
            <?php echo get_logo_html('30px'); ?>
            <span><?php echo $shop_name; ?></span>
        </a>
        <button id="sidebarCollapseBtn" class="sidebar-collapse-btn" onclick="toggleSidebar()" title="Collapse sidebar">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
    </div>

    <nav class="sidebar-nav">
        <!-- Business Management -->
        <div class="nav-section">
            <div class="nav-section-title">Business</div>
            <a href="<?php echo $base_path; ?>/manager/dashboard.php" class="nav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo $base_path; ?>/manager/orders.php" class="nav-item <?php echo $current_page === 'orders.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                Orders
            </a>

            <a href="<?php echo $base_path; ?>/manager/customizations.php" class="nav-item <?php echo in_array($current_page, ['job_orders.php','customizations.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Customization
            </a>

            <a href="<?php echo $base_path; ?>/manager/customers.php" class="nav-item <?php echo $current_page === 'customers.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                Customers
            </a>
            <a href="<?php echo $base_path; ?>/manager/products.php" class="nav-item <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                Products
            </a>
            <a href="<?php echo $base_path; ?>/manager/services.php" class="nav-item <?php echo $current_page === 'services.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                Services
            </a>
            <a href="<?php echo $base_path; ?>/manager/inventory_items.php" class="nav-item <?php echo in_array($current_page, ['inventory.php', 'inventory_items.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                Inventory Items
            </a>
            <a href="<?php echo $base_path; ?>/manager/inv_transactions_ledger.php" class="nav-item <?php echo $current_page === 'inv_transactions_ledger.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Inventory Ledger
            </a>
            <a href="<?php echo $base_path; ?>/manager/reports.php" class="nav-item <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Reports
            </a>
        </div>

        <!-- System -->
        <div class="nav-section">
            <div class="nav-section-title">System</div>
            <a href="<?php echo $base_path; ?>/manager/notifications.php" class="nav-item <?php echo $current_page === 'notifications.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                Notifications
                <span id="sidebar-notif-badge" data-notif-badge class="nav-badge" style="visibility:<?php echo ($unread_notif_count > 0 ? 'visible' : 'hidden'); ?>;"><?php echo $unread_notif_count > 99 ? '99+' : ($unread_notif_count > 0 ? (int)$unread_notif_count : ''); ?></span>
            </a>
        </div>

        <!-- Account -->
        <div class="nav-section">
            <div class="nav-section-title">Account</div>
            <a href="<?php echo $base_path; ?>/manager/profile.php" class="nav-item <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                My Profile
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <a href="<?php echo $base_path; ?>/manager/profile.php" class="user-profile" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px; width:100%;">
            <div class="user-avatar" style="flex-shrink:0; overflow:hidden;">
                <?php if ($sidebar_profile_pic): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_profile_pic); ?>?t=<?php echo time(); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <?php echo $user_initial; ?>
                <?php endif; ?>
            </div>
            <div class="user-info" style="min-width:0;">
                <div class="user-name-display" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role">Manager</div>
            </div>
        </a>
        <button onclick="document.getElementById('logoutModal').style.display='flex'" class="logout-btn-footer" title="Log out">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Log out</span>
        </button>
    </div>
</aside>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:white; border-radius:16px; padding:32px; width:100%; max-width:380px; margin:16px; box-shadow:0 25px 50px rgba(0,0,0,0.25); text-align:center;">
        <div style="width:56px; height:56px; border-radius:50%; background:#fef2f2; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <svg width="28" height="28" fill="none" stroke="#ef4444" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
        </div>
        <h3 style="font-size:18px; font-weight:700; color:#1f2937; margin:0 0 8px;">Log Out</h3>
        <p style="font-size:14px; color:#6b7280; margin:0 0 24px;">Are you sure you want to log out of your manager account?</p>
        <div style="display:flex; gap:10px;">
            <button onclick="document.getElementById('logoutModal').style.display='none'" style="flex:1; padding:10px; border:1px solid #e5e7eb; background:white; border-radius:8px; font-size:14px; font-weight:600; color:#374151; cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">Cancel</button>
            <a href="<?php echo htmlspecialchars($logout_url); ?>" data-turbo="false" style="flex:1; padding:10px; background:#ef4444; border:none; border-radius:8px; font-size:14px; font-weight:600; color:white; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; transition:background 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Log Out</a>
        </div>
    </div>
</div>

<script>
// Sidebar collapse toggle
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar || sidebar.dataset.pfToggleLock === '1') return;
    sidebar.dataset.pfToggleLock = '1';
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
    window.setTimeout(function () { sidebar.dataset.pfToggleLock = '0'; }, 320);

    const btn = document.getElementById('sidebarCollapseBtn');
    if (isCollapsed) {
        btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>';
        btn.title = 'Expand sidebar';
    } else {
        btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>';
        btn.title = 'Collapse sidebar';
    }
}

// Restore sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const collapsed = localStorage.getItem('sidebarCollapsed') === 'true' || localStorage.getItem('sidebarCollapsed') === '1';
    const btn = document.getElementById('sidebarCollapseBtn');
    if (collapsed) {
        sidebar.classList.add('collapsed');
        if (btn) {
            btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>';
            btn.title = 'Expand sidebar';
        }
    } else {
        sidebar.classList.remove('collapsed');
        if (btn) {
            btn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>';
            btn.title = 'Collapse sidebar';
        }
    }
    document.body.classList.remove('sidebar-collapsed');
    document.documentElement.classList.remove('sidebar-preload-collapsed');
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.documentElement.classList.add('sidebar-transitions-enabled');
        });
    });
});

// Mobile burger menu toggle
function toggleMobileSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const isActive = sidebar.classList.toggle('active');

    if (isActive) {
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    } else {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close mobile sidebar when clicking outside
document.addEventListener('click', function(event) {
    if (window.innerWidth <= 768) {
        const sidebar = document.getElementById('adminSidebar');
        const burger = document.getElementById('mobileBurger');
        if (sidebar && burger && sidebar.classList.contains('active')) {
            if (!sidebar.contains(event.target) && !burger.contains(event.target)) {
                toggleMobileSidebar();
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var nav = document.querySelector('#printflow-persistent-sidebar .sidebar-nav') || document.querySelector('.sidebar-nav');
    var sidebar = document.getElementById('adminSidebar');
    if (!nav || !sidebar) return;

    var scrollKey = 'printflow_manager_sidebar_nav_scroll';
    function clampNavScroll(y) {
        var max = Math.max(0, nav.scrollHeight - nav.clientHeight);
        return Math.max(0, Math.min(y, max));
    }
    if (!sidebar.classList.contains('collapsed')) {
        var saved = null;
        try { saved = sessionStorage.getItem(scrollKey); } catch (e) {}
        if (saved !== null && saved !== '') {
            var y = parseInt(saved, 10);
            if (!isNaN(y)) {
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        nav.scrollTop = clampNavScroll(y);
                    });
                });
            }
        } else {
            var activeItem = nav.querySelector('a.nav-item.active');
            if (activeItem) {
                requestAnimationFrame(function() {
                    activeItem.scrollIntoView({ block: 'nearest', behavior: 'auto' });
                });
            }
        }
    }

    var shell = document.getElementById('printflow-persistent-sidebar');
    if (shell) {
        shell.addEventListener('click', function(ev) {
            var a = ev.target.closest && ev.target.closest('a[href]');
            if (!a || !shell.contains(a)) return;
            var href = a.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#') return;
            try {
                sessionStorage.setItem(scrollKey, String(nav.scrollTop));
            } catch (e) {}
        }, true);
    }
    nav.querySelectorAll('a.nav-item').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                var sb = document.getElementById('adminSidebar');
                var overlay = document.getElementById('sidebarOverlay');
                if (sb && sb.classList.contains('active')) {
                    sb.classList.remove('active');
                    if (overlay) overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });
    });
});

    // Notification badge updates are handled by notifications.js (loaded below)
</script>

<?php
require_once __DIR__ . '/shop_config.php';
$_pf_uid   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id']   : 0;
$_pf_utype = isset($_SESSION['user_type']) ? $_SESSION['user_type']       : 'Manager';
?>
<script>
window.PFConfig = { userId: <?php echo json_encode($_pf_uid); ?>, userType: <?php echo json_encode($_pf_utype); ?>, sessionId: <?php echo json_encode(session_id()); ?>, basePath: <?php echo json_encode($base_path); ?>, logoUrl: <?php echo json_encode($shop_logo_url); ?>, logoutUrl: <?php echo json_encode($base_path . '/logout'); ?>, loginUrl: <?php echo json_encode($base_path . '/?auth_modal=login'); ?>, sessionStatusUrl: <?php echo json_encode($base_path . '/public/api_session_status.php'); ?> };
(function() {
    function supportPresenceBeat() {
        var base = (window.PFConfig && window.PFConfig.basePath) || '';
        fetch(base + '/public/api/support_chat_presence.php', { credentials: 'same-origin' }).catch(function() {});
    }
    supportPresenceBeat();
    setInterval(supportPresenceBeat, 10000);
    window.addEventListener('focus', supportPresenceBeat);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') supportPresenceBeat();
    });
})();
</script>
<?php $notif_js_ver = @filemtime(__DIR__ . '/../public/assets/js/notifications.js') ?: time(); ?>
<script src="<?php echo $base_path; ?>/public/assets/js/notifications.js?v=<?php echo $notif_js_ver; ?>" defer></script>
<script src="<?php echo $base_path; ?>/public/assets/js/inactivity_logout.js" defer></script>
</div>
