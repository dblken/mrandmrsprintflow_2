<?php
/**
 * Customer Notifications Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

$customer_id = get_user_id();
$base_url = defined('BASE_URL') ? BASE_URL : '/printflow';

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    db_execute("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND customer_id = ?", 'ii', [$notification_id, $customer_id]);
    if (!empty($_GET['next'])) {
        $next = (string)$_GET['next'];
        $path = parse_url($next, PHP_URL_PATH);
        $host = parse_url($next, PHP_URL_HOST);
        $base_path = parse_url($base_url, PHP_URL_PATH) ?: $base_url;
        // Only redirect to paths within our own app base (no external redirects)
        $allowed_paths = ['/customer/', '/public/'];
        $path_without_base = str_replace(rtrim($base_path, '/'), '', $path ?? '');
        $is_safe = !$host && $path && (
            strpos($path, rtrim($base_path, '/') . '/') === 0 ||
            preg_match('#^/(customer|public)/#', $path_without_base ?: $path)
        );
        if ($is_safe) {
            redirect($next);
        }
    }
    $back_filter = isset($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : '';
    redirect($base_url . '/customer/notifications.php' . $back_filter);
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    db_execute("UPDATE notifications SET is_read = 1 WHERE customer_id = ? AND is_read = 0", 'i', [$customer_id]);
    redirect($base_url . '/customer/notifications.php');
}

// Pagination settings
$items_per_page = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

// Get total count
$count_result = db_query(
    "SELECT COUNT(*) as total FROM notifications WHERE customer_id = ?",
    'i',
    [$customer_id]
);
$total_items = $count_result[0]['total'] ?? 0;
$total_pages = ceil($total_items / $items_per_page);
$notifications = get_customer_notifications_for_display($customer_id, $items_per_page, $offset);

// Categorize by read status for display
$grouped_notifications = [
    'New' => [],
    'Earlier' => []
];
foreach ($notifications as $notification) {
    if ((int)($notification['is_read'] ?? 0) === 0) {
        $grouped_notifications['New'][] = $notification;
    } else {
        $grouped_notifications['Earlier'][] = $notification;
    }
}
$grouped_notifications = array_filter($grouped_notifications);
$unread_total = get_unread_notification_count($customer_id, 'Customer');

$page_title = 'Notifications - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* Container */
    .notif-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 1rem;
    }

    /* Header Section */
    .notif-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 2rem;
        gap: 1rem;
    }
    .notif-page-title {
        font-size: 1.75rem;
        font-weight: 800;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .notif-mark-all-btn {
        padding: 0.65rem 1.25rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: rgba(14, 116, 144, 0.08);
        border: 1px solid rgba(14, 116, 144, 0.25);
        color: #0e7490;
        text-decoration: none;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .notif-mark-all-btn:hover {
        background: rgba(14, 116, 144, 0.14);
        transform: translateY(-1px);
    }

    /* Group Label */
    .notif-group-label {
        font-size: 0.7rem;
        font-weight: 800;
        color: #0e7490;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin: 2rem 0 0.75rem;
        padding-left: 0.25rem;
    }
    .notif-group-label:first-child {
        margin-top: 0;
    }

    /* Notification Card */
    .notif-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        transition: all 0.2s;
        text-decoration: none;
        display: block;
    }
    .notif-card:hover {
        background: #f8fafc;
        transform: translateX(4px);
    }
    .notif-card.unread {
        background: #f8fafc;
        border-left: 3px solid #0e7490;
    }

    /* Desktop Layout */
    .notif-card-inner {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }
    .notif-image-wrap {
        width: 48px;
        height: 48px;
        min-width: 48px;
        border-radius: 8px;
        overflow: hidden;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        flex-shrink: 0;
    }
    .notif-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .notif-content-wrap {
        flex: 1;
        min-width: 0;
    }
    .notif-title {
        font-size: 0.9rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.35rem;
    }
    .notif-card.unread .notif-title {
        color: #0e7490;
    }
    .notif-description {
        font-size: 0.85rem;
        line-height: 1.5;
        color: #334155;
        margin-bottom: 0.5rem;
        word-wrap: break-word;
    }
    .notif-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .notif-time {
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
    }
    .notif-view-btn {
        padding: 0.4rem 0.9rem;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        background: rgba(14, 116, 144, 0.08);
        border: 1px solid rgba(14, 116, 144, 0.25);
        color: #0e7490;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .notif-card:hover .notif-view-btn {
        background: #0e7490;
        color: #ffffff;
    }

    /* Empty State */
    .notif-empty {
        text-align: center;
        padding: 4rem 2rem;
        color: #64748b;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }
    .notif-empty-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    /* Mobile Responsive */
    @media (max-width: 640px) {
        .notif-page-header {
            flex-direction: column;
            align-items: stretch;
        }
        .notif-page-title {
            font-size: 1.5rem;
        }
        .notif-mark-all-btn {
            width: 100%;
            text-align: center;
        }
        .notif-card-inner {
            flex-direction: column;
        }
        .notif-image-wrap {
            width: 100%;
            height: auto;
            aspect-ratio: 16/9;
            max-height: 180px;
        }
        .notif-meta {
            flex-direction: column;
            align-items: flex-start;
        }
        .notif-view-btn {
            width: 100%;
            text-align: center;
            padding: 0.6rem 1rem;
        }
    }
</style>

<div class="min-h-screen py-8">
    <div class="notif-container">
        <!-- Header -->
        <div class="notif-page-header">
            <h1 class="notif-page-title">
                Notifications
                <?php if ($unread_total > 0): ?>
                    <span style="background: #53c5e0; color: #030d11; padding: 4px 12px; border-radius: 0; font-size: 0.75rem; font-weight: 900; box-shadow: 0 0 15px rgba(83, 197, 224, 0.4);"><?php echo $unread_total; ?></span>
                <?php endif; ?>
            </h1>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" id="pf-push-toggle" class="notif-mark-all-btn" style="border:none;cursor:pointer;position:relative;z-index:5;pointer-events:auto;" onclick="return window.PFNotifications && window.PFNotifications.handlePushToggleClick ? (window.PFNotifications.handlePushToggleClick(this), false) : false;">
                    Enable notifications
                </button>
                <?php if ($unread_total > 0): ?>
                    <a href="?mark_all_read=1" class="notif-mark-all-btn">Mark all as read</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="notif-empty">
                <div class="notif-empty-icon">&#128276;</div>
                <p style="font-size: 1rem; font-weight: 600;">No notifications yet</p>
                <p style="font-size: 0.85rem; margin-top: 0.5rem;">We'll notify you when something important happens</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_notifications as $group => $notifs): ?>
                <?php if ($group === 'New'): ?>
                    <div class="notif-group-label"><?php echo htmlspecialchars($group); ?></div>
                <?php endif; ?>
                <?php foreach ($notifs as $notif): ?>
                    <?php
                    $is_rating_notif = (
                        (string)($notif['type'] ?? '') === 'Rating' ||
                        stripos((string)$notif['message'], 'rate your experience') !== false ||
                        stripos((string)$notif['message'], 'rate your order') !== false ||
                        stripos((string)$notif['message'], 'replied to your review') !== false
                    );
                    $msg = htmlspecialchars((string)$notif['message']);
                    $msg = preg_replace('/(Order\s+[A-Z0-9][A-Za-z0-9\-]*-\d+)/u', '<b>$1</b>', $msg);
                    $msg = preg_replace('/(your\s+order\s+[A-Z0-9][A-Za-z0-9\-]*-\d+)/iu', '<b>$1</b>', $msg);
                    $msg = preg_replace('/(Order\s*#\s*\d+)/iu', '<b>$1</b>', $msg);
                    $msg = preg_replace('/(your\s+order\s*#\s*\d+)/iu', '<b>$1</b>', $msg);
                    ?>
                    <a href="<?php echo htmlspecialchars((string)$notif['link']); ?>" class="notif-card <?php echo !empty($notif['is_read']) ? '' : 'unread'; ?>">
                        <div class="notif-card-inner">
                            <div class="notif-image-wrap">
                                <img src="<?php echo htmlspecialchars((string)$notif['image']); ?>"
                                     alt="<?php echo htmlspecialchars((string)$notif['title']); ?>"
                                     class="notif-image"
                                     onerror="this.onerror=null;this.src='<?php echo htmlspecialchars((string)$notif['fallback'], ENT_QUOTES); ?>';">
                            </div>
                            <div class="notif-content-wrap">
                                <div class="notif-title"><?php echo htmlspecialchars((string)$notif['title']); ?></div>
                                <div class="notif-description"><?php echo $msg; ?></div>
                                <div class="notif-meta">
                                    <div class="notif-time"><?php echo htmlspecialchars((string)$notif['time_ago']); ?></div>
                                    <?php if (!empty($notif['data_id'])): ?>
                                        <?php
                                        $btn_label = 'View';
                                        if ($is_rating_notif) {
                                            $btn_label = 'Rate Now';
                                        } elseif (
                                            strpos(strtolower((string)($notif['link'] ?? '')), 'payment.php') !== false ||
                                            strpos(strtolower((string)($notif['message'] ?? '')), 'proceed to payment') !== false ||
                                            strpos(strtolower((string)($notif['message'] ?? '')), 'payment of') !== false
                                        ) {
                                            $btn_label = 'Pay Now';
                                        }
                                        ?>
                                        <span class="notif-view-btn"><?php echo $btn_label; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="margin-top: 2rem;">
                <?php echo render_pagination($current_page, $total_pages, [], 'page'); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
