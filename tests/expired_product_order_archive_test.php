<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = file_get_contents($root . '/includes/expired_product_order_archive.php');
$orders = file_get_contents($root . '/staff/orders.php');
$staffApi = file_get_contents($root . '/staff/api/archived_expired_product_orders.php');
$api = file_get_contents($root . '/admin/api/clear_archived_expired_product_orders.php');
$products = file_get_contents($root . '/admin/products_management.php');
$archivedJson = file_get_contents($root . '/includes/staff_orders_archived_json.php');

if ($helpers === false || $orders === false || $staffApi === false || $api === false || $products === false || $archivedJson === false) {
    throw new RuntimeException('Unable to read archive flow sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($helpers, 'INTERVAL 7 DAY') !== false, 'archive uses 7-day grace after expiry');
$assert(strpos($helpers, "pp.status = 'expired'") !== false, 'archive requires confirmed expired provider payment');
$assert(strpos($helpers, 'printflow_expired_product_order_exclude_archived_sql') !== false, 'main list excludes archived-eligible orders');
$assert(strpos($orders, 'ajax=archived') !== false, 'staff orders keeps archived ajax shortcut');
$assert(strpos($staffApi, 'printflow_staff_archived_expired_orders_payload') !== false, 'staff API loads archived payload');
$assert(strpos($orders, 'btn-open-archived-orders') !== false && strpos($orders, 'openArchivedOrdersModal') !== false, 'staff orders archived modal UI');
$assert(strpos($products, 'printflowInitClearArchivedExpiredOrders') !== false, 'admin products binds clear archived handler on page init');
$assert(strpos($products, 'PF_PRODUCTS_CSRF') !== false, 'admin products exposes CSRF for clear archived');
$assert(strpos($orders, 'fp_display_status') !== false, 'filter panel includes Paid/Expired/Completed');
$assert(strpos($products, 'Clear Archived Expired Orders') !== false, 'admin products page has clear button label');
$assert(strpos($api, 'printflow_expired_product_order_purge_archived') !== false, 'admin API purges only eligible archived orders');

echo "OK expired_product_order_archive_test\n";
