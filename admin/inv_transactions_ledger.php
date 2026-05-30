<?php
/**
 * New Inventory - Transactions Ledger
 * Professional Transaction-Based Inventory UI
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';

require_role(['Admin', 'Manager']);
$current_user = get_logged_in_user();
$branchCtx = init_branch_context(false);
$selectedBranchId = $branchCtx['selected_branch_id'] ?? InventoryManager::getCurrentBranchId();
$selectedBranchParam = ($selectedBranchId === 'all') ? 'all' : (string)(int)$selectedBranchId;
$branchId = ($selectedBranchId === 'all') ? 0 : (int)$selectedBranchId;
$is_manager = (($current_user['role'] ?? '') === 'Manager');
$page_title = $is_manager ? 'Inventory Ledger - Manager' : 'Inventory Ledger - Admin';

/** Safe JSON for onclick="viewTransaction(...)" — never emit empty / broken JS */
function pf_ledger_tx_json_attr(array $row): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $j = json_encode($row, $flags);
    if ($j === false) {
        $j = '{}';
    }
    return htmlspecialchars($j, ENT_QUOTES, 'UTF-8');
}

function pf_ledger_enrich_transaction_row(array $row): array {
    $refType = strtoupper((string)($row['ref_type'] ?? ''));
    $refId = (int)($row['ref_id'] ?? 0);
    $referenceLabel = '';

    if ($refType === 'ORDER') {
        $orderRef = printflow_get_order_inventory_reference($refId);
        $referenceLabel = $orderRef['label'] ?? '';
    } elseif ($refType === 'ORDER_PRODUCT') {
        if (preg_match('/Order\s*#([A-Z0-9-]+)/i', (string)($row['notes'] ?? ''), $m)) {
            $referenceLabel = 'Order #' . strtoupper(trim((string)$m[1]));
        } else {
            $referenceLabel = 'Product Order';
        }
    } elseif ($refType === 'JOB_ORDER') {
        $customizationId = (int)($row['customization_ref_id'] ?? 0);
        if ($customizationId > 0) {
            $referenceLabel = 'Customization #' . printflow_format_customization_code($customizationId);
        } elseif (!empty($row['job_order_store_order_id'])) {
            $orderRef = printflow_get_order_inventory_reference((int)$row['job_order_store_order_id']);
            $referenceLabel = $orderRef['label'] ?? '';
        } elseif ($refId > 0) {
            $referenceLabel = 'Job #' . printflow_format_job_code($refId);
        }
    }

    $row['reference_label'] = $referenceLabel;
    $row['raw_notes'] = (string)($row['notes'] ?? '');
    $row['display_notes'] = $referenceLabel !== ''
        ? printflow_format_inventory_reference_note((string)($row['notes'] ?? ''), $referenceLabel)
        : (string)($row['notes'] ?? '');
    $row['notes'] = $row['display_notes'];

    return $row;
}

function pf_ledger_normalize_uom(?string $uom, ?string $categoryName = null): string {
    $normalized = strtolower(trim((string)$uom));
    $categoryNormalized = strtoupper(trim((string)$categoryName));

    if ($categoryNormalized === 'INK L120' || $categoryNormalized === 'INK L130') {
        return 'l';
    }

    if ($normalized === 'btl') return 'l';
    if (in_array($normalized, ['pcs', 'ft', 'l'], true)) return $normalized;
    return 'pcs';
}

function pf_ledger_uom_label(string $uom): string {
    return match ($uom) {
        'ft' => 'Feet (ft)',
        'l' => 'Liters (L)',
        'pcs' => 'Pieces (pcs)',
        default => strtoupper($uom),
    };
}

// Get filter parameters
printflow_ensure_product_inventory_transaction_schema();
$hasProductIdColumn = db_table_has_column('inventory_transactions', 'product_id');

$item_id      = (int)($_GET['item_id'] ?? 0);
$catalog_product_id = max(0, (int)($_GET['catalog_product_id'] ?? 0));
$type_filter  = $_GET['type'] ?? '';
$search       = trim($_GET['search'] ?? '');
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$sort         = $_GET['sort'] ?? 'transaction_date';
$dir          = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 15;

// Build Query - show only inventory material movements tied to job/customization work
$productNameExpr = $hasProductIdColumn
    ? "NULLIF(TRIM(p_direct.name), '')"
    : "NULL";
$legacyProductNameExpr = "NULLIF(TRIM(p_item.name), '')";
$productKindExpr = $hasProductIdColumn
    ? "(t.product_id IS NOT NULL AND t.product_id > 0)"
    : "0";
$productRefExpr = "NULLIF(TRIM(p_ref.name), '')";
$legacyProductKindExpr = "(UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT') AND p_item.product_id IS NOT NULL)";
$itemNameSql = "COALESCE({$productNameExpr}, {$legacyProductNameExpr}, {$productRefExpr}, i.name, CASE WHEN {$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER') THEN CONCAT('Product #', COALESCE(NULLIF(t.product_id, 0), NULLIF(t.ref_id, 0), NULLIF(t.item_id, 0))) ELSE CONCAT('Item #', t.item_id) END)";

