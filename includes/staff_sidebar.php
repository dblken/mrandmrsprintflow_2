<?php
// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_name'] ?? 'Staff';
$user_initial = strtoupper(substr($user_name, 0, 1));
$is_pending = isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'Pending';
$staff_access_meta = function_exists('printflow_get_staff_access_meta') ? printflow_get_staff_access_meta() : ['key' => 'online', 'short_label' => 'Staff'];
$is_pos_staff = ($staff_access_meta['key'] ?? '') === 'pos';
$is_online_staff = ($staff_access_meta['key'] ?? '') === 'online';
require_once __DIR__ . '/shop_config.php';

// Load config for BASE_PATH
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
$base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$logout_url = $base_path . '/logout';
$sidebar_profile_pic = '';
$staff_branch_label = '';
if (!$is_pending && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Staff') {
    require_once __DIR__ . '/branch_context.php';
    $staff_branch_label = (string) (init_branch_context(false)['branch_name'] ?? '');
}

// Unread notification count for badge
if (!function_exists('db_query')) require_once __DIR__ . '/db.php';
$_staff_unread_notif = 0;
if (isset($_SESSION['user_id'])) {
    $_staff_unread_notif = get_unread_notification_count((int)$_SESSION['user_id'], 'Staff');
    $sidebar_user = db_query("SELECT profile_picture FROM users WHERE user_id = ?", 'i', [$_SESSION['user_id']]);
    if (!empty($sidebar_user) && !empty($sidebar_user[0]['profile_picture'])) {
        $sidebar_profile_pic = function_exists('get_profile_image')
            ? get_profile_image($sidebar_user[0]['profile_picture'])
            : $base_path . '/public/assets/uploads/profiles/' . basename($sidebar_user[0]['profile_picture']);
    }
}
?>
<div id="printflow-persistent-sidebar" data-turbo-permanent>
<?php include __DIR__ . '/sidebar_layout_boot.php'; ?>

