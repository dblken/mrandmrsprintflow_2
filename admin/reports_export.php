<?php
/**
 * Reports CSV Export Endpoint
 * PrintFlow - Admin Reports
 * Professional CSV exports with branch filtering and proper formatting
 */

// Prevent PHP errors from corrupting CSV output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/reports_date_range.php';
require_once __DIR__ . '/../includes/sales_page_queries.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';

require_role(['Admin', 'Manager']);

$report   = $_GET['report'] ?? '';
$dateRange = pf_reports_export_date_range();
$from     = $dateRange['from'];
$to       = $dateRange['to'];
$fromStart = $dateRange['fromStart'];
$toEnd    = $dateRange['toEnd'];

$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id'];
$branchName = $branchCtx['branch_name'];

[$dateSql, $dateTypes, $dateParams] = pf_reports_order_date_where('o');

[$bSql, $bTypes, $bParams] = branch_where_parts('o', $branchId);

$storePaidSql = pf_reports_store_order_paid_completed_expr('o');
$storePaidOnlySql = pf_reports_store_order_paid_expr('o');
$jobCompletedSql = pf_reports_job_order_paid_completed_expr('jo');
$storeRevenueSql = pf_reports_store_order_revenue_expr('o');

// UTF-8 BOM for Excel compatibility
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="PrintFlow_' . preg_replace('/[^a-z0-9]/i', '_', $report) . '_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

