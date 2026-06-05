<?php
// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_name'] ?? 'Admin';
$user_initial = strtoupper(substr($user_name, 0, 1));
require_once __DIR__ . '/shop_config.php';

// Load config for BASE_PATH
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/customer_id_verification.php';
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
    $unread_notif_result = db_query("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0", 'i', [$_SESSION['user_id']]);
    $unread_notif_count = $unread_notif_result[0]['count'] ?? 0;
} else {
    $unread_notif_count = 0;
}
?>
<div id="printflow-persistent-sidebar" data-turbo-permanent>
<?php include __DIR__ . '/sidebar_layout_boot.php'; ?>

<!-- Mobile Sidebar Overlay -->
<div id="sidebarOverlay" onclick="toggleMobileSidebar(event, false)"></div>

<!-- Mobile Burger Menu Button -->
<button id="mobileBurger" onclick="toggleMobileSidebar(event)" aria-label="Toggle menu" aria-expanded="false" aria-controls="adminSidebar">
    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
</button>

<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <a href="<?php echo $base_path; ?>/admin/dashboard.php" class="logo">
            <?php echo get_logo_html('30px'); ?>
            <span style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal; line-height: 1.3;"><?php echo $shop_name; ?></span>
        </a>
        <button id="global-sidebar-toggle" class="sidebar-collapse-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="sidebar-toggle-icon">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <!-- Business Management -->
        <div class="nav-section">
            <div class="nav-section-title">Business</div>
            <a href="<?php echo $base_path; ?>/admin/dashboard.php" class="nav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo $base_path; ?>/admin/orders_management.php" class="nav-item <?php echo $current_page === 'orders_management.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                Orders
            </a>

            <a href="<?php echo $base_path; ?>/admin/customizations.php" class="nav-item <?php echo in_array($current_page, ['job_orders.php','customizations.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Customization
            </a>

            <?php
            $customers_nav_active = in_array($current_page, ['customers_management.php', 'customer_verification.php'], true);
            $pending_verification_count = pf_count_customer_verification_pending();
            ?>
            <div class="nav-group<?php echo $customers_nav_active ? ' expanded' : ''; ?>" data-nav-group="customers" data-nav-initial-expanded="<?php echo $customers_nav_active ? '1' : '0'; ?>">
                <button
                    type="button"
                    class="nav-item nav-parent <?php echo $customers_nav_active ? 'active' : ''; ?>"
                    data-nav-toggle="customers"
                    aria-expanded="<?php echo $customers_nav_active ? 'true' : 'false'; ?>"
                    aria-controls="nav-subitems-customers"
                >
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span class="nav-label">Customers</span>
                    <svg class="nav-chevron" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
                <div class="nav-subitems" id="nav-subitems-customers">
                    <a href="<?php echo $base_path; ?>/admin/customers_management.php" class="nav-subitem <?php echo $current_page === 'customers_management.php' ? 'active' : ''; ?>" data-nav-page="customers_management.php">
                        Customer List
                    </a>
                    <a href="<?php echo $base_path; ?>/admin/customer_verification.php" class="nav-subitem <?php echo $current_page === 'customer_verification.php' ? 'active' : ''; ?>" data-nav-page="customer_verification.php">
                        Verification
                        <?php if ($pending_verification_count > 0): ?>
                        <span class="nav-badge"><?php echo $pending_verification_count > 99 ? '99+' : (int)$pending_verification_count; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
            <script>
            (function () {
                var group = document.querySelector('[data-nav-group="customers"]');
                if (!group) return;
                try {
                    var saved = sessionStorage.getItem('printflow_nav_customers_expanded');
                    var btn = group.querySelector('[data-nav-toggle]');
                    if (saved === '1') {
                        group.classList.add('expanded');
                        if (btn) btn.setAttribute('aria-expanded', 'true');
                    } else if (saved === '0') {
                        group.classList.remove('expanded');
                        if (btn) btn.setAttribute('aria-expanded', 'false');
                    } else if (group.dataset.navInitialExpanded === '1') {
                        sessionStorage.setItem('printflow_nav_customers_expanded', '1');
                    }
                } catch (e) {}
            })();
            </script>
            <a href="<?php echo $base_path; ?>/admin/products_management.php" class="nav-item <?php echo $current_page === 'products_management.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                Products
            </a>
            <a href="<?php echo $base_path; ?>/admin/services.php" class="nav-item <?php echo in_array($current_page, ['services.php', 'services_management.php'], true) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                Services
            </a>
            <a href="<?php echo $base_path; ?>/admin/inv_items_management.php" class="nav-item <?php echo in_array($current_page, ['inv_items_management.php', 'inventory_management.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
                Inventory Items
            </a>
            <a href="<?php echo $base_path; ?>/admin/inv_transactions_ledger.php" class="nav-item <?php echo in_array($current_page, ['inv_transactions_ledger.php', 'inventory_monthly.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Inventory Ledger
            </a>
            <a href="<?php echo $base_path; ?>/admin/branches_management.php" class="nav-item <?php echo $current_page === 'branches_management.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                Branches
            </a>
            <a href="<?php echo $base_path; ?>/admin/reports.php" class="nav-item <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Reports
            </a>
        </div>
        
        <!-- System Management -->
        <div class="nav-section">
            <div class="nav-section-title">System</div>
            <a href="<?php echo $base_path; ?>/admin/user_staff_management.php" class="nav-item <?php echo in_array($current_page, ['user_staff_management.php','create_manager.php']) ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                Team Management
            </a>

            <a href="<?php echo $base_path; ?>/admin/faq_chatbot_management.php" class="nav-item <?php echo $current_page === 'faq_chatbot_management.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                </svg>
                Support chat
            </a>
            <a href="<?php echo $base_path; ?>/admin/notifications.php" class="nav-item <?php echo $current_page === 'notifications.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                Notifications
                <span id="sidebar-notif-badge" data-notif-badge class="nav-badge" style="visibility:<?php echo ($unread_notif_count > 0 ? 'visible' : 'hidden'); ?>;"><?php echo $unread_notif_count > 99 ? '99+' : ($unread_notif_count > 0 ? (int)$unread_notif_count : ''); ?></span>
            </a>
            <a href="<?php echo $base_path; ?>/admin/settings.php" class="nav-item <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Settings
            </a>
        </div>
        
        <!-- Maintenance -->
        <div class="nav-section">
            <div class="nav-section-title">Maintenance</div>
            <a href="<?php echo $base_path; ?>/admin/activity_logs.php" class="nav-item <?php echo $current_page === 'activity_logs.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Activity Logs
            </a>
        </div>

        <!-- Account -->
        <div class="nav-section">
            <div class="nav-section-title">Account</div>
            <a href="<?php echo $base_path; ?>/admin/profile.php" class="nav-item <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                My Profile
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <a href="<?php echo $base_path; ?>/admin/profile.php" class="user-profile" style="text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px; width:100%;">
            <div class="user-avatar" style="flex-shrink:0; overflow:hidden;">
                <?php if ($sidebar_profile_pic): ?>
                    <img src="<?php echo htmlspecialchars($sidebar_profile_pic); ?>?t=<?php echo time(); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <?php echo $user_initial; ?>
                <?php endif; ?>
            </div>
            <div class="user-info" style="min-width:0;">
                <div class="user-name-display" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($_SESSION['user_type'] ?? 'Admin'); ?></div>
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
        <p style="font-size:14px; color:#6b7280; margin:0 0 24px;">Are you sure you want to log out of your admin account?</p>
        <div style="display:flex; gap:10px;">
            <button onclick="document.getElementById('logoutModal').style.display='none'" style="flex:1; padding:10px; border:1px solid #e5e7eb; background:white; border-radius:8px; font-size:14px; font-weight:600; color:#374151; cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">Cancel</button>
            <a href="<?php echo htmlspecialchars($logout_url); ?>" data-turbo="false" style="flex:1; padding:10px; background:#ef4444; border:none; border-radius:8px; font-size:14px; font-weight:600; color:white; cursor:pointer; text-decoration:none; display:flex; align-items:center; justify-content:center; transition:background 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">Log Out</a>
        </div>
    </div>
