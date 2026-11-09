<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = file_get_contents($root . '/includes/expired_product_order_archive.php');
$scope = file_get_contents($root . '/includes/ready_made_product_order_scope.php');
$orders = file_get_contents($root . '/staff/orders.php');
$adminOrders = file_get_contents($root . '/admin/orders_management.php');
$orderDataApi = file_get_contents($root . '/staff/get_order_data.php');
$orderDetails = file_get_contents($root . '/staff/order_details.php');
$cron = file_get_contents($root . '/cron/cleanup_expired_product_orders.php');
$retentionDoc = file_get_contents($root . '/docs/unpaid_product_order_retention.md');
$retentionDocNormalized = is_string($retentionDoc) ? preg_replace('/\s+/', ' ', $retentionDoc) : false;
$staffApi = file_get_contents($root . '/staff/api/archived_expired_product_orders.php');
$api = file_get_contents($root . '/admin/api/clear_archived_expired_product_orders.php');
$products = file_get_contents($root . '/admin/products_management.php');
$archivedJson = file_get_contents($root . '/includes/staff_orders_archived_json.php');

if ($helpers === false || $scope === false || $orders === false || $adminOrders === false || $orderDataApi === false || $orderDetails === false || $cron === false || $retentionDoc === false || $staffApi === false || $api === false || $products === false || $archivedJson === false) {
    throw new RuntimeException('Unable to read archive flow sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($helpers, 'INTERVAL 7 DAY') === false, 'old 7-day retention is removed');
$assert(substr_count($helpers, 'INTERVAL 24 HOUR') >= 2, 'awaiting and expired states use a 24-hour database-clock threshold');
$assert(strpos($helpers, '<= DATE_SUB(NOW(), INTERVAL 24 HOUR)') !== false, 'orders at or beyond the 24-hour boundary are eligible');
$assert(strpos($helpers, 'order_date <= DATE_SUB(NOW(), INTERVAL 24 HOUR)') !== false, 'awaiting retention uses order DATETIME, not displayed date');
$assert(strpos($helpers, 'provider_payment_status_history') !== false, 'expired retention prefers the actual expired transition time');
$assert(strpos($helpers, "= 'awaiting_payment'") !== false, 'provider Awaiting Payment orders are eligible by the displayed provider state');
$assert(strpos($helpers, "= 'expired'") !== false, 'provider Expired orders are eligible by the displayed provider state');
$assert(strpos($helpers, "IN ('to pay', 'awaiting payment', 'pending')") !== false, 'legacy Pending/To Pay/Awaiting Payment share the unpaid awaiting bucket');
$assert(strpos($helpers, 'ready_made_product_order_scope.php') !== false, 'archive helpers load ready-made scope predicates');
$assert(strpos($scope, 'function printflow_ready_made_product_order_scope_sql') !== false, 'ready-made scope file is loadable');
$assert(strpos($helpers, 'printflow_ready_made_product_cancelled_operational_hidden_sql') !== false, 'cancelled ready-made orders are hidden operationally');
$assert(strpos($helpers, 'printflow_expired_product_order_physical_cleanup_enabled') !== false, 'physical cleanup is gated off unless explicitly enabled');
$assert(strpos($helpers, 'payment_submissions ps_protect') === false, 'payment submissions do not block stale unpaid order deletion');
$assert(strpos($helpers, 'information_schema.TABLES') !== false && strpos($helpers, "!== 'innodb'") !== false, 'every deleted table must be InnoDB for rollback safety');
$assert(strpos($helpers, "UPPER(TRIM(COALESCE(it.direction, ''))) = 'OUT'") === false, 'inventory stock-out rows do not block stale unpaid order deletion');
$assert(strpos($helpers, 'jo_protect.order_id') === false, 'linked job-order rows do not block stale unpaid order deletion');
$assert(strpos($helpers, "'paid', 'payment confirmed', 'processing', 'in production', 'printing'") !== false, 'active and successful order states are explicitly protected');
$assert(strpos($helpers, "'ready for pickup'") !== false && strpos($helpers, "'completed', 'delivered'") !== false, 'pickup, completed, and delivered orders are explicitly protected');
$assert(strpos($helpers, "LOWER(TRIM(COALESCE(pp_paid.status, ''))) = 'paid'") !== false && strpos($helpers, 'pp_paid.mode') === false, 'successful payment ledger rows protect the order in every mode');
$displayStart = strpos($helpers, 'function printflow_expired_product_order_overdue_for_display_sql');
$displayEnd = strpos($helpers, 'function printflow_expired_product_order_exclude_archived_sql', $displayStart ?: 0);
$displayFunction = $displayStart !== false && $displayEnd !== false
    ? substr($helpers, $displayStart, $displayEnd - $displayStart)
    : '';
$assert($displayFunction !== '', 'overdue list visibility has a dedicated predicate');
$assert(strpos($displayFunction, 'printflow_expired_product_order_retention_eligible_sql') === false, 'display filtering is not coupled to physical-deletion guards');
$assert(strpos($helpers, 'printflow_ready_made_product_unpaid_overdue_operational_hidden_sql') !== false
    && strpos($helpers, 'printflow_expired_product_order_awaiting_age_sql') !== false
    && strpos($helpers, 'printflow_expired_product_order_expired_age_sql') !== false,
    'unpaid overdue helper still applies the 24-hour order and expiration timestamps');
$assert(strpos($displayFunction, 'payment_submissions') === false
    && strpos($displayFunction, 'job_orders') === false
    && strpos($displayFunction, 'inventory_transactions') === false,
    'unresolved child records cannot keep an overdue unpaid order in active lists');
$assert(strpos($helpers, "return 'NOT (' . printflow_expired_product_order_overdue_for_display_sql(") !== false, 'shared list exclusion uses the dedicated overdue display predicate');
$assert(strpos($helpers, 'ORDER BY id ASC FOR UPDATE') !== false, 'payment ledger rows lock before order recheck');
$assert(strpos($helpers, 'LIMIT 1 FOR UPDATE') !== false, 'order eligibility is rechecked under a row lock before deletion');
$assert(strpos($cron, 'printflow_expired_product_order_cleanup(null, 500)') !== false, 'scheduled task runs the shared cleanup across branches');
$assert(strpos($cron, "PHP_SAPI !== 'cli'") !== false && strpos($cron, 'GET_LOCK') !== false, 'cron is CLI-only and serializes overlapping scheduled runs');
$assert(strpos($orders, 'printflow_expired_product_order_cleanup($staffBranchId, 100)') !== false, 'staff page runs branch fallback before list and counts');
$assert(substr_count($orders, 'printflow_expired_product_order_exclude_archived_sql') >= 2, 'main list and KPI queries exclude due rows if cleanup is delayed');
$assert(strpos($orderDataApi, 'printflow_expired_product_order_exclude_archived_sql') !== false, 'legacy order list/details API applies shared operational exclusion');
$assert(strpos($orderDetails, 'printflow_expired_product_order_exclude_archived_sql') !== false, 'direct order details route applies shared operational exclusion');
$assert(substr_count($adminOrders, 'printflow_expired_product_order_exclude_archived_sql') >= 3, 'admin product orders list, count, and KPIs apply operational exclusion');
$assert(strpos($retentionDoc, 'Asia/Manila') !== false && strpos($retentionDoc, '* * * * *') !== false, 'scheduler/timezone operations are documented');
$assert(is_string($retentionDocNormalized) && stripos($retentionDocNormalized, 'payment submissions, linked job records, and inventory movement rows do not block') !== false, 'documentation explains the non-payment-child deletion policy');
$assert(strpos($orders, 'fp_display_status') !== false, 'filter panel still supports payment display filtering');
$assert(strpos($orders, "'PAID', 'EXPIRED', 'COMPLETED'") === false, 'Paid and Expired are removed from Product Orders display-status filter allowlist');
$assert(strpos($orders, "'COMPLETED'") !== false && strpos($orders, "'EXPIRED' => 'Expired'") === false, 'only Completed remains in the payment-status filter UI');
$assert(strpos($helpers, 'latestProviderAbsent') === false, 'stale Pending must hide even when a non-awaiting provider row exists');
$assert(strpos($helpers, 'printflow_ready_made_product_expired_operational_hidden_sql') !== false, 'expired ready-made rows are hidden operationally');
$assert(strpos($orders, 'btn-open-archived-orders') === false && strpos($orders, 'openArchivedOrdersModal') === false, 'staff Product Orders Archived UI is removed');
$assert(strpos($orders, 'archivedOrdersModal') === false, 'staff archived orders modal markup is removed');
$assert(strpos($orders, "ajax'] === 'archived'") !== false && strpos($orders, '410') !== false, 'legacy staff ajax=archived shortcut returns gone instead of listing');
$assert(strpos($staffApi, 'http_response_code(410)') !== false, 'legacy staff archived API returns gone');
$assert(strpos($products, 'openArchiveModal') === false && strpos($products, 'archive-storage-overlay') === false, 'admin Products Archived Items UI is removed');
$assert(strpos($products, 'Clear Archived Expired Orders') === false && strpos($products, 'printflowInitClearArchivedExpiredOrders') === false, 'admin Clear Archived Expired Orders UI is removed');
$assert(strpos($products, "status != 'Archived'") !== false, 'active product lists still exclude archived product status without deleting rows');
$assert(strpos($api, 'http_response_code(410)') !== false, 'legacy clear-archived API returns gone');
$assert(strpos($helpers, 'function printflow_expired_product_order_cleanup') !== false
    && strpos($helpers, 'function printflow_expired_product_order_exclude_archived_sql') !== false,
    '24-hour cleanup helpers remain available for cron and list exclusion');

// Contract examples for the SQL threshold: timestamps at or younger than 24
// hours are retained, while older timestamps are due. This is a pure
// timezone-aware policy check, not a database integration test.
$dbNow = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('Asia/Manila'));
$boundary = $dbNow->modify('-24 hours');
$isDue = static fn(DateTimeImmutable $anchor): bool => $anchor <= $boundary;
$assert(!$isDue($dbNow->modify('-23 hours -59 minutes -59 seconds')), 'Awaiting Payment less than 24 hours old is retained');
$assert($isDue($boundary), 'Awaiting Payment exactly 24 hours old is hidden');
$assert($isDue($boundary->modify('-1 second')), 'Awaiting Payment more than 24 hours old is due');
$expiredAt = $dbNow->modify('-23 hours');
$assert(!$isDue($expiredAt), 'Expired order age starts at its expiration timestamp, not its older creation time');
$assert($isDue($expiredAt->modify('-1 hour -1 second')), 'Expired order is due when it is older than 24 hours in Expired state');

echo "OK expired_product_order_archive_test\n";
