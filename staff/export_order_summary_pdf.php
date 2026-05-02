<?php
/**
 * Export Order Summary - Professional HTML Report Viewer
 * PrintFlow - Staff Reports
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

$userType = $_SESSION['user_type'] ?? '';
if (!in_array($userType, ['Staff', 'Admin', 'Manager'], true)) {
    http_response_code(403);
    die('Unauthorized access.');
}

$staffId = $_SESSION['user_id'] ?? 0;
$staffData = db_query("SELECT first_name, last_name, email, contact_number, branch_id FROM users WHERE user_id = ?", 'i', [$staffId])[0] ?? [];
$staffName = trim(($staffData['first_name'] ?? '') . ' ' . ($staffData['last_name'] ?? '')) ?: 'Staff Account';
$staffEmail = $staffData['email'] ?? '';
$staffContact = $staffData['contact_number'] ?? '';

$staffBranchId = printflow_branch_filter_for_user() ?? (int)($staffData['branch_id'] ?? 1);
$branchInfo = db_query("SELECT * FROM branches WHERE id = ?", 'i', [$staffBranchId])[0] ?? [];
$branchName = $branchInfo['branch_name'] ?? 'Mr. and Mrs. Print Main';
$branchAddress = trim(($branchInfo['address'] ?? '') . ' ' . ($branchInfo['address_line'] ?? '') . ' ' . ($branchInfo['barangay'] ?? '') . ' ' . ($branchInfo['city'] ?? '') . ' ' . ($branchInfo['province'] ?? ''));
if (empty($branchAddress)) {
    $branchAddress = "#240 corner M.L. Quezon St., Cabuyao, Philippines, 4025";
}
$branchContact = $branchInfo['contact_number'] ?? '0921 212 2293';
$branchEmail = $branchInfo['email'] ?? 'mrandmrsprints@gmail.com';

$range = $_GET['range'] ?? 'week';
$status_filter = $_GET['status'] ?? 'ALL';

if ($range === 'month') {
    $date_condition = "YEAR(o.order_date) = YEAR(CURDATE()) AND MONTH(o.order_date) = MONTH(CURDATE())";
    $range_label = 'This Month';
} elseif ($range === 'today') {
    $date_condition = "DATE(o.order_date) = CURDATE()";
    $range_label = 'Today';
} else {
    $range = 'week';
    $date_condition = "YEARWEEK(o.order_date, 1) = YEARWEEK(CURDATE(), 1)";
    $range_label = 'This Week';
}

$sql = "
    SELECT
        o.order_id,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''), 'Guest') AS customer_name,
        COALESCE((SELECT COALESCE(p.name, 'Custom Service')
                  FROM order_items oi
                  LEFT JOIN products p ON oi.product_id = p.product_id
                  WHERE oi.order_id = o.order_id
                  LIMIT 1), 'General') AS service_type,
        o.order_date,
        o.total_amount,
        o.status
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    WHERE {$date_condition}
";

$params = [];
$types = '';

if ($staffBranchId !== null) {
    $sql .= " AND o.branch_id = ?";
    $params[] = $staffBranchId;
    $types .= 'i';
}

if ($status_filter !== 'ALL' && $status_filter !== '') {
    $sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " ORDER BY o.order_date DESC";

$orders = db_query($sql, $types ?: null, $params ?: null) ?: [];
$grandTotal = 0.0;
foreach ($orders as $row) {
    $grandTotal += (float)($row['total_amount'] ?? 0);
}

$sessionDate = date('F d, Y');
$generatedAt = date('M d, Y g:i A');

// Logo path
$logoPath = '../public/images/logo.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Summary Report - <?php echo $sessionDate; ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --primary-blue: #0047AB;
            --bg-grey: #f1f5f9;
            --paper-width: 850px;
        }

        * { box-sizing: border-box; }
        body { font-family: "Inter", "Segoe UI", Helvetica, Arial, sans-serif; background-color: var(--bg-grey); margin: 0; padding: 0; color: #1e293b; }

        /* Actions Bar */
        .actions {
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px);
            padding: 15px 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-print { background: #0f172a; color: #fff; }
        .btn-print:hover { background: #1e293b; }
        .btn-download { background: var(--primary-blue); color: #fff; }
        .btn-download:hover { background: #003580; }

        /* Report Container (A4 Style) */
        .report-wrapper {
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }

        .report-container {
            width: var(--paper-width);
            background: #fff;
            padding: 50px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border-radius: 4px;
            min-height: 1123px; /* A4 aspect ratio */
        }

        /* Header Styling */
        .report-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
        }

        .header-logo { width: 90px; margin-right: 20px; }
        .header-logo img { width: 100%; height: auto; border-radius: 50%; }
        .header-details { flex: 1; text-align: center; padding-right: 90px; }
        .header-details h1 { margin: 0; font-size: 24px; font-weight: 800; color: #000; }
        .header-details p { margin: 4px 0; font-size: 13px; color: #475569; }

        /* Content Sections */
        .report-title { font-size: 20px; font-weight: 800; color: var(--primary-blue); margin-bottom: 8px; }
        .meta-info { font-size: 13px; color: #64748b; margin-bottom: 25px; line-height: 1.6; }
        .meta-info strong { color: #1e293b; }

        /* Summary Boxes */
        .summary-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1.5fr 1.5fr;
            border: 1.5px solid #000;
            margin-bottom: 25px;
        }
        .summary-item { padding: 12px 15px; font-size: 14px; display: flex; align-items: center; border-right: 1.5px solid #000; }
        .summary-item:last-child { border-right: none; }
        .summary-label { background: #f8fafc; font-weight: 700; text-transform: uppercase; font-size: 11px; color: #475569; }
        .summary-value { font-weight: 800; font-size: 15px; justify-content: center; }
        .summary-total { font-weight: 800; font-size: 16px; color: #000; justify-content: flex-end; }

        /* Table Design */
        .orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 12px;
        }
        .orders-table th {
            background-color: var(--primary-blue);
            color: #fff;
            padding: 12px 8px;
            text-align: center;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            border: 1.5px solid #000;
        }
        .orders-table td {
            padding: 10px 8px;
            border: 1.5px solid #000;
            vertical-align: middle;
        }
        .orders-table tr:nth-child(even) { background-color: #f9fafb; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }

        /* Footer */
        .report-footer {
            margin-top: 50px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
        }
        .footer-left p { margin: 4px 0; }
        .footer-right { text-align: right; font-weight: 700; color: #1e293b; }

        /* Print Specifics */
        @media print {
            .actions { display: none !important; }
            body { background: #fff !important; }
            .report-wrapper { padding: 0 !important; }
            .report-container { 
                width: 100% !important; 
                box-shadow: none !important; 
                padding: 0 !important; 
                margin: 0 !important;
                min-height: auto;
            }
            .summary-item, .orders-table th, .orders-table td { border-width: 1pt !important; }
        }
    </style>
</head>
<body>

<div class="actions">
    <button onclick="window.print()" class="btn btn-print">
        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        Print Report
    </button>
</div>

<div class="report-wrapper">
    <div class="report-container" id="report-content">
        
        <header class="report-header">
            <div class="header-logo">
                <img src="<?php echo $logoPath; ?>" alt="Logo">
            </div>
            <div class="header-details">
                <h1>Mr. and Mrs. Print Main</h1>
                <p><?php echo htmlspecialchars($branchAddress); ?></p>
                <p>Contact: 0921 212 2293 | Email: mrandmrsprints@gmail.com</p>
                <p>Facebook: Mr. and Mrs.Print Main</p>
            </div>
        </header>

        <div class="report-title">Order Summary Details</div>
        <div class="meta-info">
            <strong>Branch:</strong> <?php echo htmlspecialchars($branchName); ?><br>
            <strong>Session Date:</strong> <?php echo $sessionDate; ?><br>
            <strong>Staff Account:</strong> <?php echo htmlspecialchars($staffName); ?><br>
            <strong>Contact Info:</strong> <?php echo htmlspecialchars($staffContact); ?> | <?php echo htmlspecialchars($staffEmail); ?>
        </div>

        <div class="summary-grid">
            <div class="summary-item summary-label">TOTAL ORDERS</div>
            <div class="summary-item summary-value"><?php echo count($orders); ?></div>
            <div class="summary-item summary-label">TOTAL GROSS SALES</div>
            <div class="summary-item summary-total">₱ <?php echo number_format($grandTotal, 2); ?></div>
        </div>

        <table class="orders-table">
            <thead>
                <tr>
                    <th style="width: 10%;">Order #</th>
                    <th style="width: 25%;">Customer Name</th>
                    <th style="width: 20%;">Service Type</th>
                    <th style="width: 15%;">Status</th>
                    <th style="width: 15%;">Date Created</th>
                    <th style="width: 15%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6" class="text-center">No orders found for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td class="text-center"><?php echo (int)$o['order_id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$o['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars((string)$o['service_type']); ?></td>
                            <td class="text-center"><?php echo htmlspecialchars((string)$o['status']); ?></td>
                            <td class="text-center"><?php echo date('Y-m-d', strtotime((string)$o['order_date'])); ?></td>
                            <td class="text-right">₱ <?php echo number_format((float)$o['total_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <footer class="report-footer">
            <div class="footer-left">
                <p>Generated by <strong>PrintFlow System</strong></p>
                <p><?php echo htmlspecialchars($branchName); ?> | <?php echo htmlspecialchars($branchAddress); ?></p>
                <p>Prepared By: <?php echo htmlspecialchars($staffName); ?></p>
            </div>
            <div class="footer-right">
                Generated On: <?php echo $generatedAt; ?><br>
                <span style="font-size: 14px; margin-top: 10px; display: inline-block;">PF-REP-SUMMARY-01-Rev00</span>
            </div>
        </footer>

    </div>
</div>



</body>
</html>
<?php exit; ?>