<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <a href="<?php echo $is_pending ? 'profile' : 'dashboard'; ?>" class="logo">
            <?php echo get_logo_html('30px'); ?>
            <span><?php echo $shop_name; ?></span>
        </a>
        <button id="global-sidebar-toggle" class="sidebar-collapse-btn" type="button" title="Collapse sidebar">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="sidebar-toggle-icon">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <?php if ($is_pending): ?>
        <!-- Pending Staff: Only Profile visible -->
        <div class="nav-section">
            <div class="nav-section-title">Account</div>
            <a href="profile" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Complete Profile
            </a>
        </div>
        <div style="padding: 16px 20px;">
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px; font-size: 12px; color: #92400e; line-height: 1.5;">
                <strong style="display:block; margin-bottom:4px;">⏳ Account Pending</strong>
                Complete your profile information. Once approved by an admin, you'll have full access.
            </div>
        </div>
        <?php else: ?>
        <!-- Activated Staff: Full navigation -->
        <?php if ($staff_branch_label !== '' && $staff_branch_label !== 'All Branches'): ?>
        <div style="padding: 10px 20px 0; font-size: 11px; color: rgba(255,255,255,0.65);">
            Branch: <strong style="color: rgba(255,255,255,0.95);"><?php echo htmlspecialchars($staff_branch_label); ?></strong>
        </div>
        <?php endif; ?>
        <!-- Operations -->
        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <a href="<?php echo $base_path; ?>/staff/dashboard.php" class="nav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
            <?php if ($is_pos_staff): ?>
            <a href="<?php echo $base_path; ?>/staff/pos.php" class="nav-item <?php echo $current_page === 'pos.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                POS (Walk-in)
            </a>
            <?php endif; ?>
            <a href="<?php echo $base_path; ?>/staff/orders.php" class="nav-item <?php echo in_array($current_page, ['orders.php', 'order_details.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <?php echo $is_pos_staff ? 'Walk-in Orders' : 'Online Orders'; ?>
            </a>
            <?php if ($is_online_staff): ?>
            <a href="<?php echo $base_path; ?>/staff/payment_verification.php" class="nav-item <?php echo $current_page === 'payment_verification.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Payment Verification
            </a>
            <?php endif; ?>
            <?php if ($is_online_staff): ?>
            <a href="<?php echo $base_path; ?>/staff/chats.php" class="nav-item <?php echo $current_page === 'chats.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                Chats
            </a>
            <?php endif; ?>
            <a href="<?php echo $base_path; ?>/staff/customizations.php" class="nav-item <?php echo $current_page === 'customizations.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                Customizations
            </a>
            <?php /* HIDDEN: Customizations V2 page is no longer in use
            <a href="<?php echo $base_path; ?>/staff/customizations_v2.php" class="nav-item <?php echo $current_page === 'customizations_v2.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
                Customizations V2
            </a>
            */ ?>
            <a href="<?php echo $base_path; ?><?php echo $is_online_staff ? '/staff/online/products.php' : '/staff/products.php'; ?>" class="nav-item <?php echo $current_page === 'products.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                Products
            </a>
            <a href="<?php echo $base_path; ?>/staff/reports.php" class="nav-item <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Reports
            </a>
            <?php if ($is_online_staff): ?>
            <a href="<?php echo $base_path; ?>/staff/reviews.php" class="nav-item <?php echo $current_page === 'reviews.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.14 3.51a1 1 0 00.95.69h3.69c.969 0 1.371 1.24.588 1.81l-2.985 2.168a1 1 0 00-.363 1.118l1.14 3.51c.3.921-.755 1.688-1.539 1.118l-2.985-2.168a1 1 0 00-1.176 0l-2.985 2.168c-.783.57-1.838-.197-1.539-1.118l1.14-3.51a1 1 0 00-.363-1.118L2.98 8.937c-.783-.57-.38-1.81.588-1.81h3.69a1 1 0 00.95-.69l1.14-3.51z"/>
                </svg>
                Reviews
            </a>
            <?php endif; ?>
        </div>
        
        <!-- System -->
        <div class="nav-section">
            <div class="nav-section-title">System</div>
            <a href="<?php echo $base_path; ?>/staff/notifications.php" class="nav-item <?php echo $current_page === 'notifications.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                Notifications
                <span id="sidebar-notif-badge" data-notif-badge class="nav-badge nav-badge--sidebar-slot" style="visibility:<?php echo $_staff_unread_notif > 0 ? 'visible' : 'hidden'; ?>;"><?php echo $_staff_unread_notif > 99 ? '99+' : ($_staff_unread_notif > 0 ? (int)$_staff_unread_notif : ''); ?></span>
            </a>
        </div>
        <?php endif; ?>
    </nav>
    
    <div class="sidebar-footer">
        <a href="<?php echo $base_path; ?>/staff/profile.php" class="user-profile" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 6px; transition: background 0.2s;">
            <div class="user-avatar">
                <?php if ($sidebar_profile_pic): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_profile_pic); ?>?t=<?php echo time(); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.onerror=null;this.parentNode.textContent='<?php echo htmlspecialchars($user_initial, ENT_QUOTES, 'UTF-8'); ?>';">
                <?php else: ?>
                    <?php echo $user_initial; ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name-display"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($staff_access_meta['short_label'] ?? 'Staff'); ?><?php if ($is_pending): ?> <span style="color:#f59e0b;">• Pending</span><?php endif; ?></div>
            </div>
        </a>
        <button type="button" onclick="document.getElementById('staffLogoutModal').style.display='flex'" class="logout-btn-footer" title="Log out">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Log out</span>
        </button>
    </div>
</aside>