</div>

<div id="pf-fg-portal" class="pf-fg-portal" aria-hidden="true"></div>

<script>
// Sidebar collapse toggle (matches staff sidebar behavior)
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar || sidebar.dataset.pfToggleLock === '1') return;
    sidebar.dataset.pfToggleLock = '1';
    const isCollapsed = sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
    window.setTimeout(function () { sidebar.dataset.pfToggleLock = '0'; }, 320);
    
    // Update button icon (chevron flips: left = expanded, right = collapsed)
    const iconEl = document.getElementById('sidebar-toggle-icon');
    const path = iconEl ? (iconEl.tagName === 'path' ? iconEl : iconEl.querySelector('path')) : null;
    const btn = document.getElementById('global-sidebar-toggle');
    if (path && btn) {
        path.setAttribute('d', isCollapsed ? 'M9 5l7 7-7 7' : 'M15 19l-7-7 7-7');
        btn.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
}

// Restore sidebar state immediately (re-run on body swap if needed)
function printflowSyncSidebarState() {
    const sidebar = document.getElementById('adminSidebar');
    const toggleIcon = document.getElementById('sidebar-toggle-icon');
    if (!sidebar) return;
    
    const collapsed = localStorage.getItem('sidebarCollapsed') === 'true' || localStorage.getItem('sidebarCollapsed') === '1';
    if (collapsed) {
        sidebar.classList.add('collapsed');
        const path = toggleIcon ? (toggleIcon.tagName === 'path' ? toggleIcon : toggleIcon.querySelector('path')) : null;
        if (path) path.setAttribute('d', 'M9 5l7 7-7 7');
    } else {
        sidebar.classList.remove('collapsed');
        const path = toggleIcon ? (toggleIcon.tagName === 'path' ? toggleIcon : toggleIcon.querySelector('path')) : null;
        if (path) path.setAttribute('d', 'M15 19l-7-7 7-7');
    }
    document.body.classList.remove('sidebar-collapsed');
    document.documentElement.classList.remove('sidebar-preload-collapsed');
}

