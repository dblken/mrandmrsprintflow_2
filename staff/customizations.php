<?php
/**
 * Staff: Customizations Management
 * Production tracking & material assignment.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : ''));
}
require_role(['Admin', 'Staff', 'Manager']);
printflow_require_staff_module('customizations');
if (in_array($_SESSION['user_type'] ?? '', ['Staff', 'Manager'], true)) {
    require_once __DIR__ . '/../includes/staff_pending_check.php';
}

$deepLinkOrderId = (int)($_GET['order_id'] ?? 0);
$deepLinkJobType = strtoupper(trim((string)($_GET['job_type'] ?? '')));
if ($deepLinkOrderId > 0 && !in_array($deepLinkJobType, ['JOB', 'CUSTOMIZATION'], true)) {
    $deepLinkOrder = db_query(
        "SELECT order_type FROM orders WHERE order_id = ? LIMIT 1",
        'i',
        [$deepLinkOrderId]
    );
    $deepLinkOrderType = strtolower(trim((string)($deepLinkOrder[0]['order_type'] ?? '')));
    if ($deepLinkOrderType === 'product') {
        // Check if it's a service (needs customization/production tracking)
        $preview = printflow_order_notification_preview($deepLinkOrderId);
        if (strtolower(trim((string)($preview['item_kind'] ?? ''))) !== 'service') {
            // Strictly a product order with no service components — redirect to orders.php
            redirect((defined('BASE_PATH') ? BASE_PATH : '') . '/staff/orders.php?order_id=' . $deepLinkOrderId);
        }
    }

    // For custom/service orders: try to find a linked job_orders row for a cleaner display
    $linkedJob = db_query(
        "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1",
        'i',
        [$deepLinkOrderId]
    );
    $linkedJobId = (int)($linkedJob[0]['id'] ?? 0);
    if ($linkedJobId > 0) {
        // Redirect to the specific job order view
        redirect((defined('BASE_PATH') ? BASE_PATH : '') . '/staff/customizations.php?order_id=' . $linkedJobId . '&job_type=JOB');
    }
    // Custom order exists but no job_orders row yet (e.g. ordered via order_review.php).
    // Stay on customizations.php — the page will show the order from the orders table.
    // Do NOT redirect to orders.php; that page only shows product orders.
    // Fall through to render customizations.php normally with the order_id context.
    unset($deepLinkOrderId, $deepLinkJobType, $deepLinkOrder, $deepLinkOrderType, $linkedJob, $linkedJobId);
}

$page_title = 'Customizations - PrintFlow';
$showLatestCustomizationOnly = false;
$staffCustomizationRole = ($_SESSION['user_type'] ?? '') === 'Staff'
    ? printflow_get_staff_access_role()
    : null;
$isPosCustomizationView = $staffCustomizationRole === 'pos';

$branchFilter = printflow_branch_filter_for_user();
$joBranchSql = '';
$joBranchTypes = '';
$joBranchParams = [];
$ordBranchSql = '';
$ordBranchTypes = '';
$ordBranchParams = [];
if ($branchFilter !== null) {
    $b = (int) $branchFilter;
    $joBranchSql = ' AND COALESCE(jo.branch_id, (SELECT o2.branch_id FROM orders o2 WHERE o2.order_id = jo.order_id LIMIT 1)) = ?';
    $joBranchTypes = 'i';
    $joBranchParams = [$b];
    $ordBranchSql = ' AND branch_id = ?';
    $ordBranchTypes = 'i';
    $ordBranchParams = [$b];
}

// Get statistics for KPIs from job/customization work only.
$jobCustomizationScopeSql = " AND (
    jo.order_id IS NULL
    OR EXISTS (
        SELECT 1
        FROM orders o_scope
        JOIN order_items oi_scope ON oi_scope.order_id = o_scope.order_id
        LEFT JOIN products p_scope ON p_scope.product_id = oi_scope.product_id
        WHERE o_scope.order_id = jo.order_id
          AND o_scope.order_type IN ('custom', 'product')
          AND COALESCE(LOWER(TRIM(p_scope.product_type)), 'custom') <> 'fixed'
    )
)";

$total_jobs_jobs = db_query(
    "SELECT COUNT(*) as count FROM job_orders jo WHERE 1=1" . $jobCustomizationScopeSql . $joBranchSql,
    $joBranchTypes ?: null,
    $joBranchParams ?: null
)[0]['count'];
$total_jobs = $total_jobs_jobs;

$pending_jobs_jobs = db_query(
    "SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'PENDING'" . $jobCustomizationScopeSql . $joBranchSql,
    $joBranchTypes ?: null,
    $joBranchParams ?: null
)[0]['count'];
$pending_jobs = $pending_jobs_jobs;

$approval_jobs = db_query(
    "SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'APPROVED'" . $jobCustomizationScopeSql . $joBranchSql,
    $joBranchTypes ?: null,
    $joBranchParams ?: null
)[0]['count'];
$pending_approved_jobs = $pending_jobs + $approval_jobs;

$in_production_jobs = db_query(
    "SELECT COUNT(*) as count FROM job_orders jo WHERE status IN ('IN_PRODUCTION','PROCESSING','PRINTING')" . $jobCustomizationScopeSql . $joBranchSql,
    $joBranchTypes ?: null,
    $joBranchParams ?: null
)[0]['count'];
$in_production = $in_production_jobs;

$completed_jobs_jobs = db_query(
    "SELECT COUNT(*) as count FROM job_orders jo WHERE status = 'COMPLETED'" . $jobCustomizationScopeSql . $joBranchSql,
    $joBranchTypes ?: null,
    $joBranchParams ?: null
)[0]['count'];
$completed_jobs = $completed_jobs_jobs;

$preloaded_customization_rows = [];

$job_rows = db_query(
    "SELECT jo.id,
            jo.order_id,
            jo.job_title,
            jo.service_type,
            jo.width_ft,
            jo.height_ft,
            jo.quantity,
            jo.status,
            jo.created_at,
            jo.updated_at,
            jo.due_date,
            c.first_name,
            c.last_name,
            c.customer_type,
            c.transaction_count,
            c.profile_picture AS customer_profile_picture,
            COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) AS customer_contact,
            CASE
                WHEN LOWER(TRIM(COALESCE(ord.order_source, ''))) IN ('pos', 'walk-in') THEN LOWER(TRIM(ord.order_source))
                WHEN EXISTS (
                    SELECT 1
                    FROM customizations cpos
                    WHERE cpos.order_id = jo.order_id
                      AND cpos.customization_details LIKE '%\"source\":\"POS\"%'
                    LIMIT 1
                ) THEN 'pos'
                ELSE COALESCE(ord.order_source, 'customer')
            END AS order_source
     FROM job_orders jo
     LEFT JOIN customers c ON jo.customer_id = c.customer_id
     LEFT JOIN orders ord ON ord.order_id = jo.order_id
     WHERE (
        jo.order_id IS NULL
        OR EXISTS (
            SELECT 1
            FROM orders o_scope
            JOIN order_items oi_scope ON oi_scope.order_id = o_scope.order_id
            LEFT JOIN products p_scope ON p_scope.product_id = oi_scope.product_id
            WHERE o_scope.order_id = jo.order_id
              AND o_scope.order_type IN ('custom', 'product')
              AND COALESCE(LOWER(TRIM(p_scope.product_type)), 'custom') <> 'fixed'
        )
     )" . $joBranchSql . "
     ORDER BY jo.created_at DESC
     LIMIT 200",
    $joBranchTypes ?: null,
    $joBranchParams ?: null
) ?: [];

foreach ($job_rows as $row) {
    $resolvedSource = strtolower(trim((string)($row['order_source'] ?? 'customer')));
    $isPosSource = in_array($resolvedSource, ['pos', 'walk-in'], true);
    if ($staffCustomizationRole === 'pos' && !$isPosSource) {
        continue;
    }
    if ($staffCustomizationRole === 'online' && $isPosSource) {
        continue;
    }
    if (!empty($row['order_id'])) {
        $payload = JobOrderService::getStoreOrderItemsPayload((int)$row['order_id'], true, true);
        $serviceItems = array_values($payload['items'] ?? []);
        if (empty($serviceItems)) {
            continue;
        }
        $payload['items'] = $serviceItems;
        JobOrderService::enrichStaffJobRowFromStorePayload($row, $payload);
        $row['order_code'] = printflow_get_order_inventory_reference((int)$row['order_id'])['code'] ?? '';
    } else {
        $row['order_code'] = printflow_get_job_inventory_reference((int)($row['id'] ?? 0))['code'] ?? '';
    }
    $row['order_type'] = 'JOB';
    $preloaded_customization_rows[] = $row;
}

if (!function_exists('printflow_pos_customization_status_bucket')) {
    function printflow_pos_customization_status_bucket(string $status): string {
        $normalized = strtoupper(trim($status));
        if (in_array($normalized, ['COMPLETED'], true)) {
            return 'COMPLETED';
        }
        if (in_array($normalized, ['CANCELLED', 'REJECTED'], true)) {
            return 'CANCELLED';
        }
        return 'PENDING';
    }
}

$pos_pending_count = 0;
$pos_completed_count = 0;
$pos_cancelled_count = 0;
if ($isPosCustomizationView) {
    foreach ($preloaded_customization_rows as $row) {
        $bucket = printflow_pos_customization_status_bucket((string)($row['status'] ?? 'PENDING'));
        if ($bucket === 'COMPLETED') {
            $pos_completed_count++;
        } elseif ($bucket === 'CANCELLED') {
            $pos_cancelled_count++;
        } else {
            $pos_pending_count++;
        }
    }
}

if ($showLatestCustomizationOnly) {
    usort($preloaded_customization_rows, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    $preloaded_customization_rows = array_slice($preloaded_customization_rows, 0, 1);

    $latestRow = $preloaded_customization_rows[0] ?? null;
    $latestStatus = strtoupper(str_replace(' ', '_', (string)($latestRow['status'] ?? '')));
    $total_jobs = $latestRow ? 1 : 0;
    $pending_jobs = ($latestStatus === 'PENDING') ? 1 : 0;
    $approval_jobs = ($latestStatus === 'APPROVED') ? 1 : 0;
    $in_production = in_array($latestStatus, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING'], true) ? 1 : 0;
    $completed_jobs = ($latestStatus === 'COMPLETED') ? 1 : 0;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-visit-control" content="reload">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>



        .toolbar-container {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 12px;
            overflow: visible;
            position: relative;
            z-index: 25;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .toolbar-group--tabs {
            flex: 1 1 100%;
            min-width: 0;
            overflow: visible;
            margin-top: 8px;
        }

        .toolbar-group--actions {
            flex: 0 0 auto;
            margin-left: auto;
            justify-content: flex-end;
            position: relative;
            z-index: 30;
            overflow: visible;
            flex-wrap: nowrap;
        }

        .toolbar-group--actions .dropdown-panel {
            z-index: 1200;
        }
        .toolbar-group--title {
            flex: 0 0 auto;
        }
        .pf-entry-btn {
            height: 36px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
            border: 1px solid transparent;
            cursor: pointer;
            background: transparent;
            white-space: nowrap;
        }
        .pf-entry-in {
            border-color: #10b981;
            color: #10b981;
        }
        .pf-entry-in:hover {
            background: #10b981;
            color: #fff;
        }
        .pf-entry-out {
            border-color: #ef4444;
            color: #ef4444;
        }
        .pf-entry-out:hover {
            background: #ef4444;
            color: #fff;
        }
        .pf-staff-customizations-root .kpi-row {
            gap: 16px;
            margin-bottom: 24px;
        }

        .pf-staff-customizations-root .pf-customizations-table-card {
            margin-top: 8px;
        }

        .pf-custom-tabs {
            display: flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 8px;
            flex: 1 1 auto;
            min-width: 0;
            overflow-x: auto;
            overflow-y: visible;
            scrollbar-width: none;
            -ms-overflow-style: none;
            margin: 8px 0 12px;
            padding-top: 6px;
            padding-bottom: 4px;
        }

        .pf-custom-tabs::-webkit-scrollbar {
            display: none;
        }

        .filter-select {
            height: 36px;
            padding: 0 32px 0 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            background-color: white;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 16px;
            appearance: none;
            cursor: pointer;
            outline: none;
            transition: all 0.2s;
        }
        .filter-select:focus { border-color: #06A1A1; ring: 2px; ring-color: #06A1A1; }

        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .toolbar-btn:hover {
            border-color: #9ca3af;
            background: #f9fafb;
        }
        .toolbar-btn.active {
            border-color: #0d9488;
            color: #0d9488;
            background: #f0fdfa;
        }
        .toolbar-btn svg {
            flex-shrink: 0;
            width: 16px;
            height: 16px;
        }
        .toolbar-btn-label {
            font-weight: 500;
        }
        .toolbar-btn-label-light {
            font-weight: 400;
        }

        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 220px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            padding: 8px;
            overflow: hidden;
            z-index: 1200;
        }

        .sort-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .sort-option:hover {
            background: #f9fafb;
            color: #111827;
        }
        .sort-option.active {
            background: #f0fdfa;
            color: #0d9488;
            font-weight: 600;
        }

        .filter-panel {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            overflow: hidden;
        }

        .filter-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }

        .pf-staff-customizations-root .filter-header {
            color: #475569 !important;
            font-size: 12px !important;
            font-weight: 600 !important;
        }

        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f8fafc;
        }

        .filter-label {
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }

        .filter-input {
            width: 100%;
            min-height: 38px;
            padding: 9px 12px;
            border: 1px solid #dbe3ec;
            border-radius: 10px;
            background: #fff;
            color: #334155;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .filter-input:focus,
        .filter-select:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
        }

        .filter-reset-link {
            border: 0;
            background: transparent;
            color: #0d9488;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
        }

        .filter-footer {
            padding: 14px 18px 18px;
            background: #fff;
        }

        .filter-btn-reset {
            min-height: 40px;
            padding: 0 14px;
            border: 1px solid #dbe3ec;
            border-radius: 10px;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .filter-btn-reset:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 9999px;
            background: #0d9488;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .source-badge-pill {
            min-width: 76px;
            text-align: center;
        }
        .source-badge-pill.online {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .source-badge-pill.pos {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-tab { 
            position: relative;
            padding: 8px 12px; 
            font-weight: 500; 
            font-size: 11px; 
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #3f5f5f; 
            border-radius: 9999px; 
            transition: all 0.2s; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px;
            background: #ffffff;
            border: 1px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .pill-tab > :first-child {
            display: inline-block;
            max-width: 110px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .pill-tab:hover { background: #eef8f6; color: #023d3d; border-color: rgba(6, 161, 161, 0.22); }
        .pill-tab.active { background: linear-gradient(135deg, #f7fefb 0%, #e5f9f2 42%, #d4f0e6 100%); color: #023d3d; border: 1px solid #06A1A1; box-shadow: 0 6px 18px rgba(6, 161, 161, 0.12); }
        .tab-count { 
            background: #06A1A1; 
            color: white; 
            font-size: 10px; 
            padding: 1px 6px; 
            border-radius: 9999px; 
            font-weight: 600;
        }
        .pill-tab:not(.active) .tab-count { background: #e7f3f0; color: #035f5f; }

        .pf-custom-tabs.pos-tabs {
            width: 100%;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }



        /* Unified Table Typography */
        .table-text-main {
            font-size: 13px;
            color: #111827;
            font-weight: 500;
            display: block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .table-text-sub {
            font-size: 11px;
            color: #6b7280;
            font-weight: 400;
            display: block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .pf-customizations-table-card .table-text-sub.uppercase.tracking-wider:not([x-text]):has(> span[x-text="jo.width_ft"]) {
            display: none !important;
        }
        .truncate-ellipsis {
            display: block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .status-col-cell {
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
        }
        .status-col-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            min-width: 0;
        }
        .status-col-inner > * {
            max-width: 100%;
        }
        .action-col-cell {
            text-align: center;
            white-space: nowrap;
        }
        .status-badge-pill {
            min-width: 100px;
            max-width: 100%;
        }
        .pf-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            max-width: 100%;
        }
        .order-code-cell,
        .customization-info-cell {
            min-width: 0;
            overflow: hidden;
        }
        .order-code-cell .table-text-main,
        .customization-info-cell .table-text-main,
        .customization-info-cell .table-text-sub {
            max-width: 100%;
        }
        .badge-pending { background:#fef9c3; color:#92400e; }
        .badge-approved { background:#dbeafe; color:#1e40af; }
        .badge-topay { background:#fef3c7; color:#b45309; }
        .badge-verify { background:#fef9c3; color:#92400e; }
        .badge-production { background:#d1fae5; color:#065f46; }
        .badge-pickup { background:#ede9fe; color:#5b21b6; }
        .badge-fulfilled { background:#dcfce7; color:#166534; }
        .badge-cancelled { background:#fee2e2; color:#991b1b; }
        
        thead th { 
            font-size: 11px; 
            font-weight: 600; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 2px solid #f3f4f6;
        }

        .row-indicator {
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 3px;
            background: linear-gradient(180deg, #035f5f 0%, #06A1A1 55%, #9ED7C4 100%);
            border-radius: 0 4px 4px 0;
            opacity: 0;
            transition: opacity 0.2s;
        }
        tr:hover .row-indicator { opacity: 1; }

        .pf-customizations-table-card tbody tr {
            transition: background-color 0.18s ease, box-shadow 0.18s ease;
        }
        .pf-customizations-table-card tbody tr:hover {
            background: linear-gradient(90deg, rgba(6, 161, 161, 0.06) 0%, rgba(158, 215, 196, 0.12) 100%) !important;
        }

        .table-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 5px 12px;
            border: 1px solid #06A1A1;
            border-radius: 6px;
            background: transparent;
            color: #06A1A1;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
        }
        .table-action-btn:hover {
            background: #06A1A1;
            color: #fff;
            border-color: #06A1A1;
        }

        .action-btn-group {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            white-space: nowrap;
            width: 100%;
        }

        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:560px; max-height:88vh; overflow-y:auto; margin:16px; position:relative; }
        .modal-wrap-text { max-width:100%; white-space:normal; word-break:break-word; overflow-wrap:anywhere; }
        .modal-header-copy { min-width:0; flex:1 1 auto; padding-right:12px; }
        .modal-item-title { line-height:1.35; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pf-tab-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }
        [x-cloak] { display: none !important; }

        @media (max-width: 1100px) {
            .toolbar-container {
                gap: 10px;
            }

            .pill-tab {
                padding: 7px 10px;
                font-size: 10px;
            }

            .toolbar-btn {
                min-height: 42px;
                padding: 0 16px;
            }
        }

        /* Mobile Fixes for Staff Customizations */
        @media (max-width: 768px) {
            .main-content header {
                padding: 16px 20px 12px !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 12px !important;
                margin-bottom: 4px !important;
            }

            #mobileBurger {
                position: static !important;
                margin-bottom: 8px !important;
                margin-left: -4px !important;
            }

            .page-title {
                font-size: 20px !important;
            }

            .page-subtitle {
                font-size: 12px !important;
            }

            .main-content main {
                padding: 0 16px 24px !important;
            }

            .kpi-row {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
                margin-bottom: 16px !important;
            }

            .kpi-card {
                padding: 14px !important;
                border-radius: 12px !important;
            }

            .kpi-label {
                font-size: 10px !important;
            }

            .kpi-value {
                font-size: 18px !important;
            }

            .kpi-sub {
                font-size: 9px !important;
            }

            .toolbar-container {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 14px !important;
                padding: 4px 0 !important;
            }

            .toolbar-group--actions {
                margin-left: 0 !important;
                justify-content: flex-start !important;
                gap: 8px !important;
                overflow-x: auto !important;
                padding-bottom: 4px !important;
                scrollbar-width: none;
            }
            .toolbar-group--actions::-webkit-scrollbar { display: none; }

            .toolbar-btn {
                padding: 6px 12px !important;
                font-size: 12px !important;
                height: 34px !important;
                min-height: 34px !important;
            }

            .pf-custom-tabs {
                display: flex !important;
                flex-wrap: nowrap !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                scrollbar-width: none !important;
                gap: 8px !important;
                margin: 4px 0 8px !important;
                padding-top: 2px !important;
                width: 100% !important;
            }
            .pf-custom-tabs::-webkit-scrollbar { display: none !important; }

            .toolbar-group--tabs {
                width: 100% !important;
                overflow: visible !important;
                display: block !important;
            }

            .pill-tab {
                padding: 6px 12px !important;
                font-size: 10px !important;
                flex-shrink: 0 !important;
                white-space: nowrap !important;
            }

            /* Fix horizontal overflow */
            .overflow-x-auto.-mx-6.px-6 {
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                overflow-x: visible !important;
            }

            .pf-customizations-table-card thead {
                display: none !important;
            }

            /* Modal Responsiveness */
            .modal-panel {
                margin: 10px !important;
                max-height: 94vh !important;
            }

            .modal-body {
                padding: 16px !important;
            }

            /* Improved Table Card Layout for Mobile */
            /* Stop horizontal scroll at table wrapper */
            .pf-staff-customizations-root .overflow-x-auto {
                padding: 0 !important;
                margin: 0 !important;
                overflow-x: hidden !important;
                max-width: 100vw !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            html .pf-staff-customizations-root table, 
            .pf-staff-customizations-root table { 
                display: block !important; 
                width: 100% !important; 
                max-width: 100% !important; 
                min-width: 0 !important; 
                overflow: hidden !important; 
                box-sizing: border-box !important; 
            }
            html .pf-staff-customizations-root thead, 
            .pf-staff-customizations-root thead { 
                display: none !important; 
            }
            html .pf-staff-customizations-root tbody, 
            .pf-staff-customizations-root tbody { 
                display: block !important; 
                width: 100% !important; 
                max-width: 100% !important; 
                min-width: 0 !important; 
                overflow: hidden !important; 
            }

            /* Each job row = a card */
            html .pf-staff-customizations-root tr, 
            .pf-staff-customizations-root tr { 
                display: flex !important; 
                flex-direction: column !important; 
                width: 100% !important; 
                max-width: 100% !important; 
                min-width: 0 !important; 
                box-sizing: border-box !important; 
                margin-bottom: 10px !important; 
                border: 1px solid #e2e8f0 !important; 
                border-radius: 10px !important; 
                background: #fff !important; 
                overflow: hidden !important; 
                padding: 0 !important; 
                gap: 0 !important; 
            }

            /* Every cell */
            html .pf-staff-customizations-root td, 
            .pf-staff-customizations-root td { 
                display: flex !important; 
                align-items: center !important; 
                justify-content: space-between !important;
                width: 100% !important; 
                max-width: 100% !important; 
                min-width: 0 !important; 
                box-sizing: border-box !important; 
                padding: 6px 12px !important; 
                border-bottom: 1px solid #f1f5f9 !important; 
                overflow: hidden !important; 
                font-size: 12px !important; 
                color: #374151 !important; 
            }
            html .pf-staff-customizations-root td:last-child, 
            .pf-staff-customizations-root td:last-child { 
                border-bottom: none !important; 
            }

            /* ── Row 0: Order Code ── */
            html .pf-staff-customizations-root td:nth-child(1), 
            .pf-staff-customizations-root td:nth-child(1) { 
                order: 0 !important; 
                background: #f8fafc !important; 
                padding: 8px 12px !important; 
                font-weight: 700 !important; 
                color: #1e293b !important; 
                gap: 6px !important; 
            }
            .pf-staff-customizations-root td:nth-child(1)::before { content: none !important; }

            /* ── Row 1: Info ── */
            html .pf-staff-customizations-root td:nth-child(2), 
            .pf-staff-customizations-root td:nth-child(2) { 
                order: 1 !important; 
                padding: 7px 12px !important; 
                overflow: hidden !important; 
                flex-direction: column !important;
                align-items: flex-start !important;
            }
            html .pf-staff-customizations-root td:nth-child(2)::before, 
            .pf-staff-customizations-root td:nth-child(2)::before { display: none !important; }
            html .pf-staff-customizations-root td:nth-child(2) .table-text-main, 
            .pf-staff-customizations-root td:nth-child(2) .table-text-main { 
                font-size: 12px !important; 
                font-weight: 600 !important; 
                color: #111827 !important; 
                white-space: nowrap !important; 
                overflow: hidden !important; 
                text-overflow: ellipsis !important; 
                max-width: 250px !important; 
                width: 100% !important; 
                display: block !important; 
            }
            html .pf-staff-customizations-root td:nth-child(2) .table-text-sub, 
            .pf-staff-customizations-root td:nth-child(2) .table-text-sub { 
                font-size: 10px !important; 
                white-space: nowrap !important; 
                overflow: hidden !important; 
                text-overflow: ellipsis !important; 
                max-width: 250px !important; 
                width: 100% !important; 
                display: block !important; 
            }

            /* ── Row 2: Customer ── */
            html .pf-staff-customizations-root td:nth-child(5), 
            .pf-staff-customizations-root td:nth-child(5) { 
                order: 2 !important; 
                overflow: hidden !important; 
            }
            html .pf-staff-customizations-root td:nth-child(5)::before, 
            .pf-staff-customizations-root td:nth-child(5)::before { 
                content: "Customer  " !important; font-size: 9px !important; 
                font-weight: 700 !important; text-transform: uppercase !important; 
                color: #94a3b8 !important; flex-shrink: 0 !important; 
                white-space: nowrap !important; margin-right: 4px !important; 
            }
            html .pf-staff-customizations-root td:nth-child(5) .table-text-main, 
            .pf-staff-customizations-root td:nth-child(5) .table-text-main { 
                white-space: nowrap !important; overflow: hidden !important; 
                text-overflow: ellipsis !important; max-width: 160px !important; 
                width: 100% !important; display: block !important; 
            }

            /* ── HIDE Source and Date/Created ── */
            html .pf-staff-customizations-root td:nth-child(4), 
            .pf-staff-customizations-root td:nth-child(4), 
            html .pf-staff-customizations-root td:nth-child(6), 
            .pf-staff-customizations-root td:nth-child(6) { display: none !important; }

            /* ── Row 4: Status ── */
            html .pf-staff-customizations-root td:nth-child(3), 
            .pf-staff-customizations-root td:nth-child(3) { 
                order: 4 !important; 
                justify-content: flex-start !important; 
                gap: 6px !important; 
                overflow: hidden !important; 
            }
            .pf-staff-customizations-root td:nth-child(3)::before { 
                content: "Status  " !important; font-size: 9px !important; 
                font-weight: 700 !important; text-transform: uppercase !important; 
                color: #94a3b8 !important; flex-shrink: 0 !important; 
                white-space: nowrap !important; margin-right: 4px !important; 
            }

            /* ── Row 5: Action buttons ── */
            html .pf-staff-customizations-root td:nth-child(7), 
            .pf-staff-customizations-root td:nth-child(7) { 
                order: 10 !important; 
                padding: 10px 12px !important; 
                border-top: 1px solid #e8eef3 !important; 
                border-bottom: none !important; 
                overflow: visible !important; 
                justify-content: stretch !important;
            }
            .pf-staff-customizations-root td:nth-child(7)::before { display: none !important; }
            html .pf-staff-customizations-root td:nth-child(7) .action-btn-group,
            .pf-staff-customizations-root td:nth-child(7) .action-btn-group {
                width: 100% !important;
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) minmax(0, 88px) !important;
                gap: 8px !important;
                align-items: center !important;
            }
            html .pf-staff-customizations-root td:nth-child(7) .table-action-btn, 
            .pf-staff-customizations-root td:nth-child(7) .table-action-btn { 
                display: flex !important; align-items: center !important; justify-content: center !important; 
                width: auto !important; max-width: none !important;
                flex: 1 1 0 !important;
                min-width: 0 !important; padding: 10px 8px !important; font-size: 12px !important; 
                font-weight: 700 !important; border-radius: 8px !important; white-space: nowrap !important; 
                overflow: hidden !important; text-overflow: ellipsis !important; min-height: 36px !important; 
                box-sizing: border-box !important; 
            }

            /* Button Wrap and spacing fix */
            .pf-entry-btn {
                white-space: normal !important;
                height: auto !important;
                min-height: 42px !important;
                padding: 8px 16px !important;
                line-height: 1.2 !important;
                text-align: center !important;
                justify-content: center !important;
                width: 100% !important;
            }

            .pf-entry-btn.pf-entry-in {
                margin-bottom: 8px !important;
            }

            /* Modal Footer Stacking */
            [style*="justify-content:space-between;align-items:center;gap:8px;"] {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
                padding: 20px !important;
            }

            [style*="flex-direction:column;align-items:flex-start;gap:8px;min-width:0;flex:1;"] {
                width: 100% !important;
            }

            [style*="display:flex;gap:8px; flex-wrap:wrap; align-items:center;"] {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 10px !important;
                width: 100% !important;
            }

            [style*="display:flex; gap:8px;"] {
                flex-direction: column !important;
                gap: 10px !important;
                width: 100% !important;
            }
        }
    </style>
</head>
<body data-base-url="<?php echo htmlspecialchars(BASE_URL); ?>" data-csrf="<?php echo htmlspecialchars(generate_csrf_token()); ?>" data-user-type="<?php echo htmlspecialchars($_SESSION['user_type'] ?? 'Staff'); ?>">
<div class="dashboard-container">
    <?php 
    if (in_array($_SESSION['user_type'] ?? '', ['Staff', 'Manager'])) {
        include __DIR__ . '/../includes/staff_sidebar.php';
    } else {
        include __DIR__ . '/../includes/admin_sidebar.php';
    }
    ?>
    <div class="main-content">
        <div id="staffJoCustomizationsPage" x-data="joManager('ALL')" x-init="init()" class="pf-staff-customizations-root" @keydown.escape.window="onSvcEscape()">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 class="page-title">Customizations</h1>
                <p class="page-subtitle">Track and manage all custom jobs</p>
            </div>
        </header>

        <main>
                <div class="kpi-row">
                <div class="kpi-card indigo">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Total Customizations</span>
                        <span class="kpi-value"><?php echo number_format($total_jobs); ?></span>
                        <span class="kpi-sub"><?php echo number_format($completed_jobs); ?> items finished</span>
                    </span>
                </div>
                <?php if ($isPosCustomizationView): ?>
                <div class="kpi-card amber">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Pending</span>
                        <span class="kpi-value"><?php echo number_format($pos_pending_count); ?></span>
                        <span class="kpi-sub">Active walk-in jobs</span>
                    </span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Completed</span>
                        <span class="kpi-value"><?php echo number_format($pos_completed_count); ?></span>
                        <span class="kpi-sub">Finished walk-in orders</span>
                    </span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Cancelled</span>
                        <span class="kpi-value"><?php echo number_format($pos_cancelled_count); ?></span>
                        <span class="kpi-sub">Stopped transactions</span>
                    </span>
                </div>
                <?php else: ?>
                <div class="kpi-card amber">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Pending Approval</span>
                        <span class="kpi-value"><?php echo number_format($pending_jobs); ?></span>
                        <span class="kpi-sub">Awaiting review</span>
                    </span>
                </div>
                <div class="kpi-card blue">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">Approved</span>
                        <span class="kpi-value"><?php echo number_format($approval_jobs); ?></span>
                        <span class="kpi-sub">Ready for production</span>
                    </span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-card-inner">
                        <span class="kpi-label">In Production</span>
                        <span class="kpi-value"><?php echo number_format($in_production); ?></span>
                        <span class="kpi-sub">Active task tracks</span>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Jobs List & Filters (matching Enterprise reference) -->
            <div class="card overflow-visible pf-customizations-table-card">
                <div class="toolbar-container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <div class="toolbar-group toolbar-group--title">
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;white-space:nowrap;">Customization List</h3>
                    </div>
                    <div class="toolbar-group toolbar-group--actions" style="margin-left: auto; display: flex; gap: 8px;">
    
                        <!-- Sort Menu -->
                        <div style="position: relative;">
                            <button @click="sortOpen = !sortOpen; filterOpen = false" class="toolbar-btn" :class="sortOrder !== 'newest' ? 'active' : ''">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                                <span class="toolbar-btn-label">Sort by</span>
                            </button>
                            <div x-show="sortOpen" @click.away="sortOpen = false" x-cloak class="dropdown-panel sort-dropdown" style="right: 0;">
                                <div class="sort-option" :class="sortOrder === 'newest' ? 'active' : ''" @click="sortOrder = 'newest'; sortOpen = false">
                                    <span>Newest to Oldest</span>
                                    <svg x-show="sortOrder === 'newest'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="sortOrder === 'oldest' ? 'active' : ''" @click="sortOrder = 'oldest'; sortOpen = false">
                                    <span>Oldest to Newest</span>
                                    <svg x-show="sortOrder === 'oldest'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="sortOrder === 'az' ? 'active' : ''" @click="sortOrder = 'az'; sortOpen = false">
                                    <span>A to Z</span>
                                    <svg x-show="sortOrder === 'az'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="sortOrder === 'za' ? 'active' : ''" @click="sortOrder = 'za'; sortOpen = false">
                                    <span>Z to A</span>
                                    <svg x-show="sortOrder === 'za'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div x-show="verificationStockBlockMessage()" style="padding:12px 14px; border-radius:10px; border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; font-size:12px; font-weight:700; line-height:1.45;" x-text="verificationStockBlockMessage()"></div>
                            </div>
                        </div>

                        <!-- Filter Menu -->
                        <div style="position: relative;">
                            <button @click="filterOpen = !filterOpen; sortOpen = false" class="toolbar-btn" :class="(serviceFilter !== 'ALL' || dateFilter !== 'ALL') ? 'active' : ''">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                                <span class="toolbar-btn-label-light">Filter</span>
                                <template x-if="serviceFilter !== 'ALL' || dateFilter !== 'ALL'">
                                    <span class="filter-badge" x-text="(serviceFilter !== 'ALL' ? 1 : 0) + (dateFilter !== 'ALL' ? 1 : 0)"></span>
                                </template>
                            </button>
                            <div x-show="filterOpen" @click.away="filterOpen = false" x-cloak class="dropdown-panel filter-panel" style="right: 0;">
                                <div class="filter-header">Filter</div>
                                
                                <div class="filter-section">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span class="filter-label" style="margin:0;">Service Type</span>
                                        <button @click="serviceFilter = 'ALL'" class="filter-reset-link">Reset</button>
                                    </div>
                                    <select x-model="serviceFilter" class="filter-select">
                                        <option value="ALL">All Services</option>
                                        <option value="T-SHIRT PRINTING">T-Shirt Printing</option>
                                        <option value="TARPAULIN PRINTING">Tarpaulin</option>
                                        <option value="DECALS/STICKERS (PRINT/CUT)">Stickers/Decals</option>
                                        <option value="TRANSPARENT STICKER PRINTING">Transparent Stickers</option>
                                        <option value="SINTRA BOARD">Sintraboard</option>
                                        <option value="REFLECTORIZED SIGNAGE">Reflectorized</option>
                                        <option value="SOUVENIRS">Souvenirs</option>
                                    </select>
                                </div>

                                <div class="filter-section">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span class="filter-label" style="margin:0;">Date range</span>
                                        <button @click="dateFilter = 'ALL'; customDateFrom = ''; customDateTo = ''" class="filter-reset-link">Reset</button>
                                    </div>
                                    <select x-model="dateFilter" class="filter-select" style="margin-bottom:8px;">
                                        <option value="ALL">All Dates</option>
                                        <option value="TODAY">Today</option>
                                        <option value="WEEK">This Week</option>
                                        <option value="MONTH">This Month</option>
                                        <option value="CUSTOM">Custom Range</option>
                                    </select>
                                    <div x-show="dateFilter === 'CUSTOM'" style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                                        <div>
                                            <div style="font-size:11px; color:#6b7280; margin-bottom:4px;">From:</div>
                                            <input type="date" x-model="customDateFrom" class="filter-input">
                                        </div>
                                        <div>
                                            <div style="font-size:11px; color:#6b7280; margin-bottom:4px;">To:</div>
                                            <input type="date" x-model="customDateTo" class="filter-input">
                                        </div>
                                    </div>
                                </div>

                                <div class="filter-section">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span class="filter-label" style="margin:0;">Keyword search</span>
                                        <button @click="search = ''" class="filter-reset-link">Reset</button>
                                    </div>
                                    <input type="text" x-model="search" class="filter-input" placeholder="Search...">
                                </div>

                                <div class="filter-footer">
                                    <button @click="serviceFilter = 'ALL'; dateFilter = 'ALL'; customDateFrom = ''; customDateTo = ''; search = '';" class="filter-btn-reset" style="width:100%;">
                                        Reset all filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="toolbar-group toolbar-group--tabs">
                    <div class="pf-custom-tabs<?php echo $isPosCustomizationView ? ' pos-tabs' : ''; ?>">
                        <button type="button" @click="activeStatus = 'ALL'" :class="activeStatus === 'ALL' ? 'active' : ''" class="pill-tab">
                            <span>ALL</span>
                            <span class="tab-count" x-text="getStatusCount('ALL')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'PENDING'" :class="activeStatus === 'PENDING' ? 'active' : ''" class="pill-tab">
                            <span>PENDING</span>
                            <span class="tab-count" x-text="getStatusCount('PENDING')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'COMPLETED'" :class="activeStatus === 'COMPLETED' ? 'active' : ''" class="pill-tab">
                            <span>COMPLETED</span>
                            <span class="tab-count" x-text="getStatusCount('COMPLETED')"></span>
                        </button>
                        <?php if (!$isPosCustomizationView): ?>
                        <button type="button" @click="activeStatus = 'APPROVED'" :class="activeStatus === 'APPROVED' ? 'active' : ''" class="pill-tab">
                            <span>APPROVED</span>
                            <span class="tab-count" x-text="getStatusCount('APPROVED')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'TO_PAY'" :class="activeStatus === 'TO_PAY' ? 'active' : ''" class="pill-tab">
                            <span>TO PAY</span>
                            <span class="tab-count" x-text="getStatusCount('TO_PAY')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'TO_VERIFY'" :class="activeStatus === 'TO_VERIFY' ? 'active' : ''" class="pill-tab">
                            <span>TO VERIFY</span>
                            <span class="tab-count" x-text="getStatusCount('TO_VERIFY')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'IN_PRODUCTION'" :class="activeStatus === 'IN_PRODUCTION' ? 'active' : ''" class="pill-tab">
                            <span>IN PRODUCTION</span>
                            <span class="tab-count" x-text="getStatusCount('IN_PRODUCTION')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'TO_RECEIVE'" :class="activeStatus === 'TO_RECEIVE' ? 'active' : ''" class="pill-tab">
                            <span>TO PICKUP</span>
                            <span class="tab-count" x-text="getStatusCount('TO_RECEIVE')"></span>
                        </button>
                        <button type="button" @click="activeStatus = 'REJECTED'" :class="activeStatus === 'REJECTED' ? 'active' : ''" class="pill-tab">
                            <span>REJECTED</span>
                            <span class="tab-count" x-text="getStatusCount('REJECTED')"></span>
                        </button>
                        <?php endif; ?>
                        <button type="button" @click="activeStatus = 'CANCELLED'" :class="activeStatus === 'CANCELLED' ? 'active' : ''" class="pill-tab">
                            <span>CANCELLED</span>
                            <span class="tab-count" x-text="getStatusCount('CANCELLED')"></span>
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto -mx-6 px-6" style="clear:both;">
                    <table class="w-full text-sm text-left border-separate border-spacing-0" style="table-layout:fixed;">
                        <thead class="bg-gray-50/50">
                            <tr>
                                <th class="pl-6 pr-4 py-4 <?php echo $isPosCustomizationView ? 'w-[11%]' : 'w-[12%]'; ?> border-b border-gray-100">Order Code</th>
                                <th class="px-4 py-4 <?php echo $isPosCustomizationView ? 'w-[25%]' : 'w-[28%]'; ?> border-b border-gray-100">Customization Info</th>
                                <th class="px-4 py-4 <?php echo $isPosCustomizationView ? 'w-[14%]' : 'w-[18%]'; ?> border-b border-gray-100 text-center">Status</th>
                                <th class="px-4 py-4 w-[8%] border-b border-gray-100 text-center">Source</th>
                                <th class="px-4 py-4 <?php echo $isPosCustomizationView ? 'w-[12%]' : 'w-[14%]'; ?> border-b border-gray-100">Customer</th>
                                <th class="px-4 py-4 <?php echo $isPosCustomizationView ? 'w-[10%]' : 'w-[10%]'; ?> border-b border-gray-100 text-right">Created</th>
                                <th class="px-4 py-4 <?php echo $isPosCustomizationView ? 'w-[20%]' : 'w-[10%]'; ?> border-b border-gray-100 text-center uppercase tracking-widest text-[10px]">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="jo in paginatedOrders" :key="(jo.order_type || 'JOB') + '-' + jo.id">
                                <tr @click="viewDetails(jo.id, jo.order_type || 'JOB')" class="group transition-all relative cursor-pointer">
                                    <td class="pl-6 pr-4 py-4 relative order-code-cell">
                                        <div class="row-indicator"></div>
                                        <span class="table-text-main truncate-ellipsis" :title="getDisplayOrderCode(jo)" x-text="getDisplayOrderCode(jo)"></span>
                                    </td>
                                    <td class="px-4 py-4 customization-info-cell">
                                        <div class="flex items-center gap-3">
                                            <div class="flex flex-col gap-0 min-w-0">
                                                <div class="table-text-main truncate-ellipsis" :title="getRowDisplayName(jo)" x-text="getRowDisplayName(jo)"></div>
                                                <div class="table-text-sub uppercase tracking-wider truncate-ellipsis" x-show="jo.order_type !== 'SERVICE'"><span x-text="jo.width_ft"></span>'×<span x-text="jo.height_ft"></span>' • <span x-text="jo.quantity"></span> pcs</div>
                                                <div class="table-text-sub uppercase tracking-wider truncate-ellipsis" x-show="false && jo.order_type !== 'SERVICE'"><span x-text="jo.width_ft"></span>'Ã—<span x-text="jo.height_ft"></span>' â€¢ <span x-text="jo.quantity"></span> pcs</div>
                                                <div class="table-text-sub uppercase tracking-wider truncate-ellipsis" x-show="jo.order_type !== 'SERVICE'" x-text="formatCustomizationInfo(jo)"></div>
                                                <div class="table-text-sub uppercase tracking-wider truncate-ellipsis" x-show="jo.order_type === 'SERVICE'">Service purchase</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 status-col-cell">
                                        <div class="status-col-inner">
                                        <div :class="getStatusBadgeClass(jo)" class="pf-pill status-badge-pill" x-text="getStatusLabel(jo)">
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <template x-if="['pos','walk-in'].includes((jo.order_source || '').toLowerCase())">
                                            <span class="pf-pill source-badge-pill pos">Pos</span>
                                        </template>
                                        <template x-if="!['pos','walk-in'].includes((jo.order_source || '').toLowerCase())">
                                            <span class="pf-pill source-badge-pill online">Online</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="table-text-main truncate-ellipsis" :title="(jo.first_name + ' ' + (jo.last_name || '')).trim()" x-text="jo.first_name + ' ' + (jo.last_name || '')"></div>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <div class="table-text-main truncate-ellipsis" :title="jo.created_at ? new Date(jo.created_at).toLocaleDateString(undefined, {month:'long', day:'numeric', year:'numeric'}) : ''" x-text="jo.created_at ? new Date(jo.created_at).toLocaleDateString(undefined, {month:'long', day:'numeric', year:'numeric'}) : ''"></div>
                                        <div class="table-text-sub uppercase truncate-ellipsis" :title="jo.due_date ? 'Due ' + new Date(jo.due_date).toLocaleDateString() : ''" x-text="jo.due_date ? 'Due ' + new Date(jo.due_date).toLocaleDateString() : ''"></div>
                                    </td>
                                    <td class="px-4 py-4 action-col-cell">
                                        <div class="action-btn-group">
                                            <button @click.stop="viewDetails(jo.id, jo.order_type || 'JOB')" class="table-action-btn">View</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredOrders.length === 0">
                                <td colspan="7" class="px-6 py-24 text-center">
                                    <span class="table-text-sub uppercase tracking-widest">No matching jobs in this stage</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Standardized Premium Pagination -->
                <div x-show="totalPages > 1" class="pagination-container" style="display: flex !important; width: 100% !important; justify-content: center !important; align-items: center !important; padding: 24px 0; border-top: 1px solid #f3f4f6; margin-top: auto !important; clear: both !important;">
                    <div style="display: flex !important; align-items: center !important; gap: 6px !important; justify-content: center !important;">
                        <!-- Previous Button -->
                        <button x-show="currentPage > 1" 
                                @click="currentPage--" 
                                style="display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#64748b; transition:all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor:pointer;"
                                onmouseover="this.style.borderColor='#06A1A1'; this.style.color='#06A1A1'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(6, 161, 161, 0.1)';" 
                                onmouseout="this.style.borderColor='#e2e8f0'; this.style.color='#64748b'; this.style.transform='none'; this.style.boxShadow='none';">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>

                        <template x-for="(p, i) in pageNumbers" :key="i">
                            <div style="display:flex; align-items:center;">
                                <template x-if="p === '...'">
                                    <span style="width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; color:#94a3b8; font-weight:600; font-size:14px; letter-spacing:1px;">...</span>
                                </template>
                                <template x-if="p !== '...'">
                                    <button @click="currentPage = p" 
                                            :style="currentPage === p 
                                                ? 'display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 12px; border-radius:10px; border:none; background:linear-gradient(135deg, #06A1A1 0%, #047676 100%); color:white; font-size:14px; font-weight:700; box-shadow: 0 4px 12px rgba(6, 161, 161, 0.25); cursor:pointer;'
                                                : 'display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 12px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#64748b; font-size:14px; font-weight:600; transition:all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor:pointer;'"
                                            x-text="p"
                                            @mouseover="if(currentPage !== p) { $el.style.borderColor='#06A1A1'; $el.style.color='#06A1A1'; $el.style.transform='translateY(-1px)'; $el.style.boxShadow='0 4px 12px rgba(6, 161, 161, 0.1)'; }"
                                            @mouseout="if(currentPage !== p) { $el.style.borderColor='#e2e8f0'; $el.style.color='#64748b'; $el.style.transform='none'; $el.style.boxShadow='none'; }">
                                    </button>
                                </template>
                            </div>
                        </template>

                        <!-- Next Button -->
                        <button x-show="currentPage < totalPages" 
                                @click="currentPage++" 
                                style="display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; color:#64748b; transition:all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor:pointer;"
                                onmouseover="this.style.borderColor='#06A1A1'; this.style.color='#06A1A1'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(6, 161, 161, 0.1)';" 
                                onmouseout="this.style.borderColor='#e2e8f0'; this.style.color='#64748b'; this.style.transform='none'; this.style.boxShadow='none';">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </main>

    <!-- No more materials modal - integrated into details -->

<!-- Image Preview Lightbox -->
<div x-show="previewFile" x-cloak @click.self="previewFile = null" style="position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:10000; max-width:90vw; max-height:90vh;">
    <div style="position:relative; background:white; border-radius:16px; padding:20px; box-shadow:0 25px 50px rgba(0,0,0,0.3); border:1px solid #e5e7eb;">
        <button @click="previewFile = null" style="position:absolute; top:10px; right:10px; background:#f3f4f6; border:none; color:#374151; font-size:24px; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s; z-index:10001; font-weight:300; line-height:1;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">&times;</button>
        <img :src="previewFile" @click.stop style="max-width:80vw; max-height:70vh; border-radius:8px; display:block;">
        <div style="margin-top:16px; text-align:center;">
            <a :href="previewFile" download @click.stop style="background:#06A1A1; color:white; padding:10px 24px; border-radius:8px; text-decoration:none; font-size:14px; font-weight:600; display:inline-flex; align-items:center; gap:8px; transition:all 0.2s;" onmouseover="this.style.background='#047676'" onmouseout="this.style.background='#06A1A1'">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Download Artwork
            </a>
        </div>
    </div>
</div>

<!-- Customization Details Modal — matching customers_management.php style -->
<div x-show="showDetailsModal" x-cloak>
    <div class="modal-overlay" @click.self="closeDetailsModal()">
        <div class="modal-panel" @click.stop>

            <!-- Loading State -->
            <div x-show="loadingDetails" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#06A1A1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading job details...</p>
            </div>

            <!-- Content -->
            <div x-show="!loadingDetails && currentJo.id">

                <!-- Modal Header -->
                <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                    <div class="modal-header-copy">
                        <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0;" x-text="currentJo.order_code || getDisplayOrderCode(currentJo)"></h3>
                        <p class="modal-wrap-text" style="font-size:12px;color:#6b7280;margin:2px 0 0;" x-text="getCorrectServiceType(currentJo)"></p>
                    </div>
                    <button @click="closeDetailsModal()" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div style="padding:24px;">

                    <!-- Customer Row -->
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #f3f4f6;">
                        <div x-show="!currentJo.customer_profile_picture || currentJo.customer_profile_picture === 'null' || currentJo.customer_profile_picture === 'undefined'" style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#06A1A1,#047676);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:22px;flex-shrink:0;" x-text="currentJo.customer_full_name ? currentJo.customer_full_name[0].toUpperCase() : '?'"></div>
                        <img x-show="currentJo.customer_profile_picture && currentJo.customer_profile_picture !== 'null' && currentJo.customer_profile_picture !== 'undefined'" :src="getProfileImage(currentJo.customer_profile_picture)" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #06A1A1;background:#f3f4f6;flex-shrink:0;" onerror="this.src='<?php echo htmlspecialchars(BASE_PATH . '/public/assets/uploads/profiles/default.png', ENT_QUOTES, 'UTF-8'); ?>'">
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#1f2937;" x-text="currentJo.customer_full_name"></div>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap;">
                                <span style="font-size:12px;color:#6b7280;" x-text="currentJo.customer_contact"></span>
                            </div>
                            <div x-show="currentJo.customer_address" style="font-size:12px;color:#6b7280;margin-top:8px;max-width:100%;word-break:break-word;" x-text="currentJo.customer_address"></div>
                        </div>
                    </div>


                    <!-- Dynamic Order Details (service-specific fields from customization_data) -->
                    <template x-if="currentJo.items && currentJo.items.length > 0">
                        <div style="margin-bottom:20px; padding:16px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb;">
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:12px;">Order Details (Customer Specifications)</label>
                            <template x-for="(item, idx) in currentJo.items" :key="item.order_item_id || idx">
                                <div style="margin-bottom:16px; padding:12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px;">
                                    <div class="modal-wrap-text modal-item-title" style="font-size:13px; font-weight:700; color:#1f2937; margin-bottom:10px;" x-text="getDynamicProductName(item) + ' × ' + item.quantity"></div>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:10px;">
                                        <template x-for="([k, v]) in getDisplayableCustom(item.customization, item)" :key="k">
                                            <div style="padding:8px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; min-width:0; overflow-wrap:break-word;">
                                                <div style="font-size:10px; font-weight:600; color:#6b7280; text-transform:uppercase; margin-bottom:2px;" x-text="getCustomLabel(k)"></div>
                                                <div style="font-size:12px; font-weight:500; color:#1f2937; word-break:break-word; overflow-wrap:break-word;" x-text="formatCustomValuePlain(v)"></div>
                                                <a x-show="isDisplayableLink(v)" :href="sanitizeStaffLink(v)" target="_blank" rel="noopener noreferrer" style="font-size:11px;color:#4f46e5;font-weight:600;margin-top:4px;display:inline-block;">Open link →</a>
                                            </div>
                                        </template>
                                    </div>
                                    <template x-if="staffEffectiveDesignOpenUrl(item) && staffDesignShowsAsImage(item)">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Design Preview</div>
                                            <div style="display:flex; align-items:flex-end; gap:12px;">
                                                <img :src="staffEffectiveDesignOpenUrl(item)" 
                                                     @click="previewFile = staffEffectiveDesignOpenUrl(item)"
                                                     style="width:140px; height:auto; border-radius:10px; border:1px solid #e2e8f0; cursor:zoom-in; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);" 
                                                     onerror="this.src='<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/images/services/default.png', ENT_QUOTES, 'UTF-8'); ?>'">
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="staffEffectiveDesignOpenUrl(item) && !staffDesignShowsAsImage(item)">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Uploaded Design</div>
                                            <a :href="staffEffectiveDesignOpenUrl(item)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               style="display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; color:#334155; font-size:12px; font-weight:600; max-width:100%; overflow-wrap:anywhere; text-decoration:none;">
                                                <span style="font-size:14px;">FILE</span>
                                                <span x-text="item.design_name || 'Open uploaded design'"></span>
                                            </a>
                                        </div>
                                    </template>
                                    <template x-if="!staffEffectiveDesignOpenUrl(item) && item.design_name">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Uploaded Design</div>
                                            <div style="display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; color:#334155; font-size:12px; font-weight:600; max-width:100%; overflow-wrap:anywhere;">
                                                <span style="font-size:14px;">FILE</span>
                                                <span x-text="item.design_name"></span>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="item.reference_open_url && (item.reference_is_image || staffFilenameLooksLikeImage(item.reference_name))">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Reference image</div>
                                            <div style="display:flex; align-items:flex-end; gap:12px;">
                                                <img :src="item.reference_url || item.reference_open_url"
                                                     @click="previewFile = item.reference_url || item.reference_open_url"
                                                     style="width:140px; height:auto; border-radius:10px; border:1px solid #e2e8f0; cursor:zoom-in; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                                                     onerror="this.style.display='none'">
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="item.reference_open_url && !(item.reference_is_image || staffFilenameLooksLikeImage(item.reference_name))">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Reference File</div>
                                            <a :href="item.reference_open_url"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               style="display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; color:#334155; font-size:12px; font-weight:600; max-width:100%; overflow-wrap:anywhere; text-decoration:none;">
                                                <span style="font-size:14px;">FILE</span>
                                                <span x-text="item.reference_name || 'Open reference file'"></span>
                                            </a>
                                        </div>
                                    </template>
                                    <template x-if="!item.reference_open_url && item.reference_name">
                                        <div style="margin-top:12px;">
                                            <div style="font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px;">Reference File</div>
                                            <div style="display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; color:#334155; font-size:12px; font-weight:600; max-width:100%; overflow-wrap:anywhere;">
                                                <span style="font-size:14px;">FILE</span>
                                                <span x-text="item.reference_name"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Payment proof: show as image whenever uploaded (not only on TO VERIFY tab) -->
                    <template x-if="staffPaymentProofSrc(currentJo) && !isVerifyStageRow(currentJo)">
                        <div style="margin-bottom:20px; padding:16px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb;">
                            <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:12px;">Payment proof (customer)</label>
                            <div @click="previewFile = staffPaymentProofSrc(currentJo)"
                                 style="display:block;line-height:0;background:#fff;border:1px solid #d1d5db;border-radius:12px;overflow:hidden;max-width:100%;cursor:zoom-in;box-shadow:0 4px 12px rgba(15,23,42,0.06);">
                                <img :src="staffPaymentProofSrc(currentJo)"
                                     style="display:block;width:100%;max-height:420px;object-fit:contain;background:#fff;"
                                     alt="Payment proof"
                                     @error="$el.src = (document.body.getAttribute('data-base-url') || '') + '/public/assets/images/image_broken.php?text=Payment+proof'; $el.style.opacity='0.4'">
                            </div>
                        </div>
                    </template>

                    <!-- Notes -->
                    <div style="margin-bottom:20px;" x-show="combinedCustomerNotes().trim() !== '' && combinedCustomerNotes() !== 'No specific instructions.'">
                        <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:6px;">Order Notes</label>
                        <div style="font-size:13px;color:#6b7280;background:#fffbeb;border:1px solid #fef3c7;padding:10px 14px;border-radius:8px;word-break:break-word;overflow-wrap:break-word;white-space:pre-wrap;" x-text="combinedCustomerNotes()"></div>
                    </div>

                    <template x-if="isPosSimplifiedView && isPosWalkInSource(currentJo) && getPosWalkInBucket(currentJo) === 'PENDING'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #d1fae5; background:#f0fdf4;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                                <div style="min-width:0; flex:1;">
                                    <label style="font-size:11px;font-weight:700;color:#047857;text-transform:uppercase;display:block;margin-bottom:6px;">Pending Walk-in Order</label>
                                    <div style="font-size:13px; color:#065f46;">This order is still active and can be marked as completed once the printing or release is finished.</div>
                                </div>
                                <button type="button" @click="openPosCompleteConfirm()" :disabled="actionBusy" class="pf-entry-btn pf-entry-in" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Mark as Completed</button>
                            </div>
                        </div>
                    </template>

                    <!-- 4. TO_VERIFY (Payment Verification) -->
                    <template x-if="isVerifyStageRow(currentJo)">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb;">
                            <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;display:block;margin-bottom:16px;">Step 4: Verify Payment Proof</label>
                            
                            <div style="display:flex; flex-direction:column; gap:14px;">
                                <template x-if="staffPaymentProofSrc(currentJo)">
                                        <div @click="previewFile = staffPaymentProofSrc(currentJo)"
                                             style="display:block;line-height:0;background:#fff;border:1px solid #d1d5db;border-radius:12px;overflow:hidden;box-shadow:0 8px 18px rgba(15,23,42,0.08);cursor:zoom-in;">
                                            <img :src="staffPaymentProofSrc(currentJo)"
                                                 style="display:block;width:100%;max-height:460px;object-fit:contain;background:#fff;"
                                                 alt="Payment Proof"
                                                 @error="$el.src = (document.body.getAttribute('data-base-url') || '') + '/public/assets/images/image_broken.php?text=Payment Proof'; $el.style.opacity='0.4'">
                                        </div>
                                    </template>
                                
                                <div style="padding:14px 16px; border-radius:10px; background:#fff; border:1px solid #e5e7eb;">
                                    <div style="font-size:11px; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.04em;">Amount Submitted</div>
                                    
                                    <div style="margin-top:4px; font-size:24px; font-weight:800; color:#1f2937;" x-text="'₱' + Number(currentJo.payment_submitted_amount || 0).toLocaleString()"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- 2. APPROVED (Set Price & Materials) -->
                    <template x-if="currentJo.status === 'APPROVED'">
                        <div style="margin-bottom:20px; display:flex; flex-direction:column; gap:20px;">
                            
                            <!-- Production Details Section -->
                            <div style="padding:20px; border-radius:16px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                    <h3 style="font-size:14px; font-weight:700; color:#1f2937; text-transform:uppercase; letter-spacing:0.025em; margin:0;">Production Assignment</h3>
                                    <span style="font-size:11px; background:#f3f4f6; color:#6b7280; padding:4px 10px; border-radius:100px; font-weight:600;" x-text="getCorrectServiceType(currentJo)"></span>
                                </div>

                                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
                                    
                                    <!-- A. Materials Selection -->
                                    <div style="display:flex; flex-direction:column; gap:12px;">
                                        <label style="font-size:12px; font-weight:700; color:#374151;">[1] Core Materials</label>
                                        
                                        <!-- Searchable Selection -->
                                        <div style="position:relative;">
                                            <input type="text" x-model="materialSearch" placeholder="Search materials (e.g. tarpaulin, vinyl...)" 
                                                   style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; margin-bottom:8px;">
                                            
                                            <select x-model="newMaterialId" @change="handleMaterialSelection($event.target.value)" 
                                                    style="width:100%; padding:10px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:white; cursor:pointer;">
                                                <option value="">-- Choose Material --</option>
                                                <template x-for="item in availableMaterialsForCurrentOrder" :key="item.id">
                                                    <option :value="item.id" x-text="`${item.name} (${item.current_stock} ${item.unit_of_measure})`"></option>
                                                </template>
                                            </select>
                                        </div>

                                        <template x-if="newMaterialId">
                                            <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                                <div style="grid-column: span 2;">
                                                    <label style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;">Qty / Length</label>
                                                    <input type="number" x-model.number="newMaterialQty" min="1" step="any" @input="handleMaterialQtyInput($event.target.value)" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                                                </div>
                                                <template x-if="isTarpaulin(newMaterialId)">
                                                    <div style="grid-column: span 2;">
                                                        <label style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;">Height (ft)</label>
                                                        <input type="number" x-model.number="newMaterialHeight" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                                                    </div>
                                                </template>
                                                <div x-show="selectedMaterialStockError" style="grid-column: span 2; padding:8px 10px; border-radius:8px; border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; font-size:11px; font-weight:700; line-height:1.4;" x-text="selectedMaterialStockError"></div>
                                                <button @click="addMaterialToQueue()" :disabled="!!selectedMaterialStockError" class="btn-staff-action btn-staff-action-indigo" :style="selectedMaterialStockError ? 'grid-column: span 2; padding:8px; font-size:12px; font-weight:600; opacity:0.55; cursor:not-allowed;' : 'grid-column: span 2; padding:8px; font-size:12px; font-weight:600;'">Add to Order Content</button>
                                            </div>
                                        </template>

                                        <div x-show="pendingMaterials.length > 0" style="display:flex; flex-direction:column; gap:6px;">
                                            <template x-for="(pm, idx) in pendingMaterials" :key="idx">
                                                <div style="display:flex; align-items:center; justify-content:space-between; background:#f1f5f9; border-radius:8px; padding:8px 12px; font-size:12px; border:1px solid #e2e8f0;">
                                                    <div>
                                                        <span style="font-weight:600; color:#1e293b;" x-text="pm.name"></span>
                                                        <span x-show="pm.qty > 0" style="margin-left:4px; font-weight:800; color:#06A1A1;" x-text="'x' + pm.qty"></span>
                                                    </div>
                                                    <div style="display:flex; align-items:center; gap:12px;">
                                                        <span style="color:#64748b;" x-text="pm.qty + ' ' + pm.uom"></span>
                                                        <button @click="pendingMaterials.splice(idx,1)" style="color:#ef4444; border:none; background:none; cursor:pointer; font-weight:700;">✕</button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- B. Ink Options -->
                                    <div style="display:flex; flex-direction:column; gap:12px;">
                                        <div style="display:flex; align-items:center; justify-content:space-between;">
                                            <label style="font-size:12px; font-weight:700; color:#374151;">[2] Ink Options</label>
                                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="checkbox" x-model="useInk" style="width:16px; height:16px; cursor:pointer; accent-color:#06A1A1;">
                                                <span style="font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase;">Use Ink</span>
                                            </label>
                                        </div>

                                        <div x-show="useInk" x-transition style="padding:16px; border:1px solid #cbd5e1; border-radius:12px; background:#f9fafb;">
                                            <label style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; margin-bottom:10px; display:block;">Select Ink Set</label>
                                            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                                                <template x-for="type in availableInkOptionsForService" :key="type">
                                                    <button type="button" @click="inkCategorySelected = type" 
                                                            :style="inkCategorySelected === type ? 'background:#06A1A1; color:white; border-color:#06A1A1;' : 'background:white; color:#64748b; border-color:#e2e8f0;'"
                                                            style="padding:8px 16px; border-radius:8px; border:2px solid; font-size:12px; font-weight:700; transition:all 0.2s; cursor:pointer;"
                                                            x-text="type"></button>
                                                </template>
                                            </div>

                                            <template x-if="inkCategorySelected">
                                                <div>
                                                    <div style="background:#fff; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                                                        <div style="font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            Ink Consumption (ml)
                                                        </div>
                                                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#ef4444; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                                                                    <span style="width:12px; height:12px; background:#ef4444; border-radius:50%; display:inline-block;"></span>
                                                                    RED
                                                                </label>
                                                                <div style="position:relative;">
                                                                    <input type="number" x-model.number="inkRed" step="0.1" min="0" placeholder="0.0" style="width:100%; padding:10px 32px 10px 12px; border:2px solid #e5e7eb; border-radius:8px; font-size:14px; font-weight:600; transition:border-color 0.2s;" onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='#e5e7eb'">
                                                                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:11px; color:#9ca3af; font-weight:600;">ml</span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                                                                    <span style="width:12px; height:12px; background:#3b82f6; border-radius:50%; display:inline-block;"></span>
                                                                    BLUE
                                                                </label>
                                                                <div style="position:relative;">
                                                                    <input type="number" x-model.number="inkBlue" step="0.1" min="0" placeholder="0.0" style="width:100%; padding:10px 32px 10px 12px; border:2px solid #e5e7eb; border-radius:8px; font-size:14px; font-weight:600; transition:border-color 0.2s;" onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e5e7eb'">
                                                                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:11px; color:#9ca3af; font-weight:600;">ml</span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#1f2937; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                                                                    <span style="width:12px; height:12px; background:#1f2937; border-radius:50%; display:inline-block;"></span>
                                                                    BLACK
                                                                </label>
                                                                <div style="position:relative;">
                                                                    <input type="number" x-model.number="inkBlack" step="0.1" min="0" placeholder="0.0" style="width:100%; padding:10px 32px 10px 12px; border:2px solid #e5e7eb; border-radius:8px; font-size:14px; font-weight:600; transition:border-color 0.2s;" onfocus="this.style.borderColor='#1f2937'" onblur="this.style.borderColor='#e5e7eb'">
                                                                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:11px; color:#9ca3af; font-weight:600;">ml</span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label style="font-size:10px; font-weight:700; color:#eab308; text-transform:uppercase; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                                                                    <span style="width:12px; height:12px; background:#eab308; border-radius:50%; display:inline-block;"></span>
                                                                    YELLOW
                                                                </label>
                                                                <div style="position:relative;">
                                                                    <input type="number" x-model.number="inkYellow" step="0.1" min="0" placeholder="0.0" style="width:100%; padding:10px 32px 10px 12px; border:2px solid #e5e7eb; border-radius:8px; font-size:14px; font-weight:600; transition:border-color 0.2s;" onfocus="this.style.borderColor='#eab308'" onblur="this.style.borderColor='#e5e7eb'">
                                                                    <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:11px; color:#9ca3af; font-weight:600;">ml</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div x-show="inkStockIssues.length > 0" style="margin-top:12px; padding:10px 12px; border-radius:8px; border:1px solid #fecaca; background:#fef2f2; color:#b91c1c; font-size:11px; font-weight:700; line-height:1.45;">
                                                            <template x-for="(issue, idx) in inkStockIssues" :key="idx">
                                                                <div x-text="issue"></div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        <div x-show="!useInk" style="font-size:12px; color:#94a3b8; font-style:italic; text-align:center; padding:16px; background:#f9fafb; border-radius:8px; border:1px dashed #e2e8f0;">
                                            No ink required for this job
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Final Step: Pricing and Submit -->
                            <div style="padding:16px; border-radius:14px; border:1px solid #99f6e4; background:#f0fdfa;">
                                <div style="margin-bottom:20px;">
                                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                                        <svg width="16" height="16" fill="none" stroke="#0f766e" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <label style="font-size:11px; font-weight:700; color:#0f766e; text-transform:uppercase; letter-spacing:0.04em;">[2] Set Final Price</label>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:10px 12px; border-radius:10px; border:1px solid #99f6e4; background:#ffffff;">
                                        <div style="font-size:12px; font-weight:700; color:#0f766e; text-transform:uppercase; letter-spacing:0.04em;">Estimated Price</div>
                                        <div style="font-size:16px; font-weight:800; color:#0f766e;" x-text="'₱' + Number(currentJo.estimated_price || currentJo.estimated_total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></div>
                                    </div>
                                    <div style="position:relative;">
                                        <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); font-weight:800; color:#0f766e; font-size:20px;">₱</span>
                                        <input type="text" 
                                               placeholder="0.00"
                                               x-init="$watch('showDetailsModal', v => { if(v) $nextTick(() => { $el.value = Number(jobPriceInput || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }); })"
                                               x-on:input="
                                                   let val = $event.target.value.replace(/[^0-9.]/g, '');
                                                   let parts = val.split('.');
                                                   parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                                                   $event.target.value = parts.join('.');
                                                   jobPriceInput = $event.target.value.replace(/,/g, '');
                                               "
                                               x-on:blur="
                                                   if (jobPriceInput) {
                                                       jobPriceInput = parseFloat(jobPriceInput).toFixed(2);
                                                       $event.target.value = Number(jobPriceInput).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                                   }
                                               "
                                               style="width:100%; height:42px; padding:0 12px 0 42px; border:1px solid #5eead4; border-radius:10px; font-size:18px; font-weight:700; color:#0f766e; outline:none; background:#ffffff; transition:all 0.2s;"
                                               onfocus="this.style.borderColor='#0d9488'; this.style.boxShadow='0 0 0 3px rgba(6, 161, 161, 0.08)'"
                                               onblur="this.style.borderColor='#5eead4'; this.style.boxShadow='none'">
                                    </div>
                                    <div style="font-size:11px; color:#0f766e; margin-top:8px; line-height:1.45;">This is the total amount the customer will pay.</div>
                                </div>
                                <div x-show="approvalStockErrors.length > 0" style="margin-bottom:12px; padding:12px 14px; border-radius:10px; border:1px solid #fecaca; background:#fff1f2; color:#b91c1c; font-size:12px; font-weight:700; line-height:1.5;">
                                    <template x-for="(issue, idx) in approvalStockErrors" :key="idx">
                                        <div x-text="issue"></div>
                                    </template>
                                </div>
                                <div style="font-size:11px; color:#0f766e; margin-top:8px; line-height:1.45;">Approving will notify the customer, set the final price, and prepare materials for production.</div>
                            </div>
                        </div>
                    </template>

                    <!-- 3. TO_PAY (Waiting for Payment) -->
                    <template x-if="currentJo.status === 'TO_PAY'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #dbeafe; background:#f0f9ff;">
                            <label style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;display:block;margin-bottom:12px;">Step 3: Awaiting Payment</label>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                <div style="font-size:14px; font-weight:500; color:#1e40af;">Total Outstanding:</div>
                                <div style="font-size:20px; font-weight:800; color:#1e40af;" x-text="'₱' + Number(currentJo.estimated_total || 0).toLocaleString()"></div>
                            </div>
                            <div style="font-size:13px; color:#1e40af; line-height:1.5;">Waiting for the customer to upload payment proof. Once uploaded, it will appear in the TO VERIFY section.</div>
                        </div>
                    </template>

                    <!-- 5. IN_PRODUCTION -->
                    <template x-if="currentJo.status === 'IN_PRODUCTION' || currentJo.status === 'Processing'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #06A1A1; background:#f0fbfb;">
                            <label style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;display:block;margin-bottom:12px;">Step 5: Production In Progress</label>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px;">
                                <div style="font-size:14px; color:#0f766e; font-weight:500;" x-text="materialsDeductedSummary"></div>
                            </div>
                        </div>
                    </template>

                    <!-- 6. TO_RECEIVE -->
                    <template x-if="currentJo.status === 'TO_RECEIVE'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #06A1A1; background:#f0fbfb;">
                            <label style="font-size:11px;font-weight:700;color:#0f766e;text-transform:uppercase;display:block;margin-bottom:12px;">Step 6: Ready for Pickup</label>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <div style="font-size:14px; color:#0f766e; font-weight:500;">Customer has been notified to pick up the order.</div>
                            </div>
                        </div>
                    </template>

                    <!-- 7. COMPLETED -->
                    <template x-if="currentJo.status === 'COMPLETED'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #bbf7d0; background:#f0fdf4;">
                            <label style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;display:block;margin-bottom:4px;">Workflow Finished</label>
                            <div style="font-size:15px; font-weight:700; color:#15803d; display:flex; align-items:center; gap:8px;">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Order Successfully Completed
                            </div>
                        </div>
                    </template>

                    <!-- REJECTED -->
                    <template x-if="currentJo.status === 'REJECTED'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #fca5a5; background:#fff1f2;">
                            <label style="font-size:11px;font-weight:700;color:#be123c;text-transform:uppercase;display:block;margin-bottom:4px;">Payment Rejected</label>
                            <div style="font-size:15px; font-weight:700; color:#be123c; display:flex; align-items:center; gap:8px;">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10A8 8 0 112 10a8 8 0 0116 0zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                Payment proof rejected
                            </div>
                        </div>
                    </template>

                    <!-- CANCELLED -->
                    <template x-if="currentJo.status === 'CANCELLED'">
                        <div style="margin-bottom:20px; padding:18px; border-radius:12px; border:1px solid #fca5a5; background:#fef2f2;">
                            <label style="font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;display:block;margin-bottom:4px;">Workflow Terminated</label>
                            <div style="font-size:15px; font-weight:700; color:#dc2626; display:flex; align-items:center; gap:8px;">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                Order Cancelled
                            </div>
                        </div>
                    </template>

                    <div x-show="currentJo.materials && currentJo.materials.length > 0" style="margin-top:20px;">
                        <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:8px;">Assigned Production Materials</label>
                        <template x-for="m in groupedMaterials" :key="m.item_id">
                            <div style="background:white; border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:12px; font-weight:600; color:#1f2937;">
                                        <span x-text="m.item_name"></span>
                                        <span x-show="m.track_by_roll == 0 && m.quantity > 0" style="margin-left:4px; font-weight:800; color:#06A1A1;" x-text="'x' + Number(m.quantity)"></span>
                                        <template x-if="m.track_by_roll == 1">
                                            <span style="margin-left:4px; font-weight:800; color:#06A1A1;" x-text="'x' + Number(m.computed_required_length_ft)"></span>
                                        </template>
                                    </div>
                                    <div style="font-size:11px; color:#6b7280; margin-top:2px;">
                                        <span x-show="m.track_by_roll == 1">
                                            Req: <span x-text="m.computed_required_length_ft"></span>'
                                            <span x-show="m.roll_code"> (Roll: <span x-text="m.roll_code"></span>)</span>
                                            <span x-show="!m.roll_code"> (Auto Pick Roll)</span>
                                        </span>
                                        <span x-show="m.track_by_roll == 0">Qty: <span x-text="m.quantity"></span></span>
                                        <template x-if="m.metadata && m.metadata.lamination_item_id">
                                            <div style="color:#059669; font-weight:600; margin-top:4px;">
                                                + Lamination (Auto Pick Roll) — <span x-text="m.metadata.lamination_length_ft"></span>'
                                            </div>
                                        </template>
                                        <template x-if="m.metadata && m.metadata.waste_length_ft !== undefined">
                                            <div style="color:#b45309; margin-top:2px;">
                                                Recorded Waste: <span x-text="m.metadata.waste_length_ft"></span>'
                                            </div>
                                        </template>
                                        <span style="color:#06A1A1; font-weight:600; margin-left:8px;" x-show="m.deducted_at">✓ Deducted</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <template x-if="currentJo.ink_usage && currentJo.ink_usage.length > 0">
                        <div style="margin-top:16px;">
                            <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:8px;">Ink Consumption Recorded</label>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <template x-for="ink in (currentJo.ink_usage || [])" :key="ink.id">
                                    <div style="background:#fdf4ff; border:1px solid #fbcfe8; border-radius:6px; padding:6px 10px; font-size:11px; font-weight:600; color:#9d174d;">
                                        <span x-text="ink.item_name + ' → '"></span>
                                        <span x-text="formatInkQuantity(ink.quantity_used) + ' ml'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Design Status / Revision Alert -->
                    <template x-if="currentJo.design_status === 'Revision Submitted'">
                        <div style="margin-bottom:20px; padding:12px; background:#e0f2fe; border:1px solid #bae6fd; border-radius:10px; display:flex; flex-wrap:wrap; align-items:flex-start; gap:12px;">
                             <div style="background:#0284c7; color:#fff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 4px 6px -1px rgba(2, 132, 199, 0.4);">
                                 <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M12 11l5 5m0 0l-5 5m5-5H6"/></svg>
                             </div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:13px; font-weight:700; color:#0369a1;">Revision Re-submitted</div>
                                <div style="font-size:12px; color:#075985;">The customer has uploaded a new design file. Please review and approve.</div>
                            </div>
                            <template x-for="(revItem, revIdx) in (currentJo.items || [])" :key="revItem.order_item_id || revIdx">
                                <div x-show="(staffDesignShowsAsImage(revItem) && staffEffectiveDesignOpenUrl(revItem)) || (revItem.revision_design_url && staffFilenameLooksLikeImage(revItem.revision_design_name))"
                                     @click="previewFile = revItem.revision_design_url || staffEffectiveDesignOpenUrl(revItem)"
                                     style="flex-shrink:0; cursor:zoom-in;">
                                    <img :src="revItem.revision_design_url || staffEffectiveDesignOpenUrl(revItem)"
                                         alt="Revised design preview"
                                         style="display:block; max-width:min(100%, 220px); max-height:140px; object-fit:contain; border-radius:10px; border:1px solid #bae6fd; background:#fff; box-shadow:0 2px 8px rgba(2,132,199,0.12);"
                                         onerror="this.style.display='none'">
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- Artwork Files -->
                    <div x-show="currentJo.files && currentJo.files.length > 0" style="margin-top:16px;">
                        <label style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;display:block;margin-bottom:8px;">Artwork Files</label>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <template x-for="file in (currentJo.files || [])" :key="file.id">
                                <a :href="(document.body.getAttribute('data-base-url') || '') + '/' + file.file_path.replace(/^\/+/, '')" target="_blank" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:#1f2937;transition:border-color 0.2s;" onmouseover="this.style.borderColor='#06A1A1'" onmouseout="this.style.borderColor='#e5e7eb'">
                                    <span style="font-size:12px;font-weight:500;" x-text="file.file_name"></span>
                                    <span style="font-size:11px;color:#06A1A1;font-weight:600;">View ↗</span>
                                </a>
                            </template>
                        </div>
                    </div>
                </div>


                <!-- Modal Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <!-- Left: Status actions -->
                    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;min-width:0;flex:1;">
                        <div style="display:flex;gap:8px; flex-wrap:wrap; align-items:center;">
                            <div x-show="isPosSimplifiedView && isPosWalkInSource(currentJo) && getPosWalkInBucket(currentJo) === 'PENDING'" style="display:flex; gap:8px;">
                                <button type="button" @click="cancelPosWalkInOrder()" :disabled="actionBusy" class="pf-entry-btn pf-entry-out" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Cancel Order</button>
                            </div>
                            <div x-show="!isPosSimplifiedView && isPendingReviewStatus(currentJo) && !isVerifyStageRow(currentJo)" style="display:flex; gap:8px;">
                                <button type="button" @click="jobAction('APPROVED')" :disabled="actionBusy" class="pf-entry-btn pf-entry-in" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Approve to Set Price</button>
                                <button type="button" @click="openRevisionModal()" :disabled="actionBusy" class="pf-entry-btn pf-entry-out" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Request Revision</button>
                            </div>
                            <div x-show="!isPosSimplifiedView && currentJo.status === 'APPROVED'" style="display:flex; gap:8px;">
                                <button type="button" @click="submitToPay()" :disabled="actionBusy || approvalStockErrors.length > 0" class="pf-entry-btn pf-entry-in" :style="(actionBusy || approvalStockErrors.length > 0) ? 'opacity:.6;cursor:not-allowed;' : ''">Confirm Approval &amp; Send to Payment</button>
                            </div>
                            <div x-show="!isPosSimplifiedView && isVerifyStageRow(currentJo)" style="display:flex; gap:8px;">
                                <button type="button" @click="verifyPayment()" :disabled="actionBusy || !canApproveVerification()" class="pf-entry-btn pf-entry-in" :style="(actionBusy || !canApproveVerification()) ? 'opacity:.6;cursor:not-allowed;' : ''">Approve Payment</button>
                                <button type="button" @click="openRejectPaymentModal()" :disabled="actionBusy" class="pf-entry-btn pf-entry-out" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Reject</button>
                            </div>
                            <div x-show="!isPosSimplifiedView && (currentJo.status === 'IN_PRODUCTION' || currentJo.status === 'Processing')" style="display:flex; gap:8px;">
                                <button type="button" @click="markReadyForPickup()" :disabled="actionBusy" class="pf-entry-btn pf-entry-in" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Mark as Ready for Pickup</button>
                            </div>
                            <div x-show="!isPosSimplifiedView && currentJo.status === 'TO_RECEIVE'" style="display:flex; gap:8px;">
                                <button type="button" @click="completeOrder()" :disabled="actionBusy" class="pf-entry-btn pf-entry-in" :style="actionBusy ? 'opacity:.6;cursor:not-allowed;' : ''">Mark Final Completed</button>
                            </div>
                        </div>
                        <div x-show="footerActionError" x-cloak style="font-size:12px;font-weight:600;color:#dc2626;line-height:1.45;max-width:560px;" x-text="footerActionError"></div>
                    </div>
                    <!-- Right: Close -->
                    <button @click="closeDetailsModal()" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>
</div>

<template x-if="showPosCompleteConfirmModal">
    <div>
        <div x-show="showPosCompleteConfirmModal" x-cloak style="position:fixed; inset:0; z-index:10001; background:rgba(0,0,0,0.45);" @click="closePosCompleteConfirm()"></div>
        <div x-show="showPosCompleteConfirmModal" x-cloak style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10002; width:calc(100% - 32px); max-width:430px; background:#fff; border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden;">
            <div style="padding:18px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:18px; font-weight:700; color:#1f2937;">Confirm Completion</h3>
                <button type="button" @click="closePosCompleteConfirm()" style="background:none; border:none; color:#9ca3af; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div style="padding:20px; color:#374151; font-size:14px; line-height:1.6;">
                Are you sure you want to mark this walk-in order as completed?
            </div>
            <div style="padding:16px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" @click="closePosCompleteConfirm()" class="btn-secondary">Cancel</button>
                <button type="button" @click="confirmPosComplete()" class="pf-entry-btn pf-entry-in" style="background:#10b981; color:#fff;">Mark as Completed</button>
            </div>
        </div>
    </div>
</template>

<div x-show="actionBusy" x-cloak style="position:fixed;inset:0;z-index:12000;display:flex;align-items:center;justify-content:center;pointer-events:none;">
    <div style="width:46px;height:46px;border:4px solid #d1d5db;border-top-color:#06A1A1;border-radius:50%;animation:spin .8s linear infinite;"></div>
</div>

<!-- REVISION MODAL -->
    <template x-if="showRevisionModal">
        <div>
            <!-- Backdrop -->
            <div x-show="showRevisionModal" x-cloak
                 style="position:fixed; inset:0; z-index:10001; background:transparent;"
                 @click="closeRevisionModal()"></div>
            <!-- Modal Panel — true viewport center via transform -->
            <div x-show="showRevisionModal" x-cloak
                 style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10002;
                        width:calc(100% - 32px); max-width:420px;
                        background:white; border-radius:16px;
                        box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
                        border:1px solid #fee2e2; overflow:hidden;">
                <!-- Header -->
                <div style="padding:16px 20px; border-bottom:1px solid #fee2e2; background:#fef2f2; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#b91c1c;">Request Revision</h3>
                    <button @click="closeRevisionModal()" style="background:none; border:none; color:#f87171; cursor:pointer;" onmouseover="this.style.color='#b91c1c'" onmouseout="this.style.color='#f87171'">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <!-- Body -->
                <div style="padding:20px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Reason for Revision</label>
                    <select x-model="revisionReasonSelect" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; margin-bottom:16px; outline:none;" onfocus="this.style.borderColor='#f87171'" onblur="this.style.borderColor='#d1d5db'">
                        <option value="">-- Select a reason --</option>
                        <option value="Low image quality">Low image quality</option>
                        <option value="Wrong design uploaded">Wrong design uploaded</option>
                        <option value="Incorrect details provided">Incorrect details provided</option>
                        <option value="Not printable / invalid format">Not printable / invalid format</option>
                        <option value="Others">Others</option>
                    </select>
                    <div x-show="revisionReasonSelect === 'Others'" style="transition:all 0.2s;">
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Please specify</label>
                        <textarea x-model="revisionReasonText" rows="3" placeholder="Enter custom reason..." style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; resize:vertical; outline:none; box-sizing:border-box;" onfocus="this.style.borderColor='#f87171'" onblur="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                </div>
                <!-- Footer -->
                <div style="padding:16px 20px; border-top:1px solid #f3f4f6; background:#f9fafb; display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                    <div style="display:flex; justify-content:flex-end; gap:8px;">
                        <button @click="closeRevisionModal()" class="btn-secondary">Cancel</button>
                        <button @click="submitRevision()" class="btn-action red">Submit Revision</button>
                    </div>
                    <div x-show="revisionModalError" x-cloak style="width:100%; font-size:12px; font-weight:600; color:#dc2626; text-align:left;" x-text="revisionModalError"></div>
                </div>
            </div>
        </div>
    </template>


    <!-- REJECT PAYMENT MODAL -->
    <template x-if="showRejectPaymentModal">
        <div>
            <!-- Backdrop -->
            <div x-show="showRejectPaymentModal" x-cloak
                 style="position:fixed; inset:0; z-index:10001; background:transparent;"
                 @click="closeRejectPaymentModal()"></div>
            <!-- Modal Panel -->
            <div x-show="showRejectPaymentModal" x-cloak
                 style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:10002;
                        width:calc(100% - 32px); max-width:420px;
                        background:white; border-radius:16px;
                        box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
                        border:1px solid #fee2e2; overflow:hidden;">
                <!-- Header -->
                <div style="padding:16px 20px; border-bottom:1px solid #fee2e2; background:#fef2f2; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:#b91c1c;">Reject Payment Proof</h3>
                    <button @click="closeRejectPaymentModal()" style="background:none; border:none; color:#f87171; cursor:pointer;" onmouseover="this.style.color='#b91c1c'" onmouseout="this.style.color='#f87171'">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <!-- Body -->
                <div style="padding:20px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Reason for Rejection</label>
                    <select x-model="rejectPaymentReasonSelect" style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; margin-bottom:16px; outline:none;" onfocus="this.style.borderColor='#f87171'" onblur="this.style.borderColor='#d1d5db'">
                        <option value="">-- Select a reason --</option>
                        <option value="Unclear image / receipt">Unclear image / receipt</option>
                        <option value="Incorrect amount submitted">Incorrect amount submitted</option>
                        <option value="Payment not received">Payment not received</option>
                        <option value="Expired reference">Expired reference</option>
                        <option value="Others">Others</option>
                    </select>
                    <div x-show="rejectPaymentReasonSelect === 'Others'" style="transition:all 0.2s;">
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Please specify</label>
                        <textarea x-model="rejectPaymentReasonText" rows="3" placeholder="Enter custom reason..." style="width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; resize:vertical; outline:none; box-sizing:border-box;" onfocus="this.style.borderColor='#f87171'" onblur="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                </div>
                <!-- Footer -->
                <div style="padding:16px 20px; border-top:1px solid #f3f4f6; background:#f9fafb; display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                    <div style="display:flex; justify-content:flex-end; gap:8px;">
                        <button @click="closeRejectPaymentModal()" class="btn-secondary">Cancel</button>
                        <button @click="submitRejectPayment()" class="btn-action red">Confirm Rejection</button>
                    </div>
                    <div x-show="rejectPaymentModalError" x-cloak style="width:100%; font-size:12px; font-weight:600; color:#dc2626; text-align:left;" x-text="rejectPaymentModalError"></div>
                </div>
            </div>
        </div>
    </template>
        <!-- Custom Staff Alert Modal -->
        <div x-show="alertModal.show" x-cloak style="position:fixed; inset:0; z-index:90000; display:flex; align-items:center; justify-content:center; padding:24px; backdrop-filter:blur(4px);">
            <div @click.self="closeStaffAlert()" style="position:fixed; inset:0; background:rgba(17,24,39,0.7);"></div>
            <div style="background:white; border-radius:24px; width:100%; max-width:400px; position:relative; z-index:1; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); animation:modalIn 0.3s ease-out; margin:0 auto;">
                <div style="padding:32px 32px 24px; text-align:center;">
                    <div style="width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; background:transparent;">
                        <svg width="28" height="28" fill="none" stroke="#00A1A1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 x-text="alertModal.title" style="font-size:20px; font-weight:800; color:#111827; margin:0 0 8px;"></h3>
                    <p x-text="alertModal.message" style="font-size:15px; color:#4b5563; line-height:1.6; margin:0;"></p>
                </div>
                <div style="padding:0 32px 32px;">
                    <button @click="closeStaffAlert()" style="width:100%; background:#00232b; color:white; border:none; border-radius:14px; padding:14px; font-weight:700; font-size:15px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#003a47'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(0,35,43,0.24)'" onmouseout="this.style.background='#00232b'; this.style.transform='none'; this.style.boxShadow='none'">OK</button>
                </div>
            </div>
        </div>

        <?php include __DIR__ . '/partials/service_order_modal.php'; ?>
        </div><!-- /#staffJoCustomizationsPage -->
    </div><!-- /.main-content -->
</div><!-- /.dashboard-container -->

<script src="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/js/staff_service_order_modal.js'); ?>"></script>
<?php
$preloaded_customization_rows_json = json_encode(
    $preloaded_customization_rows,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($preloaded_customization_rows_json === false) {
    $preloaded_customization_rows_json = '[]';
}
$preloaded_customization_rows_b64 = base64_encode($preloaded_customization_rows_json);
?>
<script id="pf-customization-preloaded" type="application/json"><?php echo htmlspecialchars($preloaded_customization_rows_b64, ENT_QUOTES, 'UTF-8'); ?></script>
<script>
window.pfCustomizationPreloadedOrders = (() => {
    try {
        const el = document.getElementById('pf-customization-preloaded');
        if (!el) return [];
        const encoded = (el.textContent || '').trim();
        if (!encoded) return [];
        return JSON.parse(atob(encoded));
    } catch (e) {
        console.error('Failed to parse preloaded customization data', e);
        return [];
    }
})();
</script>
<script>
    (function registerStaffCustomizationManager() {
        let registered = false;

        function boot() {
            if (registered || typeof Alpine === 'undefined') return;
            registered = true;

            Alpine.data('joManager', function (defaultStatus) {
            defaultStatus = defaultStatus || 'ALL';
            return {
            ...printflowStaffServiceOrderModalMixin({
                async afterSvcMutation() { await this.loadOrders(); }
            }),
            statuses: <?php echo $isPosCustomizationView ? "['ALL', 'PENDING', 'COMPLETED', 'CANCELLED']" : "['ALL', 'PENDING', 'APPROVED', 'TO_PAY', 'TO_VERIFY', 'IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED', 'REJECTED', 'CANCELLED']"; ?>,
            activeStatus: defaultStatus || 'ALL',
            currentPage: 1,
            itemsPerPage: 15,
            orders: [],
            statusOverrides: {},
            isPosSimplifiedView: <?php echo $isPosCustomizationView ? 'true' : 'false'; ?>,
            showPosCompleteConfirmModal: false,
            posCompleteConfirmTarget: null,
            ordersVersion: 0,
            sortOrder: 'newest',
            sortOpen: false,
            filterOpen: false,
            machines: [],
            showDetailsModal: false,
            loadingDetails: false,
            showRevisionModal: false,
            revisionReasonSelect: '',
            revisionReasonText: '',
            revisionModalError: '',
            showRejectPaymentModal: false,
            rejectPaymentReasonSelect: '',
            rejectPaymentReasonText: '',
            rejectPaymentModalError: '',
            previewFile: null,
            currentJo: {},
            deepLinkExpectedStatus: '',
            deepLinkSourceOrderId: '',
            availableRolls: {},
            allInventoryItems: [],
            inventoryPollMs: 20000,
            ordersPollMs: 10000,
            newMaterialId: '',
            newMaterialQty: 1,
            materialQtyManuallyEdited: false,
            newMaterialHeight: 0,
            newMaterialRollId: '',
            newMaterialNotes: '',
            newMaterialMetadata: {lamination: '', lamination_roll_id: ''},
            pendingMaterials: [],
            availableRollsList: [],
            laminationItemsList: [],
            availableLamRollsList: [],
            impactPreview: null,
            search: '',
            jobPriceInput: 0,
            
            // ── Profile Image Fallback ───────────────────────────────────
            getProfileImage(image) {
                if (!image || image === 'null' || image === 'undefined') {
                    return (document.body.getAttribute('data-base-url') || '') + '/public/assets/uploads/profiles/default.png';
                }
                if (typeof image !== 'string') return (document.body.getAttribute('data-base-url') || '') + '/public/assets/uploads/profiles/default.png';
                image = image.trim();
                if (!image || image === 'null' || image === 'undefined') {
                    return (document.body.getAttribute('data-base-url') || '') + '/public/assets/uploads/profiles/default.png';
                }
                if (image.startsWith('http')) {
                    return image;
                }
                const base = document.body.getAttribute('data-base-url') || '';
                if (image.startsWith(base + '/')) return image;
                if (image.startsWith('/public/') || image.startsWith('/uploads/')) return base + image;
                if (image.startsWith('public/') || image.startsWith('uploads/')) return base + '/' + image;
                if (image.startsWith('/')) return image;
                return base + '/public/assets/uploads/profiles/' + image.split('/').pop();
            },
            resolvePageUrl(path) {
                return new URL(path, window.location.href).toString();
            },
            adminApiUrl(path = '') {
                return this.resolvePageUrl(`../admin/${String(path).replace(/^\/+/, '')}`);
            },
            staffApiUrl(path = '') {
                return this.resolvePageUrl(`../staff/${String(path).replace(/^\/+/, '')}`);
            },

            getItemCount(name, list) {
                if (!list || !Array.isArray(list)) return 0;
                return list.filter(m => (m.item_name || m.name) === name).length;
            },

            get groupedMaterials() {
                if (!this.currentJo || !this.currentJo.materials) return [];
                const grouped = [];
                this.currentJo.materials.forEach(m => {
                    const existing = grouped.find(g => g.item_id === m.item_id);
                    const hasDeductedAt = !!(m && m.deducted_at && String(m.deducted_at).trim() !== '');
                    if (existing) {
                        existing.quantity = (parseFloat(existing.quantity) || 0) + (parseFloat(m.quantity) || 0);
                        existing.computed_required_length_ft = (parseFloat(existing.computed_required_length_ft) || 0) + (parseFloat(m.computed_required_length_ft) || 0);
                        // Preserve deducted badge if any merged assignment line was already deducted.
                        if (!existing.deducted_at && hasDeductedAt) {
                            existing.deducted_at = m.deducted_at;
                        }
                    } else {
                        grouped.push({ ...m });
                    }
                });
                return grouped;
            },

            get materialsDeductedSummary() {
                if (!this.currentJo || !this.currentJo.materials || this.currentJo.materials.length === 0) {
                    return "No production materials are recorded for this job yet.";
                }
                const deductedMaterials = this.currentJo.materials.filter(m => !!m.deducted_at);
                if (deductedMaterials.length === 0) {
                    return "Production materials are assigned, but inventory deduction is still pending.";
                }
                const counts = {};
                deductedMaterials.forEach(m => {
                    const name = m.item_name;
                    const q = parseFloat(m.track_by_roll == 1 ? m.computed_required_length_ft : m.quantity) || 0;
                    counts[name] = (counts[name] || 0) + q;
                });
                const summary = Object.entries(counts).map(([name, count]) => {
                    const cleanCount = Number(Number(count).toFixed(2));
                    return `${cleanCount}x ${name}`;
                }).join(", ");
                return summary + " deducted from inventory.";
            },
            getInventoryItem(itemId) {
                return this.allInventoryItems.find(i => String(i.id) === String(itemId)) || null;
            },
            normalizeMaterialQtyValue(value, fallback = 1) {
                const parsed = parseFloat(value);
                if (!Number.isFinite(parsed)) return fallback;
                return parsed < 1 ? 1 : parsed;
            },
            isPcsMaterial(itemId) {
                const item = this.getInventoryItem(itemId);
                const uom = String(item?.unit_of_measure || '').trim().toLowerCase();
                return uom === 'pcs' || uom === 'pc' || uom === 'piece' || uom === 'pieces';
            },
            getMaterialEntryUom(itemId) {
                const item = this.getInventoryItem(itemId);
                if (!item) return 'pcs';
                if (this.isRollTracked(itemId)) {
                    return item.unit_of_measure || 'ft';
                }
                if (this.isSticker(itemId)) {
                    return 'pcs';
                }
                return item.unit_of_measure || 'pcs';
            },
            getDefaultMaterialQty(itemId) {
                if (!this.isPcsMaterial(itemId)) return 1;
                return this.normalizeMaterialQtyValue(this.currentJo?.quantity || 1, 1);
            },
            handleMaterialSelection(selectedId) {
                this.newMaterialId = selectedId;
                this.newMaterialRollId = '';
                this.availableRollsList = [];
                this.materialQtyManuallyEdited = false;
                
                // Auto-fill from job dimensions if available
                if (this.currentJo && selectedId) {
                    const jH = parseFloat(this.currentJo.height_ft || 0);
                    const jW = parseFloat(this.currentJo.width_ft || 0);
                    const jQ = parseFloat(this.currentJo.quantity || 1);
                    
                    if (this.isTarpaulin(selectedId)) {
                        // For Tarpaulin, qty is width, height is height (heuristic)
                        // This ensures 'Req:' is not 0.00
                        this.newMaterialQty = jW || 1;
                        this.newMaterialHeight = jH || 1;
                    } else if (this.isRollTracked(selectedId)) {
                         // Default to max dimension for roll-tracked length
                         this.newMaterialQty = Math.max(jH, jW) || 1;
                         this.newMaterialHeight = 0;
                    } else {
                        this.newMaterialQty = jQ || 1;
                        this.newMaterialHeight = 0;
                    }
                } else {
                    this.newMaterialQty = this.getDefaultMaterialQty(selectedId);
                    this.newMaterialHeight = 0;
                }

                if (this.isRollTracked(selectedId)) {
                    this.loadAvailableRolls(selectedId);
                }
            },
            handleMaterialQtyInput(value) {
                this.materialQtyManuallyEdited = true;
                this.newMaterialQty = this.normalizeMaterialQtyValue(value, 1);
            },
            getMaterialRequiredStock(itemId, qty = 0, height = 0) {
                const parsedQty = parseFloat(qty || 0);
                if (parsedQty <= 0) return 0;
                if (this.isTarpaulin(itemId)) {
                    const parsedHeight = parseFloat(height || 0);
                    return parsedHeight > 0 ? parsedQty * parsedHeight : parsedQty;
                }
                return parsedQty;
            },
            getQueuedMaterialRequiredStock(itemId) {
                return this.pendingMaterials
                    .filter(m => String(m.item_id) === String(itemId))
                    .reduce((sum, m) => sum + this.getMaterialRequiredStock(m.item_id, m.qty, m.metadata?.height_ft || 0), 0);
            },
            get selectedMaterialStockError() {
                if (!this.newMaterialId) return '';
                const item = this.getInventoryItem(this.newMaterialId);
                if (!item) return '';
                const available = parseFloat(item.current_stock || 0);
                const queued = this.getQueuedMaterialRequiredStock(this.newMaterialId);
                const needed = this.getMaterialRequiredStock(this.newMaterialId, this.newMaterialQty, this.newMaterialHeight);
                if (parseFloat(this.newMaterialQty || 0) < 1) {
                    return 'Material quantity must be at least 1.';
                }
                if (needed <= 0) return '';
                const remaining = Math.max(0, available - queued);
                if (needed > remaining) {
                    return `${item.name} has only ${remaining} ${item.unit_of_measure || 'pcs'} left in this branch, but ${needed} is needed.`;
                }
                return '';
            },
            getInkInputValue(color) {
                const map = {
                    RED: parseFloat(this.inkRed || 0),
                    BLUE: parseFloat(this.inkBlue || 0),
                    BLACK: parseFloat(this.inkBlack || 0),
                    YELLOW: parseFloat(this.inkYellow || 0),
                };
                return map[color] || 0;
            },
            formatInkQuantity(value) {
                const parsed = parseFloat(value);
                if (!Number.isFinite(parsed)) return '0.0';
                return parsed.toFixed(1);
            },
            isLiterBasedInkItem(item) {
                if (!item) return false;
                const uom = String(item.unit_of_measure || '').trim().toLowerCase();
                const category = String(item.category_name || '').trim().toUpperCase();
                const name = String(item.name || '').trim().toUpperCase();
                if (uom === 'l' || uom === 'liter' || uom === 'liters' || uom.includes('liter') || uom.includes('(l)')) {
                    return true;
                }
                if (category === 'INK L120' || category === 'INK L130') {
                    return true;
                }
                return name.includes('INK L120') || name.includes('INK L130');
            },
            getInkStockMeta(item) {
                if (!item) {
                    return { availableMl: 0, displayStock: '0 ml' };
                }
                const stock = parseFloat(item.current_stock || 0);
                if (stock <= 0) {
                    return { availableMl: 0, displayStock: '0 ml' };
                }
                if (this.isLiterBasedInkItem(item)) {
                    const availableMl = stock * 1000;
                    return {
                        availableMl,
                        displayStock: `${stock} L (${availableMl} ml)`
                    };
                }
                return {
                    availableMl: stock,
                    displayStock: `${stock} ${item.unit_of_measure || 'ml'}`
                };
            },
            get inkStockIssues() {
                if (!this.useInk || !this.inkCategorySelected || !this.inkTypes[this.inkCategorySelected]) return [];
                const mapped = this.inkTypes[this.inkCategorySelected];
                const issues = [];
                ['RED', 'BLUE', 'BLACK', 'YELLOW'].forEach(color => {
                    const needed = this.getInkInputValue(color);
                    if (needed <= 0) return;
                    const item = this.getInventoryItem(mapped[color]);
                    const stockMeta = this.getInkStockMeta(item);
                    if (needed > stockMeta.availableMl) {
                        issues.push(`${color} ink has only ${stockMeta.displayStock} available in this branch, but ${needed} ml is needed.`);
                    }
                });
                return issues;
            },
            get approvalStockErrors() {
                const errors = [];
                if (this.selectedMaterialStockError) errors.push(this.selectedMaterialStockError);
                this.pendingMaterials.forEach(pm => {
                    const item = this.getInventoryItem(pm.item_id);
                    if (!item) return;
                    const available = parseFloat(item.current_stock || 0);
                    const needed = this.getQueuedMaterialRequiredStock(pm.item_id);
                    if (needed > available) {
                        const message = `${item.name} has only ${available} ${item.unit_of_measure || 'pcs'} available in this branch, but queued materials need ${needed}.`;
                        if (!errors.includes(message)) errors.push(message);
                    }
                });
                this.inkStockIssues.forEach(issue => {
                    if (!errors.includes(issue)) errors.push(issue);
                });
                return errors;
            },
             
            // Ink Settings
            inkCategorySelected: '',
            inkBlue: '',
            inkRed: '',
            inkBlack: '',
            inkYellow: '',
            useInk: false,
            materialSearch: '',
            dateFilter: 'ALL',
            serviceFilter: 'ALL',
            customDateFrom: '',
            customDateTo: '',
            actionBusy: false,
            footerActionError: '',
            alertModal: {
                show: false,
                title: 'System Message',
                message: '',
                onClose: null
            },
            showStaffAlert(title, message, onClose = null) {
                this.alertModal.title = title;
                this.alertModal.message = message;
                this.alertModal.onClose = onClose;
                this.alertModal.show = true;
            },
            closeStaffAlert() {
                const cb = this.alertModal.onClose;
                this.alertModal.show = false;
                if (typeof cb === 'function') cb();
            },
            applyPosSetPriceDeepLinkOverride(row) {
                if (!row || !this.deepLinkExpectedStatus) return row;
                const expected = String(this.deepLinkExpectedStatus || '').toUpperCase();
                const current = String(row.status || '').toUpperCase();
                const source = String(row.order_source || '').toLowerCase();
                const sourceOrderId = String(this.deepLinkSourceOrderId || '').trim();
                const rowOrderId = String(row.order_id || '').trim();
                const isPos = source === 'pos' || source === 'walk-in';
                const isMatchingOrder = sourceOrderId !== '' && rowOrderId !== '' && sourceOrderId === rowOrderId;
                const isPendingLike = ['PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'FOR_REVISION'].includes(current);

                if (expected === 'APPROVED' && isPos && isMatchingOrder && isPendingLike) {
                    return { ...row, status: 'APPROVED' };
                }

                return row;
            },
            clearDeepLinkParams() {
                try {
                    const url = new URL(window.location.href);
                    ['order_id', 'status', 'job_type', 'source_order_id', 'return_to_pos'].forEach(key => url.searchParams.delete(key));
                    window.history.replaceState({}, document.title, url.toString());
                } catch (e) {
                    console.warn('Unable to clear customization deep-link params', e);
                }
            },
            closeDetailsModal() {
                this.showDetailsModal = false;
                this.footerActionError = '';
                this.clearDeepLinkParams();
            },
            setFooterActionError(message) {
                this.footerActionError = message || '';
            },
            clearFooterActionError() {
                this.footerActionError = '';
            },
            normalizeCustomerType(customerType, transactionCount = null) {
                const raw = String(customerType || '').trim().toUpperCase();
                if (raw === 'REGULAR' || raw === 'RETURNING') return 'REGULAR';
                if (raw === 'NEW') return 'NEW';
                if (raw) return raw;
                const tx = Number(transactionCount || 0);
                return tx >= 5 ? 'REGULAR' : 'NEW';
            },
            beginModalAction() {
                if (this.actionBusy) return false;
                this.clearFooterActionError();
                this.actionBusy = true;
                return true;
            },
            endModalAction() {
                this.actionBusy = false;
            },
            bumpOrdersVersion() {
                this.ordersVersion++;
            },
            statusOverrideKey(row) {
                return this.orderGroupKey(row);
            },
            setStatusOverride(row, status) {
                const key = this.statusOverrideKey(row);
                this.statusOverrides[key] = {
                    status,
                    ts: Date.now()
                };
            },
            statusPriority(row) {
                const raw = String(row?.status || '').trim().toUpperCase().replace(/\s+/g, '_');
                const priorities = {
                    REJECTED: 100,
                    CANCELLED: 95,
                    COMPLETED: 90,
                    TO_RECEIVE: 80,
                    READY_TO_COLLECT: 80,
                    IN_PRODUCTION: 70,
                    PROCESSING: 70,
                    PRINTING: 70,
                    TO_VERIFY: 60,
                    VERIFY_PAY: 60,
                    PENDING_VERIFICATION: 60,
                    DOWNPAYMENT_SUBMITTED: 60,
                    TO_PAY: 50,
                    APPROVED: 40,
                    PENDING: 30
                };
                return priorities[raw] || 0;
            },
            typePriority(row) {
                const type = String(row?.order_type || 'JOB').toUpperCase();
                const priorities = {
                    ORDER: 4,
                    CUSTOMIZATION: 3,
                    JOB: 2,
                    SERVICE: 1
                };
                return priorities[type] || 0;
            },
            orderGroupKey(row) {
                const oid = row?.order_id ?? row?.id ?? null;
                if (oid != null && oid !== '') {
                    return `ORDER:${oid}`;
                }
                return `${String(row?.order_type || 'JOB').toUpperCase()}:${row?.id ?? ''}`;
            },
            isGenericServiceLabel(value) {
                const raw = String(value || '').trim().toUpperCase();
                if (!raw) return true;
                return [
                    'CUSTOM ORDER',
                    'CUSTOM SERVICE',
                    'SERVICE',
                    'SERVICE ORDER',
                    'ORDER ITEM',
                    'STANDARD PRODUCT',
                    'GENERAL'
                ].includes(raw);
            },
            descriptiveTypePriority(row) {
                const type = String(row?.order_type || 'JOB').toUpperCase();
                const priorities = {
                    CUSTOMIZATION: 4,
                    ORDER: 3,
                    JOB: 2,
                    SERVICE: 1
                };
                return priorities[type] || 0;
            },
            mergeGroupedRows(existing, incoming) {
                const existingTs = existing?._ts || 0;
                const incomingTs = incoming?._ts || 0;
                let winner = existing;
                let loser = incoming;

                if (incomingTs > existingTs) {
                    winner = incoming;
                    loser = existing;
                } else if (incomingTs === existingTs) {
                    const statusDiff = this.statusPriority(incoming) - this.statusPriority(existing);
                    if (statusDiff > 0 || (statusDiff === 0 && this.typePriority(incoming) > this.typePriority(existing))) {
                        winner = incoming;
                        loser = existing;
                    }
                }

                const merged = { ...winner };
                const winnerTypePriority = this.descriptiveTypePriority(winner);
                const loserTypePriority = this.descriptiveTypePriority(loser);
                const winnerService = String(winner?.service_type || '').trim();
                const loserService = String(loser?.service_type || '').trim();
                const winnerTitle = String(winner?.job_title || '').trim();
                const loserTitle = String(loser?.job_title || '').trim();

                const shouldUseLoserService =
                    loserService &&
                    (
                        this.isGenericServiceLabel(winnerService) ||
                        (!this.isGenericServiceLabel(loserService) && loserTypePriority > winnerTypePriority)
                    );
                if (shouldUseLoserService) {
                    merged.service_type = loserService;
                }

                const shouldUseLoserTitle =
                    loserTitle &&
                    (
                        !winnerTitle ||
                        this.isGenericServiceLabel(winnerTitle) ||
                        (!this.isGenericServiceLabel(loserTitle) && loserTypePriority > winnerTypePriority)
                    );
                if (shouldUseLoserTitle) {
                    merged.job_title = loserTitle;
                }

                if ((!merged.width_ft || merged.width_ft === '1') && loser?.width_ft && loser.width_ft !== '1') {
                    merged.width_ft = loser.width_ft;
                }
                if ((!merged.height_ft || merged.height_ft === '1') && loser?.height_ft && loser.height_ft !== '1') {
                    merged.height_ft = loser.height_ft;
                }
                if ((!Number(merged.quantity) || Number(merged.quantity) <= 1) && Number(loser?.quantity) > 1) {
                    merged.quantity = loser.quantity;
                }
                if (!merged.order_code && loser?.order_code) {
                    merged.order_code = loser.order_code;
                }
                if ((!merged.order_source || merged.order_source === 'customer') && loser?.order_source) {
                    merged.order_source = loser.order_source;
                }
                if (!merged.job_order_id && loser?.job_order_id) {
                    merged.job_order_id = loser.job_order_id;
                }
                if ((!merged.items || !merged.items.length) && Array.isArray(loser?.items) && loser.items.length) {
                    merged.items = loser.items;
                }

                return merged;
            },
            posDuplicateSignature(row) {
                if (!row) return '';
                const source = String(row.order_source || '').toLowerCase();
                if (!['pos', 'walk-in'].includes(source)) return '';

                const customer = String(row.customer_full_name || row.customer_name || '').trim().toLowerCase();
                const status = String(row.status || '').trim().toUpperCase();
                const created = String(row.created_at || row.order_date || '').trim().slice(0, 10);
                const rawLabel = String(row.service_type || row.job_title || '').trim().toLowerCase();
                const normalizedLabel = rawLabel
                    .replace(/\s+-\s+\d+\s*pcs?$/i, '')
                    .replace(/\bprint\/cut\b/gi, 'stickers decals')
                    .replace(/[^\w\s]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();

                if (!customer || !created || !normalizedLabel) {
                    return '';
                }

                return [customer, created, status, normalizedLabel].join('|');
            },
            dedupePosDuplicateRows(rows = []) {
                const deduped = new Map();
                rows.forEach((row) => {
                    const signature = this.posDuplicateSignature(row);
                    if (!signature) {
                        deduped.set(`row:${String(row.order_type || 'JOB')}:${row.id}:${row.order_id || ''}`, row);
                        return;
                    }

                    const existing = deduped.get(signature);
                    if (!existing) {
                        deduped.set(signature, row);
                        return;
                    }

                    const merged = this.mergeGroupedRows(existing, row);
                    deduped.set(signature, merged);
                });

                return Array.from(deduped.values());
            },
            getDisplayOrderCode(row) {
                if (!row) return '';
                const explicit = String(row.order_code || '').trim();
                if (explicit) return explicit;

                const type = String(row.order_type || 'JOB').toUpperCase();
                if (type === 'CUSTOMIZATION') {
                    return 'CUST-' + String(row.id || 0).padStart(5, '0');
                }
                if (type === 'JOB') {
                    return 'JO-' + String(row.id || 0).padStart(5, '0');
                }
                if (type === 'SERVICE') {
                    return 'SRV-' + String(row.id || 0).padStart(5, '0');
                }
                const orderId = row.order_id ?? row.id ?? 0;
                return 'ORD-' + String(orderId).padStart(5, '0');
            },
            formatCustomizationInfo(row) {
                if (!row) return 'Custom service';
                const width = String(row.width_ft ?? '').trim();
                const height = String(row.height_ft ?? '').trim();
                const quantity = Number(row.quantity || 0);
                const parts = [];

                if (width && height) {
                    parts.push(`${width}'×${height}'`);
                } else if (width) {
                    parts.push(width);
                }

                if (quantity > 0) {
                    parts.push(`${quantity} pcs`);
                }

                let base = parts.join(' • ') || 'Custom service';

                const first = row.items && row.items[0];
                const custom = first && first.customization && typeof first.customization === 'object' && !Array.isArray(first.customization)
                    ? first.customization
                    : null;
                if (custom) {
                    const noiseKeys = new Set([
                        'width', 'height', 'width_ft', 'height_ft', 'dimensions', 'service_type', 'service_id', 'product_id',
                        'source_page', 'source', 'branch_id', 'Branch_ID', 'notes', 'additional_notes'
                    ]);
                    const extras = [];
                    for (const [k, v] of Object.entries(custom)) {
                        if (v == null || v === '' || typeof v === 'object') continue;
                        const kl = String(k).toLowerCase();
                        if (noiseKeys.has(k) || noiseKeys.has(kl)) continue;
                        if (kl.includes('upload') || kl.includes('mime') || kl.includes('tmp') || kl.includes('_path')) continue;
                        const s = String(v).trim();
                        if (!s || s.length > 120) continue;
                        extras.push(s);
                        if (extras.length >= 4) break;
                    }
                    if (extras.length) {
                        base = [base, ...extras].filter(Boolean).join(' • ');
                    }
                }

                return base;
            },
            normalizeOrderRow(row) {
                const normalized = {
                    ...row,
                    customer_type: this.normalizeCustomerType(row?.customer_type, row?.transaction_count),
                    _ts: new Date(row?.updated_at || row?.created_at || row?.order_date || 0).getTime()
                };
                const override = this.statusOverrides[this.statusOverrideKey(normalized)] || null;
                const overrideMaxAgeMs = 15000;
                const overrideIsFresh = override && ((Date.now() - (override.ts || 0)) <= overrideMaxAgeMs);
                // Only apply a temporary optimistic override while it is very fresh
                // and newer than the server timestamp for this row.
                if (overrideIsFresh && override.status && (override.ts || 0) > (normalized._ts || 0)) {
                    normalized.status = override.status;
                    normalized._ts = Math.max(normalized._ts || 0, override.ts || 0);
                }
                return normalized;
            },
            verificationStockBlockMessage() {
                const readiness = String(this.currentJo?.readiness || '').toUpperCase();
                if (readiness === 'MISSING') {
                    return 'Cannot approve payment yet because required materials are missing in inventory.';
                }
                if (readiness === 'LOW') {
                    return 'Cannot approve payment yet because inventory stock is not enough for the required materials.';
                }
                return '';
            },
            canApproveVerification() {
                return this.verificationStockBlockMessage() === '';
            },
            prepareOrderRows(rows = []) {
                const grouped = new Map();
                rows.forEach((rawRow) => {
                    const row = this.normalizeOrderRow(rawRow);
                    const source = String(row.order_source || 'customer').toLowerCase();
                    const isPosSource = source === 'pos' || source === 'walk-in';
                    const staffRole = <?php echo json_encode($staffCustomizationRole); ?>;
                    if (staffRole === 'pos' && !isPosSource) {
                        return;
                    }
                    if (staffRole === 'online' && isPosSource) {
                        return;
                    }
                    const key = this.orderGroupKey(row);
                    const existing = grouped.get(key);
                    if (!existing) {
                        grouped.set(key, row);
                        return;
                    }
                    grouped.set(key, this.mergeGroupedRows(existing, row));
                });

                return this.dedupePosDuplicateRows(Array.from(grouped.values()))
                    .sort((a, b) => (b._ts || 0) - (a._ts || 0));
            },

            serviceMapping: {
                'TARPAULIN PRINTING': { categories: [2], ink: 'TARP' },
                'T-SHIRT PRINTING': { categories: [7], ink: ['L120', 'L130'] },
                'DECALS/STICKERS (PRINT/CUT)': { categories: [3, 8], ink: ['L120', 'L130'] },
                'GLASS/WALL STICKERS': { categories: [3, 8], ink: ['L120', 'L130'] },
                'TRANSPARENT STICKERS': { categories: [3], ink: ['L120', 'L130'] },
                'REFLECTORIZED': { categories: [3], ink: ['L120', 'L130'] },
                'SINTRA BOARD': { categories: [3], ink: ['L120', 'L130'] },
                'SOUVENIRS': { categories: [3, 1], ink: ['L120', 'L130'] }
            },

            inkTypes: {
                'TARP': { 'BLUE': 24, 'RED': 25, 'BLACK': 26, 'YELLOW': 27 },
                'L120': { 'BLUE': 28, 'RED': 29, 'BLACK': 30, 'YELLOW': 31 },
                'L130': { 'BLUE': 32, 'RED': 33, 'BLACK': 34, 'YELLOW': 35 }
            },

            isGenericServiceLabel(value) {
                const t = String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
                if (!t) return true;
                if (['order item', 'custom order', 'customer order', 'service order', 'custom service', 'standard product', 'service item'].includes(t)) {
                    return true;
                }
                if (t.startsWith('order item ') || t.startsWith('order item-')) {
                    return true;
                }
                return false;
            },
            getDynamicProductName(item) {
                const fallbackCustom = this.currentJo && this.currentJo.customization_details && typeof this.currentJo.customization_details === 'object'
                    ? this.currentJo.customization_details
                    : {};
                const itemCustom = (item && item.customization && typeof item.customization === 'object' && !Array.isArray(item.customization))
                    ? item.customization
                    : {};
                const custom = Object.keys(itemCustom).length > 0 ? itemCustom : fallbackCustom;
                const fromCustomLine = String(custom?.service_type || custom?.product_type || '').trim();
                if (fromCustomLine && !this.isGenericServiceLabel(fromCustomLine)) {
                    return fromCustomLine;
                }
                const productName = String(item?.product_name || '').trim();
                if (productName && !this.isGenericServiceLabel(productName)) {
                    return productName;
                }
                const explicitService = String(custom?.service_type || custom?.product_type || item?.product_name || '').trim();
                if (explicitService) {
                    return explicitService;
                }
                
                // Helper to safely find a key value case-insensitively
                const findKey = (searchKeys) => {
                    for (const [k, v] of Object.entries(custom)) {
                        const lowerK = k.toLowerCase().replace(/_/g, ' ');
                        const lowerS = searchKeys.map(s => s.toLowerCase().replace(/_/g, ' '));
                        if (lowerS.includes(lowerK) && v) return v;
                    }
                    return null;
                };

                const sintraVal = findKey(['sintra_type', 'sintra type', 'type']);
                if (sintraVal || findKey(['is_standee', 'thickness', 'sintraboard_thickness'])) {
                    return 'Sintra Board - ' + (sintraVal || 'Standee');
                }

                const tarpVal = findKey(['tarp_size', 'tarp size']);
                if (tarpVal) {
                    return 'Tarpaulin Printing - ' + tarpVal;
                }

                const width = findKey(['width']);
                const height = findKey(['height']);
                if (width && height && findKey(['finish', 'with_eyelets'])) {
                    return 'Tarpaulin Printing (' + width + 'x' + height + ' ft)';
                }

                if (findKey(['vinyl_type', 'print_placement', 'tshirt_color', 'shirt_color', 'tshirt_size', 'shirt_source'])) {
                    return 'T-Shirt Printing';
                }

                const stickerVal = findKey(['sticker_type', 'sticker type', 'shape', 'cut_type']);
                if (stickerVal) {
                    return 'Decals/Stickers (Print/Cut) - ' + stickerVal;
                }

                return item.product_name || 'Standard Product';
            },
            getCorrectServiceType(jo) {
                if (!jo) return '';
                const head = String(jo.service_type || jo.job_title || '').trim();
                if (head && !this.isGenericServiceLabel(head)) {
                    return head;
                }
                if (jo.items && jo.items.length > 0) {
                    for (const item of jo.items) {
                        const pn = String(item?.product_name || '').trim();
                        if (pn && !this.isGenericServiceLabel(pn)) {
                            return pn;
                        }
                    }
                    const fallbackCustom = jo.customization_details && typeof jo.customization_details === 'object'
                        ? jo.customization_details
                        : {};
                    for (const item of jo.items) {
                        const itemCustom = (item && item.customization && typeof item.customization === 'object' && !Array.isArray(item.customization))
                            ? item.customization
                            : {};
                        const custom = Object.keys(itemCustom).length > 0 ? itemCustom : fallbackCustom;
                        const explicit = String(custom?.service_type || custom?.product_type || item?.product_name || '').trim();
                        if (explicit) return explicit;
                        const findKey = (searchKeys) => {
                            for (const [k, v] of Object.entries(custom)) {
                                const lowerK = k.toLowerCase().replace(/_/g, ' ');
                                const lowerS = searchKeys.map(s => s.toLowerCase().replace(/_/g, ' '));
                                if (lowerS.includes(lowerK) && v) return true;
                            }
                            return false;
                        };

                        // User Priority Logic
                        if (findKey(['sintra_type', 'sintra type', 'is_standee', 'type', 'thickness', 'sintraboard_thickness'])) return 'SINTRA BOARD';
                        if (findKey(['tarp_size', 'tarp size', 'with_eyelets', 'finish'])) return 'TARPAULIN PRINTING';
                        if (findKey(['width']) && findKey(['height']) && findKey(['finish', 'with_eyelets'])) return 'TARPAULIN PRINTING';
                        if (findKey(['vinyl_type', 'print_placement', 'tshirt_color', 'shirt_color', 'shirt_source'])) return 'T-SHIRT PRINTING';
                        if (findKey(['sticker_type', 'sticker type', 'shape', 'cut_type', 'lamination'])) return 'DECALS/STICKERS (PRINT/CUT)';
                    }
                }
                const raw = String(jo.service_type || jo.job_title || '').trim();
                return raw || 'Custom Service';
            },

            customFieldLabels: {
                size: 'Size', color: 'Color', shirt_color: 'Color', print_placement: 'Placement',
                design_type: 'Design Type', template: 'Template', width: 'Width (ft)', height: 'Height (ft)',
                finish: 'Finish', with_eyelets: 'Eyelets', shape: 'Shape', waterproof: 'Waterproof',
                lamination: 'Lamination', laminate_option: 'Lamination Option', layout: 'Layout',
                dimensions: 'Dimensions', needed_date: 'Needed Date', notes: 'Notes', additional_notes: 'Notes',
                tshirt_provider: 'T-Shirt Provider', shirt_source: 'Shirt Source', brand: 'Brand',
                material: 'Material', surface_application: 'Surface', surface_type: 'Surface Type',
                sintraboard_thickness: 'Thickness', is_standee: 'Standee', sticker_type: 'Sticker Type',
                sintra_type: 'Type',
                cut_type: 'Cut Type', thickness: 'Thickness', installation_fee: 'Installation Fee',
                design_upload: 'Design upload', reference_upload: 'Reference upload',
                design_upload_path: 'Design file path', reference_upload_path: 'Reference file path',
                product_type: 'Product / variant'
            },
            // Redundant / internal keys to skip in the customization grid.
            // notes and additional_notes are handled separately in the yellow 'Order Notes' box.
            customFieldSkip: [
                'Branch_ID', 'branch_id',
                'service_type', 'service_id', 'product_id',
                'source_page', 'source',
                'notes', 'additional_notes', 'layout_file', 'reference_file',
                'design_tmp_path', 'reference_tmp_path', 'design_mime', 'reference_mime',
                'cart_key', '_cart_key', 'config_id', 'form_type'
            ],
            getDisplayableCustom(custom, item = null) {
                let sourceCustom = custom;
                const fallbackCustom = this.currentJo && this.currentJo.customization_details && typeof this.currentJo.customization_details === 'object'
                    ? this.currentJo.customization_details
                    : null;

                if (!sourceCustom || typeof sourceCustom !== 'object' || Array.isArray(sourceCustom) || Object.keys(sourceCustom).length === 0) {
                    if (fallbackCustom && !Array.isArray(fallbackCustom) && Object.keys(fallbackCustom).length > 0) {
                        sourceCustom = fallbackCustom;
                    }
                } else if (
                    fallbackCustom &&
                    !Array.isArray(fallbackCustom) &&
                    Object.keys(fallbackCustom).length > 0
                ) {
                    const targetOrderItemId = Number(this.currentJo?.order_item_id || 0);
                    const itemOrderItemId = Number(item?.order_item_id || 0);
                    const shouldMergeFallback =
                        (targetOrderItemId > 0 && itemOrderItemId > 0 && targetOrderItemId === itemOrderItemId)
                        || (targetOrderItemId <= 0 && Array.isArray(this.currentJo?.items) && this.currentJo.items.length === 1);

                    if (shouldMergeFallback) {
                        sourceCustom = { ...fallbackCustom, ...sourceCustom };
                    }
                }
                if (!sourceCustom || typeof sourceCustom !== 'object' || Array.isArray(sourceCustom)) return [];
                const isDetail = !!this.showDetailsModal;
                const skip = isDetail 
                    ? ['design_tmp_path', 'reference_tmp_path', 'design_mime', 'reference_mime', 'cart_key', '_cart_key', 'config_id', 'form_type', 'layout_file', 'reference_file', 'source_page', 'source'] 
                    : this.customFieldSkip;
                
                return Object.entries(sourceCustom).filter(([k, v]) => {
                    if (v === '' || v == null) return false;
                    if (skip.includes(k)) return false;
                    if (typeof v === 'string' && v.length > 2000) return false;
                    if (isDetail && item) {
                        const dupDesignKeys = ['design_upload', 'design_upload_path', 'upload_design', 'upload_design_path', 'design_file'];
                        if (dupDesignKeys.includes(k) && this.staffEffectiveDesignOpenUrl(item)) {
                            return false;
                        }
                        const lk = String(k).toLowerCase().replace(/\s+/g, '_');
                        if ((lk.includes('payment') && lk.includes('proof')) || ['payment_proof', 'payment_upload', 'proof_of_payment'].includes(lk)) {
                            if (this.staffPaymentProofSrc(this.currentJo)) return false;
                        }
                    }
                    return true;
                });
            },
            getCustomLabel(k) {
                return this.customFieldLabels[k] || k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            },
            formatCustomValuePlain(v) {
                if (v == null) return '';
                if (typeof v === 'object') return JSON.stringify(v);
                return String(v);
            },
            isDisplayableLink(v) {
                if (v == null || typeof v === 'object') return false;
                const s = String(v).trim();
                if (s.length < 2) return false;
                if (/^https?:\/\//i.test(s)) return true;
                return s.startsWith('/');
            },
            sanitizeStaffLink(v) {
                const s = String(v).trim();
                if (/^https?:\/\//i.test(s)) return s;
                if (s.startsWith('/')) return s;
                return '#';
            },
            staffResolveMediaUrl(raw) {
                if (raw == null || raw === '') return '';
                const s = String(raw).trim();
                if (!s) return '';
                if (/^https?:\/\//i.test(s)) return s;
                const base = document.body.getAttribute('data-base-url') || '';
                if (s.startsWith('/')) return base + s;
                return base + '/' + s.replace(/^\/+/, '');
            },
            staffPaymentProofSrc(jo) {
                if (!jo) return '';
                const raw = (jo.payment_proof_path || jo.payment_proof || '').trim();
                return raw ? this.staffResolveMediaUrl(raw) : '';
            },
            staffFilenameLooksLikeImage(name) {
                if (!name) return false;
                return /\.(jpe?g|png|gif|webp|bmp|svg|avif)$/i.test(String(name));
            },
            staffDesignShowsAsImage(item) {
                if (!item) return false;
                if (item.design_is_image) return true;
                return this.staffFilenameLooksLikeImage(item.design_name);
            },
            /** Fallback when API omitted design_open_url but line item has stored artwork + filename */
            staffOrderItemDesignServeUrl(item) {
                if (!item || !item.order_item_id) return '';
                const id = Number(item.order_item_id);
                if (!id) return '';
                const base = document.body.getAttribute('data-base-url') || '';
                return base + '/public/serve_design.php?type=order_item&id=' + id;
            },
            staffEffectiveDesignOpenUrl(item) {
                if (!item) return '';
                
                // Priority 1: Check for revision design URL (newly uploaded revision)
                const revisionUrl = (item.revision_design_url || '').trim();
                if (revisionUrl) return this.staffResolveMediaUrl(revisionUrl);
                
                // Priority 2: Check for standard design URL from API
                const fromApi = (item.design_open_url || item.design_url || '').trim();
                if (fromApi) return this.staffResolveMediaUrl(fromApi);
                
                // Priority 3: Fallback to serve_design.php if we have order_item_id and filename
                if (item.order_item_id && (this.staffFilenameLooksLikeImage(item.design_name) || item.design_is_image)) {
                    return this.staffOrderItemDesignServeUrl(item);
                }
                if (item.order_item_id && item.design_name) {
                    return this.staffOrderItemDesignServeUrl(item);
                }
                return '';
            },
            combinedCustomerNotes() {
                const j = this.currentJo;
                if (!j) return '';
                
                // Priority 1: Store order notes (Checkout notes)
                let note = (j.store_order_notes || '').trim();
                
                // Priority 2: Item-specific customization notes
                if (!note && j.items && j.items.length) {
                    for (const it of j.items) {
                        const itemCustom = (it && it.customization && typeof it.customization === 'object' && !Array.isArray(it.customization))
                            ? it.customization
                            : {};
                        const fallbackCustom = j.customization_details && typeof j.customization_details === 'object'
                            ? j.customization_details
                            : {};
                        const c = Object.keys(itemCustom).length > 0 ? itemCustom : fallbackCustom;
                        const n = (c.notes || c.additional_notes || '').trim();
                        if (n) { 
                            note = n; 
                            break; 
                        }
                    }
                }
                
                // Priority 3: Production/Job notes (filter out Revision history)
                if (!note && j.notes) {
                    const lines = j.notes.split('\n')
                        .filter(l => !l.includes('[REVISION REQUEST]'))
                        .map(l => l.trim())
                        .filter(l => l !== '');
                    note = lines.join('\n');
                }

                return note || '';
            },
            isPendingReviewStatus(jo) {
                if (!jo) return false;
                const s = String(jo.status || '');
                const u = s.toUpperCase().replace(/\s+/g, '_');
                if (['PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'FOR_REVISION'].includes(u)) return true;
                return ['Pending Review', 'Pending Approval', 'For Revision'].includes(s);
            },

            /** Row is in payment-verification stage — strictly status-based only. */
            isVerifyStageRow(row) {
                if (!row) return false;
                const s = String(row.status || '').toUpperCase().replace(/\s+/g, '_');
                return s === 'VERIFY_PAY' || s === 'TO_VERIFY' || s === 'PENDING_VERIFICATION' || s === 'DOWNPAYMENT_SUBMITTED';
            },

            /** Store/job row is actively in production — strictly status-based only. */
            isInProductionRow(row) {
                if (!row) return false;
                const raw = String(row.status || '').trim();
                const t = raw.toUpperCase().replace(/\s+/g, '_');
                if (t === 'IN_PRODUCTION' || t === 'PROCESSING' || t === 'PRINTING') return true;
                if (/PAID[-–_\s]+IN[-–_\s]+PROCESS/i.test(raw)) return true;
                return false;
            },

            /** Waiting for customer payment — strictly status-based only. */
            isToPayRow(row) {
                if (!row) return false;
                const s = String(row.status || '').toUpperCase().replace(/\s+/g, '_');
                return s === 'TO_PAY';
            },

            get availableMaterialsForCurrentOrder() {
                if (!this.currentJo || !this.allInventoryItems) return [];
                
                const serviceRaw = String(this.currentJo.service_type || this.currentJo.job_title || '').toUpperCase();
                let allowedCats = [];

                // Detect matching service from mapping
                for (const [key, map] of Object.entries(this.serviceMapping)) {
                    if (serviceRaw.includes(key)) {
                        allowedCats = map.categories;
                        break;
                    }
                }

                return this.allInventoryItems.filter(item => {
                    // MUST HAVE STOCK > 0
                    const stock = parseFloat(item.current_stock || 0);
                    if (stock <= 0) return false;

                    // If we found specific categories for this service, filter by them
                    if (allowedCats.length > 0) {
                        if (!allowedCats.includes(Number(item.category_id))) return false;
                    }

                    // Search filter
                    if (this.materialSearch && !item.name.toUpperCase().includes(this.materialSearch.toUpperCase())) {
                        return false;
                    }

                    return true;
                });
            },

            get availableInkOptionsForService() {
                if (!this.currentJo) return [];
                const serviceRaw = String(this.currentJo.service_type || this.currentJo.job_title || '').toUpperCase();
                for (const [key, map] of Object.entries(this.serviceMapping)) {
                    if (serviceRaw.includes(key)) {
                        return Array.isArray(map.ink) ? map.ink : [map.ink];
                    }
                }
                return ['L120', 'L130']; // Default
            },

            async init() {
                if (Array.isArray(window.pfCustomizationPreloadedOrders) && window.pfCustomizationPreloadedOrders.length > 0) {
                    const preloadedRows = this.prepareOrderRows(window.pfCustomizationPreloadedOrders);
                    this.orders = <?php echo $showLatestCustomizationOnly ? 'preloadedRows.slice(0, 1)' : 'preloadedRows'; ?>;
                    this.bumpOrdersVersion();
                }
                this.$watch('search', () => { this.currentPage = 1; });
                this.$watch('activeStatus', () => { this.currentPage = 1; });
                this.$watch('showDetailsModal', (isOpen) => {
                    if (!isOpen) this.clearDeepLinkParams();
                });
                await this.loadOrders();
                await this.loadMachines();
                await this.loadAllInventoryItems();

                // Keep stock values in sync with admin-side ledger deductions.
                // This page otherwise fetches `current_stock` only once on load and would show stale stock.
                if (!window.pfStaffCustomizationsInventoryPollListenerAttached) {
                    window.pfStaffCustomizationsInventoryPollListenerAttached = true;
                    document.addEventListener('turbo:before-cache', function () {
                        if (window.pfStaffCustomizationsInventoryPoll) {
                            clearInterval(window.pfStaffCustomizationsInventoryPoll);
                            window.pfStaffCustomizationsInventoryPoll = null;
                        }
                        if (window.pfStaffCustomizationsOrdersPoll) {
                            clearInterval(window.pfStaffCustomizationsOrdersPoll);
                            window.pfStaffCustomizationsOrdersPoll = null;
                        }
                    });
                }
                if (window.pfStaffCustomizationsInventoryPoll) {
                    clearInterval(window.pfStaffCustomizationsInventoryPoll);
                }
                window.pfStaffCustomizationsInventoryPoll = setInterval(() => {
                    this.loadAllInventoryItems().catch(() => {});
                }, this.inventoryPollMs);

                if (!window.pfStaffCustomizationsOrdersPollListenerAttached) {
                    window.pfStaffCustomizationsOrdersPollListenerAttached = true;
                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') {
                            this.loadOrders({ silent: true }).catch(() => {});
                        }
                    });
                    window.addEventListener('focus', () => {
                        this.loadOrders({ silent: true }).catch(() => {});
                    });
                }
                if (window.pfStaffCustomizationsOrdersPoll) {
                    clearInterval(window.pfStaffCustomizationsOrdersPoll);
                }
                window.pfStaffCustomizationsOrdersPoll = setInterval(() => {
                    this.loadOrders({ silent: true }).catch(() => {});
                }, this.ordersPollMs);
                
                // Auto-open modal if order_id is in URL
                const params = new URLSearchParams(window.location.search);
                const orderId = params.get('order_id');
                const sourceOrderId = params.get('source_order_id');
                const initialStatus = params.get('status');
                const returnToPOS = params.get('return_to_pos') === '1';
                this.deepLinkExpectedStatus = initialStatus ? initialStatus.toUpperCase().replace(/\s+/g, '_') : '';
                this.deepLinkSourceOrderId = sourceOrderId || '';

                if (initialStatus) {
                    // Map common statuses to tabs
                    const statusMap = this.isPosSimplifiedView
                        ? {
                            'PENDING': 'PENDING',
                            'PENDING_REVIEW': 'PENDING',
                            'APPROVED': 'PENDING',
                            'TO_PAY': 'PENDING',
                            'TO_VERIFY': 'PENDING',
                            'PENDING_VERIFICATION': 'PENDING',
                            'DOWNPAYMENT_SUBMITTED': 'PENDING',
                            'VERIFY_PAY': 'PENDING',
                            'IN_PRODUCTION': 'PENDING',
                            'PROCESSING': 'PENDING',
                            'PRINTING': 'PENDING',
                            'TO_RECEIVE': 'PENDING',
                            'READY_TO_COLLECT': 'PENDING',
                            'COMPLETED': 'COMPLETED',
                            'REJECTED': 'CANCELLED',
                            'CANCELLED': 'CANCELLED'
                        }
                        : {
                            'TO_VERIFY': 'TO_VERIFY',
                            'PENDING_VERIFICATION': 'TO_VERIFY',
                            'DOWNPAYMENT_SUBMITTED': 'TO_VERIFY',
                            'VERIFY_PAY': 'TO_VERIFY',
                            'TO_PAY': 'TO_PAY',
                            'PENDING': 'PENDING',
                            'PENDING_REVIEW': 'PENDING',
                            'APPROVED': 'APPROVED',
                            'PROCESSING': 'IN_PRODUCTION'
                        };
                    const mapped = statusMap[initialStatus.toUpperCase().replace(/\s+/g, '_')] || initialStatus;
                    if (this.statuses.includes(mapped)) {
                        this.activeStatus = mapped;
                    } else if (orderId) {
                        // If we have an order_id but the status doesn't match a tab, default to ALL to ensure it's found
                        this.activeStatus = 'ALL';
                    }
                } else if (returnToPOS && sourceOrderId) {
                    this.activeStatus = this.isPosSimplifiedView ? 'PENDING' : 'APPROVED';
                }

                if (orderId) {
                    const jobType = params.get('job_type') || 'JOB';
                    await this.viewDetails(parseInt(orderId, 10), jobType);
                    this.clearDeepLinkParams();
                } else if (sourceOrderId) {
                    const parsedSourceOrderId = parseInt(sourceOrderId, 10);
                    if (!Number.isNaN(parsedSourceOrderId) && parsedSourceOrderId > 0) {
                        const resolved = await this.parseJsonResponse(
                            await fetch(this.adminApiUrl(`job_orders_api.php?action=resolve_job_for_order&order_id=${encodeURIComponent(parsedSourceOrderId)}`))
                        );

                        if (resolved.success && resolved.job_id) {
                            await this.viewDetails(parseInt(resolved.job_id, 10), 'JOB');
                        } else {
                            await this.viewDetails(parsedSourceOrderId, 'ORDER');
                        }
                        this.clearDeepLinkParams();
                    }
                }
            },

            async loadOrders(options = {}) {
                const silent = !!options.silent;
                try {
                    // Drop stale optimistic overrides before applying freshly fetched rows.
                    const now = Date.now();
                    Object.keys(this.statusOverrides || {}).forEach((key) => {
                        const ts = Number(this.statusOverrides[key]?.ts || 0);
                        if (!ts || (now - ts) > 15000) {
                            delete this.statusOverrides[key];
                        }
                    });
                    const refreshToken = Date.now();
                    const [joRes, pendingRes] = await Promise.all([
                        fetch(`../admin/job_orders_api.php?action=list_orders&service_only=1&per_page=200&_=${refreshToken}`, { cache: 'no-store' }).then(r => this.parseJsonResponse(r)),
                        fetch(`../admin/job_orders_api.php?action=list_pending_orders&service_only=1&per_page=250&_=${refreshToken}`, { cache: 'no-store' }).then(r => this.parseJsonResponse(r)),
                    ]);

                    const jobOrders = joRes.success ? joRes.data : [];
                    const pendingOrders = pendingRes.success ? pendingRes.data : [];
                    // Include JOB rows plus store/service rows (ORDER, CUSTOMIZATION, SERVICE) so customer service
                    // checkout always appears even when job_orders is missing or filtered differently per API.
                    const combinedRows = [...jobOrders, ...pendingOrders];
                    const preparedRows = this.prepareOrderRows(combinedRows);
                    const visibleRows = <?php echo $showLatestCustomizationOnly ? 'preparedRows.slice(0, 1)' : 'preparedRows'; ?>;
                    this.orders = visibleRows;
                    this.bumpOrdersVersion();

                    if (this.orders.length === 0 && Array.isArray(window.pfCustomizationPreloadedOrders) && window.pfCustomizationPreloadedOrders.length > 0) {
                        const preloadedRows = this.prepareOrderRows(window.pfCustomizationPreloadedOrders);
                        this.orders = <?php echo $showLatestCustomizationOnly ? 'preloadedRows.slice(0, 1)' : 'preloadedRows'; ?>;
                        this.bumpOrdersVersion();
                    }
                } catch(err) {
                    if (!silent) {
                        console.error('Error loading orders:', err);
                    }
                    this.orders = Array.isArray(window.pfCustomizationPreloadedOrders)
                        ? <?php echo $showLatestCustomizationOnly ? 'this.prepareOrderRows(window.pfCustomizationPreloadedOrders).slice(0, 1)' : 'this.prepareOrderRows(window.pfCustomizationPreloadedOrders)'; ?>
                        : [];
                    this.bumpOrdersVersion();
                }
            },

            async loadMachines() {
                const res = await this.parseJsonResponse(
                    await fetch('../admin/job_orders_api.php?action=list_machines')
                );
                this.machines = res.success ? res.data : [];
            },

            sameId(a, b) {
                if (a == null || b == null) return false;
                return String(a) === String(b);
            },

            /** job_orders.id for API calls (handles ORDER rows where id is store order_id) */
            effectiveJobId() {
                const j = this.currentJo;
                if (!j) return null;
                if (j.order_type === 'ORDER') {
                    const jid = j.job_order_id;
                    return jid != null && jid !== '' ? Number(jid) : null;
                }
                if (j.order_type === 'CUSTOMIZATION') {
                    const jid = j.job_order_id;
                    return jid != null && jid !== '' ? Number(jid) : null;
                }
                return j.id != null && j.id !== '' ? Number(j.id) : null;
            },

            /** Resolves job_orders.id from store order_id when job_order_id was missing (API limit / older rows). */
            async resolveEffectiveJobId() {
                let jid = this.effectiveJobId();
                if (jid != null && !Number.isNaN(jid) && jid > 0) return jid;
                const j = this.currentJo;
                if (!j) return null;
                if (j.order_type === 'CUSTOMIZATION') {
                    const oid = j.order_id;
                    if (oid == null || oid === '') return null;
                    try {
                        const res = await this.parseJsonResponse(
                            await fetch(`../admin/job_orders_api.php?action=resolve_job_for_order&order_id=${encodeURIComponent(oid)}`)
                        );
                        if (res.success && res.job_id) {
                            this.currentJo.job_order_id = res.job_id;
                            await this.loadOrders({ silent: true });
                            return Number(res.job_id);
                        }
                    } catch (e) {
                        console.error('resolve_job_for_customization', e);
                    }
                    return null;
                }
                if (j.order_type !== 'ORDER') return null;
                const oid = j.order_id ?? j.id;
                if (oid == null || oid === '') return null;
                try {
                    const res = await this.parseJsonResponse(
                        await fetch(`../admin/job_orders_api.php?action=resolve_job_for_order&order_id=${encodeURIComponent(oid)}`)
                    );
                    if (res.success && res.job_id) {
                        this.currentJo.job_order_id = res.job_id;
                        await this.loadOrders();
                        return Number(res.job_id);
                    }
                } catch (e) {
                    console.error('resolve_job_for_order', e);
                }
                return null;
            },

            findOrder(id, orderType = 'JOB') {
                const normalizedType = orderType || 'JOB';
                const direct = this.orders.find(o => this.sameId(o.id, id) && (o.order_type || 'JOB') === normalizedType);
                if (direct) return direct;

                if (normalizedType === 'ORDER') {
                    return this.orders.find(o =>
                        (o.order_type || 'JOB') === 'ORDER' &&
                        (this.sameId(o.order_id, id) || this.sameId(o.job_order_id, id) || this.sameId(o.id, id))
                    );
                }

                if (normalizedType === 'JOB') {
                    return this.orders.find(o =>
                        (o.order_type || 'JOB') === 'JOB' &&
                        (this.sameId(o.id, id) || this.sameId(o.order_id, id))
                    );
                }

                return this.orders.find(o =>
                    this.sameId(o.id, id) || this.sameId(o.order_id, id) || this.sameId(o.job_order_id, id)
                );
            },

            getRowDisplayName(jo) {
                if (!jo) return 'Custom Service';
                const resolvedService = String(this.getCorrectServiceType(jo) || '').trim();
                const rawTitle = String(jo.job_title || '').trim();
                if (!rawTitle) {
                    return resolvedService || 'Custom Service';
                }
                if (!resolvedService) {
                    return rawTitle;
                }

                const titleUpper = rawTitle.toUpperCase();
                const serviceUpper = resolvedService.toUpperCase();
                const compatibilityMap = {
                    'REFLECTORIZED': ['REFLECTORIZED', 'SIGNAGE', 'GATE PASS', 'TEMPORARY PLATE', 'PLATE NUMBER'],
                    'TARPAULIN PRINTING': ['TARPAULIN', 'TARP'],
                    'T-SHIRT PRINTING': ['T-SHIRT', 'TSHIRT', 'SHIRT', 'VINYL'],
                    'DECALS/STICKERS (PRINT/CUT)': ['DECAL', 'STICKER', 'PRINT/CUT'],
                    'SINTRA BOARD': ['SINTRA', 'STANDEE'],
                    'SINTRABOARD STANDEES': ['SINTRA', 'STANDEE'],
                    'TRANSPARENT STICKERS': ['TRANSPARENT'],
                    'GLASS/WALL STICKERS': ['GLASS', 'WALL', 'FROSTED'],
                    'SOUVENIRS': ['SOUVENIR', 'MUG', 'KEYCHAIN', 'TOTE', 'TUMBLER']
                };

                const expectedTerms = compatibilityMap[serviceUpper] || [serviceUpper];
                const titleMatchesService = expectedTerms.some(term => titleUpper.includes(term));

                if (!titleMatchesService) {
                    return resolvedService;
                }

                return rawTitle;
            },
            getServiceFilterValue(jo) {
                const raw = this.getCorrectServiceType(jo).toUpperCase();
                if (raw.includes('T-SHIRT') || raw.includes('TSHIRT') || raw.includes('T SHIRT')) return 'T-SHIRT PRINTING';
                if (raw.includes('TARPAULIN')) return 'TARPAULIN PRINTING';
                if (raw.includes('TRANSPARENT STICKER') || raw.includes('TRANSPARENT')) return 'TRANSPARENT STICKER PRINTING';
                if (raw.includes('STICKER') || raw.includes('DECAL')) return 'DECALS/STICKERS (PRINT/CUT)';
                if (raw.includes('SINTRA')) return 'SINTRA BOARD';
                if (raw.includes('SOUVENIR')) return 'SOUVENIRS';
                if (raw.includes('REFLECTORIZED') || raw.includes('SIGNAGE')) return 'REFLECTORIZED SIGNAGE';
                return raw || 'OTHER';
            },
            isPosWalkInSource(jo) {
                return ['pos', 'walk-in'].includes(String(jo?.order_source || '').toLowerCase());
            },
            getPosWalkInBucket(jo) {
                const status = String(jo?.status || '').toUpperCase();
                if (status === 'COMPLETED') return 'COMPLETED';
                if (status === 'CANCELLED' || status === 'REJECTED') return 'CANCELLED';
                return 'PENDING';
            },
            getStatusLabel(jo) {
                if (this.isPosSimplifiedView && this.isPosWalkInSource(jo)) {
                    const bucket = this.getPosWalkInBucket(jo);
                    if (bucket === 'COMPLETED') return 'Completed';
                    if (bucket === 'CANCELLED') return 'Cancelled';
                    return 'Pending';
                }
                return jo.status === 'COMPLETED' ? 'Completed' :
                    (jo.status === 'APPROVED' ? 'Approved' :
                    (jo.status === 'TO_PAY' ? 'To Pay' :
                    (jo.status === 'VERIFY_PAY' ? 'To Verify' :
                    (jo.status === 'REJECTED' ? 'Rejected' :
                    (jo.status === 'IN_PRODUCTION' ? 'In Production' :
                    (jo.status === 'TO_RECEIVE' || jo.status === 'READY_TO_COLLECT' ? 'To Pickup' : jo.status))))));
            },
            getStatusBadgeClass(jo) {
                if (this.isPosSimplifiedView && this.isPosWalkInSource(jo)) {
                    const bucket = this.getPosWalkInBucket(jo);
                    return {
                        'badge-pending': bucket === 'PENDING',
                        'badge-fulfilled': bucket === 'COMPLETED',
                        'badge-cancelled': bucket === 'CANCELLED'
                    };
                }
                return {
                    'badge-fulfilled':  jo.status === 'COMPLETED',
                    'badge-approved':   jo.status === 'APPROVED',
                    'badge-topay':      jo.status === 'TO_PAY',
                    'badge-verify':     jo.status === 'VERIFY_PAY',
                    'badge-production': jo.status === 'IN_PRODUCTION',
                    'badge-pickup':     jo.status === 'TO_RECEIVE' || jo.status === 'READY_TO_COLLECT',
                    'badge-pending':    jo.status === 'PENDING',
                    'badge-cancelled':  jo.status === 'REJECTED' || jo.status === 'CANCELLED'
                };
            },
            isPosWalkInPending(jo) {
                return this.isPosSimplifiedView && this.isPosWalkInSource(jo) && this.getPosWalkInBucket(jo) === 'PENDING';
            },
            matchesStatusTab(jo, status) {
                if (this.isPosSimplifiedView && this.isPosWalkInSource(jo)) {
                    if (status === 'ALL') return true;
                    return this.getPosWalkInBucket(jo) === status;
                }
                if (status === 'ALL') return true;
                if (status === 'APPROVED') return jo.status === 'APPROVED';
                if (status === 'TO_VERIFY') return this.isVerifyStageRow(jo);
                if (status === 'TO_PAY') return this.isToPayRow(jo);
                if (status === 'IN_PRODUCTION') return this.isInProductionRow(jo);
                if (status === 'TO_RECEIVE') return jo.status === 'TO_RECEIVE' || jo.status === 'READY_TO_COLLECT';
                if (status === 'REJECTED') return jo.status === 'REJECTED';
                return jo.status === status;
            },
            matchesNonStatusFilters(jo) {
                if (this.serviceFilter !== 'ALL') {
                    const rowService = this.getServiceFilterValue(jo);
                    if (rowService !== this.serviceFilter) return false;
                }

                if (this.dateFilter !== 'ALL') {
                    const orderDate = new Date(jo.created_at || jo.order_date);
                    const now = new Date();

                    if (this.dateFilter === 'TODAY') {
                        if (orderDate.toDateString() !== now.toDateString()) return false;
                    } else if (this.dateFilter === 'WEEK') {
                        const lastWeek = new Date();
                        lastWeek.setDate(now.getDate() - 7);
                        if (orderDate < lastWeek) return false;
                    } else if (this.dateFilter === 'MONTH') {
                        if (orderDate.getMonth() !== now.getMonth() || orderDate.getFullYear() !== now.getFullYear()) return false;
                    } else if (this.dateFilter === 'CUSTOM') {
                        if (this.customDateFrom) {
                            const from = new Date(this.customDateFrom);
                            from.setHours(0,0,0,0);
                            if (orderDate < from) return false;
                        }
                        if (this.customDateTo) {
                            const to = new Date(this.customDateTo);
                            to.setHours(23,59,59,999);
                            if (orderDate > to) return false;
                        }
                    }
                }

                const searchRaw = (this.search || '').trim();
                const searchLower = searchRaw.toLowerCase();
                const codeMatch = searchRaw.match(/^(?:ORD|JO|CUST|SRV)[-#\s]*(\d+)$/i);
                const searchOrderIdNum = codeMatch ? codeMatch[1] : '';

                const matchSearch = !this.search ||
                    ((this.getDisplayOrderCode(jo) || '').toLowerCase().includes(searchLower)) ||
                    (jo.job_title && jo.job_title.toLowerCase().includes(searchLower)) ||
                    (jo.service_type && jo.service_type.toLowerCase().includes(searchLower)) ||
                    (((jo.first_name || '') + ' ' + (jo.last_name || '')).toLowerCase().includes(searchLower)) ||
                    (jo.id != null && jo.id.toString().includes(searchLower)) ||
                    (searchOrderIdNum && jo.order_id != null && String(jo.order_id) === searchOrderIdNum);
                return matchSearch;
            },

            get filteredOrders() {
                const filtered = this.orders.filter(jo => {
                    if (!this.matchesStatusTab(jo, this.activeStatus)) return false;
                    return this.matchesNonStatusFilters(jo);
                });

                // Sorting
                return filtered.sort((a, b) => {
                    if (this.sortOrder === 'oldest') {
                        return (a._ts || 0) - (b._ts || 0);
                    } else if (this.sortOrder === 'az') {
                        const nameA = ((a.first_name || '') + ' ' + (a.last_name || '')).toLowerCase();
                        const nameB = ((b.first_name || '') + ' ' + (b.last_name || '')).toLowerCase();
                        return nameA.localeCompare(nameB);
                    } else if (this.sortOrder === 'za') {
                        const nameA = ((a.first_name || '') + ' ' + (a.last_name || '')).toLowerCase();
                        const nameB = ((b.first_name || '') + ' ' + (b.last_name || '')).toLowerCase();
                        return nameB.localeCompare(nameA);
                    }
                    return (b._ts || 0) - (a._ts || 0); // newest (default)
                });
            },

            get paginatedOrders() {
                const start = (this.currentPage - 1) * this.itemsPerPage;
                const end = start + this.itemsPerPage;
                return this.filteredOrders.slice(start, end);
            },

            get totalPages() {
                return Math.ceil(this.filteredOrders.length / this.itemsPerPage);
            },

            get pageNumbers() {
                const total = this.totalPages;
                if (total <= 1) return [];
                let pages = [1];
                const windowSize = 3; // Increased window size for better accessibility
                for (let i = Math.max(2, this.currentPage - windowSize); i <= Math.min(total - 1, this.currentPage + windowSize); i++) {
                    pages.push(i);
                }
                if (total > 1 && !pages.includes(total)) pages.push(total);
                
                let uniquePages = [...new Set(pages)].sort((a,b) => a - b);
                let finalPages = [];
                let prev = null;
                for (let p of uniquePages) {
                    if (prev && p - prev > 1) finalPages.push('...');
                    finalPages.push(p);
                    prev = p;
                }
                return finalPages;
            },

            getStatusCount(status) {
                void this.ordersVersion;
                return this.orders.filter(o => this.matchesNonStatusFilters(o) && this.matchesStatusTab(o, status)).length;
            },

            async viewDetails(id, orderType = 'JOB') {
                let order = this.findOrder(id, orderType);
                if (orderType === 'SERVICE' || order?.order_type === 'SERVICE') {
                    await this.openSvcModal(id);
                    return;
                }

                this.showDetailsModal = true;
                this.loadingDetails = true;
                this.footerActionError = '';
                this.currentJo = {};
                
                if (orderType === 'CUSTOMIZATION') {
                    // Fetch customization entry details
                    try {
                        const detailRes = await this.parseJsonResponse(
                            await fetch(this.adminApiUrl(`job_orders_api.php?action=get_customization&id=${id}`))
                        );
                        if (detailRes.success) {
                            this.currentJo = this.applyPosSetPriceDeepLinkOverride({ ...detailRes.data, order_type: 'CUSTOMIZATION' });
                            this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                            this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                            this.jobPriceInput = this.currentJo.final_price || 0;
                            const isPreApprovalPosCustomization =
                                (String(this.currentJo.order_source || '').toLowerCase() === 'pos' || String(this.currentJo.order_source || '').toLowerCase() === 'walk-in') &&
                                String(this.currentJo.status || '').toUpperCase() === 'APPROVED';
                            if (!isPreApprovalPosCustomization && (!Array.isArray(this.currentJo.materials) || this.currentJo.materials.length === 0) && this.currentJo.job_order_id) {
                                await this.refreshMaterials();
                            }
                        } else {
                            const fallbackOrderId = order?.order_id ?? id;
                            const fallbackRes = await this.parseJsonResponse(
                                await fetch(this.adminApiUrl(`job_orders_api.php?action=get_regular_order&service_only=1&id=${fallbackOrderId}`))
                            );
                            if (fallbackRes.success && fallbackRes.data) {
                                this.currentJo = this.applyPosSetPriceDeepLinkOverride({ ...fallbackRes.data, order_type: 'ORDER' });
                                this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                                this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                                this.jobPriceInput = this.currentJo.final_price || 0;
                                if (!this.currentJo.job_order_id) {
                                    await this.resolveEffectiveJobId();
                                }
                                if ((!Array.isArray(this.currentJo.materials) || this.currentJo.materials.length === 0) && this.currentJo.job_order_id) {
                                    await this.refreshMaterials();
                                }
                            } else {
                                this.showStaffAlert('Error', detailRes.error || 'Customization details could not be loaded.');
                                this.showDetailsModal = false;
                            }
                        }
                    } catch (e) {
                        console.error('Error fetching customization detail:', e);
                        try {
                            const fallbackOrderId = order?.order_id ?? id;
                            const fallbackRes = await this.parseJsonResponse(
                                await fetch(this.adminApiUrl(`job_orders_api.php?action=get_regular_order&service_only=1&id=${fallbackOrderId}`))
                            );
                            if (fallbackRes.success && fallbackRes.data) {
                                this.currentJo = this.applyPosSetPriceDeepLinkOverride({ ...fallbackRes.data, order_type: 'ORDER' });
                                this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                                this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                                this.jobPriceInput = this.currentJo.final_price || 0;
                                if (!this.currentJo.job_order_id) {
                                    await this.resolveEffectiveJobId();
                                }
                                if ((!Array.isArray(this.currentJo.materials) || this.currentJo.materials.length === 0) && this.currentJo.job_order_id) {
                                    await this.refreshMaterials();
                                }
                            } else {
                                this.showDetailsModal = false;
                            }
                        } catch (_fallbackError) {
                            this.showDetailsModal = false;
                        }
                    }
                    this.loadingDetails = false;
                    return;
                }
                
                if (orderType === 'ORDER') {
                    // Always fetch full order details to get `items` array and dynamic fields
                    const regularOrderId = order?.order_id ?? order?.id ?? id;
                    let detailErrorMessage = '';
                    try {
                        const detailRes = await this.parseJsonResponse(
                            await fetch(this.adminApiUrl(`job_orders_api.php?action=get_regular_order&service_only=1&id=${regularOrderId}`))
                        );
                        if (detailRes.success) {
                            order = detailRes.data;
                        } else {
                            detailErrorMessage = detailRes.error || detailErrorMessage;
                            let resolvedJobId = order?.job_order_id || null;
                            if (!resolvedJobId) {
                                const resolveRes = await this.parseJsonResponse(
                                    await fetch(this.adminApiUrl(`job_orders_api.php?action=resolve_job_for_order&order_id=${regularOrderId}`))
                                );
                                if (resolveRes.success && resolveRes.job_id) {
                                    resolvedJobId = resolveRes.job_id;
                                } else if (resolveRes.error) {
                                    detailErrorMessage = resolveRes.error || detailErrorMessage;
                                    console.warn('resolve_job_for_order failed:', resolveRes.error);
                                }
                            }

                            if (resolvedJobId) {
                                const jobFallbackRes = await this.parseJsonResponse(
                                    await fetch(this.adminApiUrl(`job_orders_api.php?action=get_order&id=${resolvedJobId}`))
                                );
                                if (jobFallbackRes.success && jobFallbackRes.data) {
                                    order = {
                                        ...jobFallbackRes.data,
                                        order_type: 'ORDER',
                                        id: regularOrderId,
                                        order_id: jobFallbackRes.data.order_id || regularOrderId,
                                        job_order_id: jobFallbackRes.data.id || resolvedJobId
                                    };
                                } else if (jobFallbackRes.error) {
                                    detailErrorMessage = jobFallbackRes.error || detailErrorMessage;
                                    console.warn('get_order fallback failed:', jobFallbackRes.error);
                                }
                            } else if (detailRes.error) {
                                console.warn('get_regular_order failed:', detailRes.error);
                            }
                        }
                    } catch (e) {
                        detailErrorMessage = e?.message || detailErrorMessage;
                        console.error('Error fetching order detail:', e);
                    }
                    
                    if (!order || !order.items) {
                        this.loadingDetails = false;
                        this.showDetailsModal = false;
                        this.showStaffAlert('Not Found', detailErrorMessage || 'Order details could not be loaded for this pending entry.');
                        return;
                    }
                    this.currentJo = { ...order, order_type: 'ORDER' };
                    this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                    this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                    this.jobPriceInput = this.currentJo.final_price || 0;
                    if (!this.currentJo.job_order_id) {
                        await this.resolveEffectiveJobId();
                    }
                    if ((!Array.isArray(this.currentJo.materials) || this.currentJo.materials.length === 0) && this.currentJo.job_order_id) {
                        await this.refreshMaterials();
                    }
                    this.loadingDetails = false;
                } else {
                    // JOB ORDER
                    const jid = id || (order ? order.id : null);
                    if (!jid) {
                        this.loadingDetails = false;
                        this.showDetailsModal = false;
                        return;
                    }

                    try {
                        const res = await this.parseJsonResponse(
                            await fetch(this.adminApiUrl(`job_orders_api.php?action=get_order&id=${jid}`))
                        );
                        if (res.success) {
                            this.currentJo = { ...res.data, order_type: 'JOB' };
                            this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                            this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                            this.jobPriceInput = this.currentJo.final_price || 0;
                            this.resetMaterialForm();
                            this.resetInkForm();
                            for (const m of this.currentJo.materials || []) {
                                if (m.track_by_roll == 1) this.loadAvailableRolls(m.item_id);
                            }
                        } else {
                            // Fallback: It might be a regular order ID passed with job_type=JOB
                            const fallbackRes = await this.parseJsonResponse(
                                await fetch(this.adminApiUrl(`job_orders_api.php?action=get_regular_order&service_only=1&id=${jid}`))
                            );
                            if (fallbackRes.success) {
                                this.currentJo = { ...fallbackRes.data, order_type: 'ORDER' };
                                this.currentJo.customer_type = this.normalizeCustomerType(this.currentJo.customer_type, this.currentJo.transaction_count);
                                this.currentJo.customer_profile_picture = this.currentJo.customer_profile_picture || this.currentJo.profile_picture || this.currentJo.customer_picture || '';
                                this.jobPriceInput = this.currentJo.final_price || 0;
                                if (!this.currentJo.job_order_id) {
                                    await this.resolveEffectiveJobId();
                                }
                            } else {
                                this.showStaffAlert('Error', 'Order details could not be loaded.');
                                this.showDetailsModal = false;
                            }
                        }
                    } catch (e) {
                        console.error('Error loading job details', e);
                        this.showDetailsModal = false;
                    }
                    this.loadingDetails = false;
                }
            },

            isOverdue(date) {
                if(!date) return false;
                return new Date(date) < new Date() && this.activeStatus !== 'COMPLETED' && this.activeStatus !== 'CANCELLED';
            },

            async loadAvailableRolls(itemId) {
                if(this.availableRolls[itemId]) {
                    this.availableRollsList = this.availableRolls[itemId];
                    return;
                }
                const res = await this.parseJsonResponse(
                    await fetch(`../admin/inventory_rolls_api.php?action=list_rolls&item_id=${itemId}`)
                );
                if(res.success) {
                    this.availableRolls[itemId] = res.data;
                    this.availableRollsList = res.data;
                }
            },

            isRollTracked(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.track_by_roll == 1;
            },

            async assignRoll(jomId, rollId) {
                const fd = new FormData();
                fd.append('action', 'assign_roll');
                fd.append('jom_id', jomId);
                fd.append('roll_id', rollId);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    await this.loadOrders();
                    await this.refreshMaterials();
                } else {
                    this.showStaffAlert('Error', res.error);
                }
            },

            async jobAction(status, machineId = null) {
                if (this.currentJo.order_type === 'CUSTOMIZATION') {
                    const fd = new FormData();
                    fd.append('action', 'update_customization');
                    fd.append('id', this.currentJo.id);
                    fd.append('status', status === 'APPROVED' ? 'Approved' : status);
                    const res = await (await fetch(this.adminApiUrl('job_orders_api.php'), { method: 'POST', body: fd })).json();
                    if (res.success) { await this.loadOrders(); this.showDetailsModal = false; }
                    else this.showStaffAlert('Error', res.error || 'Update failed.');
                    return;
                }
                const jid = await this.resolveEffectiveJobId();
                if (!jid) {
                    this.showStaffAlert('Production Job Error', 'Could not create or find a production job for this store order. Confirm the order has line items in Orders.');
                    return;
                }
                const ok = await this.updateStatus(jid, status, machineId);
                if (ok) this.showDetailsModal = false;
            },

            async updateStatus(id, status, machineId = null, reason = '') {
                if (id == null || id === '' || Number(id) <= 0) {
                    this.showStaffAlert('Error', 'Invalid job order id.');
                    return false;
                }

                const fd = new FormData();
                
                if (this.currentJo.order_type === 'CUSTOMIZATION') {
                    fd.append('action', 'update_customization');
                    fd.append('id', id);
                    fd.append('status', status);
                    if (reason) fd.append('reason', reason);
                } else {
                    fd.append('action', 'update_status');
                    fd.append('id', id);
                    fd.append('status', status);
                    if(machineId) fd.append('machine_id', machineId);
                    if(reason) fd.append('reason', reason);
                }
                
                const res = await (await fetch(this.adminApiUrl('job_orders_api.php'), { method: 'POST', body: fd })).json();
                if(res.success) {
                    await this.loadOrders();
                    if (this.showDetailsModal && (this.sameId(this.effectiveJobId(), id) || this.sameId(this.currentJo.id, id))) {
                        await this.viewDetails(this.currentJo.id, this.currentJo.order_type || 'JOB');
                    }
                    return true;
                }
                this.showStaffAlert('Update Failed', res.error);
                return false;
            },

            async parseJsonResponse(r) {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Non-JSON response', text.slice(0, 500));
                    return { success: false, error: 'Server returned an invalid response. Check console or PHP error log.' };
                }
            },
            async verifyPayment() {
                if (!this.beginModalAction()) return;
                try {
                    const ot = this.currentJo.order_type || 'JOB';
                    let res;
                    if (ot === 'ORDER') {
                        const oid = this.currentJo.order_id || this.currentJo.id;
                        const fd = new FormData();
                        fd.append('order_id', oid);
                        fd.append('action', 'Approve');
                        const r = await fetch(this.staffApiUrl('api_verify_payment.php'), { method: 'POST', body: fd });
                        res = await this.parseJsonResponse(r);
                    } else {
                        const jid = await this.resolveEffectiveJobId();
                        if (!jid) {
                            this.showStaffAlert('Error', 'No linked production job for payment verification.');
                            return;
                        }
                        const fd = new FormData();
                        fd.append('action', 'verify_payment');
                        fd.append('id', jid);
                        if (this.currentJo.order_id) {
                            fd.append('order_id', this.currentJo.order_id);
                        }
                        const r = await fetch(this.adminApiUrl('api_verify_job_payment.php'), { method: 'POST', body: fd });
                        res = await this.parseJsonResponse(r);
                    }
                    if (res.success) {
                        this.activeStatus = 'IN_PRODUCTION';
                        await this.loadOrders();
                        await this.loadAllInventoryItems();
                        this.currentJo.status = 'IN_PRODUCTION';
                        const currentOrderId = this.currentJo.order_id ?? null;
                        this.orders = this.orders.map(o => (
                            (
                                (this.sameId(o.id, this.currentJo.id) && (o.order_type || 'JOB') === (this.currentJo.order_type || 'JOB')) ||
                                (currentOrderId != null && this.sameId(o.order_id, currentOrderId))
                            )
                                ? { ...o, status: 'IN_PRODUCTION', updated_at: new Date().toISOString(), _ts: Date.now() }
                                : o
                        ));
                        this.bumpOrdersVersion();
                        this.showDetailsModal = false;
                        const verificationMessage = res.warning
                            ? 'Payment verified. Production started, but inventory deduction still needs follow-up.'
                            : 'Payment verified. Materials deducted and production started.';
                        this.showStaffAlert('Success', verificationMessage);
                    } else {
                        this.showStaffAlert('Verification Failed', res.error || 'Verification failed.');
                    }
                } finally {
                    this.endModalAction();
                }
            },

            openRejectPaymentModal() {
                this.rejectPaymentReasonSelect = '';
                this.rejectPaymentReasonText = '';
                this.rejectPaymentModalError = '';
                this.showRejectPaymentModal = true;
            },

            closeRejectPaymentModal() {
                this.rejectPaymentModalError = '';
                this.showRejectPaymentModal = false;
            },
            async submitRejectPayment() {
                const finalReason = this.rejectPaymentReasonSelect === 'Others' ? this.rejectPaymentReasonText : this.rejectPaymentReasonSelect;
                if (!finalReason) {
                    this.rejectPaymentModalError = 'Please select or specify a reason for rejection.';
                    return;
                }
                this.rejectPaymentModalError = '';
                this.closeRejectPaymentModal();
                await this.rejectPayment(finalReason);
            },

            async rejectPayment(reasonOverride = null) {
                let reason = reasonOverride;
                if (!reason) {
                    reason = prompt("Enter reason for rejection (e.g., Unclear image, Incorrect amount):");
                }
                if (!reason) return;
                if (!this.beginModalAction()) return;
                try {
                    const ot = this.currentJo.order_type || 'JOB';
                    let res;
                    if (ot === 'ORDER') {
                        const oid = this.currentJo.order_id || this.currentJo.id;
                        const fd = new FormData();
                        fd.append('order_id', oid);
                        fd.append('action', 'Reject');
                        fd.append('reason', reason);
                        const r = await fetch(this.staffApiUrl('api_verify_payment.php'), { method: 'POST', body: fd });
                        res = await this.parseJsonResponse(r);
                    } else {
                        const jid = await this.resolveEffectiveJobId();
                        if (!jid) {
                            this.showStaffAlert('Error', 'No linked production job.');
                            return;
                        }
                        const fd = new FormData();
                        fd.append('action', 'reject_payment');
                        fd.append('id', jid);
                        if (this.currentJo.order_id) {
                            fd.append('order_id', this.currentJo.order_id);
                        }
                        fd.append('reason', reason);
                        const r = await fetch(this.adminApiUrl('api_verify_job_payment.php'), { method: 'POST', body: fd });
                        res = await this.parseJsonResponse(r);
                    }
                    if (res.success) {
                        this.activeStatus = 'REJECTED';
                        await this.loadOrders();
                        this.currentJo.status = 'REJECTED';
                        const currentOrderId = this.currentJo.order_id ?? null;
                        this.orders = this.orders.map(o => (
                            (
                                (this.sameId(o.id, this.currentJo.id) && (o.order_type || 'JOB') === (this.currentJo.order_type || 'JOB')) ||
                                (currentOrderId != null && this.sameId(o.order_id, currentOrderId))
                            )
                                ? { ...o, status: 'REJECTED', updated_at: new Date().toISOString(), _ts: Date.now() }
                                : o
                        ));
                        this.bumpOrdersVersion();
                        this.showDetailsModal = false;
                        this.showStaffAlert('Success', 'Payment proof rejected.');
                    } else {
                        this.showStaffAlert('Rejection Failed', res.error || 'Rejection failed.');
                    }
                } finally {
                    this.endModalAction();
                }
            },
            async setJobPrice(id) {
                if(this.jobPriceInput < 0) return;
                let jid = id != null ? id : await this.resolveEffectiveJobId();
                if (!jid) {
                    this.showStaffAlert('Error', 'No linked production job.');
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'set_price');
                fd.append('id', jid);
                fd.append('price', this.jobPriceInput);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(!res.success) {
                    this.showStaffAlert('Error', res.error);
                    throw new Error(res.error);
                }
            },

            addMaterialToQueue() {
                if (!this.newMaterialId) return;
                const item = this.allInventoryItems.find(i => i.id == this.newMaterialId);
                if (!item) return;
                const normalizedQty = this.normalizeMaterialQtyValue(this.newMaterialQty, 1);
                if (normalizedQty < 1) {
                    this.showStaffAlert('Invalid Quantity', 'Material quantity must be at least 1.');
                    return;
                }
                this.newMaterialQty = normalizedQty;
                if (this.selectedMaterialStockError) {
                    this.showStaffAlert('Insufficient Stock', this.selectedMaterialStockError);
                    return;
                }

                // Check if already in pending queue
                const existing = this.pendingMaterials.find(m => m.item_id == this.newMaterialId);
                if (existing) {
                    existing.qty = this.normalizeMaterialQtyValue((parseFloat(existing.qty) || 0) + normalizedQty, 1);
                    // Reset input
                    this.resetMaterialForm();
                    return;
                }

                let meta = {};
                if (this.isTarpaulin(this.newMaterialId)) {
                    meta.height_ft = this.newMaterialHeight;
                    meta.finishing = this.newMaterialMetadata.finishing || '';
                } else if (this.isSticker(this.newMaterialId)) {
                    meta.lamination = this.newMaterialMetadata.lamination || '';
                }
                this.pendingMaterials.push({
                    item_id: this.newMaterialId,
                    name: item.name,
                    qty: normalizedQty,
                    uom: this.getMaterialEntryUom(this.newMaterialId),
                    roll_id: this.newMaterialRollId || '',
                    notes: this.newMaterialNotes,
                    metadata: meta
                });
                // Reset form
                this.resetMaterialForm();
            },
            buildCurrentMaterialPayload() {
                if (!this.newMaterialId) return null;
                const normalizedQty = this.normalizeMaterialQtyValue(this.newMaterialQty, 1);
                if (normalizedQty < 1) return null;
                this.newMaterialQty = normalizedQty;
                const item = this.allInventoryItems.find(i => i.id == this.newMaterialId);
                if (!item) return null;

                let meta = {};
                if (this.isTarpaulin(this.newMaterialId)) {
                    meta.height_ft = this.newMaterialHeight;
                    meta.finishing = this.newMaterialMetadata.finishing || '';
                } else if (this.isSticker(this.newMaterialId)) {
                    const orderedHeight = parseFloat(this.currentJo.height_ft || 0) > 0 ? parseFloat(this.currentJo.height_ft || 0) : 1;
                    meta.waste_length_ft = Math.max(0, (parseFloat(this.newMaterialQty) || 0) - orderedHeight);
                    if (this.newMaterialMetadata.lamination) {
                        meta.lamination_item_id = this.newMaterialMetadata.lamination;
                        meta.lamination_roll_id = this.newMaterialMetadata.lamination_roll_id || null;
                        meta.lamination_length_ft = this.newMaterialQty;
                    }
                }

                return {
                    item_id: this.newMaterialId,
                    qty: normalizedQty,
                    uom: this.getMaterialEntryUom(this.newMaterialId),
                    roll_id: this.newMaterialRollId || '',
                    notes: this.newMaterialNotes || '',
                    metadata: meta
                };
            },
            buildInkPayload() {
                if (!this.useInk || !this.inkCategorySelected || !this.inkTypes[this.inkCategorySelected]) return [];
                const mappedInks = this.inkTypes[this.inkCategorySelected];
                const inkPayload = [];
                if (this.inkBlue > 0) inkPayload.push({ item_id: mappedInks['BLUE'], color: 'BLUE', quantity: this.inkBlue });
                if (this.inkRed > 0) inkPayload.push({ item_id: mappedInks['RED'], color: 'RED', quantity: this.inkRed });
                if (this.inkBlack > 0) inkPayload.push({ item_id: mappedInks['BLACK'], color: 'BLACK', quantity: this.inkBlack });
                if (this.inkYellow > 0) inkPayload.push({ item_id: mappedInks['YELLOW'], color: 'YELLOW', quantity: this.inkYellow });
                return inkPayload;
            },
            hasProductionAssignments(extraMaterials = [], extraInks = []) {
                const savedMaterials = Array.isArray(this.currentJo.materials) ? this.currentJo.materials.length : 0;
                const savedInks = Array.isArray(this.currentJo.ink_usage) ? this.currentJo.ink_usage.length : 0;
                return savedMaterials > 0 || savedInks > 0 || extraMaterials.length > 0 || extraInks.length > 0;
            },
            async getProductionAssignmentTarget() {
                const jid = await this.resolveEffectiveJobId();
                return { orderId: jid, orderType: 'JOB', jobId: jid };
            },
            async submitToPay() {
                console.log('submitToPay called');
                console.log('jobPriceInput value:', this.jobPriceInput);
                console.log('jobPriceInput type:', typeof this.jobPriceInput);
                if (this.currentJo.order_type === 'CUSTOMIZATION') {
                    const priceValue = parseFloat(this.jobPriceInput);
                    console.log('Parsed price value:', priceValue);
                    console.log('Is NaN?', isNaN(priceValue));
                    if (!priceValue || priceValue <= 0 || isNaN(priceValue)) {
                        this.setFooterActionError('Please enter a valid final price before approving.');
                        return;
                    }
                    const target = await this.getProductionAssignmentTarget();
                    if (!target.jobId) {
                        this.setFooterActionError('No linked production job was found for this customization.');
                        return;
                    }
                    const materialsToSave = [...this.pendingMaterials];
                    const currentMaterial = this.buildCurrentMaterialPayload();
                    if (currentMaterial) {
                        materialsToSave.push(currentMaterial);
                    }
                    const inkPayload = this.buildInkPayload();
                    if (!this.hasProductionAssignments(materialsToSave, inkPayload)) {
                        this.setFooterActionError('Please add at least one production material or ink before approving.');
                        return;
                    }
                    for (const pm of materialsToSave) {
                        const fdMaterial = new FormData();
                        fdMaterial.append('action', 'add_material');
                        fdMaterial.append('order_id', target.orderId);
                        fdMaterial.append('order_type', target.orderType);
                        fdMaterial.append('item_id', pm.item_id);
                        fdMaterial.append('quantity', pm.qty);
                        fdMaterial.append('uom', pm.uom);
                        fdMaterial.append('roll_id', pm.roll_id);
                        fdMaterial.append('notes', pm.notes);
                        fdMaterial.append('metadata', JSON.stringify(pm.metadata || {}));
                        const materialRes = await (await fetch(this.adminApiUrl('job_orders_api.php'), { method: 'POST', body: fdMaterial })).json();
                        if (!materialRes.success) {
                            this.showStaffAlert('Material Error', 'Failed to save material: ' + (materialRes.error || 'Unknown error'));
                            return;
                        }
                    }
                    this.pendingMaterials = [];
                    if (inkPayload.length > 0) {
                        const fdInk = new FormData();
                        fdInk.append('action', 'save_ink_usage');
                        fdInk.append('order_id', target.orderId);
                        fdInk.append('order_type', target.orderType);
                        fdInk.append('ink_data', JSON.stringify(inkPayload));
                        const inkRes = await (await fetch(this.adminApiUrl('job_orders_api.php'), { method: 'POST', body: fdInk })).json();
                        if (!inkRes.success) {
                            this.showStaffAlert('Ink Error', 'Failed to save ink usage: ' + (inkRes.error || 'Unknown error'));
                            return;
                        }
                    }
                    await this.refreshMaterials();
                    if (!this.beginModalAction()) return;
                    try {
                        const fd = new FormData();
                        fd.append('action', 'update_customization');
                        fd.append('id', this.currentJo.id);
                        fd.append('status', 'TO_PAY');
                        fd.append('price', this.jobPriceInput);
                        const res = await (await fetch(this.adminApiUrl('job_orders_api.php'), { method: 'POST', body: fd })).json();
                        if (res.success) {
                            const hasPaymentProof = this.currentJo.payment_proof_path || this.currentJo.payment_proof;
                            const paymentAmount = parseFloat(this.currentJo.payment_submitted_amount || 0);
                            const targetTab = (hasPaymentProof && paymentAmount > 0) ? 'TO_VERIFY' : 'TO_PAY';
                            const successMessage = targetTab === 'TO_VERIFY'
                                ? 'Price set! Payment proof detected and order moved to verification.'
                                : 'Price set and order moved to payment stage.';
                            const details = this.currentJo.customization_details || {};
                            const urlParams = new URLSearchParams(window.location.search);
                            const returnToPOS = urlParams.get('return_to_pos') === '1';
                            if (details.source === 'POS' || returnToPOS) {
                                const savedState = sessionStorage.getItem('pos_cart_state');
                                if (savedState) {
                                    try {
                                        const state = JSON.parse(savedState);
                                        await fetch(this.staffApiUrl('api/pos_cart_handler.php'), {
                                            method: 'POST',
                                            headers: {'Content-Type': 'application/json'},
                                            body: JSON.stringify({
                                                action: 'update_price',
                                                index: state.item_index,
                                                price: priceValue
                                            })
                                        });
                                        await fetch(this.staffApiUrl('api/pos_cart_handler.php'), {
                                            method: 'POST',
                                            headers: {'Content-Type': 'application/json'},
                                            body: JSON.stringify({
                                                action: 'update_service_link',
                                                index: state.item_index,
                                                pending_order_id: parseInt(this.currentJo.order_id || this.deepLinkSourceOrderId || 0, 10) || 0,
                                                customization_id: parseInt(this.currentJo.id || 0, 10) || 0
                                            })
                                        });
                                    } catch (e) {
                                        console.error('Error updating cart price:', e);
                                    }
                                    sessionStorage.removeItem('pos_cart_state');
                                }
                                window.location.href = this.staffApiUrl('pos.php?from_customizations=1');
                                return;
                            }
                            this.activeStatus = targetTab;
                            await this.loadOrders();
                            this.showStaffAlert('Success', successMessage);
                        } else {
                            this.showStaffAlert('Error', res.error || 'Failed.');
                        }
                    } finally {
                        this.endModalAction();
                    }
                    return;
                }
                const target = await this.getProductionAssignmentTarget();
                if (!target.jobId) {
                    this.setFooterActionError('No linked production job was found for this order.');
                    return;
                }
                const jid = target.jobId;
                const userEnteredPrice = parseFloat(this.jobPriceInput);
                console.log('User entered price (captured early):', userEnteredPrice);
                const urlParams = new URLSearchParams(window.location.search);
                const returnToPOS = urlParams.get('return_to_pos') === '1';
                const fromPOS = returnToPOS || (this.currentJo.order_type === 'ORDER' && this.currentJo.order_source === 'pos') || (this.currentJo.order_type === 'CUSTOMIZATION' && this.currentJo.order_source === 'pos');
                const materialsToSave = [...this.pendingMaterials];
                const currentMaterial = this.buildCurrentMaterialPayload();
                if (currentMaterial) {
                    materialsToSave.push(currentMaterial);
                }
                const inkPayload = this.buildInkPayload();
                if (!this.hasProductionAssignments(materialsToSave, inkPayload)) {
                    this.setFooterActionError('Please add at least one production material or ink before submitting.');
                    return;
                }
                for (const pm of materialsToSave) {
                    const fd = new FormData();
                    fd.append('action', 'add_material');
                    fd.append('order_id', target.orderId);
                    fd.append('order_type', target.orderType);
                    fd.append('item_id', pm.item_id);
                    fd.append('quantity', pm.qty);
                    fd.append('uom', pm.uom);
                    fd.append('roll_id', pm.roll_id);
                    fd.append('notes', pm.notes);
                    fd.append('metadata', JSON.stringify(pm.metadata));
                    const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                    if (!res.success) { this.showStaffAlert('Material Error', 'Failed to save material: ' + res.error); return; }
                }
                this.pendingMaterials = [];
                if (inkPayload.length > 0) {
                    const fdInk = new FormData();
                    fdInk.append('action', 'save_ink_usage');
                    fdInk.append('order_id', target.orderId);
                    fdInk.append('order_type', target.orderType);
                    fdInk.append('ink_data', JSON.stringify(inkPayload));
                    const resInk = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fdInk })).json();
                    if (!resInk.success) {
                        this.showStaffAlert('Ink Error', 'Failed to save ink usage: ' + resInk.error);
                        return;
                    }
                }
                await this.refreshMaterials();
                console.log('Price before materials check:', userEnteredPrice);
                if (!userEnteredPrice || userEnteredPrice <= 0 || isNaN(userEnteredPrice)) {
                    this.setFooterActionError('Please enter a valid final price before submitting.');
                    return;
                }
                if (this.approvalStockErrors.length > 0) {
                    this.setFooterActionError(this.approvalStockErrors.join(' '));
                    return;
                }
                if (!this.beginModalAction()) return;
                try {
                    this.jobPriceInput = userEnteredPrice;
                    const priceUpdated = await this.updatePrice();
                    if (!priceUpdated) {
                        this.showStaffAlert('Error', 'Failed to update price. Please try again.');
                        return;
                    }
                    const statusUpdated = await this.updateStatus(jid, 'TO_PAY');
                    if (!statusUpdated) {
                        return;
                    }
                    if (fromPOS) {
                        const savedState = sessionStorage.getItem('pos_cart_state');
                        if (savedState) {
                            try {
                                const state = JSON.parse(savedState);
                                const itemIndex = state.item_index;
                                await fetch(this.staffApiUrl('api/pos_cart_handler.php'), {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({
                                        action: 'update_price',
                                        index: itemIndex,
                                        price: userEnteredPrice
                                    })
                                });
                                await fetch(this.staffApiUrl('api/pos_cart_handler.php'), {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({
                                        action: 'update_service_link',
                                        index: itemIndex,
                                        pending_order_id: parseInt(this.currentJo.order_id || this.deepLinkSourceOrderId || 0, 10) || 0,
                                        customization_id: parseInt(this.currentJo.id || 0, 10) || 0
                                    })
                                });
                                window.location.href = this.staffApiUrl('pos.php?from_customizations=1');
                                return;
                            } catch (e) {
                                console.error('Error updating cart:', e);
                                window.location.href = this.staffApiUrl('pos.php');
                                return;
                            }
                        }
                        window.location.href = this.staffApiUrl('pos.php');
                        return;
                    }
                    this.showStaffAlert('Success', 'Order approved and moved to payment stage!');
                } finally {
                    this.endModalAction();
                }
            },
            async loadAllInventoryItems() {
                const res = await this.parseJsonResponse(
                    await fetch('../admin/inventory_items_api.php?action=get_items&active_only=1')
                );
                if (res.success) {
                    // Drop roll cache so any newly issued/received roll deductions become visible.
                    this.availableRolls = {};
                    this.allInventoryItems = res.data;
                    this.laminationItemsList = this.allInventoryItems.filter(i => i.name.toUpperCase().includes('LAMINATE'));
                }
            },

            async loadAvailableLamRolls(itemId) {
                if(!itemId) return;
                const res = await this.parseJsonResponse(
                    await fetch(`../admin/inventory_rolls_api.php?action=list&item_id=${itemId}&status=OPEN`)
                );
                if (res.success) {
                    this.availableLamRollsList = res.data;
                }
            },
            async approveOrder() {
                if (!this.beginModalAction()) return;
                try {
                    const id = this.currentJo.id;
                    const oid = this.currentJo.order_id || this.currentJo.id;
                    if (parseFloat(this.jobPriceInput) > 0) {
                        const priceUpdated = await this.updatePrice();
                        if (!priceUpdated) return;
                    }
                    if (this.currentJo.order_type === 'ORDER') {
                        const fd = new FormData();
                        fd.append('order_id', oid);
                        fd.append('status', 'To Pay');
                        fd.append('update_status', '1');
                        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
                        const res = await (await fetch('orders.php', { 
                            method: 'POST', 
                            body: fd, 
                            headers: {'X-Requested-With': 'XMLHttpRequest'} 
                        })).json();
                        if (res.success) {
                            await this.loadOrders();
                            this.showStaffAlert('Success', 'Order approved and moved to To Pay!');
                        } else {
                            this.showStaffAlert('Error', 'Error: ' + (res.error || 'Failed to update order status'));
                        }
                    } else {
                        const ok = await this.updateStatus(id, 'TO_PAY');
                        if (ok) {
                            this.showStaffAlert('Success', 'Order approved and moved to To Pay!');
                        }
                    }
                } finally {
                    this.endModalAction();
                }
            },
            async updatePrice() {
                const jid = this.effectiveJobId();
                const oid = this.currentJo.order_id || this.currentJo.id;
                const price = parseFloat(this.jobPriceInput);
                
                if (!price || price <= 0) {
                    this.showStaffAlert('Invalid Price', 'Please enter a valid price greater than 0.');
                    return false;
                }
                
                if (this.currentJo.order_type === 'ORDER') {
                   const fd = new FormData();
                   fd.append('action', 'update_order_price');
                   fd.append('order_id', oid);
                   fd.append('price', price);
                   const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                   if (!res.success) {
                       this.showStaffAlert('Error', 'Failed to update price: ' + res.error);
                       return false;
                   }
                   this.currentJo.total_amount = price;
                   this.currentJo.final_price = price;
                   console.log('Price updated successfully to:', price);
                   return true;
                } else if (this.currentJo.order_type === 'CUSTOMIZATION') {
                   const fd = new FormData();
                   fd.append('action', 'update_customization');
                   fd.append('id', oid);
                   fd.append('status', 'APPROVED');
                   fd.append('price', price);
                   const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                   if (!res.success) {
                       this.showStaffAlert('Error', 'Failed to update customization price: ' + res.error);
                       return false;
                   }
                   this.currentJo.final_price = price;
                   console.log('Customization price updated successfully to:', price);
                   return true;
                } else {
                    const success = await this.setJobPrice(jid);
                    if (success !== false) {
                        this.currentJo.final_price = price;
                        console.log('Job price updated successfully to:', price);
                        return true;
                    }
                    return false;
                }
            },

            async setJobPrice(jid) {
                if (!jid) return false;
                const price = parseFloat(this.jobPriceInput);
                if (!price || price <= 0) return false;
                
                const fd = new FormData();
                fd.append('action', 'set_price');
                fd.append('id', jid);
                fd.append('price', price);
                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if (res.success) {
                    this.currentJo.final_price = price;
                    console.log('Job price set to:', price);
                    return true;
                } else {
                    this.showStaffAlert('Error', 'Error setting price: ' + res.error);
                    return false;
                }
            },

            openRevisionModal() {
                this.revisionReasonSelect = '';
                this.revisionReasonText = '';
                this.revisionModalError = '';
                this.showRevisionModal = true;
            },

            closeRevisionModal() {
                this.revisionModalError = '';
                this.showRevisionModal = false;
            },
            async submitRevision() {
                const oid = this.effectiveJobId();
                if (!oid) return;
                let finalReason = this.revisionReasonSelect;
                if (finalReason === 'Others' || !finalReason) {
                    finalReason = this.revisionReasonText.trim();
                }
                if (!finalReason) {
                    this.revisionModalError = 'Please select or specify a reason for the revision request.';
                    return;
                }
                this.revisionModalError = '';
                if (!this.beginModalAction()) return;
                this.showRevisionModal = false;
                try {
                    const ok = await this.updateStatus(oid, 'For Revision', null, finalReason);
                    if (ok) {
                        this.showStaffAlert('Success', 'Revision requested successfully.');
                    }
                } finally {
                    this.endModalAction();
                }
            },
            async addMaterial() {
                if(!this.newMaterialId) return;
                const normalizedQty = this.normalizeMaterialQtyValue(this.newMaterialQty, 1);
                if (normalizedQty < 1) {
                    this.showStaffAlert('Invalid Quantity', 'Material quantity must be at least 1.');
                    return;
                }
                this.newMaterialQty = normalizedQty;
                const target = await this.getProductionAssignmentTarget();
                if (!target.jobId) {
                    this.showStaffAlert('Error', 'No linked production job.');
                    return;
                }
                const jid = target.jobId;
                const item = this.allInventoryItems.find(i => i.id == this.newMaterialId);
                const fd = new FormData();
                fd.append('action', 'add_material');
                fd.append('order_id', target.orderId);
                fd.append('order_type', target.orderType);
                fd.append('item_id', this.newMaterialId);
                fd.append('quantity', normalizedQty);
                fd.append('uom', this.getMaterialEntryUom(this.newMaterialId));
                fd.append('roll_id', this.newMaterialRollId);
                fd.append('notes', this.newMaterialNotes);
                
                // Construct metadata based on category
                let meta = {};
                if (this.isTarpaulin(this.newMaterialId)) {
                    meta.height_ft = this.newMaterialHeight;
                    meta.finishing = this.newMaterialMetadata.finishing || '';
                } else if (this.isSticker(this.newMaterialId)) {
                    // STICKER LOGIC
                    let orderedHeight = this.currentJo.height_ft > 0 ? this.currentJo.height_ft : 1;
                    meta.waste_length_ft = Math.max(0, normalizedQty - orderedHeight);
                    if (this.newMaterialMetadata.lamination) {
                        meta.lamination_item_id = this.newMaterialMetadata.lamination;
                        meta.lamination_roll_id = this.newMaterialMetadata.lamination_roll_id || null;
                        meta.lamination_length_ft = normalizedQty; // Lamination length matches consumed vinyl length
                    }
                }
                fd.append('metadata', JSON.stringify(meta));

                const res = await (await fetch('../admin/job_orders_api.php', { method: 'POST', body: fd })).json();
                if(res.success) {
                    this.resetMaterialForm();
                    await this.refreshMaterials();
                } else {
                    this.showStaffAlert('Error', res.error);
                }
            },

            resetMaterialForm() {
                this.newMaterialId = '';
                this.newMaterialQty = 1;
                this.materialQtyManuallyEdited = false;
                this.newMaterialHeight = 0;
                this.newMaterialRollId = '';
                this.newMaterialNotes = '';
                this.materialSearch = '';
                this.availableLamRollsList = [];
                this.newMaterialMetadata = {
                    lamination: '',
                    lamination_roll_id: ''
                };
            },

            resetInkForm() {
                this.inkCategorySelected = '';
                this.inkBlue = '';
                this.inkRed = '';
                this.inkBlack = '';
                this.inkYellow = '';
                this.useInk = false;
            },

            isTarpaulin(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 2; // confirmed from schema check
            },

            isSticker(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 3;
            },

            isPlate(itemId) {
                const item = this.allInventoryItems.find(i => i.id == itemId);
                return item && item.category_id == 1;
            },
            async removeMaterial(jomId) {
                this.showStaffAlert('Material Locked', 'Assigned materials can no longer be removed once they have been set.');
            },
            async refreshMaterials() {
                const jid = await this.resolveEffectiveJobId();
                if (!jid) return;
                const res = await (await fetch(`../admin/job_orders_api.php?action=get_order&id=${jid}`)).json();
                if(res.success) {
                    const previousJo = this.currentJo || {};
                    const merged = {
                        ...previousJo,
                        ...res.data,
                        order_type: previousJo.order_type || 'JOB',
                        order_id: previousJo.order_id || res.data.order_id,
                        job_order_id: jid
                    };
                    if ((previousJo.order_type || '').toUpperCase() === 'CUSTOMIZATION') {
                        merged.status = previousJo.status || merged.status;
                        merged.payment_proof_status = previousJo.payment_proof_status || merged.payment_proof_status;
                        merged.payment_proof_path = previousJo.payment_proof_path || merged.payment_proof_path;
                        merged.payment_submitted_amount = previousJo.payment_submitted_amount ?? merged.payment_submitted_amount;
                        merged.final_price = previousJo.final_price ?? merged.final_price;
                        merged.estimated_total = previousJo.estimated_total ?? merged.estimated_total;
                        merged.estimated_price = previousJo.estimated_price ?? merged.estimated_price;
                    }
                    this.currentJo = merged;
                    for(const m of (this.currentJo.materials || [])) {
                        if(m.track_by_roll == 1) this.loadAvailableRolls(m.item_id);
                    }
                }
            },
            async markReadyForPickup() {
                if (!this.beginModalAction()) return;
                try {
                    await this.jobAction('TO_RECEIVE');
                } finally {
                    this.endModalAction();
                }
            },
            openPosCompleteConfirm(jo = null) {
                this.posCompleteConfirmTarget = jo || this.currentJo || null;
                this.showPosCompleteConfirmModal = true;
            },
            closePosCompleteConfirm() {
                this.showPosCompleteConfirmModal = false;
                this.posCompleteConfirmTarget = null;
            },
            async confirmPosComplete() {
                const target = this.posCompleteConfirmTarget || this.currentJo || null;
                this.closePosCompleteConfirm();
                if (!target) return;
                if (!this.showDetailsModal) {
                    this.currentJo = { ...target };
                }
                await this.completeOrder();
            },
            async cancelPosWalkInOrder() {
                const target = this.currentJo || null;
                if (!target) {
                    this.showStaffAlert('Error', 'No walk-in order is selected.');
                    return;
                }
                const reason = (window.prompt('Enter cancellation reason for this walk-in order:', '') || '').trim();
                if (!reason) {
                    this.showStaffAlert('Cancellation Required', 'Cancellation reason is required.');
                    return;
                }
                if (!window.confirm('Are you sure you want to cancel this walk-in order?')) {
                    return;
                }
                const orderId = target.order_id || target.id;
                if (!orderId) {
                    this.showStaffAlert('Error', 'No linked order found for this entry.');
                    return;
                }
                const fd = new FormData();
                fd.append('order_id', orderId);
                fd.append('status', 'Cancelled');
                fd.append('cancel_reason', reason);
                fd.append('csrf_token', document.body.getAttribute('data-csrf') || '');
                const res = await this.parseJsonResponse(
                    await fetch(this.staffApiUrl('update_order_status_process.php'), {
                        method: 'POST',
                        body: fd
                    })
                );
                if (res.success) {
                    if (this.currentJo) {
                        this.currentJo.status = 'CANCELLED';
                    }
                    await this.loadOrders();
                    this.showStaffAlert('Success', 'Walk-in order cancelled.');
                } else {
                    this.showStaffAlert('Error', res.error || 'Failed to cancel walk-in order.');
                }
            },

            async completeOrder(machineId = null) {
                if (!this.beginModalAction()) return;
                try {
                    if (this.currentJo.order_type === 'ORDER') {
                        const orderId = this.currentJo.order_id || this.currentJo.id;
                        if (!orderId) {
                            this.showStaffAlert('Error', 'No linked order found for this entry.');
                            return;
                        }

                        const fd = new FormData();
                        fd.append('order_id', orderId);
                        fd.append('status', 'Completed');
                        fd.append('csrf_token', document.body.getAttribute('data-csrf') || '');

                        const res = await this.parseJsonResponse(
                            await fetch(this.staffApiUrl('update_order_status_process.php'), {
                                method: 'POST',
                                body: fd
                            })
                        );

                        if (res.success) {
                            await this.loadOrders();
                            this.showStaffAlert('Success', 'Order marked as completed.');
                        } else {
                            this.showStaffAlert('Error', res.error || 'Failed to mark order as completed.');
                        }
                        return;
                    }

                    const jid = await this.resolveEffectiveJobId();
                    if (!jid) {
                        this.showStaffAlert('Error', 'No linked production job for this entry.');
                        return;
                    }
                    const ok = await this.updateStatus(jid, 'COMPLETED', machineId);
                    if (ok) {
                        this.showStaffAlert('Success', 'Order marked as completed.');
                    }
                } finally {
                    this.endModalAction();
                }
            }
        };
            });
        }

        if (typeof Alpine !== 'undefined') {
            boot();
        } else {
            document.addEventListener('alpine:init', boot, { once: true });
        }
    })();
    /*
     * Do NOT call Alpine.initTree here when document.readyState !== 'loading' (Turbo body swap).
     * Inline scripts run before turbo:load's setTimeout; initTree(root) + initTree(.main-content) double-mounts x-for (tripled tabs, zero counts).
     * Full load: Alpine.start() (defer) inits the page. Turbo: public/assets/js/turbo-init.js initTree(.main-content) runs after swap.
     */
</script>
</body>
</html>
