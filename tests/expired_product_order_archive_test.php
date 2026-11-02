<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = file_get_contents($root . '/includes/expired_product_order_archive.php');
$orders = file_get_contents($root . '/staff/orders.php');
$orderDataApi = file_get_contents($root . '/staff/get_order_data.php');
$orderDetails = file_get_contents($root . '/staff/order_details.php');
$cron = file_get_contents($root . '/cron/cleanup_expired_product_orders.php');
$retentionDoc = file_get_contents($root . '/docs/unpaid_product_order_retention.md');
$staffApi = file_get_contents($root . '/staff/api/archived_expired_product_orders.php');
$api = file_get_contents($root . '/admin/api/clear_archived_expired_product_orders.php');
$products = file_get_contents($root . '/admin/products_management.php');
$archivedJson = file_get_contents($root . '/includes/staff_orders_archived_json.php');

if ($helpers === false || $orders === false || $orderDataApi === false || $orderDetails === false || $cron === false || $retentionDoc === false || $staffApi === false || $api === false || $products === false || $archivedJson === false) {
    throw new RuntimeException('Unable to read archive flow sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($helpers, 'INTERVAL 7 DAY') === false, 'old 7-day retention is removed');
$assert(substr_count($helpers, 'INTERVAL 24 HOUR') >= 2, 'awaiting and expired states use a 24-hour database-clock threshold');
$assert(strpos($helpers, '<= DATE_SUB(NOW(), INTERVAL 24 HOUR)') !== false, 'exact 24-hour boundary is eligible');
$assert(strpos($helpers, 'oAlias}.order_date <= DATE_SUB(NOW(), INTERVAL 24 HOUR)') !== false, 'awaiting retention uses order DATETIME, not displayed date');
$assert(strpos($helpers, 'provider_payment_status_history') !== false, 'expired retention prefers the actual expired transition time');
$assert(strpos($helpers, "'pending', 'to pay', 'awaiting payment', 'expired'") !== false, 'raw state allowlist is narrow and fails closed for unknown/active states');
$assert(strpos($helpers, "pp_paid.status = 'paid'") !== false && strpos($helpers, 'pp_paid.mode') === false, 'any paid provider ledger protects the order');
$assert(strpos($helpers, 'payment_submissions ps_protect') !== false, 'unresolved payment submissions are protected');
$assert(strpos($helpers, 'information_schema.TABLES') !== false && strpos($helpers, "!== 'innodb'") !== false, 'every deleted table must be InnoDB for rollback safety');
$assert(strpos($helpers, "UPPER(TRIM(COALESCE(it.direction, ''))) = 'OUT'") !== false, 'orders with inventory stock-out movements are protected');
$assert(strpos($helpers, 'jo_protect.order_id') !== false, 'orders linked to production job records are protected');
$assert(strpos($helpers, 'ORDER BY id ASC FOR UPDATE') !== false, 'payment ledger rows lock before order recheck');
$assert(strpos($helpers, 'LIMIT 1 FOR UPDATE') !== false, 'order eligibility is rechecked under a row lock before deletion');
$assert(strpos($cron, 'printflow_expired_product_order_cleanup(null, 500)') !== false, 'scheduled task runs the shared cleanup across branches');
$assert(strpos($cron, "PHP_SAPI !== 'cli'") !== false && strpos($cron, 'GET_LOCK') !== false, 'cron is CLI-only and serializes overlapping scheduled runs');
$assert(strpos($orders, 'printflow_expired_product_order_cleanup($staffBranchId, 100)') !== false, 'staff page runs branch fallback before list and counts');
$assert(substr_count($orders, 'printflow_expired_product_order_exclude_archived_sql') >= 2, 'main list and KPI queries exclude due rows if cleanup is delayed');
$assert(strpos($orderDataApi, 'AND NOT {$overdueProductOrderSql}') !== false, 'legacy order list/details API hides overdue online product orders');
$assert(strpos($orderDetails, 'AND NOT {$overdueProductOrderSql}') !== false, 'direct order details route hides overdue online product orders');
$assert(strpos($staffApi, 'printflow_expired_product_order_cleanup($staffBranchId, 100)') !== false, 'direct archived API requests run the page-load fallback');
$assert(strpos($retentionDoc, 'Asia/Manila') !== false && strpos($retentionDoc, '* * * * *') !== false, 'scheduler/timezone operations are documented');
$assert(strpos($orders, 'ajax=archived') !== false, 'staff orders keeps archived ajax shortcut');
$assert(strpos($staffApi, 'printflow_staff_archived_expired_orders_payload') !== false, 'staff API loads archived payload');
$assert(strpos($orders, 'btn-open-archived-orders') !== false && strpos($orders, 'openArchivedOrdersModal') !== false, 'staff orders archived modal UI');
$assert(strpos($products, 'printflowInitClearArchivedExpiredOrders') !== false, 'admin products binds clear archived handler on page init');
$assert(strpos($products, 'PF_PRODUCTS_CSRF') !== false, 'admin products exposes CSRF for clear archived');
$assert(strpos($orders, 'fp_display_status') !== false, 'filter panel includes Paid/Expired/Completed');
$assert(strpos($products, 'Clear Archived Expired Orders') !== false, 'admin products page has clear button label');
$assert(strpos($api, 'printflow_expired_product_order_purge_archived') !== false, 'admin API purges only eligible archived orders');

// Contract examples for the SQL threshold: timestamps younger than 24 hours
// are retained, while exact and older boundaries are due. This is a pure
// timezone-aware policy check, not a database integration test.
$dbNow = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('Asia/Manila'));
$boundary = $dbNow->modify('-24 hours');
$isDue = static fn(DateTimeImmutable $anchor): bool => $anchor <= $boundary;
$assert(!$isDue($dbNow->modify('-23 hours -59 minutes -59 seconds')), 'Awaiting Payment less than 24 hours old is retained');
$assert($isDue($boundary), 'Awaiting Payment exactly 24 hours old is due');
$assert($isDue($boundary->modify('-1 second')), 'Awaiting Payment more than 24 hours old is due');
$expiredAt = $dbNow->modify('-23 hours');
$assert(!$isDue($expiredAt), 'Expired order age starts at its expiration timestamp, not its older creation time');
$assert($isDue($expiredAt->modify('-1 hour')), 'Expired order is due after 24 hours in Expired state');

echo "OK expired_product_order_archive_test\n";