// Safe CSV value - trim and ensure no control chars
function csvVal($v) {
    if ($v === null || $v === '') return '';
    $v = trim((string)$v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    return $v;
}

function writeReportHeader($output, $reportType, $from, $to, $branchName = 'All Branches') {
    fputcsv($output, ['PrintFlow Sales & Analytics Report']);
    fputcsv($output, ['Report Type', $reportType]);
    fputcsv($output, ['Branch', csvVal($branchName)]);
    fputcsv($output, ['Date Range', date('F j, Y', strtotime($from)) . ' – ' . date('F j, Y', strtotime($to))]);
    fputcsv($output, ['Generated On', date('F j, Y, g:i A', strtotime('now'))]);
    fputcsv($output, []);
}

function pf_report_inventory_soh(int $itemId, bool $trackByRoll, $branchId): float {
    InventoryManager::ensureBranchScopedSchema();
    if ($branchId === 'all') {
        if ($trackByRoll) {
            return (float)(db_query(
                "SELECT COALESCE(SUM(remaining_length_ft), 0) AS soh
                 FROM inv_rolls
                 WHERE item_id = ? AND status = 'OPEN'",
                'i',
                [$itemId]
            )[0]['soh'] ?? 0);
        }
        return (float)(db_query(
            "SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN quantity ELSE -quantity END), 0) AS soh
             FROM inventory_transactions
             WHERE item_id = ?",
            'i',
            [$itemId]
        )[0]['soh'] ?? 0);
    }

    return (float)InventoryManager::getStockOnHand($itemId, (int)$branchId);
}

switch ($report) {

    // ═══════════════════════════════════════════════════════
    // SALES REPORT
    // ═══════════════════════════════════════════════════════
    case 'sales':
        $salesBundle = pf_sales_page_filtered_breakdown($_GET, $branchId, 5000);
        $salesData = $salesBundle['salesData'];
        $salesFilterMeta = $salesBundle['filter_meta'];

        fputcsv($output, ['PrintFlow Sales & Analytics Report']);
        fputcsv($output, ['Report Type', 'Sales Report']);
        fputcsv($output, ['Branch', csvVal($branchName)]);
        foreach ($salesFilterMeta as $metaLabel => $metaValue) {
            fputcsv($output, [$metaLabel, csvVal($metaValue)]);
        }
        fputcsv($output, ['Generated On', date('F j, Y, g:i A', strtotime('now'))]);
        fputcsv($output, []);

        $sum = $salesData['summary'] ?? [];
        $totalRev = (float)($sum['total_sales'] ?? 0);
        $totalOrd = (int)($sum['transaction_count'] ?? 0);
        $avgVal = $totalOrd > 0 ? ($totalRev / $totalOrd) : 0;

        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Sales Transactions', $totalOrd]);
        fputcsv($output, ['Total Revenue', number_format($totalRev, 2, '.', '')]);
        fputcsv($output, ['Product Sales', number_format((float)($sum['product_sales'] ?? 0), 2, '.', '')]);
        fputcsv($output, ['Service/Custom Sales', number_format((float)($sum['service_sales'] ?? 0), 2, '.', '')]);
        fputcsv($output, ['Average Sale Value', number_format($avgVal, 2, '.', '')]);
        fputcsv($output, []);

        fputcsv($output, ['Type', 'Order #', 'Customer', 'Branch', 'Sales Date', 'Amount', 'Payment Status', 'Order Status']);
        foreach (($salesData['transactions'] ?? []) as $row) {
            $dateForCsv = !empty($row['sales_date']) ? '="' . date('Y-m-d H:i', strtotime((string)$row['sales_date'])) . '"' : '';
            fputcsv($output, [
                csvVal($row['type'] ?? ''),
                '#' . (int)($row['id'] ?? 0),
                csvVal($row['customer_name'] ?? ''),
                csvVal($row['branch_name'] ?? ''),
                $dateForCsv,
                number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                csvVal($row['payment_status'] ?? ''),
                csvVal($row['status'] ?? ''),
            ]);
        }
        fputcsv($output, ['TOTAL', '', '', '', '', number_format($totalRev, 2, '.', ''), '', '']);
        break;

    // ORDERS STATUS REPORT
    // ═══════════════════════════════════════════════════════
    case 'orders':
        writeReportHeader($output, 'Orders Status Report', $from, $to, $branchName);

        $summary = db_query(
            "SELECT COUNT(*) as total_orders, SUM(o.total_amount) as total_revenue,
                    AVG(o.total_amount) as avg_order_value
             FROM orders o
             WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}",
            $dateTypes.$bTypes, array_merge($dateParams, $bParams)
        );
        $sum = $summary[0] ?? [];
        $grandTotalOrd = (int)($sum['total_orders'] ?? 0);
        $grandTotalRev = (float)($sum['total_revenue'] ?? 0);
        $avgOrderVal = (float)($sum['avg_order_value'] ?? 0);

        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Orders', $grandTotalOrd]);
        fputcsv($output, ['Total Revenue', number_format($grandTotalRev, 2, '.', '')]);
        fputcsv($output, ['Average Order Value', number_format($avgOrderVal, 2, '.', '')]);
        fputcsv($output, []);

        fputcsv($output, ['Status', 'Total Orders', 'Total Amount']);

        $status_counts = db_query(
            "SELECT o.status, COUNT(*) as cnt, SUM(o.total_amount) as total
             FROM orders o
             WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}
             GROUP BY o.status ORDER BY cnt DESC",
            $dateTypes.$bTypes, array_merge($dateParams, $bParams)
        );

        if ($status_counts) {
            foreach ($status_counts as $sc) {
                fputcsv($output, [csvVal($sc['status']), (int)$sc['cnt'], number_format((float)$sc['total'], 2, '.', '')]);
            }
        }
        fputcsv($output, ['TOTAL', $grandTotalOrd, number_format($grandTotalRev, 2, '.', '')]);
        fputcsv($output, []);

        fputcsv($output, ['Date', 'Number of Orders', 'Revenue']);

        $daily = db_query(
            "SELECT DATE(o.order_date) as day, COUNT(*) as cnt, SUM(o.total_amount) as total
             FROM orders o
             WHERE 1=1{$dateSql} AND {$storePaidSql}{$bSql}
             GROUP BY DATE(o.order_date) ORDER BY day DESC",
            $dateTypes.$bTypes, array_merge($dateParams, $bParams)
        );

        if ($daily) {
            $dayCount = count($daily);
            $dayTotalOrd = 0;
            $dayTotalRev = 0;
            foreach ($daily as $d) {
                $cnt = (int)$d['cnt'];
                $tot = (float)$d['total'];
                $dayTotalOrd += $cnt;
                $dayTotalRev += $tot;
                fputcsv($output, [
                    date('M j, Y', strtotime($d['day'])),
                    $cnt,
                    number_format($tot, 2, '.', '')
                ]);
            }
            if ($dayCount > 0) {
                fputcsv($output, ['DAILY AVERAGE', number_format($dayTotalOrd / $dayCount, 1, '.', ''), number_format($dayTotalRev / $dayCount, 2, '.', '')]);
            }
        }
        break;

    // ═══════════════════════════════════════════════════════
    // CUSTOMERS REPORT
    // ═══════════════════════════════════════════════════════
    case 'customers':
        writeReportHeader($output, 'Customers Report', $from, $to, $branchName);

        if ($branchId !== 'all') {
            [$totalCustCsv, $activeCustCsv] = branch_customers_summary_for_branch((int)$branchId);
            $cs = ['total' => $totalCustCsv, 'active' => $activeCustCsv];
        } else {
            $cust_summary = db_query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Activated' THEN 1 ELSE 0 END) as active FROM customers");
            $cs = $cust_summary[0] ?? [];
        }

        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Customers', (int)($cs['total'] ?? 0)]);
        fputcsv($output, ['Active Customers', (int)($cs['active'] ?? 0)]);
        fputcsv($output, []);

        fputcsv($output, ['Customer ID', 'Name', 'Email', 'Contact Number', 'Status', 'Registered Date', 'Total Orders', 'Total Spent']);

        if ($branchId !== 'all') {
            $customers = branch_customers_report_list((int)$branchId);
        } else {
            $customers = db_query(
                "SELECT c.customer_id, CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as name,
                        COALESCE(c.email,'') as email, COALESCE(c.contact_number,'') as contact_number, c.status, c.created_at,
                        COUNT(o.order_id) as order_count, COALESCE(SUM(o.total_amount), 0) as total_spent
                 FROM customers c
                 LEFT JOIN orders o ON c.customer_id = o.customer_id AND {$storePaidSql}
                 GROUP BY c.customer_id
                 HAVING order_count > 0
                 ORDER BY total_spent DESC"
            ) ?: [];
        }

        if ($customers) {
            foreach ($customers as $c) {
                fputcsv($output, [
                    (int)$c['customer_id'],
                    csvVal($c['name']),
                    csvVal($c['email']),
                    csvVal($c['contact_number'] ?? ''),
                    csvVal($c['status']),
                    date('Y-m-d', strtotime($c['created_at'])),
                    (int)$c['order_count'],
                    number_format((float)$c['total_spent'], 2, '.', '')
                ]);
            }
        }
        break;

    // ═══════════════════════════════════════════════════════
    // DAILY SALES (same idea as staff export; branch-aware for admin)
    // ═══════════════════════════════════════════════════════
    case 'daily_sales': {
        $day = $_GET['date'] ?? $to;
        $day = date('Y-m-d', strtotime($day));
        writeReportHeader($output, 'Daily Sales Report', $day, $day, $branchName);
        fputcsv($output, ['Snapshot date', date('F j, Y', strtotime($day))]);
        fputcsv($output, []);

        $salesData = pf_reports_official_sales_breakdown($day . ' 00:00:00', $day . ' 23:59:59', $branchId, 500);
        $sum = $salesData['summary'] ?? [];
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Sales Transactions', (int)($sum['transaction_count'] ?? 0)]);
        fputcsv($output, ['Total Sales', number_format((float)($sum['total_sales'] ?? 0), 2, '.', '')]);
        fputcsv($output, ['Product Sales', number_format((float)($sum['product_sales'] ?? 0), 2, '.', '')]);
        fputcsv($output, ['Service/Custom Sales', number_format((float)($sum['service_sales'] ?? 0), 2, '.', '')]);
        fputcsv($output, []);

        fputcsv($output, ['Type', 'Order #', 'Customer', 'Branch', 'Time', 'Amount', 'Status', 'Payment']);
        foreach (($salesData['transactions'] ?? []) as $row) {
            fputcsv($output, [
                csvVal($row['type'] ?? ''),
                '#' . (int)($row['id'] ?? 0),
                csvVal($row['customer_name'] ?? ''),
                csvVal($row['branch_name'] ?? ''),
                !empty($row['sales_date']) ? date('h:i A', strtotime((string)$row['sales_date'])) : '',
                number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                csvVal($row['status'] ?? ''),
                csvVal($row['payment_status'] ?? ''),
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['TOTAL', '', '', '', '', number_format((float)($sum['total_sales'] ?? 0), 2, '.', ''), '', '']);
        break;
    }

    // ═══════════════════════════════════════════════════════
    // SHOP INVENTORY — products + inv_items (matches staff CSV)
    // ═══════════════════════════════════════════════════════
    case 'shop_inventory':
        writeReportHeader($output, 'Products & Materials Inventory', $from, $to, $branchName);
        printflow_ensure_product_branch_stock_table();

        fputcsv($output, ['PRODUCT CATALOG']);
        fputcsv($output, ['Product', 'SKU', 'Category', 'Stock', 'Price', 'Status']);

        $productSql = "SELECT p.name, p.sku, p.category, p.price, p.status,
                              p.stock_quantity,
                              p.low_stock_level";
        $productTypes = '';
        $productParams = [];
        if ($branchId !== 'all') {
            $productSql .= ", COALESCE(pbs.stock_quantity, 0) AS branch_stock_quantity,
                             COALESCE(pbs.low_stock_level, p.low_stock_level, 10) AS branch_low_stock_level
                             FROM products p
                             LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
                             WHERE p.status = 'Activated'
                             ORDER BY p.category, p.name";
            $productTypes = 'i';
            $productParams = [(int)$branchId];
        } else {
            $productSql .= " FROM products p
                             WHERE p.status = 'Activated'
                             ORDER BY p.category, p.name";
        }
        $products = db_query($productSql, $productTypes ?: null, $productParams ?: null);
        if ($products) {
            foreach ($products as $p) {
                $sq = ($branchId !== 'all')
                    ? (int)($p['branch_stock_quantity'] ?? 0)
                    : (int)($p['stock_quantity'] ?? 0);
                $lowLevel = ($branchId !== 'all')
                    ? (int)($p['branch_low_stock_level'] ?? 10)
                    : (int)($p['low_stock_level'] ?? 10);
                $stock_status = $sq <= 0 ? 'OUT OF STOCK' : ($sq <= $lowLevel ? 'LOW STOCK' : 'In Stock');
                fputcsv($output, [
                    csvVal($p['name']),
                    csvVal($p['sku'] ?? ''),
                    csvVal($p['category'] ?? ''),
                    $sq,
                    number_format((float)($p['price'] ?? 0), 2, '.', ''),
                    $stock_status,
                ]);
            }
        }
        fputcsv($output, []);

        fputcsv($output, ['INVENTORY ITEMS (materials / rolls)']);
        fputcsv($output, ['Item name', 'Category', 'Current stock', 'UOM', 'Roll-based']);

        $inv_items = db_query(
            "SELECT i.id, i.name, ic.name as category_name, i.unit_of_measure, i.track_by_roll
             FROM inv_items i
             LEFT JOIN inv_categories ic ON i.category_id = ic.id
             ORDER BY ic.name, i.name"
        );
        if ($inv_items) {
            foreach ($inv_items as $i) {
                $currentStock = pf_report_inventory_soh((int)$i['id'], !empty($i['track_by_roll']), $branchId);
                fputcsv($output, [
                    csvVal($i['name']),
                    csvVal($i['category_name'] ?? ''),
                    number_format($currentStock, 2, '.', ''),
                    csvVal($i['unit_of_measure'] ?? ''),
                    !empty($i['track_by_roll']) ? 'Yes' : 'No',
                ]);
            }
        }
        break;

    // ═══════════════════════════════════════════════════════
    // INVENTORY REPORT
    // ═══════════════════════════════════════════════════════
    case 'inventory':
        writeReportHeader($output, 'INVENTORY & STOCK REPORT', $from, $to, $branchName);

        fputcsv($output, ['MATERIAL STOCK LEVELS']);
        fputcsv($output, ['Category', 'Material', 'Unit', 'Opening Stock', 'Current Stock', 'Stock Used', 'Status']);

        $materials = db_query(
            "SELECT mc.category_name, m.material_name, m.unit, m.opening_stock, m.current_stock
             FROM materials m
             JOIN material_categories mc ON m.category_id = mc.category_id
             ORDER BY mc.category_name, m.material_name"
        );

        if ($materials) {
            $total_opening = 0;
            $total_current = 0;
            foreach ($materials as $m) {
                $used = (float)$m['opening_stock'] - (float)$m['current_stock'];
                $status = (float)$m['current_stock'] <= 0 ? 'OUT OF STOCK' : ((float)$m['current_stock'] < (float)$m['opening_stock'] * 0.2 ? 'LOW STOCK' : 'In Stock');
                $total_opening += (float)$m['opening_stock'];
                $total_current += (float)$m['current_stock'];
                fputcsv($output, [
                    $m['category_name'],
                    $m['material_name'],
                    $m['unit'],
                    number_format((float)$m['opening_stock'], 2),
                    number_format((float)$m['current_stock'], 2),
                    number_format($used, 2),
                    $status
                ]);
            }
            fputcsv($output, []);
            fputcsv($output, ['', '', 'TOTALS:', number_format($total_opening, 2), number_format($total_current, 2), number_format($total_opening - $total_current, 2)]);
        }

        fputcsv($output, []);

        // Stock movements in the period
        fputcsv($output, ['STOCK MOVEMENTS IN PERIOD']);
        fputcsv($output, ['Date', 'Material', 'Change', 'Notes']);

        $movements = db_query(
            "SELECT msm.movement_date, m.material_name, msm.quantity_change, msm.notes
             FROM material_stock_movements msm
             JOIN materials m ON msm.material_id = m.material_id
             WHERE msm.movement_date BETWEEN ? AND ?
             ORDER BY msm.movement_date DESC",
            'ss', [$from, $to]
        );

        if ($movements) {
            foreach ($movements as $mv) {
                fputcsv($output, [
                    date('M d, Y', strtotime($mv['movement_date'])),
                    $mv['material_name'],
                    number_format((float)$mv['quantity_change'], 2),
                    $mv['notes']
                ]);
            }
        }
        break;

    default:
        fputcsv($output, ['Error: Invalid report type specified.']);
        break;
}

fclose($output);
exit;
