<?php
// Execute the real reporting functions without opening a database connection.
declare(strict_types=1);
function pf_reports_store_order_sales_expr($a) { return '1=1'; }
function pf_reports_store_order_revenue_expr($a) { return "$a.total_amount"; }
function pf_reports_job_order_sales_expr($a) { return '1=1'; }
function pf_reports_job_order_revenue_expr($a) { return "$a.estimated_total"; }
function pf_reports_job_order_sales_date_expr($a) { return "$a.created_at"; }
function pf_reports_job_order_sales_date_join_parts($jobAlias, $orderAlias) { return ['', "$jobAlias.created_at"]; }
function pf_reports_job_exclude_linked_custom_store_sale_sql($a) { return ''; }
function pf_reports_store_product_order_scope_sql($a) { return '1=1'; }
function pf_reports_store_custom_order_scope_sql($a) { return '1=1'; }
function db_table_has_column(...$args) { return false; }
function branch_where_parts($a, $branch) { return $branch === 'all' ? ['', '', []] : [" AND $a.branch_id = ?", 'i', [(int)$branch]]; }
function pf_reports_deduplicate_official_sales_transactions($rows) { return $rows; }
function db_query($sql, $types = '', $params = []) {
    $GLOBALS['queries'][] = [$sql, $types, $params];
    if (str_starts_with($sql, 'SELECT COUNT(*)')) return [['cnt' => 2, 'rev' => str_contains($sql, 'FROM orders o') ? 123.45 : 60.55]];
    return [];
}
$source = file_get_contents(dirname(__DIR__) . '/includes/reports_dashboard_queries.php');
foreach (['pf_reports_canonical_official_sales_totals', 'pf_reports_date_expr_where', 'pf_reports_official_sales_breakdown'] as $fn) {
    $start = strpos($source, 'function ' . $fn . '(');
    $tokens = token_get_all('<?php ' . substr($source, $start));
    $body = ''; $depth = 0; $opened = false;
    foreach (array_slice($tokens, 1) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $body .= $text;
        if ($token === '{') { $opened = true; $depth++; }
        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
        if ($token === '}' && --$depth === 0 && $opened) break;
    }
    eval($body);
}
foreach ([1, 2, 'all'] as $branch) {
    $queries = [];
    $old = pf_reports_official_sales_breakdown('2026-10-01', '2026-10-15 23:59:59', $branch, 1);
    $oldCount = count($queries);
    $queries = [];
    $new = pf_reports_canonical_official_sales_totals('2026-10-01', '2026-10-15 23:59:59', $branch);
    if ($old['summary']['total_sales'] !== $new['total_sales'] || $new['total_sales'] !== 184.0) throw new RuntimeException('Revenue parity failed');
    if (count($queries) !== 2 || $oldCount <= 2) throw new RuntimeException('Unused report queries not eliminated');
    foreach ($queries as [$sql, $types, $params]) {
        if ($params[0] !== '2026-10-01' || $params[1] !== '2026-10-15 23:59:59') throw new RuntimeException('Date range changed');
        if ($branch !== 'all' && end($params) !== $branch) throw new RuntimeException('Branch binding lost');
    }
    echo "Branch $branch: revenue parity passed; $oldCount -> 2 data queries (stubbed DB).\n";
}
