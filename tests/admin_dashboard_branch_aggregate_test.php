<?php
declare(strict_types=1);
// Exercise the real helper without a DB connection, including all four SQL
// aggregates, previous-period growth, branch bindings, and default callers.
function pf_reports_store_order_sales_expr($a) { return '(archive_ok AND paid_ok)'; }
function pf_reports_store_order_revenue_expr($a) { return "$a.total_amount"; }
function pf_reports_store_product_order_scope_sql($a) { $GLOBALS['scopeCalls']++; return '(archive_ok AND NOT custom_order)'; }
function pf_reports_store_custom_order_scope_sql($a) { $GLOBALS['scopeCalls']++; return '(archive_ok AND custom_order)'; }
function pf_reports_job_order_sales_expr($a) { return 'job_paid_ok'; }
function pf_reports_job_order_revenue_expr($a) { return "$a.estimated_total"; }
function pf_reports_job_order_sales_date_expr($a) { return "$a.created_at"; }
function pf_reports_job_exclude_linked_custom_store_sale_sql($a) { return ' AND orphan_job'; }
function db_query($sql, $types = null, $params = null) {
    $GLOBALS['queries'][] = [$sql, $types, $params];
    if (str_starts_with($sql, 'SELECT id, branch_name FROM branches')) return [['id'=>1, 'branch_name'=>'First'], ['id'=>2, 'branch_name'=>'Second']];
    return [['branch_id'=>1, 'ord'=>2, 'rev'=>40], ['branch_id'=>2, 'ord'=>1, 'rev'=>20]];
}
$source = file_get_contents(dirname(__DIR__) . '/includes/reports_dashboard_queries.php');
$start = strpos($source, 'function pf_reports_branch_performance_merged(');
$tokens = token_get_all('<?php ' . substr($source, $start));
$body = ''; $depth = 0; $opened = false;
foreach (array_slice($tokens, 1) as $token) {
    $body .= is_array($token) ? $token[1] : $token;
    if ($token === '{') { $opened = true; $depth++; }
    if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
    if ($token === '}' && --$depth === 0 && $opened) break;
}
eval($body);
foreach ([1, 2, 'all'] as $branch) {
    $queries = []; $scopeCalls = 0;
    $old = pf_reports_branch_performance_merged('2026-10-01', '2026-10-14 23:59:59', $branch, '2026-09-17', '2026-09-30 23:59:59');
    $oldQueries = $queries;
    if ($scopeCalls !== 2) throw new RuntimeException('Default report path changed');
    $queries = []; $scopeCalls = 0;
    $new = pf_reports_branch_performance_merged('2026-10-01', '2026-10-14 23:59:59', $branch, '2026-09-17', '2026-09-30 23:59:59', true);
    if ($scopeCalls !== 0 || $new !== $old) throw new RuntimeException('Dashboard output contract changed');
    $scope = '((archive_ok AND NOT custom_order) OR (archive_ok AND custom_order))';
    foreach ($oldQueries as $i => [$sql, $types, $params]) {
        if ([str_replace($scope, '1=1', $sql), $types, $params] !== $queries[$i]) throw new RuntimeException('Unexpected SQL or binding change');
    }
}
// SQL scopes coalesce classification fields; custom_order is a non-null
// boolean. A recognized sale implies archive_ok, even if archive itself can
// otherwise be unknown. Exhaust every boolean classification for sales.
foreach ([false, true] as $archive) foreach ([false, true] as $paid) foreach ([false, true] as $custom) {
    $sale = $archive && $paid;
    if (($sale && (($archive && !$custom) || ($archive && $custom))) !== $sale) throw new RuntimeException('Scope equivalence failed');
}
echo "Dashboard aggregate scope equivalence, bindings, growth and default report path passed (isolated DB).\n";
