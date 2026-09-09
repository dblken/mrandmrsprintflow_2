<?php
/**
 * Focused source-routing and customer product-thumbnail regression checks.
 * Static checks keep this independent of a production database.
 */

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/includes/functions.php');
$orders = file_get_contents($root . '/customer/orders.php');
$staffNotifications = file_get_contents($root . '/staff/notifications.php');
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($functions, "SELECT user_id, role, branch_id, position FROM users") !== false,
    'Shop notification recipients must include staff position for source targeting.');
$assert(strpos($functions, 'printflow_notification_source_for_staff_scope') !== false,
    'Notification source resolution must be centralized.');
$assert(strpos($functions, "'Rating', 'Review'") !== false,
    'Customer review notifications must be source-scoped.');
$assert(strpos($functions, 'printflow_resolve_staff_access_role_from_user($u)') !== false,
    'Creation-side routing must use the recipient staff access role.');
$assert(strpos($functions, 'printflow_staff_role_can_access_order_source') !== false,
    'Creation and visibility must enforce canonical order source access.');
$assert(strpos($functions, "if (\$role === 'Staff' && \$data_id !== null") !== false,
    'Only Staff recipients are source-filtered; existing Admin/Manager routing remains intact.');
$assert(strpos($functions, "COALESCE(NULLIF(TRIM(p.photo_path), ''), NULLIF(TRIM(p.product_image), ''))") === false,
    'Product SQL expression belongs to customer/orders.php, not the shared helper.');
$assert(strpos($orders, "COALESCE(NULLIF(TRIM(p.photo_path), ''), NULLIF(TRIM(p.product_image), ''))") !== false,
    'Blank photo_path must fall through to product_image.');
$assert(strpos($functions, "(\$order['first_product_image'] ?? '')") !== false,
    'Ready-made order cards must use the selected catalog product image.');
$assert(strpos($functions, 'pf_order_ui_asset_url($productImage)') !== false,
    'Catalog image paths must use the existing trusted URL resolver.');
$assert(strpos($functions, "strtolower(trim((string)(\$order['order_type'] ?? ''))) === 'product'") !== false,
    'The product-image preference must be limited to ready-made product orders.');
$assert(substr_count($staffNotifications, 'printflow_dedupe_notifications') >= 3,
    'Staff page counts and polling must use the same deduplicated visible rows as the sidebar badge.');
$assert(strpos($staffNotifications, 'visibleUnreadRows') !== false
    && strpos($staffNotifications, 'notification_id IN') !== false,
    'Mark-all-read must affect only notifications visible to the current staff scope.');

if ($failures !== []) {
    fwrite(STDERR, "Notification routing scope test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Notification routing scope test passed.\n";
