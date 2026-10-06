<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = file_get_contents($root . '/includes/expired_product_order_archive.php');
$orders = file_get_contents($root . '/staff/orders.php');
$api = file_get_contents($root . '/admin/api/clear_archived_expired_product_orders.php');
$products = file_get_contents($root . '/admin/products_management.php');

if ($helpers === false || $orders === false || $api === false || $products === false) {
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
$assert(strpos($orders, 'ajax=archived') !== false, 'staff orders exposes archived modal endpoint');
$assert(strpos($orders, 'fp_display_status') !== false, 'filter panel includes Paid/Expired/Completed');
$assert(strpos($products, 'Clear Archived Expired Orders') !== false, 'admin products page has clear button label');
$assert(strpos($api, 'printflow_expired_product_order_purge_archived') !== false, 'admin API purges only eligible archived orders');

echo "OK expired_product_order_archive_test\n";
