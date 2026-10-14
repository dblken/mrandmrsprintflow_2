<?php
declare(strict_types=1);

function db_query($sql, $types = '', $params = [])
{
    return str_contains((string)$sql, 'information_schema.tables') ? [['n' => 1]] : [];
}

require_once __DIR__ . '/../includes/customer_exclusion.php';
require_once __DIR__ . '/../includes/customer_exclusion_archive_batch.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$read = static function (string $relative) use (&$failures): string {
    $path = __DIR__ . '/../' . $relative;
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        $failures[] = $relative . ' could not be read';
        return '';
    }
    return $contents;
};

$scope = printflow_customer_exclusion_customer_scope_sql('so.customer_id');
$otherScope = printflow_customer_exclusion_customer_scope_sql('jo.customer_id');
$check($scope !== $otherScope || $scope === '1=1', 'service-order and job-order archive predicates stay bound to their own customer_id column');
if ($scope !== '1=1') {
    $check(str_contains($scope, 'so.customer_id') && !str_contains(strtolower($scope), 'first_name') && !str_contains($scope, 'LIKE'), 'service-order visibility uses customer_id and not a name search');
    $check(str_contains($otherScope, 'jo.customer_id'), 'job-order visibility uses jo.customer_id');
}

$jennyFerr = 'Archive reference: JNY-25-20261012-143541-E1E404; kept for customer 25.';
$jennyLacson = 'Archive reference: ARC-238-20261012-160536-123E54; Admin-authorized reversible customer archive.';
$jennyLlanes = 'Archive reference: ARC-167-20261012-160519-3C35CF; Admin-authorized reversible customer archive.';
$check(printflow_customer_archive_order_reason_is_managed(25, $jennyFerr), 'Jenny Ferr reference stays managed for customer 25');
$check(!printflow_customer_archive_order_reason_is_managed(238, $jennyFerr), 'Jenny Ferr reference does not archive Jenny Lacson');
$check(printflow_customer_archive_order_reason_is_managed(238, $jennyLacson), 'Jenny Lacson reference stays managed for customer 238');
$check(!printflow_customer_archive_order_reason_is_managed(25, $jennyLacson), 'Jenny Lacson reference does not archive Jenny Ferr');
$check(!printflow_customer_archive_order_reason_is_managed(167, $jennyLacson), 'a similar Jenny name does not share customer 238 archive ownership');
$check(printflow_customer_archive_order_reason_is_managed(167, $jennyLlanes) && !printflow_customer_archive_order_reason_is_managed(238, $jennyLlanes), 'Jenny Llanes stays on customer 167 only');

$api = $read('admin/job_orders_api.php');
$check(str_contains($api, "printflow_customer_exclusion_customer_scope_sql('so.customer_id')"), 'staff custom-order service purchases filter by service_orders.customer_id');
$check(str_contains($api, "printflow_customer_exclusion_customer_scope_sql('jo.customer_id')"), 'staff custom-order jobs filter by job_orders.customer_id');
$check(str_contains($api, "printflow_customer_exclusion_customer_scope_sql('cust.customer_id')"), 'staff custom-order customization rows filter by customizations.customer_id');
$check(str_contains($api, "printflow_customer_exclusion_customer_scope_sql('service_orders.customer_id')"), 'service-order counts filter by service_orders.customer_id');
$check(str_contains($api, "printflow_customer_exclusion_customer_scope_sql('job_orders.customer_id')"), 'standalone job counts filter by job_orders.customer_id');
$check(!str_contains($api, "CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) LIKE") || str_contains($api, "printflow_customer_exclusion_customer_scope_sql('jo.customer_id')"), 'name text is not the archive key for the custom-order list');

$search = $read('admin/api_jenny_customer_exclusion.php');
$check(str_contains($search, 'GROUP BY c.customer_id'), 'archive search keeps one row per customer_id');
$check(str_contains($search, 'c.customer_id = ?'), 'numeric archive search can select the exact customer_id');
$check(str_contains($search, "'archive_status' => \$archiveStatus"), 'archive search returns a per-account status');
$check(str_contains($search, "ce_status.customer_id = c.customer_id"), 'archive status is read from the customer exclusion row');
$check(!preg_match('/UPDATE\s+customers\s+SET/i', $search) && !preg_match('/DELETE\s+FROM\s+customers/i', $search), 'archive search does not modify customer accounts');

$page = $read('admin/archived_orders.php');
$check(str_contains($page, '<th>Customer</th><th>Email</th><th>Phone</th><th>Customer ID</th><th>Total orders</th><th>Visible orders</th><th>Archived orders</th><th>Archive status</th>'), 'admin search results show name, email, phone, customer ID, counts, and archive status');
$check(str_contains($page, '${esc(c.archive_status||\'Visible\')}'), 'each search result prints its own archive status');
$check(str_contains($page, 'data-select="${Number(c.customer_id)}"'), 'selecting a result uses that row customer_id');

$reports = $read('includes/reports_dashboard_queries.php');
$check(str_contains($reports, "printflow_customer_exclusion_customer_scope_sql(\$alias . '.customer_id')"), 'report job and service sales use the row customer_id');

if ($failures !== []) {
    fwrite(STDERR, "FAIL customer_archive_name_isolation_contract_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK customer_archive_name_isolation_contract_test\n";
