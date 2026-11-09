<?php
declare(strict_types=1);

/**
 * Contract: obsolete Archived / Archived Items / Clear Archived UI is gone,
 * while 24-hour unpaid product-order cleanup and record exclusion remain.
 */

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) {
        $failures[] = $msg;
    }
};

$orders = (string)file_get_contents($root . '/staff/orders.php');
$products = (string)file_get_contents($root . '/admin/products_management.php');
$staffApi = (string)file_get_contents($root . '/staff/api/archived_expired_product_orders.php');
$clearApi = (string)file_get_contents($root . '/admin/api/clear_archived_expired_product_orders.php');
$helpers = (string)file_get_contents($root . '/includes/expired_product_order_archive.php');
$cron = (string)file_get_contents($root . '/cron/cleanup_expired_product_orders.php');

$expect(!str_contains($orders, 'btn-open-archived-orders'), 'Staff Product Orders has no Archived toolbar button');
$expect(!str_contains($orders, 'Archived Expired Orders'), 'Staff Product Orders has no archived modal title');
$expect(!str_contains($orders, 'openArchivedOrdersModal'), 'Staff Product Orders has no archived modal opener');
$expect(str_contains($orders, 'printflow_expired_product_order_cleanup'), 'Staff page still runs 24-hour cleanup fallback');
$expect(
    substr_count($orders, 'printflow_ready_made_product_operational_visible_sql') >= 2
    || substr_count($orders, 'printflow_expired_product_order_exclude_archived_sql') >= 2,
    'Staff list/KPI still hide overdue unpaid product orders'
);

$expect(!str_contains($products, 'openArchiveModal') && !str_contains($products, 'archive-storage-overlay'), 'Products Management has no Archived Items modal controls');
$expect(!str_contains($products, 'Clear Archived Expired Orders') && !str_contains($products, 'btn-clear-archived-expired-orders'), 'Products Management has no Clear Archived Expired Orders button');
$expect(!str_contains($products, 'name="archive_product"'), 'Products Management has no Archive action button');
$expect(!str_contains($products, 'name="restore_product"'), 'Products Management has no Restore archive action button');
$expect(str_contains($products, "status != 'Archived'"), 'Products lists still exclude Archived status without deleting products');
$expect(str_contains($products, "isset(\$_POST['archive_product'])") && str_contains($products, 'have been removed'), 'Legacy archive/restore POSTs are rejected without mutating stock');

$expect(str_contains($staffApi, '410') && str_contains($staffApi, 'removed'), 'Legacy staff archived API is gone, not broken');
$expect(str_contains($clearApi, '410') && str_contains($clearApi, 'removed'), 'Legacy clear-archived API is gone, not broken');
$expect(str_contains($helpers, 'INTERVAL 24 HOUR'), '24-hour retention threshold remains');
$expect(str_contains($cron, 'printflow_expired_product_order_cleanup(null, 500)'), 'Scheduled cleanup remains');
$expect(
    str_contains($helpers, "'paid', 'payment confirmed', 'processing', 'in production', 'printing'")
    && str_contains($helpers, "'completed', 'delivered'"),
    'Paid/completed/active statuses remain protected from cleanup'
);

// Inventory materials Archived Items is a separate feature; leave it alone.
$inv = (string)file_get_contents($root . '/admin/inv_items_management.php');
$expect(str_contains($inv, 'Archived Items'), 'Inventory materials Archived Items control is unrelated and preserved');

if ($failures !== []) {
    fwrite(STDERR, "archive_ui_removal_contract_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "archive_ui_removal_contract_test passed.\n";
