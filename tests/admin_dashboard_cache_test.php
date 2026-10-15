<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/admin_dashboard_cache.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Key is stable per branch + date range and distinct across "All Branches" vs a branch.
$keyAll = printflow_admin_dashboard_cache_key('all', '2026-10-15', '2026-10-15');
$keyOne = printflow_admin_dashboard_cache_key(1, '2026-10-15', '2026-10-15');
$keyAllNextDay = printflow_admin_dashboard_cache_key('all', '2026-10-16', '2026-10-16');
$expect($keyAll === printflow_admin_dashboard_cache_key('all', '2026-10-15', '2026-10-15'), 'Cache key is deterministic.');
$expect($keyAll !== $keyOne, 'All Branches and a single branch never share a cache entry.');
$expect($keyAll !== $keyAllNextDay, 'Different date ranges never share a cache entry.');
$expect(str_starts_with($keyAll, 'admin_dash_'), 'Admin entries use a distinct prefix from staff entries.');

// Round trip preserves summary structures (ints, floats, nested arrays, nulls).
$payload = [
    'total_customers' => 0,
    'total_revenue' => 17672.0,
    'dashboard_top_branch' => ['branch_name' => 'Las Piñas Branch', 'revenue' => 10940.5, 'growth_pct' => -17.4],
    'dashboard_low_branch' => null,
    'low_stock_by_branch' => [['branch_id' => 2, 'rows' => [['stock_status' => ['key' => 'low']]]]],
];
$testKey = 'admin_dash_test_' . bin2hex(random_bytes(4));
printflow_admin_dashboard_cache_set($testKey, $payload);
$roundTrip = printflow_admin_dashboard_cache_get($testKey, 60);
$expect(is_array($roundTrip), 'Cached payload can be read back within TTL.');
$expect(($roundTrip['total_revenue'] ?? null) === 17672.0, 'Float totals survive the round trip.');
$expect(($roundTrip['dashboard_top_branch']['branch_name'] ?? '') === 'Las Piñas Branch', 'Unicode branch names survive the round trip.');
$expect(array_key_exists('dashboard_low_branch', $roundTrip ?? []) && $roundTrip['dashboard_low_branch'] === null, 'Null summary entries are preserved.');
$expect(($roundTrip['low_stock_by_branch'][0]['rows'][0]['stock_status']['key'] ?? '') === 'low', 'Nested low-stock structures are preserved.');

// Expired entries are not served.
$expect(printflow_admin_dashboard_cache_get($testKey, 0) === null, 'TTL of zero disables reads.');

// Branch invalidation (the hook already used by POS checkout / status changes) flushes admin entries.
printflow_staff_dashboard_cache_invalidate_branch(1);
$expect(printflow_admin_dashboard_cache_get($testKey, 60) === null, 'Branch invalidation flushes admin dashboard entries.');

// Bypass flags skip cache reads.
printflow_admin_dashboard_cache_set($testKey, $payload);
$_GET['refresh'] = '1';
$expect(printflow_admin_dashboard_cache_get($testKey, 60) === null, '?refresh=1 bypasses the cache.');
unset($_GET['refresh']);
printflow_admin_dashboard_cache_flush_all();

// Dashboard wiring and bulk stock helper contracts.
$dashboard = (string)file_get_contents($root . '/admin/dashboard.php');
$inventory = (string)file_get_contents($root . '/includes/InventoryManager.php');
$expect(str_contains($dashboard, 'if (!$dashCacheHit) {') && str_contains($dashboard, '} // end if (!$dashCacheHit)'), 'Dashboard wraps summary computation in the cache window.');
$expect(str_contains($dashboard, 'printflow_admin_dashboard_cache_set($dashCacheKey, $dashCachePayload)'), 'Dashboard stores the computed summary.');
$expect(str_contains($dashboard, '$dashDbErrorsAfter === $dashDbErrorsBefore'), 'Dashboard never caches a build that hit database errors.');
$expect(!str_contains($dashboard, 'GROUP BY customer_name ORDER BY spent DESC LIMIT 5'), 'Unrendered Top Customers query removed.');
$expect(!str_contains($dashboard, 'GROUP BY DATE(o.order_date) ORDER BY day'), 'Unrendered daily sales query removed.');
$expect(str_contains($dashboard, 'InventoryManager::getStockOnHandBulk') && str_contains($dashboard, 'InventoryManager::getStockOnHand($itemId, $stockBranchId)'), 'Low stock uses bulk lookup with per-item fallback.');
$expect(str_contains($inventory, 'public static function getStockOnHandBulk(array $itemIds, $branchId = null): array'), 'Bulk stock helper exists.');
$expect(
    str_contains($inventory, "NOT IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER'){\$branchSql} GROUP BY item_id")
        && str_contains($inventory, "WHERE item_id IN ({\$ph}) AND status = 'OPEN'{\$branchSql} GROUP BY item_id"),
    'Bulk stock helper applies the same ledger exclusions, roll status, and branch clause as getStockOnHand.'
);

if ($failures !== []) {
    fwrite(STDERR, "admin_dashboard_cache_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "admin_dashboard_cache_test passed.\n";
