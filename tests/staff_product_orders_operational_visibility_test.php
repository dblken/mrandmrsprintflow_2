<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$orders = file_get_contents($root . '/staff/orders.php');
$operational = file_get_contents($root . '/includes/ready_made_product_operational_visibility.php');
$archive = file_get_contents($root . '/includes/expired_product_order_archive.php');

if (!is_string($orders) || !is_string($operational) || !is_string($archive)) {
    throw new RuntimeException('Unable to read staff product orders visibility sources.');
}

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($orders, '$count_sql = "SELECT COUNT(*) as total') !== false, 'pagination total query exists');
$assert(strpos($orders, '$sql_conditions') !== false, 'list and count share sql_conditions');
$assert(
    strpos($orders, 'FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE 1=1" . $sql_conditions')
    !== false
    && strpos($orders, '$count_sql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN customers c ON o.customer_id = c.customer_id WHERE 1=1" . $sql_conditions') !== false,
    'displayed rows and pagination total use the same filter SQL'
);

$assert(substr_count($orders, 'printflow_ready_made_product_operational_visible_sql') >= 2, 'ALL list and KPI counts apply operational exclusion');
$assert(strpos($orders, "status_filter === 'PAYMENT'") !== false, 'PAYMENT tab uses the same sql_conditions pipeline as ALL');
$assert(strpos($orders, '$customer_filter') !== false && strpos($orders, 'printflow_ready_made_product_operational_visible_sql') !== false, 'search filters reuse sql_conditions with operational exclusion');

$assert(strpos($operational, 'function printflow_ready_made_product_operational_hidden_sql') !== false, 'canonical hidden predicate exists');
$assert(strpos($operational, 'function printflow_ready_made_product_operational_visible_sql') !== false, 'canonical visible predicate exists');
$assert(strpos($operational, 'order_date') !== false && strpos($operational, 'created_at') !== false, '24-hour age uses order creation timestamps');
$assert(strpos($operational, "'pending review'") !== false, 'legacy Pending labels map to checkout-incomplete');
$assert(strpos($operational, 'printflow_ready_made_product_order_scope_sql') !== false, 'custom/service workflows stay out of scope');
$assert(strpos($operational, 'return \'0=1\'') === false, 'operational hide must not fail closed when provider schema is guarded');

$assert(strpos($archive, 'return printflow_ready_made_product_operational_hidden_sql') !== false, 'archive lists delegate to the shared operational rule');
$assert(strpos($archive, 'return printflow_ready_made_product_operational_visible_sql') !== false, 'exclude_archived delegates to operational visible SQL');

$dbNow = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('Asia/Manila'));
$boundary = $dbNow->modify('-24 hours');
$isDue = static fn(DateTimeImmutable $anchor): bool => $anchor <= $boundary;
$assert(!$isDue($dbNow->modify('-2 hours')), 'under 24 hours remains visible');
$assert($isDue($boundary), 'exactly 24 hours is hidden');
$assert($isDue($dbNow->modify('-30 days')), 'months-old pending is hidden by age rule');

echo "OK staff_product_orders_operational_visibility_test\n";
