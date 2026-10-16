<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/expense_management.php';
require_role(['Admin', 'Manager']);
header('Content-Type: application/json; charset=utf-8');

$started = microtime(true);
$allowed = ['trend','forecast','heatmap','locations','status','products','services','distribution','branches','insights'];
$section = strtolower(trim((string)($_GET['section'] ?? '')));
$json = static function (array $payload): void { ob_clean(); echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); exit; };
if (!in_array($section, $allowed, true)) $json(['success' => false, 'error' => 'invalid_section']);

// Keep each analytics request bounded. Never turn execution limits off.
$limit = 8;
if (function_exists('set_time_limit')) @set_time_limit($limit);
$user = get_logged_in_user();
$isManager = (($user['role'] ?? '') === 'Manager');
$ctx = init_branch_context(false);
$branchId = $ctx['selected_branch_id'];
if ($isManager) {
    $forced = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
    if ($forced > 0) { $branchId = $forced; $ctx['selected_branch_id'] = $forced; }
}
$from = isset($_GET['from']) ? trim((string)$_GET['from']) : date('Y-m-d');
$to = isset($_GET['to']) ? trim((string)$_GET['to']) : date('Y-m-d');
if ($from !== '' && $to !== '' && strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];
$toEnd = $to !== '' ? $to . ' 23:59:59' : '';
$branchName = (string)($ctx['branch_name'] ?? 'All Branches');
$esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => '&#8369;' . number_format((float)$v, 0);
$table = static function (array $headers, array $rows) use ($esc): string {
    if (!$rows) return '<p class="pf-fast-note">No data available for this period.</p>';
    $html='<table class="pf-fast-table"><thead><tr>'; foreach($headers as $h)$html.='<th>'.$esc($h).'</th>'; $html.='</tr></thead><tbody>';
    foreach($rows as $row){$html.='<tr>';foreach($row as $cell)$html.='<td>'.$esc($cell).'</td>';$html.='</tr>';}$html.='</tbody></table>';return $html;
};
$html = '';
try {
    if ($section === 'trend') {
        $start = date('Y-m-01', strtotime('-11 months')); $monthly = pf_reports_canonical_official_sales_monthly($start, date('Y-m-t') . ' 23:59:59', $branchId); $rows=[];
        for($i=0;$i<12;$i++){ $key=date('Y-m',strtotime($start.' +'.$i.' months')); $r=$monthly[$key]??[]; $rows[]=[date('M Y',strtotime($key.'-01')),$money($r['total_sales']??0),(int)($r['transaction_count']??0)]; }
        $html=$table(['Month','Revenue','Orders'],$rows);
    } elseif ($section === 'forecast') {
        $rows=pf_reports_top_products_merged($from,$toEnd,$branchId,6); $html='<p class="pf-fast-note">Demand forecast inputs loaded for the selected period.</p>'.$table(['Product','Units','Revenue'],array_map(static fn($r)=>[$r['product_name']??$r['name']??'Unknown',(int)($r['qty_sold']??$r['quantity']??0),number_format((float)($r['revenue']??0),2)],$rows));
    } elseif ($section === 'heatmap') {
        $years=pf_reports_heatmap_available_years($branchId); $year=(int)($_GET['heatmap_year']??0); if(!$year)$year=$years[0]??(int)date('Y'); $rows=pf_reports_heatmap_sums_for_year($year,$branchId); $html='<p class="pf-fast-note">Seasonal demand data for '.$esc($year).'.</p>'.$table(['Product','Units'],array_map(static fn($r)=>[$r['product']??$r['name']??'Unknown',(int)($r['qty']??$r['quantity']??0)],array_slice($rows,0,20))); 
    } elseif ($section === 'locations') {
        $rows=pf_reports_customer_locations_merged($from,$toEnd,$branchId,5,false,$branchName); $html=$table(['Area','Orders'],array_map(static fn($r)=>[$r['city']??'Unknown',(int)($r['orders']??0)],$rows));
    } elseif ($section === 'status') {
        [$b,$bt,$bp]=branch_where_parts('o',$branchId); $dw='';$dt='';$dp=[]; if($from!==''&&$toEnd!==''){ $dw=' AND o.order_date BETWEEN ? AND ?';$dt='ss';$dp=[$from,$toEnd]; }
        $draft=" AND NOT (LOWER(TRIM(COALESCE(o.payment_status,'')))='unpaid' AND LOWER(TRIM(COALESCE(o.status,'')))='draft' AND LOWER(TRIM(COALESCE(o.order_source,''))) IN ('pos_draft',''))";
        $rows=db_query('SELECT o.status, COUNT(*) cnt FROM orders o WHERE '.printflow_order_archive_scope_sql('o').$draft.$dw.$b.' GROUP BY o.status ORDER BY cnt DESC',$dt.$bt,array_merge($dp,$bp)); $html=$table(['Status','Orders'],array_map(static fn($r)=>[$r['status']??'Unknown',(int)$r['cnt']],$rows));
    } elseif ($section === 'products') {
        $rows=pf_reports_sales_by_official_product($from,$toEnd,$branchId); $html=$table(['Product','Revenue','Units'],array_map(static fn($r)=>[$r['name']??$r['product_name']??'Unknown',$r['total']??$r['revenue']??0,$r['items_sold']??$r['qty_sold']??0],array_slice($rows,0,10)));
    } elseif ($section === 'services') {
        $rows=pf_reports_sales_by_service_category($from,$toEnd,$branchId); $html=$table(['Service','Revenue','Jobs'],array_map(static fn($r)=>[$r['category']??'Customization',$r['total']??0,$r['qty_sold']??0],array_slice($rows,0,10)));
    } elseif ($section === 'distribution') {
        $rows=pf_reports_branch_performance_merged($from,$toEnd,$branchId,null,null,true); $html=$table(['Branch','Revenue'],array_map(static fn($r)=>[$r['branch_name']??'Branch',$r['revenue']??0],array_slice($rows,0,20)));
    } elseif ($section === 'branches') {
        $rows=pf_reports_branch_performance_merged($from,$toEnd,$branchId,null,null,true); $html=$table(['Branch','Revenue','Store','Services'],array_map(static fn($r)=>[$r['branch_name']??'Branch',$r['revenue']??0,$r['revenue_store']??0,$r['revenue_jobs']??0],array_slice($rows,0,20)));
    } else {
        $rows=pf_reports_branch_performance_merged($from,$toEnd,$branchId,null,null,true); $top=$rows[0]??null; $html=$top?'<p>Leading branch: <strong>'.$esc($top['branch_name']??'Branch').'</strong> at <strong>'.$money($top['revenue']??0).'</strong>.</p>':'<p class="pf-fast-note">No insights available for this period.</p>';
    }
    $perf = function_exists('printflow_db_perf_snapshot') ? printflow_db_perf_snapshot() : ['count'=>null];
    $diagnostics = (($_GET['reports_perf'] ?? '') === '1') ? ['section'=>$section,'query_count'=>$perf['count']??null,'php_ms'=>round((microtime(true)-$started)*1000,1)] : null;
    $json(['success'=>true,'html'=>$html,'diagnostics'=>$diagnostics]);
} catch (Throwable $e) {
    error_log('[reports] section '.$section.' failed: '.$e->getMessage());
    $json(['success'=>false,'error'=>'section_failed']);
}
