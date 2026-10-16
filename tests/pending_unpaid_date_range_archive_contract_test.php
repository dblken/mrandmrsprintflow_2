<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/admin/api_temporary_pending_unpaid_archive.php');
$page = (string)file_get_contents($root . '/admin/archived_pending_unpaid.php');
$hub = (string)file_get_contents($root . '/admin/archived_orders.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');

$failures = [];
$expect = static function (bool $ok, string $msg) use (&$failures): void { if (!$ok) { $failures[] = $msg; } };

// Entry point only under Settings → Archived Orders.
$expect(str_contains($settings, '/admin/archived_orders.php') && str_contains($settings, 'admin_archive_ui.php'), 'Settings references Archived Orders behind UI visibility flag.');
$expect(str_contains($hub, '/admin/archived_pending_unpaid.php'), 'Archived Orders links to the date-range tool.');
foreach (['admin/job_orders.php', 'admin/orders_management.php', 'staff/orders.php', 'admin/customizations.php'] as $f) {
    $expect(!str_contains((string)file_get_contents($root . '/' . $f), 'archived_pending_unpaid'), "No pending/unpaid hide control leaks into {$f}.");
}

// Date handling: Manila zone, inclusive end date, admin-only.
$expect(str_contains($api, "new DateTimeZone('Asia/Manila')"), 'API uses Manila timezone.');
$expect(str_contains($api, "modify('+1 day')->format('Y-m-d 00:00:00')"), 'End date is inclusive (exclusive next-day bound).');
$expect(str_contains($api, 'o.order_date >= ? AND o.order_date < ?'), 'Range applies to the order date.');
$expect(str_contains($api, "require_role('Admin')") && str_contains($page, "require_role('Admin')"), 'Admin-only.');

// Exact status/payment match and protective exclusions.
$expect(str_contains($api, "= 'Pending'") && str_contains($api, "= 'Unpaid'"), 'Matches only Pending + Unpaid.');
foreach (['payment_submissions', 'provider_payments', 'job_orders jo', 'customizations cu', 'inventory_movements', 'inventory_transactions', 'receipt_print_jobs', 'printflow_order_archive_map am', 'historical_generator_hidden_orders'] as $guard) {
    $expect(str_contains($api, $guard), "Excludes orders with linked {$guard} records.");
}

// Uses the established archive tables; never creates a new table or deletes/updates orders.
$expect(str_contains($api, 'printflow_order_archive_tables_ready()'), 'Requires the existing archive tables.');
$expect(str_contains($api, 'INSERT INTO printflow_order_archive_map') && str_contains($api, 'INSERT INTO printflow_order_archive_events') && str_contains($api, 'INSERT INTO printflow_order_archive_batch_events'), 'Writes only archive mapping + audit rows.');
$expect(!preg_match('/\b(CREATE\s+TABLE|DELETE\s+FROM|UPDATE\s+orders|TRUNCATE|DROP)\b/i', $api), 'No table creation, deletes, or order updates.');
$expect(!preg_match('/\b1\d{4}\b/', $api) && !preg_match('/\b1\d{4}\b/', $page), 'No hard-coded order IDs.');
$expect(!str_contains($api, 'printflow_pending_unpaid_hidden_orders') && !is_file($root . '/includes/temporary_order_visibility.php'), 'Temporary visibility table/helper is gone.');

// Preview-before-apply contract and no restore/delete controls.
$expect(str_contains($api, 'preview_token') && str_contains($api, "throw new RuntimeException('The eligible order set changed after preview."), 'Apply re-verifies the exact previewed ID set.');
$expect(str_contains($api, 'Eligibility query failed'), 'SQL errors surface instead of an empty preview.');
$expect(str_contains($page, 'Exact order IDs:'), 'Preview lists the exact matching order IDs.');
$expect(!preg_match('/<(button|a)[^>]*>[^<]*(restore|delete)/i', $page) && !preg_match('/id="(restore|delete)/i', $page), 'No Restore or delete controls on the tool page.');

if ($failures !== []) {
    fwrite(STDERR, "pending_unpaid_date_range_archive_contract_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "pending_unpaid_date_range_archive_contract_test passed.\n";
