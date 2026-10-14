<?php
/**
 * Regression checks for online customization order staff notifications.
 * Static / lightweight — no production database required.
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$functions = (string)file_get_contents($root . '/includes/functions.php');
$exclusion = (string)file_get_contents($root . '/includes/customer_exclusion.php');
$staffAccess = (string)file_get_contents($root . '/includes/staff_access.php');
$orderReview = (string)file_get_contents($root . '/customer/order_review.php');
$poll = (string)file_get_contents($root . '/public/api/push/poll.php');
$notificationsJs = (string)file_get_contents($root . '/public/assets/js/notifications.js');
$staffSidebar = (string)file_get_contents($root . '/includes/staff_sidebar.php');
$sendMessage = (string)file_get_contents($root . '/public/api/chat/send_message.php');

$GLOBALS['pf_test_customer_exclusion_ready'] = true;
function db_query($sql, $types = '', $params = [])
{
    return str_contains($sql, 'information_schema.tables')
        ? [['n' => (int)$GLOBALS['pf_test_customer_exclusion_ready']]]
        : [];
}
require_once $root . '/includes/customer_exclusion.php';

$scopeSql = printflow_customer_exclusion_notification_scope_sql('n');
$assert(!str_contains($scopeSql, 'user_type'), 'notification exclusion must not reference missing notifications.user_type');
$assert(str_contains($scopeSql, 'n.customer_id'), 'customer notification exclusion must use notifications.customer_id');
$assert(str_contains($scopeSql, 'n.data_id'), 'order-linked staff notifications must still scope by data_id');
$assert(str_contains($scopeSql, "'message'"), 'order-linked Message inquiries must respect customer archive scope');

$assert(str_contains($functions, 'function printflow_ensure_staff_new_order_notification'), 'checkout must use idempotent staff new-order notify helper');
$assert(str_contains($functions, 'printflow_staff_new_order_notification_exists'), 'duplicate staff new-order notifications must be detectable');
$assert(str_contains($functions, "notify_shop_users(\$msg, 'Order'"), 'new online orders still use existing Order-type shop notifications');
$assert(str_contains($functions, 'printflow_resolve_staff_access_role_from_user'), 'notification creation must resolve online vs POS staff role');
$assert(str_contains($functions, 'printflow_staff_role_can_access_order_source'), 'notification audience must respect order channel');
$assert(str_contains($functions, 'function printflow_staff_notifications_unread_type_sql_clause'), 'unread badge must distinguish online vs counter staff Message handling');
$assert(str_contains($functions, 'printflow_notify_chat_message'), 'customer order chat must notify staff through existing Message notifications');
$assert(str_contains($sendMessage, 'printflow_notify_chat_message'), 'send_message API must dispatch staff chat notifications');

$assert(str_contains($staffAccess, 'printflow_resolve_staff_order_source($dataId'), 'notification source resolution must match customizations channel resolver');
$assert(str_contains($staffAccess, "return !\$isPos"), 'online staff must receive non-POS customer order notifications');

$assert(str_contains($orderReview, 'printflow_ensure_staff_new_order_notification'), 'service checkout must ensure staff notification delivery');
$assert(str_contains($orderReview, 'printflow_ensure_staff_new_order_notification($orderId'), 'idempotent checkout replay must backfill missing staff notifications');

$assert(str_contains($poll, 'printflow_order_archive_notification_exclusion_sql'), 'poll fallback must apply the same notification visibility scope as badge/list');
$assert(str_contains($poll, 'printflow_filter_notifications_for_user'), 'poll must filter by staff branch and order channel');

$assert(str_contains($notificationsJs, 'API_POLL'), 'real-time UI must poll push fallback endpoint');
$assert(str_contains($notificationsJs, 'updateBadge'), 'sidebar badge must update from poll unread_count');
$assert(str_contains($staffSidebar, 'get_unread_notification_count'), 'server-rendered sidebar badge must use unread helper');
$assert(str_contains($staffSidebar, 'notifications.js'), 'staff layout must load notification client');

require_once $root . '/includes/staff_status_filters.php';
$assert(printflow_staff_notification_type_allowed('Order', 'online'), 'online operations staff still allow Order notifications');
$assert(printflow_staff_notification_type_allowed('Order', 'pos'), 'counter staff Order notifications remain unchanged');
$assert(!printflow_staff_notification_type_allowed('Message', 'pos'), 'counter staff Message filter unchanged');

if ($failures !== []) {
    fwrite(STDERR, "Online customization order notification test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Online customization order notification test passed.\n";