document.addEventListener('DOMContentLoaded', printflowSyncSidebarState);
document.addEventListener('printflow:page-init', printflowSyncSidebarState);

// Mobile burger menu toggle
function toggleMobileSidebar(event, forceOpen) {
    if (event && typeof event.stopImmediatePropagation === 'function') {
        event.stopImmediatePropagation();
    } else if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const burger = document.getElementById('mobileBurger');
    if (!sidebar || !overlay) return;

    const isActive = typeof forceOpen === 'boolean'
        ? forceOpen
        : !sidebar.classList.contains('active');

    sidebar.classList.toggle('active', isActive);
    overlay.classList.toggle('active', isActive);
    if (burger) burger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
    
    if (isActive) {
        document.body.style.overflow = 'hidden'; // Prevent body scroll
    } else {
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
                toggleMobileSidebar(null, false);
            }
        }
    }
});

    function printflowNavPath(href) {
        try {
            var u = new URL(href, window.location.href);
            var p = u.pathname.replace(/\/+$/, '') || '/';
            return p.replace(/\.php$/i, '');
        } catch (e) {
            return '';
        }
    }

    function printflowSetCustomersNavExpanded(group, expanded, persist) {
        if (!group) return;
        group.classList.toggle('expanded', expanded);
        var btn = group.querySelector('[data-nav-toggle]');
        if (btn) btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (persist !== false) {
            try {
                sessionStorage.setItem('printflow_nav_customers_expanded', expanded ? '1' : '0');
            } catch (e) {}
        }
    }

    function printflowSyncCustomersNavActiveOnly() {
        var group = document.querySelector('[data-nav-group="customers"]');
        if (!group) return;

        var currentPath = printflowNavPath(window.location.href);
        var anyActive = false;

        group.querySelectorAll('.nav-subitem').forEach(function (link) {
            var isActive = printflowNavPath(link.href) === currentPath;
            link.classList.toggle('active', isActive);
            if (isActive) anyActive = true;
        });

        var parent = group.querySelector('.nav-parent');
        if (parent) parent.classList.toggle('active', anyActive);
    }

    function printflowInitCustomersNavGroup() {
        var group = document.querySelector('[data-nav-group="customers"]');
        if (!group) return;

        var btn = group.querySelector('[data-nav-toggle]');
        if (btn && btn.dataset.pfNavBound !== '1') {
            btn.dataset.pfNavBound = '1';
            btn.addEventListener('click', function () {
                var expanded = !group.classList.contains('expanded');
                printflowSetCustomersNavExpanded(group, expanded, true);
            });
        }

        if (group.dataset.pfSubBound !== '1') {
            group.dataset.pfSubBound = '1';
            group.querySelectorAll('.nav-subitem').forEach(function (link) {
                link.addEventListener('click', function () {
                    printflowSetCustomersNavExpanded(group, true, true);
                });
            });
        }

        printflowSyncCustomersNavActiveOnly();
    }

    document.addEventListener('DOMContentLoaded', printflowInitCustomersNavGroup);
    document.addEventListener('printflow:page-init', printflowSyncCustomersNavActiveOnly);

    document.addEventListener('DOMContentLoaded', function() {
        var nav = document.querySelector('#printflow-persistent-sidebar .sidebar-nav') || document.querySelector('.sidebar-nav');
        var sidebar = document.getElementById('adminSidebar');
        if (!nav || !sidebar) return;

        var scrollKey = 'printflow_admin_sidebar_nav_scroll';
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
                var activeItem = nav.querySelector('a.nav-subitem.active') || nav.querySelector('a.nav-item.active');
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
        nav.querySelectorAll('a.nav-item, a.nav-subitem').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    var sb = document.getElementById('adminSidebar');
                    var overlay = document.getElementById('sidebarOverlay');
                    if (sb && sb.classList.contains('active')) {
                        sb.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
                        var burger = document.getElementById('mobileBurger');
                        if (burger) burger.setAttribute('aria-expanded', 'false');
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
$_pf_utype = isset($_SESSION['user_type']) ? $_SESSION['user_type']       : 'Admin';
?>
<script>
// Global PrintFlow Configuration - Available to all pages
window.PFConfig = { 
    userId: <?php echo json_encode($_pf_uid); ?>, 
    userType: <?php echo json_encode($_pf_utype); ?>,
    sessionId: <?php echo json_encode(session_id()); ?>,
    basePath: <?php echo json_encode($base_path); ?>,
    logoUrl: <?php echo json_encode($shop_logo_url); ?>,
    logoutUrl: <?php echo json_encode($logout_url); ?>,
    loginUrl: <?php echo json_encode($base_path . '/?auth_modal=login'); ?>,
    // Admin API endpoints
    adminBase: <?php echo json_encode($base_path . '/admin'); ?>,
    publicBase: <?php echo json_encode($base_path . '/public'); ?>,
    uploadsBase: <?php echo json_encode($base_path . '/uploads'); ?>
};

// Helper function to build URLs dynamically
window.pfUrl = function(path) {
    if (!path) return window.PFConfig.basePath || '';
    // Remove leading slash if present to avoid double slashes
    path = path.replace(/^\/+/, '');
    var base = window.PFConfig.basePath || '';
    return base + '/' + path;
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