<!-- Logout confirmation (matches admin sidebar pattern) -->
<div id="staffLogoutModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:white; border-radius:16px; padding:32px; width:100%; max-width:380px; margin:16px; box-shadow:0 25px 50px rgba(0,0,0,0.25); text-align:center;">
        <div style="width:56px; height:56px; border-radius:50%; background:#fef2f2; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <svg width="28" height="28" fill="none" stroke="#ef4444" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
        </div>
        <h3 style="font-size:18px; font-weight:700; color:#1f2937; margin:0 0 8px;">Log Out</h3>
        <p style="font-size:14px; color:#6b7280; margin:0 0 24px;">Are you sure you want to log out of your staff account?</p>
        <div style="display:flex; gap:10px;">
            <button type="button" onclick="document.getElementById('staffLogoutModal').style.display='none'" style="flex:1; padding:10px; border:1px solid #e5e7eb; background:white; border-radius:8px; font-size:14px; font-weight:600; color:#374151; cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">Cancel</button>
            <a href="<?php echo htmlspecialchars($logout_url); ?>" data-turbo="false" style="flex:1; padding:10px; background:#ef4444; border:none; border-radius:8px; font-size:14px; font-weight:600; color:white; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; transition:background 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Log Out</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('global-sidebar-toggle');
    const sidebar = document.getElementById('adminSidebar');
    const toggleIcon = document.getElementById('sidebar-toggle-icon');
    
    // Check localStorage for saved state
    const collapsed = localStorage.getItem('sidebarCollapsed') === 'true' || localStorage.getItem('sidebarCollapsed') === '1';
    if (collapsed) {
        sidebar.classList.add('collapsed');
        if (toggleIcon) {
            const path = toggleIcon.querySelector('path');
            if (path) {
                path.setAttribute('d', 'M9 5l7 7-7 7');
            }
            toggleBtn.title = 'Expand sidebar';
        }
    } else {
        sidebar.classList.remove('collapsed');
        if (toggleIcon) {
            const path = toggleIcon.querySelector('path');
            if (path) {
                path.setAttribute('d', 'M15 19l-7-7 7-7');
            }
            toggleBtn.title = 'Collapse sidebar';
        }
    }
    document.body.classList.remove('sidebar-collapsed');
    document.documentElement.classList.remove('sidebar-preload-collapsed');
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.documentElement.classList.add('sidebar-transitions-enabled');
        });
    });
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (sidebar.dataset.pfToggleLock === '1') return;
            sidebar.dataset.pfToggleLock = '1';
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
            window.setTimeout(function () { sidebar.dataset.pfToggleLock = '0'; }, 320);
            
            if (toggleIcon) {
                const path = toggleIcon.querySelector('path');
                if (path) {
                    path.setAttribute('d', isCollapsed ? 'M9 5l7 7-7 7' : 'M15 19l-7-7 7-7');
                }
                toggleBtn.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
            }
        });
    }

    var nav = document.querySelector('#printflow-persistent-sidebar .sidebar-nav') || document.querySelector('.sidebar-nav');
    if (nav && sidebar) {
        var scrollKey = 'printflow_staff_sidebar_nav_scroll';
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
    }
});
</script>

<div id="pf-fg-portal" class="pf-fg-portal" aria-hidden="true"></div>

<?php
require_once __DIR__ . '/shop_config.php';
$_pf_uid   = isset($_SESSION['user_id'])   ? (int)$_SESSION['user_id']   : 0;
$_pf_utype = isset($_SESSION['user_type']) ? $_SESSION['user_type']       : 'Staff';
?>
<script>
window.PFConfig = { 
    userId: <?php echo json_encode($_pf_uid); ?>, 
    userType: <?php echo json_encode($_pf_utype); ?>, 
    userAvatar: <?php echo json_encode($sidebar_profile_pic); ?>,
    userName: <?php echo json_encode($user_name); ?>,
    sessionId: <?php echo json_encode(session_id()); ?>, 
    basePath: <?php echo json_encode($base_path); ?>, 
    logoUrl: <?php echo json_encode($shop_logo_url); ?>, 
    logoutUrl: <?php echo json_encode($base_path . '/logout'); ?>, 
    loginUrl: <?php echo json_encode($base_path . '/?auth_modal=login'); ?>, 
    sessionStatusUrl: <?php echo json_encode($base_path . '/public/api_session_status.php'); ?> 
};
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