$sql = "SELECT t.*, 
               {$itemNameSql} as item_name, 
               CASE WHEN {$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER') THEN 'product' ELSE 'material' END as ledger_item_kind,
               CASE
                   WHEN {$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER')
                       THEN 'pcs'
                   ELSE COALESCE(NULLIF(TRIM(i.unit_of_measure), ''), NULLIF(TRIM(t.uom), ''), 'pcs')
               END as unit, 
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
               r.roll_code as roll_code,
               jo.id as job_ref_id,
               jo.order_id as job_order_store_order_id,
                cust_map.customization_id as customization_ref_id
        FROM inventory_transactions t
        LEFT JOIN inv_items i ON t.item_id = i.id
        " . ($hasProductIdColumn ? "LEFT JOIN products p_direct ON t.product_id = p_direct.product_id" : "") . "
        LEFT JOIN products p_item ON UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT') AND t.item_id = p_item.product_id
        LEFT JOIN products p_ref ON UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT') AND t.ref_id = p_ref.product_id
        LEFT JOIN users u ON t.created_by = u.user_id
        LEFT JOIN inv_rolls r ON t.roll_id = r.id
        LEFT JOIN job_orders jo ON UPPER(t.ref_type) = 'JOB_ORDER' AND jo.id = t.ref_id
        LEFT JOIN (
            SELECT order_id, MIN(customization_id) AS customization_id
            FROM customizations
            GROUP BY order_id
        ) cust_map ON cust_map.order_id = jo.order_id
        WHERE 1=1";
$params = [];
$types = '';
if ($branchId > 0) {
    if (InventoryManager::isMainBranch($branchId)) {
        // Include rows with branch_id matching main OR unset branch_id. Omitting unset branch_id
        // hid ORDER/ORDER_PRODUCT lines (still affecting stock); product #40 matched that path.
        $sql .= " AND (t.branch_id = ? OR t.branch_id IS NULL)";
    } else {
        $sql .= " AND t.branch_id = ?";
    }
    $types .= 'i';
    $params[] = $branchId;
}

if ($catalog_product_id > 0) {
    if ($hasProductIdColumn) {
        $sql .= " AND (
                (t.product_id IS NOT NULL AND t.product_id = ?)
                OR (t.item_id = ? AND UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT'))
                OR (UPPER(t.ref_type) = 'ORDER_PRODUCT' AND t.ref_id = ?)
            )";
        $types .= 'iii';
        $params[] = $catalog_product_id;
        $params[] = $catalog_product_id;
        $params[] = $catalog_product_id;
    } else {
        $sql .= " AND (
                (t.item_id = ? AND UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT'))
                OR (UPPER(t.ref_type) = 'ORDER_PRODUCT' AND t.ref_id = ?)
            )";
        $types .= 'ii';
        $params[] = $catalog_product_id;
        $params[] = $catalog_product_id;
    }
} elseif ($item_id) {
    $sql .= " AND t.item_id = ?";
    $params[] = $item_id;
    $types .= 'i';
}
if ($type_filter) {
    if (in_array(strtoupper($type_filter), ['IN', 'OUT'])) {
        $sql .= " AND t.direction = ?";
    } else {
        $sql .= " AND t.ref_type = ?";
    }
    $params[] = $type_filter;
    $types .= 's';
}
if ($search) {
    $st = '%' . $search . '%';
    $sql .= " AND ({$itemNameSql} LIKE ? OR t.notes LIKE ? OR t.ref_type LIKE ? OR CAST(t.ref_id AS CHAR) LIKE ? OR CAST(t.id AS CHAR) LIKE ?";
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
    $params[] = $st;
    $types .= 'sssss';
    if ($hasProductIdColumn) {
        $sql .= " OR CAST(COALESCE(t.product_id, 0) AS CHAR) LIKE ?";
        $params[] = $st;
        $types .= 's';
    }
    $sql .= ")";
}
if ($start_date && $end_date) {
    $sql .= " AND t.transaction_date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

// Count total
$count_sql = "SELECT COUNT(*) as total FROM ({$sql}) as wrap";
$total_rows = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$orderBy = match($sort) {
    'id' => 't.id',
    'item_name' => $itemNameSql,
    'direction' => 't.direction',
    'quantity' => 't.quantity',
    default => 't.transaction_date'
};

$orderSql = " ORDER BY $orderBy $dir";
if ($sort === 'transaction_date' || $sort === 'id') {
    // Keep tie-breaker direction aligned with selected sort direction.
    $orderSql .= ", t.id $dir";
} else {
    $orderSql .= ", t.transaction_date DESC, t.id DESC";
}
$sql .= $orderSql . " LIMIT $per_page OFFSET $offset";
$transactions = db_query($sql, $types ?: null, $params ?: null) ?: [];
$transactions = array_map('pf_ledger_enrich_transaction_row', $transactions);

// Get items for filters/forms
$items = db_query(
    "SELECT i.id, i.name, i.status, i.category_id, i.unit_of_measure AS unit, c.name AS category_name
     FROM inv_items i
     LEFT JOIN inv_categories c ON i.category_id = c.id
     ORDER BY i.name ASC"
) ?: [];
$categories = db_query("SELECT id, name FROM inv_categories ORDER BY sort_order ASC, name ASC") ?: [];

$tx_modal_items = [];
foreach ($items as $item) {
    $itemId = (int)($item['id'] ?? 0);
    if ($itemId <= 0 || strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
        continue;
    }
    $uom = pf_ledger_normalize_uom($item['unit'] ?? '', $item['category_name'] ?? '');
    $soh = (float)InventoryManager::getStockOnHand($itemId, $branchId);
    $tx_modal_items[] = [
        'id' => $itemId,
        'name' => (string)($item['name'] ?? ''),
        'category_id' => (int)($item['category_id'] ?? 0),
        'category_name' => (string)($item['category_name'] ?? ''),
        'uom' => $uom,
        'uom_label' => pf_ledger_uom_label($uom),
        'soh' => $soh,
        'soh_display' => $uom === 'pcs' ? (string)(int)round($soh) : rtrim(rtrim(number_format($soh, 2, '.', ''), '0'), '.'),
    ];
}
$catalog_products = db_query(
    "SELECT product_id, name FROM products WHERE status != 'Archived' ORDER BY name ASC LIMIT 4000"
) ?: [];

// AJAX Partial Response
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <?php if (empty($transactions)): ?>
        <tr><td colspan="7" style="text-align:center; padding: 60px; color:#6b7280; font-size: 15px;">No logs found for this period.</td></tr>
    <?php else: ?>
        <?php foreach ($transactions as $t): 
            $qty = (float)$t['quantity'];
            $isIN = ($t['direction'] === 'IN');
            $displayQty = $isIN ? '+' . number_format($qty, 2) : '-' . number_format($qty, 2);
            $qtyClass = $isIN ? 'qty-val positive' : 'qty-val negative';
            $badgeClass = $isIN ? 'badge-in' : 'badge-out';
            $displayType = str_replace('_', ' ', strtolower($t['ref_type'] ?: $t['direction'] ?: 'MOVEMENT'));
            
            $typeBadgeClass = "badge $badgeClass";
            $typeBadgeStyle = '';
            if (in_array($displayType, ['joborder', 'job order'])) {
                $displayType = 'customization';
                $typeBadgeClass = '';
                $typeBadgeStyle = 'display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#eef2ff;color:#4338ca;';
            }
        ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;color:#111827;">#TX-<?php echo $t['id']; ?></td>
                <td style="color:#6b7280;"><?php echo $t['transaction_date']; ?></td>
                <td class="truncate" style="font-weight:500;color:#111827;" title="<?php echo htmlspecialchars($t['item_name']); ?>">
                    <?php echo htmlspecialchars($t['item_name']); ?>
                </td>
                <td><span class="<?php echo $typeBadgeClass; ?>" style="text-transform:capitalize;pointer-events:none;<?php echo $typeBadgeStyle; ?>"><?php echo $displayType; ?></span></td>
                <td style="text-align:right;">
                    <span class="<?php echo $qtyClass; ?>"><?php echo $displayQty; ?></span>
                    <span style="font-size:11px;color:#6b7280;font-weight:600;margin-left:4px;"><?php echo $t['unit']; ?></span>
                </td>
                <td class="truncate" style="font-size:12px;color:#6b7280;" title="<?php echo htmlspecialchars($t['notes'] ?: '—'); ?>"><?php echo htmlspecialchars($t['notes'] ?: '—'); ?></td>
                <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($t['created_by_name'] ?: 'System'); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $table_html = ob_get_clean();

    ob_start();
    $p = array_filter(['branch_id'=>$selectedBranchParam, 'item_id'=>($item_id > 0 ? $item_id : null), 'catalog_product_id'=>($catalog_product_id > 0 ? $catalog_product_id : null), 'type'=>$type_filter, 'search'=>$search, 'start_date'=>$start_date, 'end_date'=>$end_date, 'sort'=>$sort, 'dir'=>$dir], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $p);
    $pagination_html = ob_get_clean();

    $badge_count = count(array_filter([$item_id ?: '', $catalog_product_id ?: '', $type_filter, $search, $start_date, $end_date], function($v) { return $v !== null && $v !== ''; }));

    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_rows),
        'badge'      => $badge_count,
        'startIdx'   => $total_rows > 0 ? $offset + 1 : 0,
        'endIdx'     => min($offset + $per_page, $total_rows),
        'total'      => $total_rows
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="turbo-visit-control" content="reload">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>

    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --in-color: #059669;
            --out-color: #dc2626;
        }

        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 11px; font-weight: 700; text-transform: capitalize; color: #6b7280; letter-spacing: 0.025em; }
        .filter-group input, .filter-group select { height: 36px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; padding: 0 10px; color: #374151; width: auto; background: #fff; }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        
        .inv-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .inv-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .inv-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
        .truncate { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .inv-table tbody tr { cursor: default; transition: background 0.1s; }
        .inv-table tbody tr:hover td { background: #f9fafb; }
        .inv-table tbody tr:last-child td { border-bottom: none; }
        
        .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid transparent; }
        .badge-in { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .badge-out { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .badge-neutral { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
        
        .qty-val { font-weight: 700; font-variant-numeric: tabular-nums; font-size: 15px; }
        .qty-val.positive { color: #059669; }
        .qty-val.negative { color: #dc2626; }
        
        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px; overflow-y: auto; animation: fadeIn 0.3s ease; }
        .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 600px; padding: 24px; position: relative; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e5e7eb; z-index: 1001; pointer-events: auto; font: inherit; font-size: 13px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .modal-title { font-size: 18px; font-weight: 700; color: #111827; padding-right: 40px; overflow-wrap: break-word; word-break: break-word; -webkit-hyphens: auto; -ms-hyphens: auto; hyphens: auto; line-height: 1.4; }
        .close-btn { background: none; border: none; font-size: 20px; color: #111827; cursor: pointer; padding: 4px; line-height: 1; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .close-btn:hover { color: #374151; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .form-group.full { grid-column: span 2; }
        
        /* Ensure select elements in modal have consistent height and style (match table font) */
        .modal select, .modal input:not([type="hidden"]) { height: 40px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0 12px; font-size: 13px; background: #fff; color: #374151; }
        .modal label { margin-bottom: 6px; display: block; font-weight: 600; color: #374151; font-size: 13px; }

        .tx-item-picker-filters { display: grid; grid-template-columns: 1fr 1.2fr; gap: 10px; margin-bottom: 10px; }
        .tx-item-selected {
            display: none; align-items: center; justify-content: space-between; gap: 10px;
            padding: 10px 12px; border: 1px solid #a7f3d0; border-radius: 10px; background: #ecfdf5; margin-bottom: 8px;
        }
        .tx-item-selected.is-visible { display: flex; }
        .tx-item-selected-name { font-weight: 600; color: #065f46; line-height: 1.35; }
        .tx-item-selected-meta { font-size: 12px; color: #047857; margin-top: 2px; }
        .tx-item-clear {
            flex-shrink: 0; border: 1px solid #6ee7b7; background: #fff; color: #047857;
            border-radius: 8px; padding: 6px 10px; font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .tx-item-clear:hover { background: #d1fae5; }
        .tx-item-results {
            max-height: 220px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff;
        }
        .tx-item-results:empty { display: none; }
        .tx-item-result {
            width: 100%; text-align: left; border: none; border-bottom: 1px solid #f3f4f6; background: #fff;
            padding: 10px 12px; cursor: pointer; font: inherit; font-size: 13px; color: #111827;
        }
        .tx-item-result:last-child { border-bottom: none; }
        .tx-item-result:hover, .tx-item-result:focus { background: #f9fafb; outline: none; }
        .tx-item-result-meta { display: block; font-size: 12px; color: #6b7280; margin-top: 2px; font-weight: 500; }
        .tx-item-empty { padding: 14px 12px; color: #6b7280; font-size: 13px; text-align: center; }
        .tx-qty-label { display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
        .tx-qty-uom-label {
            display: none; font-size: 12px; font-weight: 600; color: #0d9488; white-space: nowrap;
        }
        .tx-qty-uom-label.is-visible { display: inline; }
        @media (max-width: 560px) {
            .tx-item-picker-filters { grid-template-columns: 1fr; }
        }

        .btn-action {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 5px 12px; min-width: 80px; border: 1px solid transparent;
            background: transparent; border-radius: 6px; font-size: 12px;
            font-weight: 500; transition: all 0.2s; cursor: pointer;
            text-decoration: none; white-space: nowrap; height: 32px;
        }
        .btn-action.blue { color: #3b82f6; border-color: #3b82f6; }
        .btn-action.blue:hover { background: #3b82f6; color: white; }
        .btn-action.teal { color: #0d9488; border-color: #0d9488; }
        .btn-action.teal:hover { background: #0d9488; color: white; }
        .btn-action.red { color: #dc2626; border-color: #dc2626; }
        .btn-action.red:hover { background: #dc2626; color: white; }

        .btn-entry { height: 36px; display: inline-flex; align-items: center; gap: 8px; padding: 0 16px; border-radius: 8px; font-weight: 600; font-size: 13px; transition: all 0.2s; border: 1px solid transparent; cursor: pointer; }
        .btn-in { border-color: #10b981; color: #10b981; background: transparent; }
        .btn-in:hover { background: #10b981; color: #fff; }
        .btn-out { border-color: #ef4444; color: #ef4444; background: transparent; }
        .btn-out:hover { background: #ef4444; color: #fff; }
        .btn-secondary { border-radius: 10px; height: 44px; padding: 0 24px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-weight: 600; cursor: pointer; }
        .btn-secondary:hover { background: #f9fafb; }
        .btn-save { border-radius: 10px; height: 44px; padding: 0 24px; background: #0d9488; color: #fff; border: none; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-save:hover:not(:disabled) { background: #0f766e; }
        .btn-save:disabled { opacity: 0.65; cursor: not-allowed; }
        .btn-save--danger { background: #dc2626; }
        .btn-save--danger:hover:not(:disabled) { background: #b91c1c; }

        @keyframes fadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }

        /* Standardized Toolbar Styles */
        /* Standardized Toolbar Styles */
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .toolbar-btn:hover { border-color: #111827; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* ── Filter Panel ─── */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 100002;
            overflow: hidden;
        }
        .filter-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }
        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .filter-section:last-of-type { border-bottom: none; }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-section-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-reset-link {
            font-size: 12px;
            font-weight: 600;
            color: #0d9488;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .filter-input:focus { outline: none; border-color: #0d9488; }
        .filter-date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .filter-select {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            background: #fff;
            box-sizing: border-box;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-search-wrap { position: relative; }
        .filter-search-wrap svg {
            position: absolute;
            left: 9px;
            top: 50%;
            transform: translateY(-50%);
            color: #111827;
            pointer-events: none;
        }
        .filter-search-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
        .filter-btn-reset {
            flex: 1;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        .filter-btn-reset:hover { background: #f9fafb; }

        /* ── Sort Dropdown ─── */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 200px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 100002;
            padding: 6px 0;
            overflow: hidden;
        }
        .sort-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
            transition: background 0.1s;
        }
        .sort-option:hover { background: #f9fafb; }
        .sort-option.selected { color: #0d9488; font-weight: 600; background: #f0fdfa; }
        .sort-option .check { margin-left: auto; color: #0d9488; }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title" style="margin-bottom: 4px;">Stock Movement Ledger</h1>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <?php render_branch_selector($branchCtx); ?>
            </div>
        </header>

        <main>
            <!-- Ledger Card -->
            <div class="card">
                <div id="ledger-filter-toolbar" style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;" x-data="filterPanel()">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">
                        Ledger List
                        <span style="font-size:13px; font-weight:400; color:#6b7280; margin-left:8px;">
                            (Showing <strong style="color:#1f2937;" id="showingCount"><?php echo $total_rows > 0 ? ($offset + 1) . '–' . min($offset + $per_page, $total_rows) : '0'; ?></strong> of <span id="totalCount"><?php echo number_format($total_rows); ?></span> transactions)
                        </span>
                    </h3>
                    
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                        <button type="button" onclick="openModal('purchase')" class="toolbar-btn" style="height:38px; border-color:#059669; color:#059669; background:#ecfdf5; gap:6px;">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Receive IN
                        </button>
                        <button type="button" onclick="openModal('issue')" class="toolbar-btn" style="height:38px; border-color:#dc2626; color:#dc2626; background:#fef2f2; gap:6px;">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Issue OUT
                        </button>

                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: sortOpen}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <div class="sort-option" :class="{'selected': activeSort === 'newest'}" @click="applySortFilter('newest')">
                                    Newest to Oldest
                                    <svg x-show="activeSort === 'newest'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'oldest'}" @click="applySortFilter('oldest')">
                                    Oldest to Newest
                                    <svg x-show="activeSort === 'oldest'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'az'}" @click="applySortFilter('az')">
                                    Material A → Z
                                    <svg x-show="activeSort === 'az'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'za'}" @click="applySortFilter('za')">
                                    Material Z → A
                                    <svg x-show="activeSort === 'za'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php 
                                        $initial_badge = count(array_filter([$item_id ?: '', $catalog_product_id ?: '', $type_filter, $search, $start_date, $end_date], function($v) { return $v !== null && $v !== ''; }));
                                        if ($initial_badge > 0): ?>
                                            <span class="filter-badge"><?php echo $initial_badge; ?></span>
                                        <?php endif; 
                                    ?>
                                </span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                
                                <!-- Date Range -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['start_date','end_date'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div><div class="filter-date-label">From:</div><input type="date" id="fp_start_date" class="filter-input" value="<?php echo htmlspecialchars($start_date); ?>"></div>
                                        <div><div class="filter-date-label">To:</div><input type="date" id="fp_end_date" class="filter-input" value="<?php echo htmlspecialchars($end_date); ?>"></div>
                                    </div>
                                </div>

                                <!-- Material -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Material</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['item_id'])">Reset</button>
                                    </div>
                                    <select id="fp_item_id" class="filter-select">
                                        <option value="">All Materials</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item_id']) && $_GET['item_id'] == $item['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Catalog product (Products Management / sales stock ledger uses product_id or legacy item_id/ref_id) -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Catalog product</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['catalog_product_id'])">Reset</button>
                                    </div>
                                    <select id="fp_catalog_product_id" class="filter-select">
                                        <option value="">All catalog products</option>
                                        <?php foreach ($catalog_products as $cp): ?>
                                            <?php $cpid = (int)($cp['product_id'] ?? 0); ?>
                                            <?php if ($cpid <= 0) { continue; } ?>
                                            <option value="<?php echo $cpid; ?>" <?php echo ($catalog_product_id === $cpid) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)($cp['name'] ?? ('#' . $cpid))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Trans. Type -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Trans. Type</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['type'])">Reset</button>
                                    </div>
                                    <select id="fp_type" class="filter-select">
                                        <option value="">All Types</option>
                                        <option value="IN" <?php echo ($type_filter === 'IN') ? 'selected' : ''; ?>>All STOCK-IN</option>
                                        <option value="OUT" <?php echo ($type_filter === 'OUT') ? 'selected' : ''; ?>>All STOCK-OUT</option>
                                        <option value="opening_balance" <?php echo ($type_filter === 'opening_balance') ? 'selected' : ''; ?>>Opening Balance</option>
                                        <option value="purchase" <?php echo ($type_filter === 'purchase') ? 'selected' : ''; ?>>Purchase (IN)</option>
                                        <option value="issue" <?php echo ($type_filter === 'issue') ? 'selected' : ''; ?>>Issue (OUT)</option>
                                        <option value="adjustment_up" <?php echo ($type_filter === 'adjustment_up') ? 'selected' : ''; ?>>Adj. Up (IN)</option>
                                        <option value="adjustment_down" <?php echo ($type_filter === 'adjustment_down') ? 'selected' : ''; ?>>Adj. Down (OUT)</option>
                                        <option value="return" <?php echo ($type_filter === 'return') ? 'selected' : ''; ?>>Return (IN)</option>
                                    </select>
                                </div>
                                
                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search item, notes, ref..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>Date</th>
                                <th>Item Name</th>
                                <th>Transaction Type</th>
                                <th style="text-align:right;">Quantity</th>
                                <th>Notes</th>
                                <th>Recorded by</th>
                            </tr>
                        </thead>
                        <tbody id="ledgerTableBody">
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="7" style="text-align:center; padding: 60px; color:#6b7280; font-size: 15px;">No logs found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): 
                                    $qty = (float)$t['quantity'];
                                    $isIN = ($t['direction'] === 'IN');
                                    $displayQty = $isIN ? '+' . number_format($qty, 2) : '-' . number_format($qty, 2);
                                    $qtyClass = $isIN ? 'qty-val positive' : 'qty-val negative';
                                    $badgeClass = $isIN ? 'badge-in' : 'badge-out';
                                    $displayType = str_replace('_', ' ', strtolower($t['ref_type'] ?: $t['direction'] ?: 'MOVEMENT'));
                                    
                                    $typeBadgeClass = "badge $badgeClass";
                                    $typeBadgeStyle = '';
                                    if (in_array($displayType, ['joborder', 'job order'])) {
                                        $displayType = 'customization';
                                        $typeBadgeClass = '';
                                        $typeBadgeStyle = 'display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#eef2ff;color:#4338ca;';
                                    }
                                ?>
                                    <tr>
                                        <td style="font-family:monospace;font-size:12px;color:#111827;">#TX-<?php echo $t['id']; ?></td>
                                        <td style="color:#6b7280;"><?php echo $t['transaction_date']; ?></td>
                                        <td class="truncate" style="font-weight:500;color:#111827;" title="<?php echo htmlspecialchars($t['item_name']); ?>">
                                            <?php echo htmlspecialchars($t['item_name']); ?>
                                        </td>
                                        <td><span class="<?php echo $typeBadgeClass; ?>" style="text-transform:capitalize;pointer-events:none;<?php echo $typeBadgeStyle; ?>"><?php echo $displayType; ?></span></td>
                                        <td style="text-align:right;">
                                            <span class="<?php echo $qtyClass; ?>"><?php echo $displayQty; ?></span>
                                            <span style="font-size:11px;color:#6b7280;font-weight:600;margin-left:4px;"><?php echo $t['unit']; ?></span>
                                        </td>
                                        <td class="truncate" style="font-size:12px;color:#6b7280;" title="<?php echo htmlspecialchars($t['notes'] ?: '—'); ?>"><?php echo htmlspecialchars($t['notes'] ?: '—'); ?></td>
                                        <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars($t['created_by_name'] ?: 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="ledgerPagination">
                    <?php 
                        $p = array_filter(['branch_id'=>$selectedBranchParam, 'item_id'=>($item_id > 0 ? $item_id : null), 'catalog_product_id'=>($catalog_product_id > 0 ? $catalog_product_id : null), 'type'=>$type_filter, 'search'=>$search, 'start_date'=>$start_date, 'end_date'=>$end_date, 'sort'=>$sort, 'dir'=>$dir], function($v) { return $v !== null && $v !== ''; });
                        echo render_pagination($page, $total_pages, $p); 
                    ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Transaction View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <div style="flex:1;">
                <h3 class="modal-title" style="padding-right:30px;">Transaction Details</h3>
                <p style="color:#6b7280; margin-top:2px; padding-right:30px; overflow-wrap:break-word; word-break:break-word; hyphens:auto;" id="viewModalRef"></p>
            </div>
            <button type="button" class="close-btn" onclick="document.getElementById('viewModal').style.display='none'">×</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
            <div style="grid-column:span 2; background:#f9fafb; padding:16px; border-radius:12px; border:1px solid #f3f4f6;">
                <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:4px;">Item</div>
                <div style="font-weight:700; color:#111827; overflow-wrap:break-word; word-break:break-word; hyphens:auto;" id="viewModalItem"></div>
            </div>
            <div>
                <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:4px;">Date</div>
                <div style="font-weight:600; color:#374151;" id="viewModalDate"></div>
            </div>
            <div>
                <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:4px;">Direction</div>
                <div style="font-weight:700; color:#374151;" id="viewModalDir"></div>
            </div>
            <div>
                <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:4px;">Trans. Type</div>
                <div style="color:#374151;" id="viewModalType"></div>
            </div>
            <div>
                <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:4px;">Quantity</div>
                <div style="font-weight:700; color:#374151;" id="viewModalQty"></div>
            </div>
        </div>
        <div style="margin-bottom:24px;">
            <div style="font-size:11px; font-weight:700; color:#111827; text-transform:uppercase; margin-bottom:8px;">Internal Notes</div>
            <div style="background:#f3f4f6; border-radius:10px; padding:12px; color:#374151; min-height:60px;" id="viewModalNotes"></div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; padding-top:20px; border-top:1px solid #f3f4f6;">
            <div style="color:#6b7280;">Recorded by: <span style="font-weight:600; color:#374151;" id="viewModalAdmin"></span></div>
            <button type="button" onclick="document.getElementById('viewModal').style.display='none'" class="btn-action blue">Close</button>
        </div>
    </div>
</div>

<!-- Transaction Modal -->
<div id="txModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle" style="padding-right:30px;">Record Transaction</h3>
            <button type="button" class="close-btn" onclick="closeModal()">×</button>
        </div>
        <form id="txForm" onsubmit="saveTransaction(event)">
            <input type="hidden" name="action" value="record_transaction">
            <input type="hidden" id="txType" name="transaction_type" value="">
            
            <div class="form-grid">
                <div class="form-group full">
                    <label>Resource / Material *</label>
                    <div class="tx-item-picker" id="txItemPicker">
                        <div class="tx-item-picker-filters">
                            <select id="txItemCategory" aria-label="Filter by category">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="search" id="txItemSearch" placeholder="Search by item name..." autocomplete="off" aria-label="Search item by name">
                        </div>
                        <input type="hidden" id="txItem" name="item_id" value="" required>
                        <input type="hidden" id="txUom" name="uom" value="">
                        <div id="txItemSelected" class="tx-item-selected" aria-live="polite">
                            <div>
                                <div class="tx-item-selected-name" id="txItemSelectedName"></div>
                                <div class="tx-item-selected-meta" id="txItemSelectedMeta"></div>
                            </div>
                            <button type="button" class="tx-item-clear" id="txItemClearBtn">Change</button>
                        </div>
                        <div id="txItemResults" class="tx-item-results" role="listbox" aria-label="Matching materials"></div>
                    </div>
                </div>
                
                <div class="filter-group">
                    <label for="txDate">Transaction Date *</label>
                    <input type="date" id="txDate" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="filter-group">
                    <label for="txQty" class="tx-qty-label">
                        <span>Quantity *</span>
                        <span id="txQtyUom" class="tx-qty-uom-label" aria-hidden="true"></span>
                    </label>
                    <input type="number" step="0.01" id="txQty" name="quantity" min="0.01" required placeholder="0.00">
                </div>
                
                <div class="form-group full">
                    <label for="txNotes">Internal Memo / Notes</label>
                    <input type="text" id="txNotes" name="notes" placeholder="Reason for this movement...">
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 24px; border-top: 1px solid #f3f4f6; flex-shrink: 0;">
                <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-save" id="saveBtn">Submit Entry</button>
            </div>
        </form>
    </div>
</div>

<script>
    /* var: Turbo re-runs this script; let would conflict with other admin pages (e.g. inv_items currentSort). */
    var ledgerPage = <?php echo $page; ?>;
    var currentSort = '<?php echo $sort; ?>';
    var currentDir = '<?php echo $dir; ?>';
    var searchTimer = null;
    var ledgerFetchController = null;
    var ledgerRequestSerial = 0;
    var ledgerRealtimeMs = 15000;
    var ledgerRealtimeTimer = null;
    var ledgerRealtimeBound = false;
    var TX_LEDGER_ITEMS = <?php echo json_encode($tx_modal_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var txItemPickerBound = false;
    var txSelectedItem = null;

    function filterPanel() {
        return {
            sortOpen: false,
            filterOpen: false,
            activeSort: '<?php echo $sort === 'transaction_date' ? ($dir === 'DESC' ? 'newest' : 'oldest') : ($sort === 'item_name' ? ($dir === 'ASC' ? 'az' : 'za') : 'newest'); ?>',
            get hasActiveFilters() {
                const start = document.getElementById('fp_start_date')?.value || '';
                const end = document.getElementById('fp_end_date')?.value || '';
                const item = document.getElementById('fp_item_id')?.value || '';
                const prod = document.getElementById('fp_catalog_product_id')?.value || '';
                const type = document.getElementById('fp_type')?.value || '';
                const search = document.getElementById('fp_search')?.value || '';
                
                return item || prod || type || search || start || end;
            }
        };
    }
    window.filterPanel = filterPanel;

    function printflowInitInvLedgerPage() {
        const toolbar = document.getElementById('ledger-filter-toolbar');
        if (!toolbar) return;

        const panelSearchInput = document.getElementById('fp_search');

        const onSearchInput = (sourceEl) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                fetchUpdatedTable({ page: 1 });
            }, 250);
        };

        if (panelSearchInput) {
            panelSearchInput.addEventListener('input', () => { onSearchInput(panelSearchInput); });
        }

        ['fp_item_id', 'fp_catalog_product_id', 'fp_type', 'fp_start_date', 'fp_end_date'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => {
                clearTimeout(searchTimer);
                fetchUpdatedTable({ page: 1 });
            });
        });

        initTxItemPicker();

        startLedgerRealtime();
        if (!ledgerRealtimeBound) {
            ledgerRealtimeBound = true;
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState !== 'visible') return;
                fetchUpdatedTable({}, { silent: true });
            });
            window.addEventListener('focus', function() {
                fetchUpdatedTable({}, { silent: true });
            });
            document.addEventListener('turbo:before-cache', function() {
                if (ledgerRealtimeTimer) {
                    clearInterval(ledgerRealtimeTimer);
                    ledgerRealtimeTimer = null;
                }
                if (ledgerFetchController) {
                    ledgerFetchController.abort();
                    ledgerFetchController = null;
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', printflowInitInvLedgerPage);
    } else {
        printflowInitInvLedgerPage();
    }
    document.addEventListener('printflow:page-init', printflowInitInvLedgerPage);

    function buildFilterURL(overrides = {}, isAjax = false) {
        const params = new URLSearchParams(window.location.search);
        
        const map = {
            'item_id': 'fp_item_id',
            'catalog_product_id': 'fp_catalog_product_id',
            'type': 'fp_type',
            'search': 'fp_search',
            'start_date': 'fp_start_date',
            'end_date': 'fp_end_date'
        };

        for (const [param, id] of Object.entries(map)) {
            const val = document.getElementById(id)?.value;
            if (val) params.set(param, val);
            else params.delete(param);
        }

        if (overrides.page !== undefined) params.set('page', overrides.page);
        else if (ledgerPage > 1) params.set('page', ledgerPage);

        if (overrides.sort !== undefined) {
            params.set('sort', overrides.sort);
            currentSort = overrides.sort;
        } else {
            params.set('sort', currentSort);
        }

        if (overrides.dir !== undefined) {
            params.set('dir', overrides.dir);
            currentDir = overrides.dir;
        } else {
            params.set('dir', currentDir);
        }

        if (isAjax) params.set('ajax', '1');
        else params.delete('ajax');

        return window.location.pathname + '?' + params.toString();
    }

    function startLedgerRealtime() {
        if (ledgerRealtimeTimer) {
            clearInterval(ledgerRealtimeTimer);
        }
        ledgerRealtimeTimer = setInterval(function() {
            if (document.visibilityState !== 'visible') return;
            fetchUpdatedTable({}, { silent: true });
        }, ledgerRealtimeMs);
    }

    async function fetchUpdatedTable(overrides = {}, options = {}) {
        const silent = !!options.silent;
        const url = buildFilterURL(overrides, true);
        ledgerRequestSerial += 1;
        const requestSerial = ledgerRequestSerial;
        if (ledgerFetchController) {
            ledgerFetchController.abort();
        }
        ledgerFetchController = new AbortController();

        try {
            const resp = await fetch(url, { signal: ledgerFetchController.signal });
            if (!resp.ok) throw new Error('Request failed with status ' + resp.status);
            const rawText = await resp.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (_parseErr) {
                const possibleJson = rawText.slice(rawText.indexOf('{'));
                data = JSON.parse(possibleJson);
            }
            if (requestSerial !== ledgerRequestSerial) return;
            if (data.success) {
                const tbody = document.getElementById('ledgerTableBody');
                const pagination = document.getElementById('ledgerPagination');
                const showingText = document.getElementById('showingCount');
                const totalText = document.getElementById('totalCount');
                const badgeCont = document.getElementById('filterBadgeContainer');

                if (tbody) {
                    if (!silent) tbody.style.opacity = '0.5';
                    tbody.innerHTML = data.table;
                    if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                        try {
                            Alpine.initTree(tbody);
                        } catch (e) {
                            console.error(e);
                        }
                    }
                }
                if (pagination) pagination.innerHTML = data.pagination;
                if (showingText) {
                    showingText.textContent = data.startIdx + '–' + data.endIdx;
                }
                if (totalText) totalText.textContent = data.total;
                
                if (badgeCont) {
                    badgeCont.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                }
                if (tbody && !silent) tbody.style.opacity = '1';

                if (overrides.page !== undefined) ledgerPage = overrides.page;

                if (overrides.page !== undefined) ledgerPage = overrides.page;

                const displayUrl = buildFilterURL(overrides, false);
                window.history.replaceState({ path: displayUrl }, '', displayUrl);
            }
        } catch (e) {
            if (e.name === 'AbortError') return;
            console.error('Error updating table:', e);
            const tbody = document.getElementById('ledgerTableBody');
            if (tbody && !silent) tbody.style.opacity = '1';
        } finally {
            if (requestSerial === ledgerRequestSerial) {
                ledgerFetchController = null;
            }
        }
    }

    function applyFilters(reset = false) {
        if (reset) {
            const resetUrl = new URL(window.location.href);
            const branch = resetUrl.searchParams.get('branch_id');
            resetUrl.search = '';
            if (branch) resetUrl.searchParams.set('branch_id', branch);
            window.location.href = resetUrl.pathname + (resetUrl.search ? '?' + resetUrl.searchParams.toString() : '');
        } else {
            fetchUpdatedTable({ page: 1 });
        }
    }

    function applySortFilter(sortKey) {
        let sort = 'transaction_date';
        let dir = 'DESC';

        if (sortKey === 'newest') { sort = 'transaction_date'; dir = 'DESC'; }
        else if (sortKey === 'oldest') { sort = 'transaction_date'; dir = 'ASC'; }
        else if (sortKey === 'az') { sort = 'item_name'; dir = 'ASC'; }
        else if (sortKey === 'za') { sort = 'item_name'; dir = 'DESC'; }
        
        const root = document.getElementById('ledger-filter-toolbar');
        if (root && root._x_dataStack) {
            const data = root._x_dataStack[0];
            data.activeSort = sortKey;
            data.sortOpen = false;
        }

        fetchUpdatedTable({ sort: sort, dir: dir, page: 1 });
    }

    function resetFilterField(fields) {
        fields.forEach(f => {
            const el = document.getElementById('fp_' + f);
            if (el) el.value = '';
        });
        fetchUpdatedTable({ page: 1 });
    }

    function goToLedgerPage(page) {
        fetchUpdatedTable({ page: page });
    }

    function viewTransaction(t) {
        const isIN = (t.direction === 'IN');
        const qty = parseFloat(t.quantity);
        const displayQty = isIN ? '+' + qty.toFixed(2) : '-' + qty.toFixed(2);
        document.getElementById('viewModalRef').textContent = t.reference_label || ('#TX-' + t.id);
        document.getElementById('viewModalDate').textContent = t.transaction_date;
        document.getElementById('viewModalItem').textContent = t.item_name;
        document.getElementById('viewModalItem').style.textTransform = 'none';
        
        let typeStr = (t.ref_type || t.direction || 'MOVEMENT').replace('_',' ').toLowerCase();
        if (typeStr === 'joborder' || typeStr === 'job order') typeStr = 'customization';
        
        document.getElementById('viewModalType').textContent = typeStr;
        document.getElementById('viewModalType').style.textTransform = 'capitalize';
        document.getElementById('viewModalDir').textContent = t.direction;
        document.getElementById('viewModalDir').style.color = isIN ? '#059669' : '#dc2626';
        document.getElementById('viewModalQty').textContent = displayQty + ' ' + t.unit;
        document.getElementById('viewModalQty').style.color = isIN ? '#059669' : '#dc2626';
        document.getElementById('viewModalNotes').textContent = t.notes || 'No notes.';
        document.getElementById('viewModalAdmin').textContent = t.created_by_name || 'System';
        document.getElementById('viewModal').style.display = 'flex';
    }

    function pfFormatTxSoh(item) {
        if (!item) return '';
        return item.soh_display + ' ' + item.uom;
    }

    function pfApplyTxQtyInputRules(uom) {
        const qtyEl = document.getElementById('txQty');
        if (!qtyEl) return;
        if (uom === 'pcs') {
            qtyEl.step = '1';
            qtyEl.min = '1';
            qtyEl.placeholder = '0';
        } else {
            qtyEl.step = '0.01';
            qtyEl.min = '0.01';
            qtyEl.placeholder = '0.00';
        }
    }

    function pfUpdateTxQtyUom(item) {
        const uomEl = document.getElementById('txQtyUom');
        const uomHidden = document.getElementById('txUom');
        if (uomEl) {
            if (item) {
                uomEl.textContent = '(' + item.uom_label + ')';
                uomEl.classList.add('is-visible');
                uomEl.setAttribute('aria-hidden', 'false');
            } else {
                uomEl.textContent = '';
                uomEl.classList.remove('is-visible');
                uomEl.setAttribute('aria-hidden', 'true');
            }
        }
        if (uomHidden) uomHidden.value = item ? item.uom : '';
        pfApplyTxQtyInputRules(item ? item.uom : '');
    }

    function pfFilterTxLedgerItems() {
        const cat = document.getElementById('txItemCategory')?.value || '';
        const q = (document.getElementById('txItemSearch')?.value || '').trim().toLowerCase();
        return (TX_LEDGER_ITEMS || []).filter(function (item) {
            if (cat && String(item.category_id) !== String(cat)) return false;
            if (q && !(item.name || '').toLowerCase().includes(q)) return false;
            return true;
        }).slice(0, 80);
    }

    function pfRenderTxItemResults() {
        const resultsEl = document.getElementById('txItemResults');
        const searchEl = document.getElementById('txItemSearch');
        if (!resultsEl || txSelectedItem) return;

        const matches = pfFilterTxLedgerItems();
        if (!matches.length) {
            const hasQuery = !!(searchEl && searchEl.value.trim()) || !!(document.getElementById('txItemCategory')?.value);
            resultsEl.innerHTML = '<div class="tx-item-empty">' + (hasQuery ? 'No materials match your search.' : 'Type a name or pick a category to find materials.') + '</div>';
            return;
        }

        resultsEl.innerHTML = matches.map(function (item) {
            const cat = item.category_name ? escapeHtml(item.category_name) + ' · ' : '';
            return '<button type="button" class="tx-item-result" data-id="' + item.id + '">' +
                '<span>' + escapeHtml(item.name) + '</span>' +
                '<span class="tx-item-result-meta">' + cat + 'SOH: ' + escapeHtml(pfFormatTxSoh(item)) + ' · ' + escapeHtml(item.uom_label) + '</span>' +
                '</button>';
        }).join('');

        resultsEl.querySelectorAll('.tx-item-result').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const id = parseInt(btn.getAttribute('data-id'), 10);
                const found = (TX_LEDGER_ITEMS || []).find(function (it) { return it.id === id; });
                if (found) pfSelectTxLedgerItem(found);
            });
        });
    }

    function pfSelectTxLedgerItem(item) {
        txSelectedItem = item;
        const hidden = document.getElementById('txItem');
        const selectedWrap = document.getElementById('txItemSelected');
        const nameEl = document.getElementById('txItemSelectedName');
        const metaEl = document.getElementById('txItemSelectedMeta');
        const resultsEl = document.getElementById('txItemResults');
        const searchEl = document.getElementById('txItemSearch');
        const catEl = document.getElementById('txItemCategory');

        if (hidden) hidden.value = String(item.id);
        if (nameEl) nameEl.textContent = item.name;
        if (metaEl) {
            const cat = item.category_name ? item.category_name + ' · ' : '';
            metaEl.textContent = cat + 'On hand: ' + pfFormatTxSoh(item) + ' · ' + item.uom_label;
        }
        if (selectedWrap) selectedWrap.classList.add('is-visible');
        if (resultsEl) resultsEl.innerHTML = '';
        if (searchEl) { searchEl.value = ''; searchEl.style.display = 'none'; }
        if (catEl) catEl.style.display = 'none';
        pfUpdateTxQtyUom(item);
        document.getElementById('txQty')?.focus();
    }

    function pfClearTxLedgerItemSelection() {
        txSelectedItem = null;
        const hidden = document.getElementById('txItem');
        const selectedWrap = document.getElementById('txItemSelected');
        const searchEl = document.getElementById('txItemSearch');
        const catEl = document.getElementById('txItemCategory');

        if (hidden) hidden.value = '';
        if (selectedWrap) selectedWrap.classList.remove('is-visible');
        if (searchEl) { searchEl.style.display = ''; searchEl.value = ''; }
        if (catEl) { catEl.style.display = ''; catEl.value = ''; }
        pfUpdateTxQtyUom(null);
        pfRenderTxItemResults();
        searchEl?.focus();
    }

    function pfResetTxItemPicker() {
        pfClearTxLedgerItemSelection();
    }

    function initTxItemPicker() {
        if (txItemPickerBound) return;
        txItemPickerBound = true;

        const searchEl = document.getElementById('txItemSearch');
        const catEl = document.getElementById('txItemCategory');
        const clearBtn = document.getElementById('txItemClearBtn');
        const picker = document.getElementById('txItemPicker');

        if (searchEl) {
            searchEl.addEventListener('input', pfRenderTxItemResults);
            searchEl.addEventListener('focus', pfRenderTxItemResults);
        }
        if (catEl) catEl.addEventListener('change', pfRenderTxItemResults);
        if (clearBtn) clearBtn.addEventListener('click', pfClearTxLedgerItemSelection);
        if (picker) {
            picker.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && txSelectedItem) pfClearTxLedgerItemSelection();
            });
        }
        pfUpdateTxQtyUom(null);
    }

    function openModal(mode) {
        document.getElementById('txModal').style.display = 'flex';
        const form = document.getElementById('txForm');
        form.reset();
        document.getElementById('txDate').value = new Date().toISOString().split('T')[0];
        pfResetTxItemPicker();
        
        if (mode === 'issue') {
            document.getElementById('modalTitle').textContent = 'Issue Material (STOCK-OUT)';
            document.getElementById('txType').value = 'issue';
            document.getElementById('saveBtn').textContent = 'Submit Entry';
            document.getElementById('saveBtn').classList.add('btn-save--danger');
        } else if (mode === 'purchase') {
            document.getElementById('modalTitle').textContent = 'Receive Stock (STOCK-IN)';
            document.getElementById('txType').value = 'purchase';
            document.getElementById('saveBtn').textContent = 'Submit Entry';
            document.getElementById('saveBtn').classList.remove('btn-save--danger');
        }
    }

    function closeModal() {
        document.getElementById('txModal').style.display = 'none';
        document.getElementById('saveBtn').classList.remove('btn-save--danger');
    }

    async function saveTransaction(e) {
        e.preventDefault();
        const itemId = parseInt(document.getElementById('txItem')?.value || '0', 10);
        if (!itemId) {
            alert('Please search and select a material.');
            document.getElementById('txItemSearch')?.focus();
            return;
        }

        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.textContent = 'Recording...';

        const formData = new FormData(document.getElementById('txForm'));
        try {
            const base = window.location.pathname.replace(/\/[^/]*$/, '/');
            const apiUrl = base + 'inventory_transactions_api.php';
            const res = await fetch(apiUrl, { method: 'POST', body: formData });
            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (_) {
                console.error('API response:', rawText);
                alert('Invalid response from server. Check console for details.');
                return;
            }
            if (data.success) {
                closeModal();
                fetchUpdatedTable();
                
                if (data.fifo_deductions && data.fifo_deductions.length > 0) {
                    let summary = 'FIFO Stock-Out Summary:\n\n';
                    data.fifo_deductions.forEach(d => {
                        summary += `  Deducted: ${parseFloat(d.deducted).toFixed(2)} ft\n`;
                        summary += `  Was: ${parseFloat(d.was).toFixed(2)} ft → Now: ${parseFloat(d.now).toFixed(2)} ft`;
                        if (d.status === 'FINISHED') summary += ' (FINISHED)';
                        summary += '\n\n';
                    });
                    alert(summary);
                }
            } else {
                const errMsg = data.error || (data.errors ? Object.values(data.errors).join(' ') : 'Unknown error');
                alert('Error: ' + errMsg);
            }
        } catch (err) {
            console.error('Network error:', err);
            alert('Network failure. Check that the server is running and the API URL is correct.');
        } 
        finally { btn.disabled = false; btn.textContent = 'Submit Entry'; }
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    window.addEventListener('click', e => {
        if (e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    });

    // Helper to sync search across UI if needed
    window.addEventListener('popstate', (event) => {
        location.reload(); 
    });

    // Page-specific initialization is handled above via printflowInitInvLedgerPage.
</script>
</body>
</html>
