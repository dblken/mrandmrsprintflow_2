<?php
/**
 * Staff - Service Orders List
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role('Staff');
require_once __DIR__ . '/../includes/staff_pending_check.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/printflow');
}

service_order_ensure_tables();
$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);

$filter = $_GET['status'] ?? '';
$branchWhere = '(so.branch_id = ? OR so.branch_id IS NULL)';
$serviceArchiveSql = ' AND ' . printflow_customer_exclusion_customer_scope_sql('so.customer_id');
$sql = "SELECT so.*, c.first_name, c.last_name, c.email, c.contact_number 
        FROM service_orders so 
        LEFT JOIN customers c ON so.customer_id = c.customer_id 
        WHERE {$branchWhere}{$serviceArchiveSql}
        ORDER BY so.created_at DESC";
$params = [$staffBranchId];
$types = 'i';
if ($filter && in_array($filter, ['Pending', 'Pending Review', 'Approved', 'Processing', 'Completed', 'Rejected'], true)) {
    $sql = "SELECT so.*, c.first_name, c.last_name, c.email, c.contact_number 
            FROM service_orders so 
            LEFT JOIN customers c ON so.customer_id = c.customer_id 
            WHERE {$branchWhere} AND so.status = ?{$serviceArchiveSql}
            ORDER BY so.created_at DESC";
    $params = [$staffBranchId, $filter];
    $types = 'is';
}

$orders = db_query($sql, $types, $params);

$page_title = 'Service Orders - Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-fulfilled { background: #dcfce7; color: #15803d; }
        .badge-confirmed { background: #e0f2fe; color: #0369a1; }
        .badge-partial { background: #fef3c7; color: #a16207; }
        .badge-cancelled { background: #fee2e2; color: #b91c1c; }
        /* Identical to staff/customizations.php (.modal-overlay / .modal-panel) */
        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:560px; max-height:88vh; overflow-y:auto; margin:16px; position:relative; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-action.indigo { color: #4f46e5; border-color: #4f46e5; }
        .btn-action.indigo:hover { background: #4f46e5; color: #fff; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: #fff; }
    </style>
</head>
<body data-base-url="<?php echo htmlspecialchars(BASE_URL); ?>" data-csrf="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
<div class="dashboard-container" x-data="serviceOrderList()" x-init="initSvcUrlOpen()" @keydown.escape.window="onSvcEscape()">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>
    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title">Service Orders</h1>
                <p class="page-subtitle">Manage custom service requests and specialized print jobs</p>
            </div>
        </header>

        <main>
            <?php
            // Calculate KPIs for service orders
            $total_count = db_query("SELECT COUNT(*) as count FROM service_orders so WHERE {$branchWhere}{$serviceArchiveSql}", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $pending_count = db_query("SELECT COUNT(*) as count FROM service_orders so WHERE {$branchWhere} AND status = 'Pending'{$serviceArchiveSql}", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $processing_count = db_query("SELECT COUNT(*) as count FROM service_orders so WHERE {$branchWhere} AND status = 'Processing'{$serviceArchiveSql}", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $completed_count = db_query("SELECT COUNT(*) as count FROM service_orders so WHERE {$branchWhere} AND status = 'Completed'{$serviceArchiveSql}", 'i', [$staffBranchId])[0]['count'] ?? 0;
            ?>

            <!-- Standardized KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <span class="kpi-label">Total Requests</span>
                    <span class="kpi-value"><?php echo number_format($total_count); ?></span>
                    <span class="kpi-sub"><?php echo $completed_count; ?> completed</span>
                </div>
                <div class="kpi-card amber">
                    <span class="kpi-label">Pending Review</span>
                    <span class="kpi-value"><?php echo $pending_count; ?></span>
                    <span class="kpi-sub">New service requests</span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-label">Active Jobs</span>
                    <span class="kpi-value"><?php echo $processing_count; ?></span>
                    <span class="kpi-sub">Currently in progress</span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-label">Fulfillment Rate</span>
                    <span class="kpi-value" style="font-size:18px; line-height:36px;"><?php echo round(($completed_count / max(1, $total_count)) * 100); ?>%</span>
                    <span class="kpi-sub">Completed request ratio</span>
                </div>
            </div>

            <!-- Standardized Toolbar -->
            <div class="card overflow-visible" style="margin-bottom: 24px;">
                <div class="toolbar-container">
                    <h3 style="font-size:16px; font-weight:700; color:#1f2937; margin:0;">Request list</h3>
                    <div class="toolbar-group">
                        <a href="service_orders.php" class="toolbar-btn <?php echo !$filter ? 'active' : ''; ?>">All</a>
                        <a href="service_orders.php?status=Pending" class="toolbar-btn <?php echo $filter === 'Pending' ? 'active' : ''; ?>">Pending</a>
                        <a href="service_orders.php?status=Processing" class="toolbar-btn <?php echo $filter === 'Processing' ? 'active' : ''; ?>">Processing</a>
                        <a href="service_orders.php?status=Completed" class="toolbar-btn <?php echo $filter === 'Completed' ? 'active' : ''; ?>">Completed</a>
                    </div>
                </div>
            </div>

            <div class="card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 bg-gray-50">
                            <th class="text-left py-3 px-4">ID</th>
                            <th class="text-left py-3 px-4">Service</th>
                            <th class="text-left py-3 px-4">Customer</th>
                            <th class="text-left py-3 px-4">Status</th>
                            <th class="text-left py-3 px-4">Date</th>
                            <th class="text-right py-3 px-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr><td colspan="6" class="py-8 text-center text-gray-500">No service orders found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4 font-mono">#<?php echo $o['id']; ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars($o['service_name']); ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')); ?></td>
                            <td class="py-3 px-4"><?php echo status_badge($o['status'], 'order'); ?></td>
                            <td class="py-3 px-4"><?php echo format_datetime($o['created_at']); ?></td>
                            <td class="py-3 px-4 text-right">
                                <button type="button" class="text-indigo-600 hover:underline bg-transparent border-none cursor-pointer text-sm font-inherit" @click="openSvcModal(<?php echo (int)$o['id']; ?>)">View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <?php include __DIR__ . '/partials/service_order_modal.php'; ?>
</div>
<script src="<?php echo htmlspecialchars(BASE_URL . '/public/assets/js/staff_service_order_modal.js'); ?>"></script>
<script>
function serviceOrderList() {
    return Object.assign(
        {},
        printflowStaffServiceOrderModalMixin({
            afterSvcMutation: function () { location.reload(); }
        }),
        {
            initSvcUrlOpen: function () {
                var p = new URLSearchParams(location.search);
                var oid = p.get('open_id');
                if (!oid) return;
                var id = parseInt(oid, 10);
                if (!id) return;
                this.openSvcModal(id);
                p.delete('open_id');
                var q = p.toString();
                window.history.replaceState({}, '', location.pathname + (q ? '?' + q : '') + location.hash);
            }
        }
    );
}
</script>
</body>
</html>
