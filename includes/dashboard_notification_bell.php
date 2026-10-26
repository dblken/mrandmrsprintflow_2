<?php
// The sidebar has already loaded the current user's unread notification count.
$dashboardUnreadCount = max(0, (int)($unread_notif_count ?? 0));
$dashboardNotificationLabel = $dashboardUnreadCount > 0
    ? 'Notifications, ' . $dashboardUnreadCount . ' unread'
    : 'Notifications, no unread notifications';
?>
<a href="notifications.php" class="pf-dashboard-notification-bell"
   aria-label="<?php echo htmlspecialchars($dashboardNotificationLabel, ENT_QUOTES); ?>"
   title="<?php echo htmlspecialchars($dashboardNotificationLabel, ENT_QUOTES); ?>">
    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
    </svg>
    <span id="dashboard-notif-badge" data-badge-display="inline-flex" aria-hidden="true"
          class="pf-dashboard-notification-count"
          style="display:<?php echo $dashboardUnreadCount > 0 ? 'inline-flex' : 'none'; ?>;"><?php echo $dashboardUnreadCount > 99 ? '99+' : ($dashboardUnreadCount > 0 ? $dashboardUnreadCount : ''); ?></span>
</a>
