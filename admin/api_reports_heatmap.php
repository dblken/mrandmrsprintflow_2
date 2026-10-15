<?php
/**
 * JSON heatmap data for reports — year + branch filter (no full page reload).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';

require_role(['Admin', 'Manager']);

$branchCtx = init_branch_context(false);
$branchId = $branchCtx['selected_branch_id'];

[$y_cal, $m_cal] = pf_reports_heatmap_db_ym();
$year = isset($_GET['year']) ? (int) $_GET['year'] : $y_cal;
$heatmapType = isset($_GET['heatmap_type']) ? strtolower(trim((string) $_GET['heatmap_type'])) : 'all';
if (!in_array($heatmapType, ['all', 'product', 'service'], true)) {
    $heatmapType = 'all';
}

$emptyPayload = static function (int $y, bool $valid, string $msg = '') use ($y_cal, $m_cal): array {
    return [
        'ok' => true,
        'year' => $y,
        'yearValid' => $valid,
        'message' => $msg,
        'serverYear' => $y_cal,
        'serverMonth' => $m_cal,
        'empty' => true,
        'series' => [],
        'height' => 200,
        'rowCount' => 0,
    ];
};

if (!pf_reports_branch_has_activity($branchId)) {
    echo json_encode($emptyPayload($year, false, 'No data for this branch'));
    exit;
}

$available = pf_reports_heatmap_available_years($branchId);
if ($available === []) {
    echo json_encode($emptyPayload($year, false, 'No historical data available'));
    exit;
}

if ($year > $y_cal) {
    echo json_encode($emptyPayload($year, false, 'No data available for selected year'));
    exit;
}

if (!in_array($year, $available, true)) {
    echo json_encode($emptyPayload($year, false, 'No data available for selected year'));
    exit;
}

$heatmap_cells = pf_reports_heatmap_matrix($year, $branchId, $heatmapType);
if ($heatmap_cells === []) {
    echo json_encode($emptyPayload($year, true, 'No data available for selected year'));
    exit;
}

$series = [];
foreach ($heatmap_cells as $prod => $mo) {
    $meta = pf_reports_heatmap_row_meta((string) $prod);
    $displayName = $meta['label'] . ' - ' . $meta['source'];
    $row = [];
    for ($m = 1; $m <= 12; $m++) {
        $c = $mo[$m] ?? ['qty' => 0, 'kind' => 'empty'];
        $row[] = [
            'x' => date('M', mktime(0, 0, 0, $m, 1)),
            'y' => (int) ($c['qty'] ?? 0),
            'kind' => (string) ($c['kind'] ?? 'empty'),
        ];
    }
    $series[] = [
        'key' => (string) $prod,
        'name' => $displayName,
        'source' => $meta['source'],
        'label' => $meta['label'],
        'data' => $row,
    ];
}

$n = count($heatmap_cells);
$height = max(200, $n * 48 + 72);

echo json_encode([
    'ok' => true,
    'year' => $year,
    'yearValid' => true,
    'message' => '',
    'serverYear' => $y_cal,
    'serverMonth' => $m_cal,
    'empty' => false,
    'series' => $series,
    'height' => $height,
    'rowCount' => $n,
]);
