<?php
/**
 * Backdated POS / service orders must classify as custom once and use order_date for job sales dating.
 */

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/includes/reports_dashboard_queries.php');
$dashboard = (string)file_get_contents($root . '/admin/dashboard.php');

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$assert(str_contains($source, 'function pf_reports_store_custom_service_exists_sql'), 'custom/service scope helper must exist');
$assert(
    str_contains($source, 'job_orders jo_cs') && str_contains($source, 'customizations cz_cs'),
    'custom scope must detect job/customization rows not only order_type'
);
$assert(
    !preg_match("/order_type, 'product'\)\) = 'custom'/", $source),
    'custom scope must not default NULL order_type to product'
);
$assert(
    str_contains($source, 'NOT {$custom}') || str_contains($source, 'AND NOT {$custom}'),
    'product scope must exclude customization/service store orders'
);
$assert(
    str_contains($source, 'o_biz.order_date') && str_contains($source, 'pf_reports_job_order_sales_date_expr'),
    'job sales date must prefer linked store order_date for backdated POS'
);
$assert(
    str_contains($dashboard, 'dashboard_branch_total_revenue = (float)$total_revenue'),
    'single-branch dashboard chart must match official KPI revenue'
);

if ($failures !== []) {
    fwrite(STDERR, "Sales backdated revenue scope test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Sales backdated revenue scope test passed.\n";
