<?php
/**
 * Official sales must count each completed/paid checkout once (orders OR job_orders, not both).
 */

$root = dirname(__DIR__);
require_once $root . '/includes/sales_report_dedupe.php';

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$reportsSource = (string)file_get_contents($root . '/includes/reports_dashboard_queries.php');
$dedupeSource = (string)file_get_contents($root . '/includes/sales_report_dedupe.php');
$assert(
    str_contains($dedupeSource, 'function pf_reports_deduplicate_official_sales_transactions'),
    'sales dedupe helper must exist'
);
$assert(
    str_contains($reportsSource, 'pf_reports_deduplicate_official_sales_transactions($txnRows)'),
    'transaction list must pass through dedupe before Admin Sales totals'
);

$excludeSql = '';
if (preg_match('/function pf_reports_job_exclude_linked_custom_store_sale_sql[\s\S]*?return "([^"]+)"/', $reportsSource, $m)) {
    $excludeSql = str_replace('{$alias}', 'jo', $m[1]);
    $excludeSql = str_replace('{$storeSales}', 'STORE_SALES_EXPR', $excludeSql);
}
$assert(
    !str_contains($excludeSql, 'custom') && !str_contains($excludeSql, 'order_type'),
    'job exclusion must not require order_type=custom (online rows can stay product typed)'
);
$assert(
    str_contains($excludeSql, 'jo.order_id > 0') || str_contains($excludeSql, 'order_id > 0'),
    'job exclusion must require a linked store order_id'
);
$assert(
    str_contains($excludeSql, 'STORE_SALES_EXPR') || str_contains($reportsSource, 'pf_reports_store_order_sales_expr(\'o_ex\')'),
    'job exclusion must key off the same paid/completed store-order sales rule'
);

$dupRows = [
    [
        'type' => 'Service',
        'ref_type' => 'order',
        'id' => 12180,
        'store_order_id' => 12180,
        'sales_date' => '2026-09-09 10:00:00',
        'amount' => 1000.0,
    ],
    [
        'type' => 'Service',
        'ref_type' => 'job',
        'id' => 551,
        'store_order_id' => 12180,
        'sales_date' => '2026-09-09 14:00:00',
        'amount' => 1000.0,
    ],
];
$deduped = pf_reports_deduplicate_official_sales_transactions($dupRows);
$assert(count($deduped) === 1, 'same store order must produce one transaction row');
$assert(
    ($deduped[0]['ref_type'] ?? '') === 'order' && (float)($deduped[0]['amount'] ?? 0) === 1000.0,
    'store order row wins over linked job row for the same ₱1,000 sale'
);

$legacyJobOnly = [
    [
        'type' => 'Service',
        'ref_type' => 'job',
        'id' => 900,
        'store_order_id' => 0,
        'amount' => 750.0,
    ],
];
$assert(
    count(pf_reports_deduplicate_official_sales_transactions($legacyJobOnly)) === 1,
    'legacy job-only sales without store_order_id remain visible'
);

$paymentPage = (string)file_get_contents($root . '/admin/sales.php');
$assert(str_contains($paymentPage, 'pf_reports_official_sales_breakdown'), 'Admin Sales uses the shared official sales breakdown');

if ($failures !== []) {
    fwrite(STDERR, "Reports sales double-count test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Reports sales double-count test passed.\n";
