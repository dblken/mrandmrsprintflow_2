<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/inventory_stock_status.php';

require_role(['Admin', 'Manager']);
printflow_ensure_inv_items_threshold_schema();
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}
$current_user = get_logged_in_user();
$is_manager = (($current_user['role'] ?? '') === 'Manager');
$branchCtx = init_branch_context(false);
$selectedBranchId = $branchCtx['selected_branch_id'] ?? InventoryManager::getCurrentBranchId();
$selectedBranchParam = ($selectedBranchId === 'all') ? 'all' : (string)(int)$selectedBranchId;
$branchId = ($selectedBranchId === 'all') ? 0 : (int)$selectedBranchId;
$is_admin_cross_branch_view = (($current_user['role'] ?? '') === 'Admin') && $branchId > 0 && !InventoryManager::isMainBranch($branchId);
$inventory_read_only = $is_admin_cross_branch_view;
$can_manage_item_master = !$is_manager && !$inventory_read_only;
$page_title = $is_manager ? 'Inventory Items - Manager' : 'Inventory Items - Admin';

function normalize_inventory_uom(?string $uom, ?string $categoryName = null): string {
    $normalized = strtolower(trim((string)$uom));
    $categoryNormalized = strtoupper(trim((string)$categoryName));

    if ($categoryNormalized === 'INK L120' || $categoryNormalized === 'INK L130') {
        return 'l';
    }

    if ($normalized === 'btl') return 'l';
    if (in_array($normalized, ['pcs', 'ft', 'l'], true)) return $normalized;
    return 'pcs';
}

// Get parameters
$cat_id   = (int)($_GET['category_id'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$sort     = $_GET['sort'] ?? 'id';
$dir      = strtoupper($_GET['dir'] ?? 'DESC') === 'DESC' ? 'DESC' : 'ASC';
$page     = max(1, (int)($_GET['page'] ?? 1));
$track_by = isset($_GET['track_by_roll']) && $_GET['track_by_roll'] !== '' ? (int)$_GET['track_by_roll'] : null;
$stock_status = trim($_GET['stock_status'] ?? ''); // 'in', 'low', 'critical', 'out'
$per_page = 15;

// Inventory Archive/Restore (AJAX)
if (isset($_POST['archive_item']) || isset($_POST['restore_item'])) {
    header('Content-Type: application/json');
    if (!$can_manage_item_master) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This branch is view-only. Inventory items cannot be changed here.']);
        exit;
    }
    $item_id = (int)($_POST['item_id'] ?? 0);
    if (!$item_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid item_id']);
        exit;
    }

    $targetStatus = isset($_POST['archive_item']) ? 'INACTIVE' : 'ACTIVE';
    db_execute(
        "UPDATE inv_items SET status = ?, updated_at = NOW() WHERE id = ?",
        'si',
        [$targetStatus, $item_id]
    );

    echo json_encode(['success' => true]);
    exit;
}

// Archived Items overlay data (AJAX)
if (isset($_GET['get_archived_items'])) {
    header('Content-Type: application/json');
    if (!$can_manage_item_master) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This branch is view-only. Archived inventory items are unavailable here.']);
        exit;
    }
    $archived = db_query(
        "SELECT i.*, c.name as category_name
         FROM inv_items i
         LEFT JOIN inv_categories c ON i.category_id = c.id
         WHERE i.status = 'INACTIVE'
         ORDER BY i.updated_at DESC, i.id DESC"
    ) ?: [];

    $html = '<div class="overflow-x-auto"><table class="inv-table" style="width:100%;border-collapse:collapse;font-size:13px;">';
    $html .= '<thead><tr><th>Material Name</th><th>Category</th><th style="text-align:right;">Actions</th></tr></thead><tbody>';

    if (empty($archived)) {
        $html .= '<tr><td colspan="3" style="padding:40px;text-align:center;color:#9ca3af;">No archived materials found.</td></tr>';
    } else {
        foreach ($archived as $it) {
            $name = htmlspecialchars($it['name'] ?? '', ENT_QUOTES);
            $cat = preg_replace('/\\s*\\((pcs|ft|btl)\\)\\s*$/i', '', (string)($it['category_name'] ?? '')) ?: ($it['category_name'] ?? 'Uncategorized');
            $cat = htmlspecialchars($cat, ENT_QUOTES);
            $id = (int)$it['id'];
            $html .= '<tr>';
            $html .= '<td class="truncate" style="font-weight:500;text-transform:capitalize;" title="' . $name . '">' . $name . '</td>';
            $html .= '<td class="truncate" style="color:#6b7280;font-size:12px;" title="' . $cat . '">' . $cat . '</td>';
            $html .= '<td style="text-align:right; white-space:nowrap;">';
            // Use single-quoted JS literals to avoid breaking the HTML onclick attribute.
            $html .= '<button type="button" class="btn-action teal" onclick="event.stopPropagation(); openItemStatusConfirm(' . $id . ', \'ACTIVE\')">Restore</button>';
            $html .= '</td>';
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table></div>';
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// Build Query
$sql = "SELECT i.*, c.name as category_name 
        FROM inv_items i 
        LEFT JOIN inv_categories c ON i.category_id = c.id 
        WHERE 1=1
          AND i.status = 'ACTIVE'
          AND i.name NOT LIKE '%(Archived)%'
          AND i.name NOT LIKE '%(Legacy)%'";
$params = [];
$types = '';

if ($cat_id) {
    $sql .= " AND i.category_id = ?";
    $params[] = $cat_id;
    $types .= 'i';
}
if ($track_by !== null) {
    $sql .= " AND i.track_by_roll = ?";
    $params[] = $track_by;
    $types .= 'i';
}
if ($search) {
    $st = '%' . $search . '%';
    $sql .= " AND (i.name LIKE ? OR i.sku LIKE ?)";
    $params[] = $st; $params[] = $st;
    $types .= 'ss';
}

// Count total for pagination
$count_sql = "SELECT COUNT(*) as total FROM ({$sql}) as wrap";
$total_rows_unfiltered = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;

// If stock status filter is applied, we need to fetch all items first to filter by stock
if ($stock_status) {
    // Fetch all items without pagination
    $all_items = db_query($sql, $types ?: null, $params ?: null) ?: [];
    
    // Add stock info and apply stock status filter
    $filtered_items = [];
    foreach ($all_items as $item) {
        $item['current_stock'] = InventoryManager::getStockOnHand($item['id'], $branchId);
        
        $stock = (float)$item['current_stock'];
        $reorder = printflow_item_reorder_level($item);
        $critical = printflow_item_critical_level($item);
        $status = printflow_resolve_stock_status($stock, $reorder, $critical);
        if (!printflow_stock_matches_filter($status, $stock_status)) {
            continue;
        }
        
        $filtered_items[] = $item;
    }
    
    // Calculate pagination based on filtered results
    $total_rows = count($filtered_items);
    $total_pages = max(1, ceil($total_rows / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    
    // Apply pagination to filtered items
    $items = array_slice($filtered_items, $offset, $per_page);
} else {
    // No stock filter - use normal pagination
    $total_rows = $total_rows_unfiltered;
    $total_pages = max(1, ceil($total_rows / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    
    $sort_map = [
        'name' => 'i.name',
        'id'   => 'i.id'
    ];
    $orderBy = $sort_map[$sort] ?? 'i.name';
    $sql .= " ORDER BY $orderBy $dir LIMIT $per_page OFFSET $offset";
    
    $items = db_query($sql, $types ?: null, $params ?: null) ?: [];
    
    // Add stock info
    foreach ($items as &$item) {
        $item['current_stock'] = InventoryManager::getStockOnHand($item['id'], $branchId);
    }
    unset($item);
}

// Get categories for filters
$categories = db_query("SELECT * FROM inv_categories ORDER BY sort_order ASC, name ASC") ?: [];

// Safe JSON for inline <script> (invalid UTF-8 or encode failure must not break JS)
$items_js_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $items_js_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$items_js = json_encode($items, $items_js_flags);
if ($items_js === false) {
    $items_js = '[]';
}

// AJAX Partial Response
if (isset($_GET['ajax'])) {
    ob_start();
    if (empty($items)): ?>
        <tr id="emptyItemsRow"><td colspan="7" class="py-12 text-center text-gray-500">No inventory items matching the filter.</td></tr>
    <?php else: 
        foreach ($items as $item): 
            $stock = (float)$item['current_stock'];
            $reorder = printflow_item_reorder_level($item);
            $critical = printflow_item_critical_level($item);
            $status = printflow_resolve_stock_status($stock, $reorder, $critical);
            $stockColor = $status['text_color'];
            
            $trackBadge = $item['track_by_roll'] == 1
                ? '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#eef2ff;color:#4338ca;">Roll-Based</span>'
                : '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f3f4f6;color:#4b5563;">Standard</span>';
            
            $statusBadge = printflow_stock_status_badge_html($status);
            $inactiveBadge = $item['status'] === 'INACTIVE' ? '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f3f4f6;color:#6b7280;margin-left:6px;">Inactive</span>' : '';
    ?>
            <tr class="<?php echo htmlspecialchars($status['row_class'], ENT_QUOTES, 'UTF-8'); ?>" style="cursor:pointer;" onclick="openStockCard(<?php echo $item['id']; ?>)">
                <td style="font-weight:500;text-transform:capitalize;"><?php echo htmlspecialchars($item['name']); ?><?php echo $statusBadge . $inactiveBadge; ?></td>
                <?php
                    $catDispRaw = (string)($item['category_name'] ?? '');
                    $catDisp = preg_replace('/\\s*\\((pcs|ft|btl)\\)\\s*$/i', '', $catDispRaw) ?: $catDispRaw;
                ?>
                <td><?php echo htmlspecialchars($catDisp ?: 'Uncategorized'); ?></td>
                <td><?php echo $trackBadge; ?></td>
                <td><span class="font-semibold" style="white-space:nowrap;">&#8369;<?php echo number_format($item['unit_cost'], 2); ?></span></td>
                <?php $displayUom = normalize_inventory_uom($item['unit_of_measure'] ?? '', $item['category_name'] ?? ''); ?>
                <td><span class="stock-val" style="color:<?php echo $stockColor; ?>;"><?php echo $displayUom === 'pcs' ? (int)$stock : number_format($stock, 2); ?></span></td>
                <td style="color:#6b7280;font-size:12px;"><?php echo $displayUom === 'l' ? 'Liter (L)' : htmlspecialchars($displayUom); ?></td>
                <td class="no-truncate" style="text-align:right;">
                    <?php if (!$inventory_read_only): ?>
                    <button type="button" class="btn-action teal" onclick="event.stopPropagation(); openAddStockModalById(<?php echo $item['id']; ?>)">+ Stock</button>
                    <?php else: ?>
                    <button type="button" class="btn-action" onclick="event.stopPropagation(); openStockCard(<?php echo $item['id']; ?>)" style="border-color:#d1d5db;color:#374151;">View</button>
                    <?php endif; ?>
                    <?php if ($can_manage_item_master): ?>
                    <button type="button" class="btn-action blue" onclick="event.stopPropagation(); editItemById(<?php echo $item['id']; ?>)">Edit</button>
                    <?php endif; ?>
                </td>
            </tr>
    <?php endforeach; ?>
    <?php endif; ?>
<?php
    $table_html = ob_get_clean();

    ob_start();
    $p = array_filter(['branch_id'=>$selectedBranchParam, 'category_id'=>$cat_id, 'search'=>$search, 'sort'=>$sort, 'dir'=>$dir, 'track_by_roll'=>$track_by, 'stock_status'=>$stock_status], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $p);
    $pagination_html = ob_get_clean();

    $badge_count = count(array_filter([$cat_id ?: '', $search, $track_by, $stock_status], function($v) { return $v !== null && $v !== ''; }));

    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_rows),
        'badge'      => $badge_count,
        'startIdx'   => $total_rows > 0 ? $offset + 1 : 0,
        'endIdx'     => min($offset + $per_page, $total_rows),
        'total'      => $total_rows,
        'items'      => $items // Keep returning items for Alpine functions like find()
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        [x-cloak] { display: none !important; }
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --glass-bg: rgba(255, 255, 255, 0.8);
            --glass-border: rgba(255, 255, 255, 0.3);
        }

        .header-actions { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; padding: 24px; background: var(--glass-bg); backdrop-filter: blur(10px); border-radius: 16px; border: 1px solid var(--glass-border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 11px; font-weight: 700; text-transform: capitalize; color: #6b7280; letter-spacing: 0.025em; }
        .filter-group input, .filter-group select { padding: 10px 16px; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 14px; transition: all 0.2s; background: #fff; }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        
        .inv-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .inv-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .inv-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .no-truncate { max-width: none !important; overflow: visible !important; white-space: nowrap !important; text-overflow: clip !important; }
        .inv-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .inv-table tbody tr:hover td { background: #f9fafb; }
        .inv-table tbody tr:last-child td { border-bottom: none; }
        
        .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid transparent; }
        .badge-green { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .badge-red { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .badge-gray { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
        .badge-indigo { background: #eef2ff; color: #4338ca; border-color: #c7d2fe; }
        
        .stock-val { font-weight: 700; font-variant-numeric: tabular-nums; font-size: 15px; }
        .locked-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            cursor: default;
        }
        .locked-select:disabled {
            color: #374151;
            opacity: 1;
        }
        /* When enabled (e.g., Tarpaulin UOM override), show dropdown arrow. */
        .locked-select:not(:disabled) {
            appearance: auto;
            -webkit-appearance: auto;
            -moz-appearance: auto;
            cursor: pointer;
        }
        .btn-action {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 5px 12px; min-width: 80px; border: 1px solid transparent;
            background: transparent; border-radius: 6px; font-size: 12px;
            font-weight: 500; transition: all 0.2s; cursor: pointer;
            text-decoration: none; white-space: nowrap; height: 32px;
        }
        .btn-action.teal { color: #14b8a6; border-color: #14b8a6; }
        .btn-action.teal:hover { background: #14b8a6; color: white; }
        .btn-action.blue { color: #3b82f6; border-color: #3b82f6; }
        .btn-action.blue:hover { background: #3b82f6; color: white; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: white; }

        /* Legacy support for page-specific coloring if needed */
        .btn-edit { color: #4f46e5; background: #eef2ff; border: 1px solid #e0e7ff; }
        .btn-edit:hover { background: #6366f1; color: #fff; transform: translateY(-1px); }
        
        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 110000; align-items: center; justify-content: center; padding: 16px; overflow-y: auto; pointer-events: auto !important; }
        .modal-content { background: white; border-radius: 20px; width: 90%; max-width: 800px; position: relative; max-height: 90vh; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); z-index: 110001; animation: fadeIn 0.3s ease forwards; pointer-events: auto !important; display: flex; flex-direction: column; overflow: hidden; margin: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px !important; flex-shrink: 0; border-bottom: 1px solid #e5e7eb; }
        .modal-body { padding: 24px !important; overflow-y: auto; flex: 1; }
        .modal-title { font-size: 18px; font-weight: 700; color: #111827; padding-right: 40px; overflow-wrap: break-word; word-break: break-word; -webkit-hyphens: auto; -ms-hyphens: auto; hyphens: auto; line-height: 1.4; margin: 0; }
        .close-btn { background: none; border: none; font-size: 20px; color: #9ca3af; cursor: pointer; padding: 4px; line-height: 1; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.2s; flex-shrink: 0; }
        .close-btn:hover { color: #374151; }
        
        .modal input, .modal select, .modal textarea { pointer-events: auto !important; cursor: text; }
        .modal label { margin-bottom: 6px; display: block; font-weight: 700; color: #4b5563; font-size: 12px; text-transform: uppercase; letter-spacing: 0.025em; }
        
        /* New Modal Grid System */
        .modal-section { margin-bottom: 32px; }
        .modal-section:last-child { margin-bottom: 0; }
        .section-header { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; margin-bottom: 16px; border-bottom: 1px solid #f3f4f6; padding-bottom: 8px; }
        
        .form-row-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; margin-bottom: 20px; align-items: flex-start; }
        .form-col-full { grid-column: span 2; }

        /* Item modal: create vs edit layout */
        .item-modal-threshold-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 20px;
            align-items: start;
        }
        .item-modal--edit .item-modal-threshold-grid {
            grid-template-columns: minmax(0, 1.15fr) repeat(3, minmax(0, 1fr));
        }
        .item-modal-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .item-modal-field .field-hint {
            font-size: 10px;
            color: #9ca3af;
            line-height: 1.4;
            margin: 0;
            min-height: 28px;
        }
        .item-modal-field input[readonly],
        .item-modal-field select {
            height: 42px;
            box-sizing: border-box;
        }
        .item-modal-bottom-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
            align-items: stretch;
        }
        .item-modal-panel {
            background: #fcfcfd;
            border: 1px solid #f3f4f6;
            padding: 18px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 100%;
        }
        .item-modal-panel--roll {
            background: #f0f9ff;
            border-color: #bae6fd;
        }
        .item-modal--create #previewStatusSection,
        .item-modal--create #statusSection,
        .item-modal--create #reorderAlertsGroup,
        .item-modal--create #editModalChangeSummary {
            display: none !important;
        }
        
        /* Proportional Widths */
        .w-35 { width: 35% !important; min-width: 140px; }
        .w-40 { width: 40% !important; min-width: 150px; }
        .w-50 { width: 50% !important; }
        .w-60 { width: 60% !important; }
        .w-100 { width: 100% !important; }

        @media (max-width: 600px) {
            .form-row-grid { grid-template-columns: 1fr; gap: 16px; }
            .form-col-full { grid-column: span 1; }
            .w-35, .w-40, .w-50 { width: 100% !important; }
            .item-modal-threshold-grid,
            .item-modal--edit .item-modal-threshold-grid { grid-template-columns: 1fr; }
            .item-modal-bottom-grid { grid-template-columns: 1fr; }
        }

        .preview-badge { display: inline-flex; align-items: center; justify-content: center; height: 44px; padding: 0 16px; border-radius: 10px; background: #f9fafb; border: 1px solid #e5e7eb; font-size: 13px; font-weight: 600; color: #374151; width: 100%; box-sizing: border-box; }

        /* Validation Styles */
        .field-error { color: #dc2626; font-size: 11px; margin-top: 4px; display: none; font-weight: 500; }
        .input-error { border-color: #dc2626 !important; background-color: #fffafb !important; }
        .input-error:focus { box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1) !important; }
        .input-success { border-color: #10b981 !important; }
        .input-warning { border-color: #f59e0b !important; box-shadow: 0 0 0 3px rgba(245,158,11,0.15) !important; }
        
        #saveBtn:disabled { opacity: 0.6; cursor: not-allowed; filter: grayscale(1); }
        .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; margin-right: 8px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Premium Stock Card Modal */
        .sc-cards-grid { display: flex; justify-content: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .sc-card { flex: 1; min-width: 140px; max-width: 160px; border-radius: 12px; padding: 14px; text-align: center; }
        @media (max-width: 600px) {
            .sc-card { flex: 1 1 calc(50% - 12px); max-width: none; }
        }
        .kpi-mini-card { padding: 16px; border-radius: 16px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .kpi-mini-card .label { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: capitalize; margin-bottom: 2px; }
        .kpi-mini-card .value { font-size: 20px; font-weight: 800; color: #1f2937; }
        
        .roll-list-table { width: 100%; margin-top: 24px; font-size: 13px; }
        .roll-list-table th { text-align: left; padding: 8px; font-weight: 700; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .roll-list-table td { padding: 12px 8px; border-bottom: 1px solid #f3f4f6; }

        .chart-container { height: 200px; margin-top: 24px; padding: 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; }

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
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* Filter panel */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 100;
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

        /* Sort dropdown */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 200px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
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

        /* Low stock row highlight */
        .low-stock-row td { background-color: #fff5f5 !important; color: #1f2937 !important; }
        .low-stock-row:hover td { background-color: #fee2e2 !important; }
        @media (max-width: 768px) {
            .pf-inventory-mobile-hide {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Inventory Items</h1>
            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                <?php render_branch_selector($branchCtx); ?>
            </div>
        </header>

        <main>
            <!-- Items Card -->
            <div class="card">
                <?php if ($inventory_read_only): ?>
                <div style="margin-bottom:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;color:#4b5563;font-size:13px;">
                    This branch is view-only. You can review inventory for the selected branch, but stock changes and item edits are disabled.
                </div>
                <?php endif; ?>
                <div id="inv-filter-toolbar" style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;" x-data="filterPanel()">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">
                        Inventory Items List
                        <span class="pf-inventory-mobile-hide" style="font-size:13px; font-weight:400; color:#6b7280; margin-left:8px;">
                            (Showing <strong style="color:#1f2937;" id="showingCount"><?php echo $total_rows > 0 ? ($offset + 1) . '&ndash;' . min($offset + $per_page, $total_rows) : '0'; ?></strong><span id="invShowingMeta"> of <?php echo number_format($total_rows); ?> items)</span>
                        </span>
                    </h3>
                    
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                        <?php if ($inventory_read_only): ?>
                        <span style="display:inline-flex;align-items:center;height:38px;padding:0 12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;color:#4b5563;font-size:12px;font-weight:600;white-space:nowrap;">
                            Read Only
                        </span>
                        <?php endif; ?>
                        <?php if ($can_manage_item_master): ?>
                        <button type="button" class="toolbar-btn" onclick="openModal('create')" style="height:38px; border-color:#3b82f6; color:#3b82f6;">Add Item</button>

                        <button type="button" class="toolbar-btn" onclick="openItemsArchiveModal()" style="height:38px; border-color:#6b7280; color:#6b7280;">
                            Archived Items
                        </button>
                        <?php endif; ?>
                        
                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: sortOpen || (activeSort !== 'newest')}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
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
                                    A &rarr; Z
                                    <svg x-show="activeSort === 'az'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'za'}" @click="applySortFilter('za')">
                                    Z &rarr; A
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
                                        $initial_badge = count(array_filter([$cat_id ?: '', $search, $track_by, $stock_status], function($v) { return $v !== null && $v !== ''; }));
                                        if ($initial_badge > 0): ?>
                                            <span class="filter-badge"><?php echo $initial_badge; ?></span>
                                        <?php endif; 
                                    ?>
                                </span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                
                                <!-- Category -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Category</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['category'])">Reset</button>
                                    </div>
                                    <select id="fp_category" class="filter-select">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <?php
                                                $catNameRaw = (string)($cat['name'] ?? '');
                                                // UI-only: strip trailing unit suffix like "(FT)" or "(pcs)".
                                                $catNameDisp = preg_replace('/\\s*\\((pcs|ft|btl)\\)\\s*$/i', '', $catNameRaw) ?: $catNameRaw;
                                            ?>
                                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($catNameDisp); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Tracking Type</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['track_by_roll'])">Reset</button>
                                    </div>
                                    <select id="fp_track_by_roll" class="filter-select">
                                        <option value="">All Tracking</option>
                                        <option value="0" <?php echo ($track_by === 0) ? 'selected' : ''; ?>>Standard (Ledger)</option>
                                        <option value="1" <?php echo ($track_by === 1) ? 'selected' : ''; ?>>Roll-Based (Individual)</option>
                                    </select>
                                </div>

                                <!-- Stock Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Stock Status</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['stock_status'])">Reset</button>
                                    </div>
                                    <select id="fp_stock_status" class="filter-select">
                                        <option value="">All Stock Levels</option>
                                        <option value="in" <?php echo ($stock_status === 'in') ? 'selected' : ''; ?>>In Stock</option>
                                        <option value="low" <?php echo ($stock_status === 'low') ? 'selected' : ''; ?>>Low Stock</option>
                                        <option value="critical" <?php echo ($stock_status === 'critical') ? 'selected' : ''; ?>>Critical</option>
                                        <option value="out" <?php echo ($stock_status === 'out') ? 'selected' : ''; ?>>Out of Stock</option>
                                    </select>
                                </div>
                                
                                <!-- Keyword -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button type="button" class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <input type="text" id="fp_search" class="filter-search-input" placeholder="Search by name..." value="">
                                </div>

                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width:100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto" style="border:1px solid #f3f4f6; border-radius:12px;">
                    <table class="inv-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr>
                                <th>Material Name</th>
                                <th>Category</th>
                                <th>Tracking</th>
                                <th>Average Cost</th>
                                <th>Stock Level</th>
                                <th>UOM</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="itemsTableBody">
                            <?php if (empty($items)): ?>
                                <tr id="emptyItemsRow"><td colspan="7" class="py-12 text-center text-gray-500">No inventory items matching the filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): 
                                    $stock = (float)$item['current_stock'];
                                    $reorder = printflow_item_reorder_level($item);
                                    $critical = printflow_item_critical_level($item);
                                    $status = printflow_resolve_stock_status($stock, $reorder, $critical);
                                    
                                    $displayUom = normalize_inventory_uom($item['unit_of_measure'] ?? '', $item['category_name'] ?? '');
                                    $displayTrackByRoll = (int)($item['track_by_roll'] ?? 0);
                                    $stockColor = $status['text_color'];
                                    
                                    $trackBadge = $displayTrackByRoll == 1
                                        ? '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#eef2ff;color:#4338ca;">Roll-Based</span>'
                                        : '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f3f4f6;color:#4b5563;">Standard</span>';
                                    
                                    $statusBadge = printflow_stock_status_badge_html($status);
                                    $inactiveBadge = $item['status'] === 'INACTIVE' ? '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#f3f4f6;color:#6b7280;margin-left:6px;">Inactive</span>' : '';
                                ?>
                                    <tr class="<?php echo htmlspecialchars($status['row_class'], ENT_QUOTES, 'UTF-8'); ?>" style="cursor:pointer;" onclick="openStockCard(<?php echo $item['id']; ?>)">
                                        <td class="truncate" style="font-weight:500;text-transform:capitalize;" title="<?php echo htmlspecialchars($item['name']); ?>">
                                            <?php echo htmlspecialchars($item['name']); ?><?php echo $statusBadge . $inactiveBadge; ?>
                                        </td>
                                        <?php
                                            $catDispRaw2 = (string)($item['category_name'] ?? '');
                                            $catDisp2 = preg_replace('/\\s*\\((pcs|ft|btl)\\)\\s*$/i', '', $catDispRaw2) ?: $catDispRaw2;
                                        ?>
                                        <td class="truncate" title="<?php echo htmlspecialchars($catDisp2 ?: 'Uncategorized'); ?>"><?php echo htmlspecialchars($catDisp2 ?: 'Uncategorized'); ?></td>
                                        <td><?php echo $trackBadge; ?></td>
                                        <td style="white-space:nowrap;"><span class="font-semibold">&#8369;<?php echo number_format($item['unit_cost'], 2); ?></span></td>
                                        <td style="white-space:nowrap;"><span class="stock-val" style="color:<?php echo $stockColor; ?>;"><?php echo $displayUom === 'pcs' ? (int)$stock : number_format($stock, 2); ?></span></td>
                                        <td class="truncate" style="color:#6b7280;font-size:12px;"><?php echo $displayUom === 'l' ? 'Liter (L)' : htmlspecialchars($displayUom); ?></td>
                                        <td class="no-truncate" style="text-align:right;">
                                            <?php if (!$inventory_read_only): ?>
                                            <button type="button" class="btn-action teal" onclick="event.stopPropagation(); openAddStockModalById(<?php echo $item['id']; ?>)">+ Stock</button>
                                            <?php else: ?>
                                            <button type="button" class="btn-action" onclick="event.stopPropagation(); openStockCard(<?php echo $item['id']; ?>)" style="border-color:#d1d5db;color:#374151;">View</button>
                                            <?php endif; ?>
                                            <?php if ($can_manage_item_master): ?>
                                            <button type="button" class="btn-action blue" onclick="event.stopPropagation(); editItemById(<?php echo $item['id']; ?>)">Edit</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="itemsPagination">
                    <?php 
                        $p = array_filter(['branch_id'=>$branchId, 'category_id'=>$cat_id, 'search'=>$search, 'sort'=>$sort, 'dir'=>$dir, 'track_by_roll'=>$track_by, 'stock_status'=>$stock_status], function($v) { return $v !== null && $v !== ''; });
                        echo render_pagination($page, $total_pages, $p); 
                    ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add Stock Modal -->
<div id="addStockModal" class="modal">
    <div class="modal-content" style="max-width:450px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" style="padding-right:30px;">Inventory: Stock Intake</h3>
                <p style="font-size:13px;color:#6b7280;margin-top:2px; padding-right:30px; overflow-wrap:break-word; word-break:break-word; hyphens:auto;" id="addStockItemName">Item Name</p>
            </div>
            <button class="close-btn" onclick="closeAddStockModal()">&times;</button>
        </div>
        <div class="modal-body">
        <form id="addStockForm" onsubmit="saveAddStock(event)">
            <input type="hidden" id="addStockItemId">
            <input type="hidden" id="addStockIsRoll">
            <input type="hidden" id="addStockUom">
            <input type="hidden" id="addStockCurrentStock" value="0">
            <input type="hidden" id="addStockCurrentUnitCost" value="0">
            <div class="form-grid" style="grid-template-columns:1fr;">
                <div class="filter-group">
                    <label for="addStockQty">Quantity to Add (<span id="addStockQtyUomLabel">—</span>) *</label>
                    <input type="number"
                           step="any"
                           min="0"
                           max="100000"
                           id="addStockQty"
                           placeholder="e.g. 10 pcs"
                           inputmode="decimal"
                           autocomplete="off"
                           onkeydown="handleAddStockQtyKeyDown(event)"
                           onpaste="handleAddStockQtyPaste(event)">
                    <div id="addStockQtyError" style="display:none; font-size:12px; color:#dc2626; margin-top:4px;">Please enter a valid quantity</div>
                    <div id="addStockPreview" style="font-size:13px; font-weight:600; color:#059669; margin-top:6px;">New Stock After Adding: &mdash;</div>
                    
                    <label for="addStockUnitCost" style="margin-top:12px;">Unit Cost (&#8369;) *</label>
                    <input type="number" step="0.01" min="0" id="addStockUnitCost" placeholder="e.g. 500.00" inputmode="decimal">
                    <div id="err-addStockUnitCost" class="field-error" style="display:none;"></div>
                    <div id="addStockUnitCostPreview" style="font-size:12px; color:#6b7280; font-weight:600; margin-top:6px;">New Estimated Unit Cost: &mdash;</div>

                    <div id="addStockLargeWarning" style="display:none; font-size:13px; color:#854d0e; background:#fef9c3; padding:8px 12px; border-radius:8px; margin-top:8px; border:1px solid #fde68a;">
                        &#9888;&#65039; You are adding a large quantity. Please double check.
                    </div>
                </div>
                <div class="filter-group" id="addStockRollGroup" style="display:none; background:#f0f7ff; padding:12px; border-radius:10px; border:1px solid #dbeafe;">
                    <label for="addStockRollCode">Roll Identification</label>
                    <input type="text" id="addStockRollCode" placeholder="e.g. ROLL-001" style="margin-bottom:12px;">
                    <span id="err-addStockRollCode" class="field-error"></span>
                    
                    <label for="addStockWidth">Roll Width (ft) *</label>
                    <input type="number" step="1" min="1" max="12" id="addStockWidth" placeholder="e.g. 6 or 10">
                    <span id="err-addStockWidth" class="field-error"></span>
                    <p style="font-size:10px;color:#3b82f6;margin-top:4px;line-height:1.4;">Specify width for tarpaulin/vinyl rolls. <br>Leave code empty to auto-generate.</p>
                </div>
                <div class="filter-group">
                    <label for="addStockNotes">Notes</label>
                    <input type="text" id="addStockNotes" placeholder="e.g. Purchased from supplier XYZ">
                </div>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end; padding-top:16px; border-top:1px solid #f3f4f6; margin-top: auto;">
                <button type="button" onclick="closeAddStockModal()" class="btn-secondary" style="height:44px;border-radius:10px;padding:0 24px;">Cancel</button>
                <button type="submit" id="addStockBtn" class="btn-primary" style="height:44px;border-radius:10px;padding:0 24px;background:#059669;" disabled>Add Stock</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Add Stock Confirmation Modal -->
<div id="addStockConfirmModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Stock Addition</h3>
            <button class="close-btn" onclick="closeAddStockConfirmModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:12px; font-size:14px; color:#374151;">You are about to add: <strong id="addStockConfirmQty">0</strong></p>
            <p style="font-size:14px; color:#374151;">New total will be: <strong id="addStockConfirmTotal">0</strong></p>
            <p style="margin-top:16px; font-size:14px; color:#6b7280;">Are you sure you want to continue?</p>
        </div>
        <div style="display:flex; gap:12px; justify-content:flex-end; padding:0 24px 24px;">
            <button type="button" onclick="closeAddStockConfirmModal()" class="btn-secondary" style="height:44px;border-radius:10px;padding:0 24px;">Cancel</button>
            <button type="button" id="addStockConfirmBtn" class="btn-primary" style="height:44px;border-radius:10px;padding:0 24px;background:#059669;">Confirm</button>
        </div>
    </div>
</div>

<!-- Archive Item Confirmation Modal -->
<div id="itemArchiveConfirmModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header">
            <h3 class="modal-title" id="itemStatusConfirmTitle">Confirm Archive</h3>
            <button class="close-btn" onclick="closeItemArchiveConfirmModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px !important; font-size:14px; color:#374151;">
            <p style="margin-bottom:12px;">You are about to <strong id="itemStatusConfirmAction">archive</strong>: <strong id="itemArchiveConfirmName">—</strong></p>
            <p id="itemStatusConfirmInfoText" style="margin-top:16px; color:#6b7280;">Archived materials are hidden from new orders and POS.</p>
        </div>
        <div style="display:flex; gap:12px; justify-content:flex-end; padding-top:20px; margin-top:20px; border-top:1px solid #f3f4f6;">
            <button type="button" onclick="closeItemArchiveConfirmModal()" class="btn-secondary" style="height:44px;border-radius:10px;padding:0 24px;">Cancel</button>
            <button type="button" id="itemArchiveConfirmBtn" class="btn-primary" style="height:44px;border-radius:10px;padding:0 24px;background:#6b7280;border:none;">Archive</button>
        </div>
    </div>
</div>

<!-- Archived Items Overlay -->
<div id="items-archive-storage-overlay" role="dialog" aria-modal="true" aria-labelledby="items-archive-title"
     onclick="if (event.target === this) window.closeItemsArchiveModal()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10090;align-items:center;justify-content:center;padding:16px;pointer-events:auto;">
    <div id="items-archive-storage-modal"
         onclick="event.stopPropagation()"
         style="background:white;border-radius:16px;width:100%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px rgba(0,0,0,0.25);pointer-events:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #e5e7eb;">
            <h3 id="items-archive-title" style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Archived Items</h3>
            <button class="close-btn" onclick="window.closeItemsArchiveModal()">&times;</button>
        </div>
        <div id="items-archived-items-container" style="padding:20px;overflow:auto;">
            <div style="padding:40px;text-align:center;color:#9ca3af;">Loading archived materials...</div>
        </div>
    </div>
</div>

<!-- Stock Card View Modal (SaaS-style) -->
<div id="stockCardModal" class="modal">
    <div class="modal-content" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title" id="scName" style="padding-right:30px; word-break:break-all; overflow-wrap:anywhere;">Item Name</h3>
            <button class="close-btn" onclick="closeStockCard()">&times;</button>
        </div>
        <div class="modal-body">
        <!-- Summary Cards -->
        <div class="sc-cards-grid" id="scCardsContainer">
            <div class="sc-card" style="background:#f0f9ff; border:1px solid #bae6fd;">
                <div class="sc-card-label" style="font-size:11px; font-weight:700; color:#0369a1; text-transform:uppercase; letter-spacing:0.05em;">Current Stock</div>
                <div style="display:flex; align-items:baseline; justify-content:center; gap:4px;">
                    <span class="sc-card-value" id="scStock" style="font-size:20px; font-weight:800; color:#0c4a6e;">0</span>
                    <span id="scUnit" style="font-size:11px; font-weight:700; color:#0ea5e9; text-transform:uppercase;">UNIT</span>
                </div>
            </div>
            <div class="sc-card" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                <div class="sc-card-label" style="font-size:11px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:0.05em;">Reorder Level</div>
                <div class="sc-card-value" id="scMinStock" style="font-size:20px; font-weight:800; color:#166534;">0</div>
            </div>
            <div class="sc-card" style="background:#fef2f2; border:1px solid #fecaca;">
                <div class="sc-card-label" style="font-size:11px; font-weight:700; color:#991b1b; text-transform:uppercase; letter-spacing:0.05em;">Critical Level</div>
                <div class="sc-card-value" id="scCriticalStock" style="font-size:20px; font-weight:800; color:#991b1b;">0</div>
            </div>
            <div class="sc-card" id="scStatusCard" style="border:1px solid; display:flex; flex-direction:column; justify-content:center;">
                <div class="sc-card-label" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">Stock Status</div>
                <div class="sc-card-value" id="scStatusText" style="font-size:16px; font-weight:700;">&mdash;</div>
            </div>
            <div class="sc-card" id="scRollCard" style="background:#fdf2f8; border:1px solid #fbcfe8;">
                <div class="sc-card-label" style="font-size:11px; font-weight:700; color:#be185d; text-transform:uppercase; letter-spacing:0.05em;">Available Rolls</div>
                <div style="display:flex; align-items:baseline; justify-content:center; gap:4px;">
                    <span class="sc-card-value" id="scRoll" style="font-size:20px; font-weight:800; color:#9d174d;">0</span>
                    <span style="font-size:11px; font-weight:700; color:#db2777; text-transform:uppercase;">Rolls</span>
                </div>
            </div>
        </div>

        <!-- Stock Health Progress Bar -->
        <div style="margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:12px; font-weight:600; color:#4b5563;">Stock vs Reorder Level</span>
                <span id="scProgressText" style="font-size:12px; font-weight:700; color:#374151;">0 / 0</span>
            </div>
            <div id="scProgressBg" style="height:10px; background:#e5e7eb; border-radius:9999px; overflow:hidden;">
                <div id="scProgressFill" style="height:100%; border-radius:9999px; transition: width 0.3s, background 0.3s;"></div>
            </div>
        </div>

        <!-- Smart Status Message -->
        <div id="scStatusMsg" style="display:none; padding:12px 16px; border-radius:10px; font-size:14px; font-weight:600; margin-bottom:20px;"></div>

        <!-- Product Details -->
        <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; margin-bottom:20px;">
            <div style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">Product Details</div>
            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px 24px; font-size:13px;">
                <div><span style="color:#6b7280;">Category:</span> <span id="scCategory" style="font-weight:600;">&mdash;</span></div>
                <div><span style="color:#6b7280;">Unit:</span> <span id="scUnitDetail" style="font-weight:600;">&mdash;</span></div>
                <div><span style="color:#6b7280;">Average Cost:</span> <span id="scUnitCost" style="font-weight:600;">&mdash;</span></div>
                <div><span style="color:#6b7280;">Tracking Type:</span> <span id="scTrackType" style="font-weight:600;">&mdash;</span></div>
                <div style="grid-column:1/-1;"><span style="color:#6b7280;">Last Updated:</span> <span id="scLastUpdated" style="font-weight:600;">&mdash;</span></div>
            </div>
        </div>

        <!-- Recent Activity (5 records) -->
        <div style="margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span style="font-size:13px; font-weight:700; color:#374151;">Recent Activity</span>
                <a id="scSeeAllLedgerLink" href="#" style="font-size:13px; font-weight:600; color:#0d9488; text-decoration:none;">See all &rarr;</a>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f9fafb;">
                            <th style="text-align:left; padding:12px; color:#6b7280; width:100px;">Date</th>
                            <th style="text-align:left; padding:12px; color:#6b7280; width:120px;">Action</th>
                            <th style="text-align:right; padding:12px; color:#6b7280; width:100px;">Quantity</th>
                            <th style="text-align:right; padding:12px; color:#6b7280; width:120px;">Balance After</th>
                        </tr>
                    </thead>
                    <tbody id="scLedgerBody">
                        <tr><td colspan="4" style="text-align:center; padding:24px; color:#9ca3af;">No recent activity</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center;">
            <?php if (!$inventory_read_only): ?>
            <button onclick="closeStockCard(); if(selectedItemForStockCard) openAddStockModal(selectedItemForStockCard)" class="btn-action teal" style="flex:1; min-width:140px; height:40px; font-size:14px; border-radius:10px;">+ Add Stock</button>
            <button onclick="closeStockCard(); if(selectedItemForStockCard) openDeductStockModal(selectedItemForStockCard)" class="btn-action red" style="flex:1; min-width:140px; height:40px; font-size:14px; border-radius:10px;">&minus; Deduct Stock</button>
            <?php endif; ?>
            <?php if ($can_manage_item_master): ?>
            <button onclick="editFromStockCard()" class="btn-action blue" style="flex:1; min-width:120px; height:40px; font-size:14px; border-radius:10px;">Edit Settings</button>
            <?php endif; ?>
            <a id="scLedgerLink" href="inv_transactions_ledger.php" class="btn-action" style="flex:1; min-width:120px; height:40px; font-size:14px; border-radius:10px; border:1px solid #e5e7eb; color:#374151; display:flex; align-items:center; justify-content:center; text-decoration:none;">View Full Ledger</a>
        </div>
        </div>
    </div>
</div>

<!-- Deduct Stock Modal -->
<div id="deductStockModal" class="modal">
    <div class="modal-content" style="max-width:450px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title" style="padding-right:30px;">Deduct Stock</h3>
                <p style="font-size:13px;color:#6b7280;margin-top:2px; padding-right:30px; overflow-wrap:break-word; word-break:break-word; hyphens:auto;" id="deductStockItemName">Item Name</p>
            </div>
            <button class="close-btn" onclick="closeDeductStockModal()">&times;</button>
        </div>
        <div class="modal-body">
        <form id="deductStockForm" onsubmit="saveDeductStock(event)">
            <input type="hidden" id="deductStockItemId">
            <input type="hidden" id="deductStockUom">
            <div class="form-row-grid">
                <div>
                    <label for="deductStockQty">Quantity to Deduct *</label>
                    <input type="number" step="0.01" min="0.01" id="deductStockQty" required placeholder="e.g. 10" class="w-100">
                    <span id="err-deductStockQty" class="field-error"></span>
                </div>
                <div>
                    <label for="deductStockNotes">Notes</label>
                    <input type="text" id="deductStockNotes" placeholder="e.g. Used for job order" class="w-100">
                </div>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end; padding-top:16px; border-top:1px solid #f3f4f6; margin-top: auto;">
                <button type="button" onclick="closeDeductStockModal()" class="btn-secondary" style="height:44px;border-radius:10px;padding:0 24px;">Cancel</button>
                <button type="submit" id="deductStockBtn" class="btn-primary" style="height:44px;border-radius:10px;padding:0 24px;background:#dc2626;">Deduct Stock</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div id="itemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle" style="padding-right:30px;">Material Settings</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
        <form id="itemForm" onsubmit="saveItem(event)" style="display: flex; flex-direction: column; height: 100%;">
            <input type="hidden" id="itemId" name="id">
            <input type="hidden" id="actionType" name="action" value="create_item">

            <div style="flex: 1; overflow-y: auto; padding-right: 8px;">
            <!-- Section: Material Information -->
            <div class="modal-section">
                <p class="section-header">Material Information</p>
                <div class="form-row-grid" style="grid-template-columns: 2fr 1fr;">
                    <div>
                        <label for="itemName">Item Name <span style="color:#ef4444">*</span></label>
                        <input type="text" id="itemName" name="name" required placeholder="name" class="w-100">
                        <span id="err-itemName" class="field-error"></span>
                    </div>
                    <div>
                        <label for="itemCategory">Category <span style="color:#ef4444">*</span></label>
                        <select id="itemCategory" name="category_id" class="w-100" onchange="handleCategoryChange()">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <?php
                                    $catNameRaw = (string)($cat['name'] ?? '');
                                    $catNameDisp = preg_replace('/\\s*\\((pcs|ft|btl)\\)\\s*$/i', '', $catNameRaw) ?: $catNameRaw;
                                ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                        data-uom="<?php echo htmlspecialchars(normalize_inventory_uom($cat['default_uom'] ?? '', $cat['name'] ?? '')); ?>"
                                        data-roll="<?php echo $cat['default_track_by_roll'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($catNameDisp); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span id="err-itemCategory" class="field-error"></span>
                    </div>
                </div>
                <div class="form-row-grid">
                    <div>
                        <label for="itemUnit">Unit of Measure (UOM) <span id="autoTagUom" style="font-size:10px; color:#9ca3af; font-weight:normal;">(Auto-generated)</span></label>
                        <select id="itemUnit" name="unit" required onchange="handleUomChange()" class="w-100 locked-select">
                            <option value="">Select Category First</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="ft">Feet (ft)</option>
                            <option value="l">Liters (L)</option>
                        </select>
                        <span id="err-itemUnit" class="field-error"></span>
                    </div>
                    <div>
                        <label for="itemUnitCost">Unit Cost (&#8369;) <span style="color:#ef4444">*</span></label>
                        <input type="number" step="0.01" min="0" id="itemUnitCost" name="unit_cost" value="0.00" required class="w-100">
                        <span id="err-itemUnitCost" class="field-error"></span>
                    </div>
                </div>
                <div id="itemThresholdRow" class="item-modal-threshold-grid">
                    <div class="item-modal-field">
                        <label for="itemTrackByRoll">Tracking Mode <span id="autoTagTrack" style="font-size:10px; color:#9ca3af; font-weight:normal;">(Auto-generated)</span></label>
                        <select id="itemTrackByRoll" name="track_by_roll" class="w-100 locked-select">
                            <option value="0">Standard (Ledger)</option>
                            <option value="1">Roll-Based (Individual)</option>
                        </select>
                        <p class="field-hint">&nbsp;</p>
                    </div>
                    <div class="item-modal-field">
                        <label for="itemMinStock">Reorder Level <span style="font-size:10px;color:#9ca3af;font-weight:normal;">(Auto-calculated)</span></label>
                        <input type="text" id="itemMinStock" value="1" readonly class="w-100" style="background:#f9fafb;color:#374151;font-weight:600;" title="max(1, ceil(quantity × 20%))">
                        <p class="field-hint">max(1, ceil(qty × 0.20))</p>
                    </div>
                    <div class="item-modal-field">
                        <label for="itemCriticalStock">Critical Level <span style="font-size:10px;color:#9ca3af;font-weight:normal;">(Auto-calculated)</span></label>
                        <input type="text" id="itemCriticalStock" value="1" readonly class="w-100" style="background:#f9fafb;color:#374151;font-weight:600;" title="max(1, ceil(quantity × 5%))">
                        <p class="field-hint">max(1, ceil(qty × 0.05))</p>
                    </div>
                    <div id="previewStatusSection" class="item-modal-field">
                        <label>Stock Status Preview</label>
                        <div id="editModalReorderPreview" class="preview-badge" style="min-height:42px;">No stock added yet.</div>
                        <p class="field-hint">&nbsp;</p>
                    </div>
                </div>
            </div>

            <!-- Alerts Section (edit only) -->
            <div id="reorderAlertsGroup" class="modal-section" style="margin-bottom: 20px;">
                <div class="form-col-full">
                    <p style="font-size:11px; color:#6b7280; margin-bottom:8px;">Reorder warns when stock needs restocking. Critical requires urgent replenishment. Thresholds update in real time as quantity changes.</p>
                    <div id="editModalReorderWarnHigh" style="display:none; font-size:11px; color:#854d0e; background:#fef9c3; padding:8px 12px; border-radius:8px; border:1px solid #fde68a;">&#9888;&#65039; This may mark your stock as low immediately</div>
                    <div id="editModalReorderWarnLow" style="display:none; font-size:11px; color:#854d0e; background:#fef9c3; padding:8px 12px; border-radius:8px; border:1px solid #fde68a;">&#9888;&#65039; You may run out of stock before being warned</div>
                    <div id="editModalNoStockYet" style="display:none; font-size:11px; color:#6b7280; background:#f3f4f6; padding:8px 12px; border-radius:8px; border:1px solid #e5e7eb;">No stock added yet.</div>
                </div>
            </div>

            <!-- Bottom Row: Initial Balance | Roll Settings | System Status (edit) -->
            <div id="itemModalBottomRow" class="item-modal-bottom-grid">
                <!-- Column 1: Initial Stock (Create Mode Only) -->
                <div id="startingStockGroup" class="item-modal-panel" style="display:none;">
                    <p class="section-header" style="margin:0 0 4px;">Initial Balance</p>
                    <label for="itemStartingStock">Initial Stock Quantity</label>
                    <input type="number" step="1" min="0" id="itemStartingStock" name="starting_stock" value="0" class="w-100" inputmode="numeric"
                        onkeydown="handlePcsInitialStockKeyDown(event)"
                        onpaste="handlePcsInitialStockPaste(event)">
                    <p id="startingStockHelper" style="font-size:10px; color:#9ca3af; margin-top:8px; line-height: 1.4;">Opening balance for new item record</p>
                    <span id="err-itemStartingStock" class="field-error"></span>
                </div>

                <!-- Column 1b: Dual Input (Feet/Rolls) - UOM: ft Only -->
                <div id="ftDualInputGroup" class="item-modal-panel" style="display:none;">
                    <p class="section-header" style="margin:0 0 4px;">Initial Balance (Feet)</p>

                    <input type="hidden" id="stockInputMethodHidden" name="stock_input_method" value="">
                    <input type="hidden" id="startingRollsHidden" name="starting_rolls" value="">

                    <label style="display:block; margin-bottom:8px;">Stock Input Method</label>
                    <div style="display:flex; gap:14px; align-items:center; margin-bottom:12px;">
                        <label style="display:flex; gap:8px; align-items:center; font-size:13px; color:#374151;">
                            <input type="radio" name="stock_input_method_radio" id="stockByRolls" value="rolls" onchange="handleStockInputMethodChange()">
                            By Rolls
                        </label>
                        <label style="display:flex; gap:8px; align-items:center; font-size:13px; color:#374151;">
                            <input type="radio" name="stock_input_method_radio" id="stockByFeet" value="feet" onchange="handleStockInputMethodChange()">
                            By Feet
                        </label>
                    </div>

                    <div id="stockByRollsFields" style="display:none;">
                        <label for="startingRolls">Number of Rolls</label>
                        <input type="number" step="1" min="1" id="startingRolls" value="" class="w-100" oninput="handleDualInputChange()">
                        <p style="font-size:10px; color:#9ca3af; margin-top:6px; line-height: 1.4;">Use rolls for bulk input. Whole numbers only. Total feet will be calculated as Rolls × Roll Length.</p>
                        <span id="err-startingRolls" class="field-error"></span>
                    </div>

                    <div id="stockByFeetFields" style="display:none;">
                        <label for="startingFeet">Total Feet</label>
                        <input type="number" step="0.01" min="0.01" id="startingFeet" value="" class="w-100" oninput="handleDualInputChange()">
                        <p style="font-size:10px; color:#9ca3af; margin-top:6px; line-height: 1.4;">Use feet for exact measurement. Decimals allowed (e.g. 164.5).</p>
                        <span id="err-startingFeet" class="field-error"></span>
                    </div>

                    <div id="dualComputedTotal" style="display:none; margin-top:10px; font-size:12px; color:#065f46; font-weight:700;">
                        Total Feet: &mdash;
                    </div>
                    <div id="dualComputedRollEq" style="display:none; margin-top:4px; font-size:11px; color:#6b7280;">
                        Equivalent to ~&mdash; rolls
                    </div>
                </div>

                <!-- Column 2: Roll Settings (UOM: ft Only) -->
                <div id="rollSettingsSection" class="item-modal-panel item-modal-panel--roll" style="display:none;">
                    <p class="section-header" style="margin:0 0 4px; color:#0369a1; border-color:#bae6fd;">Roll Settings</p>
                    <label for="itemRollLength">Roll Length (ft) <span style="color:#ef4444" id="rollLengthRequired">*</span></label>
                    <input type="number" step="0.01" min="1" max="1000" id="itemRollLength" name="roll_length_ft" placeholder="e.g. 164.00" class="w-100" oninput="handleDualInputChange()">
                    <span id="err-itemRollLength" class="field-error"></span>
                </div>

                <!-- Column 3: System Status (edit only; new items default to Active) -->
                <div id="statusSection" class="item-modal-panel" style="background:#f9fafb;">
                    <p class="section-header" style="margin:0 0 4px;">System Status</p>
                    <div class="item-modal-field">
                        <label for="itemStatus">Status</label>
                        <select id="itemStatus" name="status" class="w-100">
                            <option value="ACTIVE">Active</option>
                            <option value="INACTIVE">Inactive</option>
                        </select>
                        <div id="statusHelperMessage" style="display:none;">
                            <p class="field-hint" style="min-height:auto;">Inactive materials are hidden from new orders and POS.</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Change Summary (Edit mode, when changed) -->
            <div id="editModalChangeSummary" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px 16px; margin-bottom:16px;">
                <div style="font-size:12px; font-weight:700; color:#166534; margin-bottom:8px;">Changes Summary</div>
                <div id="editModalChangeSummaryContent" style="font-size:13px; color:#374151;"></div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top:16px; border-top:1px solid #f3f4f6; margin-top: auto;">
                <button type="button" onclick="closeModal()" class="btn-secondary" style="border-radius: 10px; height: 44px; padding: 0 24px;">Cancel</button>
                <button type="button" id="archiveItemBtn" onclick="openItemArchiveConfirm()" class="btn-secondary" style="border-radius: 10px; height: 44px; padding: 0 24px; display:none;">Archive</button>
                <button type="submit" class="btn-primary" id="saveBtn" style="border-radius: 10px; height: 44px; padding: 0 24px; background: #10b981; border:none; transition: background 0.2s;">Save Changes</button>
            </div>
            </div>
        </form>
        </div>
    </div>
</div>




  <script>
    /* var: Turbo re-executes this block; let/const would throw "already been declared". */
    var ADMIN_API_BASE = '<?php echo $base_path; ?>/admin/';
    var currentItems = <?php echo $items_js; ?>;
    var usageChart = null;
    var selectedItemForStockCard = null;
    var editItemOriginalValues = {};
    function pfSuggestReorderLevel(qty) {
        var q = Math.max(0, Number(qty) || 0);
        return q <= 0 ? 1 : Math.max(1, Math.ceil(q * 0.20));
    }

    function pfSuggestCriticalLevel(qty) {
        var q = Math.max(0, Number(qty) || 0);
        return q <= 0 ? 1 : Math.max(1, Math.ceil(q * 0.05));
    }

    function pfResolveStockStatus(quantity, reorderLevel, criticalLevel, isNewItemWithoutStock) {
        var qty = Math.max(0, Number(quantity) || 0);
        var reorder = Math.max(1, Number(reorderLevel) || 1);
        var critical = Math.max(1, Math.min(Number(criticalLevel) || 1, reorder));

        if (isNewItemWithoutStock && qty <= 0) {
            return {
                key: 'no_stock_yet',
                label: 'No stock added yet.',
                textColor: '#6b7280',
                bgColor: '#f3f4f6',
                borderColor: '#e5e7eb',
                rowClass: ''
            };
        }
        if (qty <= 0) {
            return { key: 'out', label: 'Out of Stock', textColor: '#7f1d1d', bgColor: '#f3f4f6', borderColor: '#d1d5db', rowClass: 'low-stock-row stock-status-out' };
        }
        if (qty <= critical) {
            return { key: 'critical', label: 'Critical', textColor: '#991b1b', bgColor: '#fef2f2', borderColor: '#fecaca', rowClass: 'low-stock-row stock-status-critical' };
        }
        if (qty <= reorder) {
            return { key: 'low', label: 'Low Stock', textColor: '#92400e', bgColor: '#fef3c7', borderColor: '#fde68a', rowClass: 'low-stock-row stock-status-low' };
        }
        return { key: 'in', label: 'In Stock', textColor: '#166534', bgColor: '#dcfce7', borderColor: '#bbf7d0', rowClass: 'stock-status-in' };
    }

    function pfGetModalReferenceQuantity() {
        var actionTypeEl = document.getElementById('actionType');
        var isEdit = actionTypeEl && actionTypeEl.value === 'update_item';
        if (isEdit) {
            return Math.max(0, parseFloat(selectedItemForStockCard?.current_stock || 0));
        }
        var uom = (document.getElementById('itemUnit')?.value || 'pcs').toLowerCase();
        if (uom === 'ft') {
            var method = getStockInputMethod();
            if (method === 'rolls') {
                var rolls = parseFloat(document.getElementById('startingRolls')?.value || 0);
                var rollLen = parseFloat(document.getElementById('itemRollLength')?.value || 0);
                if (rolls > 0 && rollLen > 0) return rolls * rollLen;
            }
            return Math.max(0, parseFloat(document.getElementById('startingFeet')?.value || document.getElementById('itemStartingStock')?.value || 0));
        }
        return Math.max(0, parseFloat(document.getElementById('itemStartingStock')?.value || 0));
    }

    function pfApplySuggestedThresholds() {
        var refQty = pfGetModalReferenceQuantity();
        var reorderEl = document.getElementById('itemMinStock');
        var criticalEl = document.getElementById('itemCriticalStock');
        if (!reorderEl || !criticalEl) return;
        reorderEl.value = String(pfSuggestReorderLevel(refQty));
        criticalEl.value = String(pfSuggestCriticalLevel(refQty));
    }
    var currentPage = <?php echo $page; ?>;
    var currentIsTarpaulinCategory = false;
    var currentSort = '<?php echo $sort; ?>';
    var currentDir = '<?php echo $dir; ?>';
    var searchDebounceTimer = null;
    var addStockPendingSubmit = false;

    function filterPanel() {
        return {
            sortOpen: false,
            filterOpen: false,
            activeSort: '<?php echo $sort === 'name' ? ($dir === 'ASC' ? 'az' : 'za') : ($sort === 'id' ? ($dir === 'DESC' ? 'newest' : 'oldest') : 'newest'); ?>',
            get hasActiveFilters() {
                return document.getElementById('fp_category')?.value ||
                       document.getElementById('fp_track_by_roll')?.value !== '' ||
                       document.getElementById('fp_stock_status')?.value ||
                       document.getElementById('fp_search')?.value;
            }
        };
    }
    window.filterPanel = filterPanel;

    function printflowInitInvItemsBindings() {
        if (!document.getElementById('inv-filter-toolbar')) return;
        var searchInput = document.getElementById('fp_search');
        if (searchInput && !searchInput._pf_bound) {
            searchInput._pf_bound = true;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(function () { fetchUpdatedTable({ page: 1 }); }, 500);
            });
        }
        var catSelect = document.getElementById('fp_category');
        if (catSelect && !catSelect._pf_bound) {
            catSelect._pf_bound = true;
            catSelect.addEventListener('change', function () { fetchUpdatedTable({ page: 1 }); });
        }
        var trackBySelect = document.getElementById('fp_track_by_roll');
        if (trackBySelect && !trackBySelect._pf_bound) {
            trackBySelect._pf_bound = true;
            trackBySelect.addEventListener('change', function () { fetchUpdatedTable({ page: 1 }); });
        }
        var stockStatusSelect = document.getElementById('fp_stock_status');
        if (stockStatusSelect && !stockStatusSelect._pf_bound) {
            stockStatusSelect._pf_bound = true;
            stockStatusSelect.addEventListener('change', function () { fetchUpdatedTable({ page: 1 }); });
        }
        var addStockQty = document.getElementById('addStockQty');
        if (addStockQty && !addStockQty._pf_bound) {
            addStockQty._pf_bound = true;
            addStockQty.addEventListener('input', updateAddStockUI);
            addStockQty.addEventListener('change', updateAddStockUI);
        }
        var addStockUnitCost = document.getElementById('addStockUnitCost');
        if (addStockUnitCost && !addStockUnitCost._pf_bound) {
            addStockUnitCost._pf_bound = true;
            addStockUnitCost.addEventListener('input', updateAddStockUI);
            addStockUnitCost.addEventListener('change', updateAddStockUI);
        }
        ['itemName', 'itemCategory', 'itemUnit', 'itemUnitCost', 'itemTrackByRoll', 'itemStatus', 'itemStartingStock', 'startingRolls', 'startingFeet', 'itemRollLength'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && !el._pf_bound) {
                el._pf_bound = true;
                var eventType = (el.tagName === 'SELECT') ? 'change' : 'input';
                el.addEventListener(eventType, function () {
                    if (id === 'itemStartingStock' || id === 'startingRolls' || id === 'startingFeet' || id === 'itemRollLength') {
                        pfApplySuggestedThresholds();
                    }
                    validateField(id);
                });
                if (id === 'itemName') el.addEventListener('input', handleItemNameInput);
            }
        });
    }

    function handleItemNameInput(e) {
        let val = e.target.value;
        // Block leading spaces
        if (val.startsWith(' ')) {
            val = val.trimStart();
        }
        // Auto Capitalize (Title Case)
        e.target.value = val.replace(/\b\w/g, l => l.toUpperCase());
        validateField('itemName');
    }

    function handlePcsInitialStockKeyDown(e) {
        const uom = (document.getElementById('itemUnit')?.value || '').toLowerCase();
        if (uom !== 'pcs') return;

        const allowed = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Home', 'End', 'Enter'];
        if (allowed.includes(e.key)) return;

        // Block decimal point and scientific notation entry.
        if (e.key === '.' || e.key === ',' || e.key === 'e' || e.key === 'E' || e.key === '-' || e.key === '+') {
            e.preventDefault();
            return;
        }

        const input = e.target;
        const curVal = String(input.value || '').trim();
        const selStart = input.selectionStart ?? 0;
        const selEnd = input.selectionEnd ?? 0;
        const hasSelection = selStart !== selEnd;

        // If current value is "0" and user types a digit, replace the "0" with that digit.
        // This prevents "03" and matches your desired admin behavior.
        if (/^\d$/.test(e.key) && !hasSelection && parseFloat(curVal) === 0) {
            if (e.key === '0') {
                // Disallow resulting value being 0.
                e.preventDefault();
                return;
            }
            e.preventDefault();
            input.value = e.key;
            validateField('itemStartingStock');
            updateSaveButtonState();
            return;
        }

        // Allow digits only.
        if (!/^\d$/.test(e.key)) e.preventDefault();
    }

    function handlePcsInitialStockPaste(e) {
        const uom = (document.getElementById('itemUnit')?.value || '').toLowerCase();
        if (uom !== 'pcs') return;

        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        // For pcs, only whole-number paste is allowed.
        if (!/^\d+$/.test(text.trim())) { e.preventDefault(); return; }
        // Disallow 0 as initial stock for pcs.
        if (parseInt(text.trim(), 10) === 0) { e.preventDefault(); return; }
    }

    function getRawInput(id) {
        return (document.getElementById(id)?.value ?? '').trim();
    }

    // Strict numeric validators for UI (prevents things like "--777" and special chars).
    function isWholeNumberLike(s) {
        // Strict whole number (no decimals). e.g. 0, 1, 2 ...
        return /^(0|[1-9]\d*)$/.test(s);
    }

    function isDecimalWithMaxPlaces(s, maxPlaces) {
        // No negatives; optional fractional part up to maxPlaces; no scientific notation.
        const mp = Math.max(0, Math.floor(maxPlaces));
        return new RegExp('^([0-9]+)(\\.[0-9]{1,' + mp + '})?$').test(s);
    }

    function isFeetPositive(s) {
        if (!isDecimalWithMaxPlaces(s, 3)) return false;
        const n = Number(s);
        return Number.isFinite(n) && n > 0;
    }

    function isRollLengthValid(s) {
        if (!isDecimalWithMaxPlaces(s, 3)) return false;
        const n = Number(s);
        return Number.isFinite(n) && n >= 1 && n <= 1000;
    }

    function validateField(id) {
        const el = document.getElementById(id);
        const errEl = document.getElementById('err-' + id);
        let isValid = true;
        let msg = '';

        const val = el?.value != null ? String(el.value).trim() : '';
        const itemUnit = (document.getElementById('itemUnit')?.value || '').toLowerCase();
        const uom = itemUnit === 'btl' ? 'l' : itemUnit;

        switch(id) {
            case 'itemName':
                if (!val) { msg = 'Item name is required.'; isValid = false; }
                else if (val.length < 2) { msg = 'Item name must be at least 2 characters.'; isValid = false; }
                else if (/^\d+$/.test(val)) { msg = 'Item name cannot contain only numbers.'; isValid = false; }
                break;
            case 'itemCategory':
                if (!val) { msg = 'Please select a category.'; isValid = false; }
                break;
            case 'itemUnit':
                if (!val) { msg = 'Please select a unit of measure.'; isValid = false; }
                break;
            case 'itemUnitCost':
                const cost = parseFloat(val);
                if (isNaN(cost)) { msg = 'Unit cost is required.'; isValid = false; }
                else if (cost <= 0) { msg = 'Unit cost must be greater than 0.'; isValid = false; }
                else if (cost > 1000000) { msg = 'Unit cost is too high (max 1M).'; isValid = false; }
                break;
            case 'itemStartingStock':
                if (!val) { msg = 'Initial stock is required.'; isValid = false; }
                else if (uom === 'pcs') {
                    if (!isWholeNumberLike(val)) { msg = 'Whole numbers only (pcs).'; isValid = false; }
                    else {
                        const n = Number(val);
                        if (!Number.isFinite(n) || n <= 0) { msg = 'Initial stock must be greater than 0 for pcs.'; isValid = false; }
                    }
                } else if (uom === 'l') {
                    if (!isDecimalWithMaxPlaces(val, 3)) { msg = 'Decimals allowed (e.g., 0.25 L), up to 3 decimals.'; isValid = false; }
                    else {
                        const n = Number(val);
                        if (!Number.isFinite(n) || n < 0) { msg = 'Initial stock cannot be negative.'; isValid = false; }
                    }
                } else if (uom === 'ft') {
                    // In ft mode the real input comes from rolls/feet; but keep a safe check.
                    if (!isDecimalWithMaxPlaces(val, 3)) { msg = 'Invalid number format.'; isValid = false; }
                    else {
                        const n = Number(val);
                        if (!Number.isFinite(n) || n < 0) { msg = 'Initial stock cannot be negative.'; isValid = false; }
                    }
                }
                break;
            case 'startingRolls':
                if (!val) { msg = 'Number of rolls is required.'; isValid = false; }
                else if (!/^(0|[1-9]\d*)$/.test(val)) { msg = 'Whole numbers only (rolls).'; isValid = false; }
                else {
                    const r = Number(val);
                    if (!Number.isFinite(r) || r < 1) { msg = 'Rolls must be at least 1.'; isValid = false; }
                }
                break;
            case 'startingFeet':
                if (!val) { msg = 'Total feet is required.'; isValid = false; }
                else if (!isFeetPositive(val)) { msg = 'Feet must be > 0 and decimals allowed (up to 3).' ; isValid = false; }
                break;
            case 'itemRollLength':
                if (!val) { msg = 'Roll Length is required for Feet (ft).'; isValid = false; }
                else if (!isRollLengthValid(val)) { msg = 'Roll Length must be between 1 and 1000 ft (up to 3 decimals).'; isValid = false; }
                break;
        }

        updateValidationUI(id, isValid, msg);
        updateSaveButtonState();
        if (id === 'itemName' || id === 'itemStatus' || id === 'itemStartingStock' || id === 'startingRolls' || id === 'startingFeet') {
            pfApplySuggestedThresholds();
            updateEditModalUI();
        }
        return isValid;
    }

    function updateValidationUI(id, isValid, msg) {
        const el = document.getElementById(id);
        const errEl = document.getElementById('err-' + id);
        if (!errEl) return;

        if (!isValid) {
            el.classList.add('input-error');
            el.classList.remove('input-success');
            errEl.textContent = msg;
            errEl.style.display = 'block';
        } else {
            el.classList.remove('input-error');
            if (el.value.trim() !== '') el.classList.add('input-success');
            else el.classList.remove('input-success');
            errEl.style.display = 'none';
        }
    }

    function updateSaveButtonState() {
        const baseFields = ['itemName', 'itemCategory', 'itemUnit', 'itemUnitCost'];
        let allValid = true;

        baseFields.forEach(id => {
            const el = document.getElementById(id);
            const val = el?.value != null ? String(el.value).trim() : '';
            if (!val) allValid = false;
            if (el?.classList?.contains('input-error')) allValid = false;
        });

        const uomRaw = document.getElementById('itemUnit')?.value || '';
        let uom = String(uomRaw).toLowerCase();
        if (uom === 'btl') uom = 'l'; // legacy safety

        const isCreate = document.getElementById('actionType')?.value === 'create_item';

        if (isCreate) {
            if (uom === 'pcs') {
                const raw = getRawInput('itemStartingStock');
                if (!raw || !isWholeNumberLike(raw) || Number(raw) <= 0) allValid = false;
            } else if (uom === 'l') {
                const raw = getRawInput('itemStartingStock');
                if (!raw || !isDecimalWithMaxPlaces(raw, 3) || Number(raw) < 0) allValid = false;
            } else if (uom === 'ft') {
                const rlRaw = getRawInput('itemRollLength');
                const rlOk = isRollLengthValid(rlRaw);
                if (!rlOk) allValid = false;

                const method = getStockInputMethod();
                if (!method) allValid = false;

                if (method === 'rolls') {
                    const rollsRaw = getRawInput('startingRolls');
                    if (!rollsRaw || !/^(0|[1-9]\d*)$/.test(rollsRaw) || Number(rollsRaw) < 1) allValid = false;
                } else if (method === 'feet') {
                    const feetRaw = getRawInput('startingFeet');
                    if (!feetRaw || !isFeetPositive(feetRaw)) allValid = false;
                }
            }
        } else {
            // Edit mode: keep existing roll length validity gate for ft.
            if (uom === 'ft') {
                const rlRaw = getRawInput('itemRollLength');
                if (!isRollLengthValid(rlRaw)) allValid = false;
            }
        }

        document.getElementById('saveBtn').disabled = !allValid;
    }

    function buildFilterURL(overrides = {}, isAjax = false) {
        const params = new URLSearchParams(window.location.search);
        
        const map = {
            'category_id': 'fp_category',
            'track_by_roll': 'fp_track_by_roll',
            'stock_status': 'fp_stock_status',
            'search': 'fp_search'
        };

        for (const [param, id] of Object.entries(map)) {
            const val = document.getElementById(id)?.value;
            if (val) params.set(param, val);
            else params.delete(param);
        }

        if (overrides.page !== undefined) params.set('page', overrides.page);
        else if (currentPage > 1) params.set('page', currentPage);

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

    async function fetchUpdatedTable(overrides = {}) {
        const url = buildFilterURL(overrides, true);
        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.success) {
                const tbody = document.getElementById('itemsTableBody');
                const pagination = document.getElementById('itemsPagination');
                const showingText = document.getElementById('showingCount');
                const badgeCont = document.getElementById('filterBadgeContainer');

                if (tbody) {
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
                const showingMeta = document.getElementById('invShowingMeta');
                if (showingText && showingMeta) {
                    showingText.textContent = data.startIdx + '\u2013' + data.endIdx;
                    showingMeta.textContent = ' of ' + data.total + ' items)';
                }
                
                if (badgeCont) {
                    badgeCont.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                }

                if (data.items) currentItems = data.items;
                if (overrides.page !== undefined) currentPage = overrides.page;

                const displayUrl = buildFilterURL(overrides, false);
                window.history.replaceState({ path: displayUrl }, '', displayUrl);
            }
        } catch (e) {
            console.error('Error updating table:', e);
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
        let sort = 'id';
        let dir = 'DESC';

        if (sortKey === 'newest') { sort = 'id'; dir = 'DESC'; }
        else if (sortKey === 'oldest') { sort = 'id'; dir = 'ASC'; }
        else if (sortKey === 'az') { sort = 'name'; dir = 'ASC'; }
        else if (sortKey === 'za') { sort = 'name'; dir = 'DESC'; }
        
        const root = document.getElementById('inv-filter-toolbar');
        if (root && root._x_dataStack) {
            const data = root._x_dataStack[0];
            data.activeSort = sortKey;
            data.sortOpen = false;
        }

        fetchUpdatedTable({ sort: sort, dir: dir, page: 1 });
    }

    function resetFilterField(fields) {
        fields.forEach(f => {
            const id = 'fp_' + (f === 'category' ? 'category' : f);
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        fetchUpdatedTable({ page: 1 });
    }

    function goToItemsPage(page) {
        fetchUpdatedTable({ page: page });
    }

    function fmtQty(val, isPcs) {
        return isPcs ? String(Math.round(val)) : parseFloat(val).toFixed(2);
    }

    async function openStockCard(itemId) {
        if (!document.getElementById('stockCardModal')) return;
        const item = currentItems.find(i => i.id == itemId);
        if (!item) return;
        selectedItemForStockCard = item;
        
        const stock = parseFloat(item.current_stock || 0);
        const reorder = pfSuggestReorderLevel(stock);
        const critical = pfSuggestCriticalLevel(stock);
        const normalizedUom = normalizeInventoryUomValue(item.unit_of_measure, item.category_name);
        const uom = normalizedUom.toUpperCase();
        const isPcs = normalizedUom === 'pcs';
        const status = pfResolveStockStatus(stock, reorder, critical, false);
        
        document.getElementById('scName').textContent = item.name;
        document.getElementById('scStock').textContent = fmtQty(stock, isPcs);
        document.getElementById('scUnit').textContent = uom;
        document.getElementById('scMinStock').textContent = fmtQty(reorder, isPcs);
        document.getElementById('scCriticalStock').textContent = fmtQty(critical, isPcs);
        document.getElementById('scStatusText').textContent = status.label;
        document.getElementById('scStatusCard').style.background = status.bgColor;
        document.getElementById('scStatusCard').style.borderColor = status.borderColor;
        document.getElementById('scStatusCard').querySelector('.sc-card-value').style.color = status.textColor;
        
        const rollCard = document.getElementById('scRollCard');
        rollCard.style.display = item.track_by_roll == 1 ? 'block' : 'none';
        document.getElementById('scRoll').textContent = '0';
        
        // Progress bar (stock vs reorder)
        const maxVal = Math.max(reorder, stock, 1);
        const pct = Math.min(100, (stock / maxVal) * 100);
        document.getElementById('scProgressFill').style.width = pct + '%';
        document.getElementById('scProgressText').textContent = fmtQty(stock, isPcs) + ' / ' + fmtQty(reorder, isPcs);
        let progColor = '#10b981';
        if (status.key === 'out') progColor = '#6b7280';
        else if (status.key === 'critical') progColor = '#dc2626';
        else if (status.key === 'low') progColor = '#f59e0b';
        document.getElementById('scProgressFill').style.background = progColor;
        
        // Status message
        const msgEl = document.getElementById('scStatusMsg');
        if (status.key === 'out') {
            msgEl.textContent = 'Out of stock. Immediate restocking required.';
            msgEl.style.background = '#f3f4f6'; msgEl.style.color = '#7f1d1d'; msgEl.style.border = '1px solid #d1d5db';
        } else if (status.key === 'critical') {
            msgEl.textContent = 'Critical stock level. Urgent replenishment required.';
            msgEl.style.background = '#fef2f2'; msgEl.style.color = '#991b1b'; msgEl.style.border = '1px solid #fecaca';
        } else if (status.key === 'low') {
            msgEl.textContent = 'Stock is getting low. Consider restocking.';
            msgEl.style.background = '#fef3c7'; msgEl.style.color = '#92400e'; msgEl.style.border = '1px solid #fde68a';
        } else {
            msgEl.textContent = 'Stock level is healthy.';
            msgEl.style.background = '#dcfce7'; msgEl.style.color = '#166534'; msgEl.style.border = '1px solid #bbf7d0';
        }
        
        // Product details
        document.getElementById('scCategory').textContent = item.category_name || 'Uncategorized';
        document.getElementById('scUnitDetail').textContent = uom;
        document.getElementById('scUnitCost').textContent = '\u20B1' + parseFloat(item.unit_cost || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('scTrackType').textContent = item.track_by_roll == 1 ? 'Roll-Based' : 'Standard';
        document.getElementById('scLastUpdated').textContent = item.updated_at ? new Date(item.updated_at).toLocaleDateString() : '\u2014';
        
        const currentBranch = new URLSearchParams(window.location.search).get('branch_id') || '<?php echo htmlspecialchars($selectedBranchParam, ENT_QUOTES, 'UTF-8'); ?>';
        document.getElementById('scLedgerLink').href = `inv_transactions_ledger.php?branch_id=${encodeURIComponent(currentBranch)}&item_id=${item.id}`;
        const ledgerUrl = `inv_transactions_ledger.php?branch_id=${encodeURIComponent(currentBranch)}&item_id=${item.id}`;
        const seeAllLink = document.getElementById('scSeeAllLedgerLink');
        if (seeAllLink) { seeAllLink.href = ledgerUrl; }
        document.getElementById('stockCardModal').style.display = 'flex';
        
        document.getElementById('scLedgerBody').innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#9ca3af;">Loading...</td></tr>';

        try {
            const currentBranch = new URLSearchParams(window.location.search).get('branch_id') || '<?php echo htmlspecialchars($selectedBranchParam, ENT_QUOTES, 'UTF-8'); ?>';
            const res = await fetch(ADMIN_API_BASE + `inventory_stock_card_api.php?branch_id=${encodeURIComponent(currentBranch)}&item_id=${item.id}`);
            const data = await res.json();
            if (data.success) {
                document.getElementById('scRoll').textContent = (data.rolls || []).length;
                
                const ledger = data.ledger || [];
                let ledgerHtml = '';
                ledger.forEach(l => {
                    const isIN = l.direction === 'IN';
                    const qty = parseFloat(l.quantity);
                    const qtyDisp = (isIN ? '+' : '-') + fmtQty(qty, data.is_pcs);
                    const balDisp = fmtQty(parseFloat(l.balance_after || 0), data.is_pcs);
                    const qtyColor = isIN ? '#059669' : '#dc2626';
                    const act = l.action_display || (l.ref_type || '').replace(/_/g, ' ');
                    ledgerHtml += `<tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:10px 12px;color:#6b7280;white-space:nowrap;">${(l.transaction_date || '').split(' ')[0]}</td>
                        <td style="padding:10px 12px;text-transform:capitalize;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${act}">${act}</td>
                        <td style="padding:10px 12px;text-align:right;font-weight:700;color:${qtyColor};">${qtyDisp}</td>
                        <td style="padding:10px 12px;text-align:right;font-weight:600;color:#374151;">${balDisp}</td>
                    </tr>`;
                });
                document.getElementById('scLedgerBody').innerHTML = ledgerHtml || '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">No recent activity</td></tr>';
            } else {
                document.getElementById('scLedgerBody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">No recent activity</td></tr>';
            }
        } catch (e) {
            console.error('Stock Card API error:', e);
            document.getElementById('scLedgerBody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">No recent activity</td></tr>';
        }
    }

    function closeStockCard() {
        document.getElementById('stockCardModal').style.display = 'none';
    }

    function handleCategoryChange() {
        const catSelect = document.getElementById('itemCategory');
        const selectedOpt = catSelect.options[catSelect.selectedIndex];
        if (!selectedOpt || !selectedOpt.value) return;

        const uom = selectedOpt.getAttribute('data-uom');
        const catLabel = (selectedOpt.textContent || '').toUpperCase();
        // Tarpaulin should be flexible: allow manual override of UOM.
        const isTarpaulin = catLabel.includes('TARPAULIN');
        // Printed stickers must be stored as Pieces (pcs), not Feet (ft).
        const isPrintedSticker = catLabel.includes('PRINTED') && (catLabel.includes('STKR') || catLabel.includes('STICKER'));
        currentIsTarpaulinCategory = !!isTarpaulin;

        // Only auto-fill if it's a NEW item (mode check)
        const isCreate = document.getElementById('actionType').value === 'create_item';
        if (isCreate) {
            if (uom) {
                document.getElementById('itemUnit').value = normalizeInventoryUomValue(uom, selectedOpt.textContent || '');
            }
            if (isPrintedSticker) {
                document.getElementById('itemUnit').value = 'pcs';
            }
        }

        // Lock UOM unless Tarpaulin.
        if (!isTarpaulin) {
            document.getElementById('itemUnit').disabled = true;
            document.getElementById('itemUnit').style.background = '#fcfcfd';
        } else {
            document.getElementById('itemUnit').disabled = false;
            document.getElementById('itemUnit').style.background = '';
        }

        // Always apply smart rules (tracking auto).
        applySmartUomRules();
    }

    function editFromStockCard() {
        if (selectedItemForStockCard) {
            closeStockCard();
            editItem(selectedItemForStockCard);
        }
    }

    function handleUomChange() {
        applySmartUomRules();
    }

    function setAutoGeneratedTags() {
        // Smart mode: tracking is auto-generated & locked based on UOM.
        const uomTag = document.getElementById('autoTagUom');
        const trackTag = document.getElementById('autoTagTrack');
        // Tarpaulin category allows UOM overrides, so don't label UOM as auto-generated there.
        if (uomTag) uomTag.style.display = currentIsTarpaulinCategory ? 'none' : 'inline';
        if (trackTag) trackTag.style.display = 'inline';
    }

    function getStockInputMethod() {
        if (document.getElementById('stockByRolls')?.checked) return 'rolls';
        if (document.getElementById('stockByFeet')?.checked) return 'feet';
        return '';
    }

    function handleStockInputMethodChange() {
        const method = getStockInputMethod();
        const byRolls = document.getElementById('stockByRollsFields');
        const byFeet = document.getElementById('stockByFeetFields');
        if (byRolls) byRolls.style.display = method === 'rolls' ? 'block' : 'none';
        if (byFeet) byFeet.style.display = method === 'feet' ? 'block' : 'none';
        document.getElementById('stockInputMethodHidden').value = method;

        // Reset irrelevant inputs to avoid conflicts.
        if (method === 'rolls') {
            const ftEl = document.getElementById('startingFeet');
            if (ftEl) ftEl.value = '';
            validateField('startingFeet');
        } else if (method === 'feet') {
            const rollsEl = document.getElementById('startingRolls');
            if (rollsEl) rollsEl.value = '';
            document.getElementById('startingRollsHidden').value = '';
            validateField('startingRolls');
        }

        handleDualInputChange();
        updateSaveButtonState();
    }

    function handleDualInputChange() {
        const method = getStockInputMethod();
        const uom = (document.getElementById('itemUnit')?.value || '').toLowerCase();
        const isCreate = document.getElementById('actionType')?.value === 'create_item';

        // Only meaningful for ft create mode
        const computedTotalEl = document.getElementById('dualComputedTotal');
        const eqEl = document.getElementById('dualComputedRollEq');
        const rollSec = document.getElementById('rollSettingsSection');
        if (!isCreate || uom !== 'ft') {
            if (computedTotalEl) computedTotalEl.style.display = 'none';
            if (eqEl) eqEl.style.display = 'none';
            if (rollSec) rollSec.style.display = (uom === 'ft') ? 'block' : 'none';
            return;
        }

        // Conditional visibility:
        // - By Rolls: show Roll Length + computed total
        // - By Feet: hide Roll Length + computed total (clean UI)
        if (rollSec) rollSec.style.display = (method === 'rolls') ? 'block' : 'none';

        const rollLen = parseFloat(document.getElementById('itemRollLength')?.value || 0);
        const startingStockEl = document.getElementById('itemStartingStock');

        let totalFeet = 0;
        let rollEq = 0;
        let rollsCount = '';

        if (method === 'rolls') {
            const r = parseInt(document.getElementById('startingRolls')?.value || '0', 10);
            rollsCount = isNaN(r) ? '' : String(Math.max(0, r));
            if (!isNaN(r) && r > 0 && !isNaN(rollLen) && rollLen > 0) {
                totalFeet = r * rollLen;
                rollEq = r;
            }
        } else if (method === 'feet') {
            const ft = parseFloat(document.getElementById('startingFeet')?.value || '0');
            if (!isNaN(ft) && ft > 0) {
                totalFeet = ft;
                if (!isNaN(rollLen) && rollLen > 0) {
                    rollEq = ft / rollLen;
                }
            }
        }

        // Always store total feet in the real field used by backend.
        if (startingStockEl) startingStockEl.value = totalFeet ? String(totalFeet.toFixed(2)) : '0.00';
        document.getElementById('startingRollsHidden').value = rollsCount;

        if (computedTotalEl) {
            computedTotalEl.style.display = (method === 'rolls' && totalFeet > 0) ? 'block' : 'none';
            computedTotalEl.textContent = 'Total Feet: ' + (totalFeet > 0 ? totalFeet.toFixed(2) + ' ft' : '—');
        }
        if (eqEl) {
            const showEq = (method === 'feet' && totalFeet > 0 && !isNaN(rollLen) && rollLen > 0);
            eqEl.style.display = showEq ? 'block' : 'none';
            if (showEq) eqEl.textContent = 'Equivalent to ~' + (rollEq ? rollEq.toFixed(2) : '—') + ' rolls (1 roll = ' + rollLen.toFixed(2) + ' ft)';
        }

        pfApplySuggestedThresholds();
        updateEditModalUI();
        // Real-time: keep Save button state accurate while typing.
        updateSaveButtonState();
    }

    function applySmartUomRules() {
        setAutoGeneratedTags();

        const uomEl = document.getElementById('itemUnit');
        let uom = (uomEl?.value || '').toLowerCase();
        // Safety: never let the UI carry legacy btl value.
        if (uom === 'btl') uom = 'l';
        const isCreate = document.getElementById('actionType')?.value === 'create_item';

        // Auto-control Tracking Mode based on UOM (locked always)
        const trackEl = document.getElementById('itemTrackByRoll');
        if (trackEl) {
            trackEl.value = (uom === 'ft') ? '1' : '0';
            trackEl.disabled = true;
            trackEl.style.background = '#fcfcfd';
        }

        // Roll settings visible for Feet only
        const rollSec = document.getElementById('rollSettingsSection');
        const rollLenEl = document.getElementById('itemRollLength');
        const rollLenReq = document.getElementById('rollLengthRequired');
        const isFeet = (uom === 'ft');
        // Note: when ft + dual input, roll section visibility depends on input method.
        if (rollSec) rollSec.style.display = isFeet ? 'block' : 'none';
        if (rollLenEl) rollLenEl.required = !!isFeet;
        if (rollLenReq) rollLenReq.style.display = isFeet ? 'inline' : 'none';

        // Initial stock inputs
        const startGroup = document.getElementById('startingStockGroup');
        const ftGroup = document.getElementById('ftDualInputGroup');
        const startingStockEl = document.getElementById('itemStartingStock');

        if (isCreate) {
            if (startGroup) startGroup.style.display = (!isFeet) ? 'block' : 'none';
            if (ftGroup) ftGroup.style.display = (isFeet) ? 'block' : 'none';

            // Validation/input behavior by UOM
            if (startingStockEl) {
                if (uom === 'pcs') {
                    startingStockEl.step = '1';
                    startingStockEl.min = '0'; // Use JS validation for "no zero" rule.
                    // Normalize legacy/default "0.00" to a strict whole number string.
                    const startingRaw = String(startingStockEl.value || '').trim();
                    if (!startingRaw || startingRaw === '0.00' || parseFloat(startingRaw) === 0) {
                        startingStockEl.value = '0';
                    } else if (startingRaw.match(/^([0-9]+)\\.0+$/)) {
                        startingStockEl.value = String(parseInt(startingRaw, 10));
                    }
                } else if (uom === 'l') {
                    startingStockEl.step = '0.01';
                    startingStockEl.min = '0';
                } else if (isFeet) {
                    startingStockEl.step = '0.01';
                    startingStockEl.min = '0';
                }
            }

            const helper = document.getElementById('startingStockHelper');
            if (helper) {
                if (uom === 'pcs') helper.textContent = 'Whole numbers only (pcs).';
                else if (uom === 'l') helper.textContent = 'Decimals allowed (e.g., 0.25 L).';
                else helper.textContent = 'Opening balance for new item record';
            }

            // Default dual input method for ft
            if (isFeet) {
                // Ensure a sensible default roll length for calculations/back-end validation.
                if (rollLenEl && (!rollLenEl.value || parseFloat(rollLenEl.value) <= 0)) {
                    rollLenEl.value = '164.00';
                }
                if (!getStockInputMethod()) {
                    const byFeet = document.getElementById('stockByFeet');
                    if (byFeet) byFeet.checked = true;
                    handleStockInputMethodChange();
                } else {
                    handleStockInputMethodChange();
                }
                handleDualInputChange();
            } else {
                // Clear dual inputs when leaving ft
                const byRolls = document.getElementById('stockByRolls');
                const byFeet = document.getElementById('stockByFeet');
                if (byRolls) byRolls.checked = false;
                if (byFeet) byFeet.checked = false;
                document.getElementById('stockInputMethodHidden').value = '';
                document.getElementById('startingRollsHidden').value = '';
                const computedTotalEl = document.getElementById('dualComputedTotal');
                const eqEl = document.getElementById('dualComputedRollEq');
                if (computedTotalEl) computedTotalEl.style.display = 'none';
                if (eqEl) eqEl.style.display = 'none';
            }
        } else {
            if (startGroup) startGroup.style.display = 'none';
            if (ftGroup) ftGroup.style.display = 'none';
        }

        updateSaveButtonState();
    }

    function setItemModalMode(mode) {
        const form = document.getElementById('itemForm');
        if (!form) return;
        form.classList.remove('item-modal--create', 'item-modal--edit');
        form.classList.add(mode === 'create' ? 'item-modal--create' : 'item-modal--edit');
    }

    function openModal(mode, item = null) {
        const modal = document.getElementById('itemModal');
        const form = document.getElementById('itemForm');
        if (!modal || !form) return;
        modal.style.display = 'flex';
        form.reset();
        setItemModalMode(mode);
        
        // Top info removed
        editItemOriginalValues = {};
        
        // Clear previous validation states
        ['itemName', 'itemCategory', 'itemUnit', 'itemUnitCost'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('input-error', 'input-success');
                const err = document.getElementById('err-' + id);
                if (err) { err.style.display = 'none'; err.textContent = ''; }
            }
        });
        const saveBtn = document.getElementById('saveBtn');
        if (saveBtn) saveBtn.disabled = true;
        
        // Ensure smart rules run after reset.
        applySmartUomRules();

        if (mode === 'create') {
            const modalTitle = document.getElementById('modalTitle');
            const actionType = document.getElementById('actionType');
            const itemId = document.getElementById('itemId');
            const itemUnitCost = document.getElementById('itemUnitCost');
            const itemMinStock = document.getElementById('itemMinStock');
            const itemCriticalStock = document.getElementById('itemCriticalStock');
            if (modalTitle) modalTitle.textContent = 'Add New Material';
            if (actionType) actionType.value = 'create_item';
            if (itemId) itemId.value = '';
            if (itemUnitCost) itemUnitCost.value = '0.00';
            pfApplySuggestedThresholds();
            // Smart UOM decides which stock group is visible.
            const startingStockGroup = document.getElementById('startingStockGroup');
            if (startingStockGroup) startingStockGroup.style.display = 'none';
            const ftGrp = document.getElementById('ftDualInputGroup');
            if (ftGrp) ftGrp.style.display = 'none';
            const itemStatus = document.getElementById('itemStatus');
            if (itemStatus) itemStatus.value = 'ACTIVE';

            // Lock them by default for new items until category is selected
            const itemUnitEl = document.getElementById('itemUnit');
            const trackElCreate = document.getElementById('itemTrackByRoll');
            if (itemUnitEl) { itemUnitEl.disabled = true; itemUnitEl.style.background = '#fcfcfd'; }
            if (trackElCreate) { trackElCreate.disabled = true; trackElCreate.style.background = '#fcfcfd'; }
            const archiveBtn = document.getElementById('archiveItemBtn');
            if (archiveBtn) archiveBtn.style.display = 'none';

            selectedItemForStockCard = null;
            setTimeout(function () {
                applySmartUomRules();
                updateEditModalUI();
            }, 0);
        } else {
            const modalTitle2 = document.getElementById('modalTitle');
            const actionType2 = document.getElementById('actionType');
            const itemId2 = document.getElementById('itemId');
            const itemName2 = document.getElementById('itemName');
            const itemCategory2 = document.getElementById('itemCategory');
            const itemUnit2 = document.getElementById('itemUnit');
            const itemUnitCost2 = document.getElementById('itemUnitCost');
            if (modalTitle2) modalTitle2.textContent = 'Edit Material Settings';
            if (actionType2) actionType2.value = 'update_item';
            if (itemId2) itemId2.value = item.id;
            if (itemName2) itemName2.value = item.name;
            if (itemCategory2) itemCategory2.value = item.category_id || '';
            if (itemUnit2) itemUnit2.value = normalizeInventoryUomValue(item.unit_of_measure, item.category_name);
            if (itemUnitCost2) itemUnitCost2.value = item.unit_cost || '0.00';
            // Tracking is auto-generated from UOM; keep UI consistent.
            const track2 = document.getElementById('itemTrackByRoll');
            const minStock2 = document.getElementById('itemMinStock');
            const status2 = document.getElementById('itemStatus');
            if (track2) track2.value = item.track_by_roll;
            if (status2) status2.value = item.status;
            selectedItemForStockCard = item;
            pfApplySuggestedThresholds();
            const startingStockGroup2 = document.getElementById('startingStockGroup');
            if (startingStockGroup2) startingStockGroup2.style.display = 'none';
            const ftGrp2 = document.getElementById('ftDualInputGroup');
            if (ftGrp2) ftGrp2.style.display = 'none';
            const archiveBtn2 = document.getElementById('archiveItemBtn');
            if (archiveBtn2) {
                archiveBtn2.style.display = (item.status && item.status === 'INACTIVE') ? 'inline-flex' : 'none';
                archiveBtn2.textContent = 'Archive';
            }

            // Lock them in Edit mode unless Tarpaulin (allow override even when editing).
            const catOpt = document.getElementById('itemCategory')?.selectedOptions?.[0];
            const catEditLabel = (catOpt?.textContent || '').toUpperCase();
            const editIsTarpaulin = catEditLabel.includes('TARPAULIN');
            const editIsPrintedSticker = catEditLabel.includes('PRINTED') && (catEditLabel.includes('STKR') || catEditLabel.includes('STICKER'));
            currentIsTarpaulinCategory = !!editIsTarpaulin;

            // UOM lock rules for edit; tracking is always auto-generated + locked.
            if (!editIsTarpaulin) {
                const itemUnit3 = document.getElementById('itemUnit');
                if (itemUnit3) { itemUnit3.disabled = true; itemUnit3.style.background = '#fcfcfd'; }
            } else {
                const itemUnit3b = document.getElementById('itemUnit');
                if (itemUnit3b) { itemUnit3b.disabled = false; itemUnit3b.style.background = ''; }
            }

            // Printed stickers must be stored as Pieces (pcs), not Feet (ft).
            if (editIsPrintedSticker) {
                const itemUnit3c = document.getElementById('itemUnit');
                if (itemUnit3c) itemUnit3c.value = 'pcs';
            }
            const track3 = document.getElementById('itemTrackByRoll');
            if (track3) { track3.disabled = true; track3.style.background = '#fcfcfd'; }

            if (normalizeInventoryUomValue(item.unit_of_measure, item.category_name) === 'ft') {
                document.getElementById('itemRollLength').value = item.default_roll_length_ft || '';
            }
            // Ensure roll/stock sections match current UOM.
            setTimeout(applySmartUomRules, 0);

            editItemOriginalValues = {
                roll_length: String(item.default_roll_length_ft || '')
            };
            // Stock info removed from modal top
        }
        setTimeout(updateEditModalUI, 50);
    }

    function updateEditModalUI() {
        const actionTypeEl = document.getElementById('actionType');
        if (!actionTypeEl) return;
        const isEdit = actionTypeEl.value === 'update_item';
        const archiveBtn = document.getElementById('archiveItemBtn');
        if (archiveBtn) {
            if (!isEdit) {
                archiveBtn.style.display = 'none';
            } else {
                const st = document.getElementById('itemStatus')?.value;
                if (st === 'INACTIVE') {
                    archiveBtn.style.display = 'inline-flex';
                    archiveBtn.textContent = 'Archive';
                } else {
                    archiveBtn.style.display = 'none';
                }
            }
        }
        pfApplySuggestedThresholds();
        const rollLength = document.getElementById('itemRollLength');
        const rollVal = parseFloat(rollLength?.value || 0);
        const currentStock = parseFloat(selectedItemForStockCard?.current_stock || 0);
        const previewQty = isEdit ? currentStock : pfGetModalReferenceQuantity();
        const reorderVal = pfSuggestReorderLevel(previewQty);
        const criticalVal = pfSuggestCriticalLevel(previewQty);
        const isNewWithoutStock = !isEdit && (!Number.isFinite(previewQty) || previewQty <= 0);
        const noStockYetEl = document.getElementById('editModalNoStockYet');
        if (noStockYetEl) noStockYetEl.style.display = isNewWithoutStock ? 'block' : 'none';

        const stockStatus = pfResolveStockStatus(previewQty, reorderVal, criticalVal, isNewWithoutStock);
        const previewEl = document.getElementById('editModalReorderPreview');
        if (previewEl) {
            previewEl.textContent = stockStatus.label;
            previewEl.style.cssText = 'min-height:38px;display:flex;align-items:center;justify-content:center;padding:0 12px;font-weight:600;border:1px solid ' + stockStatus.borderColor + ';border-radius:8px;background:' + stockStatus.bgColor + ';color:' + stockStatus.textColor + ';';
        }

        const itemStatusVal = document.getElementById('itemStatus')?.value;
        const statusHelper = document.getElementById('statusHelperMessage');
        if (statusHelper) {
            statusHelper.style.display = itemStatusVal === 'INACTIVE' ? 'block' : 'none';
        }
        
        const rollSec = document.getElementById('rollSettingsSection');
        const rollErr = document.getElementById('err-itemRollLength');
        const rollSectionVisible = document.getElementById('itemUnit')?.value === 'ft';
        let rollValid = true;
        if (rollSectionVisible) {
            const rollRaw = String(rollLength?.value || '').trim();
            rollValid = isRollLengthValid(rollRaw);
            if (rollErr) rollErr.style.display = (!rollValid && rollRaw !== '') ? 'block' : 'none';
        }
        
        const saveBtn = document.getElementById('saveBtn');
        if (saveBtn) {
            // Let smart numeric validation drive the button, then apply reorder/roll section gating.
            updateSaveButtonState();
            if (rollSectionVisible && !rollValid) saveBtn.disabled = true;
        }
        
        if (isEdit && editItemOriginalValues && Object.keys(editItemOriginalValues).length) {
            const changes = [];
            const origRoll = parseFloat(editItemOriginalValues.roll_length || 0);
            if (rollSectionVisible && Math.abs(rollVal - origRoll) > 0.001) {
                changes.push('Standard Roll Length: ' + (editItemOriginalValues.roll_length || '\u2014') + ' \u2192 ' + rollVal);
            }
            const sumEl = document.getElementById('editModalChangeSummary');
            const sumContent = document.getElementById('editModalChangeSummaryContent');
            if (sumEl && sumContent) {
                if (changes.length > 0) {
                    sumEl.style.display = 'block';
                    sumContent.innerHTML = changes.map(c => '<div style="margin-bottom:4px;">\u2022 ' + c + '</div>').join('');
                } else {
                    sumEl.style.display = 'none';
                }
            }
        }
    }

    function closeModal() {
        document.getElementById('itemModal').style.display = 'none';
    }

    function editItemById(itemId) {
        const item = currentItems.find(i => i.id == itemId);
        if (item) editItem(item);
    }
    
    function openAddStockModalById(itemId) {
        const item = currentItems.find(i => i.id == itemId);
        if (item) openAddStockModal(item);
    }

    function normalizeInventoryUomValue(rawUom, categoryName = '') {
        const uom = String(rawUom || '').trim().toLowerCase();
        const normalizedCategory = String(categoryName || '').trim().toUpperCase();
        if (normalizedCategory === 'INK L120' || normalizedCategory === 'INK L130') return 'l';
        if (uom === 'btl') return 'l';
        if (uom === 'l') return 'l';
        if (uom === 'ft') return 'ft';
        return 'pcs';
    }

    function getInventoryUomLabel(rawUom) {
        const uom = normalizeInventoryUomValue(rawUom);
        return uom === 'l' ? 'Liter (L)' : uom;
    }

    function openAddStockModal(item) {
        const currentStock = parseFloat(item.current_stock || 0);
        const modalUom = normalizeInventoryUomValue(item.unit_of_measure, item.category_name);
        document.getElementById('addStockItemName').textContent = item.name;
        document.getElementById('addStockItemId').value = item.id;
        document.getElementById('addStockIsRoll').value = item.track_by_roll;
        document.getElementById('addStockUom').value = modalUom;
        document.getElementById('addStockCurrentStock').value = currentStock;
        const qtyUomLabelEl = document.getElementById('addStockQtyUomLabel');
        if (qtyUomLabelEl) qtyUomLabelEl.textContent = getInventoryUomLabel(modalUom);
        const qtyInputEl = document.getElementById('addStockQty');
        if (qtyInputEl) {
            // Keep HTML constraints aligned with strict JS validation.
            if (modalUom === 'pcs') {
                qtyInputEl.step = '1';
                qtyInputEl.min = '1';
                qtyInputEl.placeholder = 'e.g. 10 pcs';
            } else {
                qtyInputEl.step = '0.01';
                qtyInputEl.min = '0';
                qtyInputEl.placeholder = modalUom === 'l' ? 'e.g. 1.50 L' : 'e.g. 12.25 ft';
            }
        }
        const currentUnitCost = parseFloat(item.unit_cost || 0);
        document.getElementById('addStockCurrentUnitCost').value = currentUnitCost;
        const unitCostInput = document.getElementById('addStockUnitCost');
        if (unitCostInput) unitCostInput.value = (isNaN(currentUnitCost) || currentUnitCost <= 0) ? '0.00' : currentUnitCost.toFixed(2);
        document.getElementById('addStockQty').value = '';
        document.getElementById('addStockRollCode').value = '';
        document.getElementById('addStockWidth').value = item.default_roll_width_ft || '1'; // Default to 1 if no width specified
        document.getElementById('addStockNotes').value = '';
        document.getElementById('addStockQtyError').style.display = 'none';
        const errCostEl = document.getElementById('err-addStockUnitCost');
        if (errCostEl) {
            errCostEl.textContent = '';
            errCostEl.style.display = 'none';
        }
        document.getElementById('addStockLargeWarning').style.display = 'none';
        
        const isRoll = item.track_by_roll == 1;
        // Hidden per user request ("you can hide them") - keeps modal clean for simple intake
        document.getElementById('addStockRollGroup').style.display = 'none';
        document.getElementById('addStockWidth').required = false; 
        
        updateAddStockUI();
        document.getElementById('addStockModal').style.display = 'flex';
        setTimeout(() => {
            const qtyInput = document.getElementById('addStockQty');
            if (qtyInput) qtyInput.focus();
        }, 150);
    }

    function normalizeAddStockUom() {
        const uom = String(document.getElementById('addStockUom')?.value || 'pcs').toLowerCase();
        if (uom === 'l') return 'l';
        if (uom === 'ft') return 'ft';
        return 'pcs';
    }

    function getMaxDecimalPlacesForUom(uomType) {
        return (uomType === 'pcs') ? 0 : 2;
    }

    function validateAddStockQtyString(qtyStr, uomType) {
        const msgBase = {
            pcs: 'Whole numbers only (pcs). No decimals or spaces allowed.',
            l: 'Enter a valid number (decimals allowed, no spaces).',
            ft: 'Enter a valid length (decimals allowed, no spaces).'
        };

        const cleaned = String(qtyStr ?? '').trim();
        if (cleaned === '') return { ok: false, msg: msgBase[uomType] };

        if (uomType === 'pcs') {
            if (!/^\d+$/.test(cleaned)) return { ok: false, msg: msgBase.pcs };
            const n = Number(cleaned);
            if (!Number.isFinite(n) || n < 1 || n > 100000) return { ok: false, msg: msgBase.pcs };
            return { ok: true, qtyNum: Math.trunc(n), msg: '' };
        }

        // l/ft: digits with optional single decimal, up to 2 decimals.
        if (!/^\d+(\.\d+)?$/.test(cleaned) && !/^\d*\.\d+$/.test(cleaned)) {
            return { ok: false, msg: msgBase[uomType] };
        }

        if (cleaned.includes('.')) {
            const parts = cleaned.split('.');
            const dec = parts[1] ?? '';
            const maxDec = getMaxDecimalPlacesForUom(uomType);
            if (dec.length > maxDec) return { ok: false, msg: msgBase[uomType] };
        }

        const n = Number(cleaned);
        if (!Number.isFinite(n) || n <= 0 || n > 100000) return { ok: false, msg: msgBase[uomType] };
        return { ok: true, qtyNum: n, msg: '' };
    }

    function handleAddStockQtyKeyDown(e) {
        const qtyInput = e.target;
        const uomType = normalizeAddStockUom();

        const allowedControlKeys = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End'];
        if (allowedControlKeys.includes(e.key)) return;
        if (e.ctrlKey || e.metaKey) return;

        if (e.key === ' ' || e.key === '-' || e.key === 'e' || e.key === 'E') {
            e.preventDefault();
            return;
        }
        if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
            e.preventDefault();
            return;
        }

        if (e.key.length === 1 && /\d/.test(e.key)) return;

        if (e.key === '.') {
            if (uomType === 'pcs') {
                e.preventDefault();
                return;
            }
            const currentVal = qtyInput.value || '';
            if (currentVal.includes('.')) e.preventDefault();
            return;
        }

        // Block any special character input at typing level.
        if (e.key.length === 1) e.preventDefault();
    }

    function handleAddStockQtyPaste(e) {
        e.preventDefault();
        const uomType = normalizeAddStockUom();
        const qtyInput = document.getElementById('addStockQty');

        const rawText = (e.clipboardData || window.clipboardData)?.getData('text') || '';
        let t = String(rawText);

        // Remove spaces always
        t = t.replace(/\s+/g, '');

        if (t.includes('-')) {
            qtyInput.value = '';
            updateAddStockUI();
            return;
        }

        if (uomType === 'pcs') {
            // Reject decimals for pcs.
            if (t.includes('.')) {
                qtyInput.value = '';
            } else {
                qtyInput.value = t.replace(/[^\d]/g, '');
            }
            updateAddStockUI();
            return;
        }

        // l/ft: keep only digits and dots; then collapse to a single dot.
        let cleaned = t.replace(/[^\d.]/g, '');
        const firstDot = cleaned.indexOf('.');
        if (firstDot !== -1) {
            cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
        }
        qtyInput.value = cleaned;
        updateAddStockUI();
    }

    function updateAddStockUI() {
        const qtyInput = document.getElementById('addStockQty');
        const currentStock = parseFloat(document.getElementById('addStockCurrentStock').value || 0);
        const raw = (qtyInput && qtyInput.value) ? String(qtyInput.value).trim() : '';
        const uomType = normalizeAddStockUom();
        const costInput = document.getElementById('addStockUnitCost');
        const costRaw = (costInput && costInput.value) ? String(costInput.value).trim() : '';
        const cNew = parseFloat(costRaw);
        
        const errEl = document.getElementById('addStockQtyError');
        const previewEl = document.getElementById('addStockPreview');
        const costPreviewEl = document.getElementById('addStockUnitCostPreview');
        const warnEl = document.getElementById('addStockLargeWarning');
        const errCostEl = document.getElementById('err-addStockUnitCost');
        const btn = document.getElementById('addStockBtn');
        const costValid = !isNaN(cNew) && cNew > 0;
        
        if (raw === '') {
            errEl.style.display = 'none';
            previewEl.textContent = 'New Stock After Adding: \u2014';
            warnEl.style.display = 'none';
            if (costPreviewEl) costPreviewEl.textContent = 'New Estimated Unit Cost: \u2014';
            if (errCostEl) errCostEl.style.display = 'none';
            if (btn) btn.disabled = true;
            return;
        }

        const qtyCheck = validateAddStockQtyString(raw, uomType);
        if (!qtyCheck.ok) {
            errEl.textContent = qtyCheck.msg || 'Please enter a valid quantity';
            errEl.style.display = 'block';
            previewEl.textContent = 'New Stock After Adding: \u2014';
            warnEl.style.display = 'none';
            if (costPreviewEl) costPreviewEl.textContent = 'New Estimated Unit Cost: \u2014';
            if (errCostEl) errCostEl.style.display = 'none';
            if (btn) btn.disabled = true;
            return;
        }

        // Quantity is valid
        errEl.style.display = 'none';
        const qNew = qtyCheck.qtyNum;
        const newTotal = currentStock + qNew;
        const disp = (uomType === 'pcs') ? Math.round(newTotal) : newTotal.toFixed(2);
        previewEl.textContent = 'New Stock After Adding: ' + disp;
        warnEl.style.display = (qNew > currentStock * 2) ? 'block' : 'none';

        if (!costValid) {
            if (costPreviewEl) costPreviewEl.textContent = 'New Estimated Unit Cost: \u2014';
            if (errCostEl) {
                errCostEl.textContent = 'Unit cost must be greater than 0.';
                errCostEl.style.display = 'block';
            }
            if (btn) btn.disabled = true;
            return;
        }

        if (errCostEl) errCostEl.style.display = 'none';
        if (costPreviewEl) {
            const cOld = parseFloat(document.getElementById('addStockCurrentUnitCost')?.value || 0);
            const qOld = currentStock;
            let cUpdated;
            if (qOld > 0 && (qOld + qNew) > 0) {
                cUpdated = ((qOld * cOld) + (qNew * cNew)) / (qOld + qNew);
            } else {
                // First stock entry: use C_new as the baseline
                cUpdated = cNew;
            }
            costPreviewEl.textContent = 'New Estimated Unit Cost: \u20B1' + cUpdated.toFixed(2);
        }

        if (btn) btn.disabled = false;
    }

    function closeAddStockConfirmModal() {
        document.getElementById('addStockConfirmModal').style.display = 'none';
        addStockPendingSubmit = false;
    }

    // Turbo may re-render this page without full JS teardown; keep state on window
    // to avoid redeclaration errors.
    window.pendingItemStatus = window.pendingItemStatus ?? null; // { itemId, targetStatus, itemName }

    function openItemStatusConfirm(itemId, targetStatus, itemName = null) {
        const idNum = parseInt(itemId, 10);
        if (!idNum) return;

        window.pendingItemStatus = { itemId: idNum, targetStatus, itemName: itemName || null };

        const titleEl = document.getElementById('itemStatusConfirmTitle');
        const actionEl = document.getElementById('itemStatusConfirmAction');
        const nameEl = document.getElementById('itemArchiveConfirmName');
        const infoEl = document.getElementById('itemStatusConfirmInfoText');
        const okBtn = document.getElementById('itemArchiveConfirmBtn');

        const statusIsInactive = targetStatus === 'INACTIVE';
        const actionWord = statusIsInactive ? 'archive' : 'restore';
        const titleWord = statusIsInactive ? 'Archive' : 'Restore';

        if (titleEl) titleEl.textContent = 'Confirm ' + titleWord;
        if (actionEl) actionEl.textContent = actionWord;

        const fallbackName = (window.currentItems || []).find(i => String(i.id) === String(idNum))?.name || 'this material';
        if (nameEl) nameEl.textContent = itemName || fallbackName;

        if (infoEl) {
            infoEl.textContent = statusIsInactive
                ? 'Archived materials are hidden from new orders and POS.'
                : 'Restored materials will appear as active again.';
        }

        if (okBtn) {
            okBtn.textContent = statusIsInactive ? 'Archive' : 'Restore';
            okBtn.style.background = statusIsInactive ? '#6b7280' : '#14b8a6';
        }

        const m = document.getElementById('itemArchiveConfirmModal');
        const editModal = document.getElementById('itemModal');
        if (editModal) editModal.style.zIndex = '120000';
        if (m) {
            m.style.zIndex = '120001';
            m.style.display = 'flex';
        }

        if (okBtn) {
            okBtn.onclick = async () => {
                const pending = window.pendingItemStatus;
                if (!pending) return;

                okBtn.disabled = true;
                const originalOkText = okBtn.textContent;
                okBtn.textContent = 'Please wait...';

                try {
                    const fd = new FormData();
                    fd.set('item_id', String(pending.itemId));
                    if (pending.targetStatus === 'INACTIVE') fd.set('archive_item', '1');
                    else fd.set('restore_item', '1');

                    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        closeItemArchiveConfirmModal();
                        await fetchUpdatedTable();
                        // Refresh archived overlay if open
                        const overlay = document.getElementById('items-archive-storage-overlay');
                        if (overlay && overlay.style.display === 'flex') fetchArchivedItems();
                    } else {
                        alert('Failed: ' + (data.error || 'Unknown error'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('Network error while updating status.');
                } finally {
                    okBtn.disabled = false;
                    okBtn.textContent = originalOkText;
                }
            };
        }
    }

    // Backward compatibility for old edit-modal Archive button (if it still calls this).
    function openItemArchiveConfirm() {
        const itemId = document.getElementById('itemId')?.value;
        const itemName = document.getElementById('itemName')?.value || null;
        openItemStatusConfirm(itemId, 'INACTIVE', itemName);
    }

    function closeItemArchiveConfirmModal() {
        const m = document.getElementById('itemArchiveConfirmModal');
        if (m) m.style.display = 'none';
        window.pendingItemStatus = null;
        // User request: closing archive confirm should also close edit modal.
        const editModal = document.getElementById('itemModal');
        if (editModal) editModal.style.display = 'none';
    }

    window.openItemsArchiveModal = function openItemsArchiveModal() {
        const overlay = document.getElementById('items-archive-storage-overlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        fetchArchivedItems();
    };

    window.closeItemsArchiveModal = function closeItemsArchiveModal() {
        const overlay = document.getElementById('items-archive-storage-overlay');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    async function fetchArchivedItems() {
        const container = document.getElementById('items-archived-items-container');
        if (!container) return;
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#9ca3af;">Loading archived materials...</div>';
        try {
            const resp = await fetch('?get_archived_items=1');
            const data = await resp.json();
            if (data.success) container.innerHTML = data.html;
            else container.innerHTML = '<div style="padding:40px;text-align:center;color:#ef4444;">Failed to load archived materials.</div>';
        } catch (err) {
            console.error(err);
            container.innerHTML = '<div style="padding:40px;text-align:center;color:#ef4444;">Error loading archived materials.</div>';
        }
    }

    function closeAddStockModal() {
        document.getElementById('addStockModal').style.display = 'none';
    }

    function openDeductStockModal(item) {
        document.getElementById('deductStockItemName').textContent = item.name;
        document.getElementById('deductStockItemId').value = item.id;
        document.getElementById('deductStockUom').value = normalizeInventoryUomValue(item.unit_of_measure, item.category_name);
        document.getElementById('deductStockQty').value = '';
        document.getElementById('deductStockNotes').value = '';
        document.getElementById('deductStockModal').style.display = 'flex';
        setTimeout(() => document.getElementById('deductStockQty')?.focus(), 150);
    }

    function closeDeductStockModal() {
        document.getElementById('deductStockModal').style.display = 'none';
    }

    async function saveDeductStock(e) {
        if (e && e.preventDefault) e.preventDefault();
        
        // Clear previous errors
        document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
        
        const btn = document.getElementById('deductStockBtn');
        btn.disabled = true; btn.textContent = 'Saving...';
        const fd = new FormData();
        fd.set('action', 'record_transaction');
        fd.set('item_id', document.getElementById('deductStockItemId').value);
        fd.set('transaction_type', 'adjustment_down');
        fd.set('quantity', document.getElementById('deductStockQty').value);
        fd.set('notes', document.getElementById('deductStockNotes').value || 'Manual deduction');
        try {
            const res = await fetch(ADMIN_API_BASE + 'inventory_transactions_api.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                closeDeductStockModal();
                await fetchUpdatedTable();
                if (selectedItemForStockCard) openStockCard(selectedItemForStockCard.id);
            } else { 
                if (data.errors) {
                    for (let key in data.errors) {
                        let idMap = { 'quantity': 'deductStockQty' };
                        const targetId = idMap[key] || key;
                        const errEl = document.getElementById('err-' + targetId);
                        if (errEl) {
                            errEl.textContent = data.errors[key];
                            errEl.style.display = 'block';
                        }
                    }
                } else {
                    alert('Failed: ' + (data.message || data.error || 'Unknown error')); 
                }
            }
        } catch (err) { alert('Network error.'); }
        finally { btn.disabled = false; btn.textContent = 'Deduct Stock'; }
    }

    async function saveAddStock(e) {
        if (e && e.preventDefault) e.preventDefault();
        if (addStockPendingSubmit) return;
        const qtyInput = document.getElementById('addStockQty');
        const currentStock = parseFloat(document.getElementById('addStockCurrentStock').value || 0);
        const qtyStr = qtyInput?.value ? String(qtyInput.value).trim() : '';
        const uomType = normalizeAddStockUom();
        const qtyCheck = validateAddStockQtyString(qtyStr, uomType);
        const qty = qtyCheck.ok ? qtyCheck.qtyNum : NaN;
        const unitCostInput = document.getElementById('addStockUnitCost');
        const unitCost = parseFloat(unitCostInput?.value || 0);
        const errCostEl = document.getElementById('err-addStockUnitCost');
        
        if (!qtyCheck.ok) {
            const errEl = document.getElementById('addStockQtyError');
            if (errEl) {
                errEl.textContent = qtyCheck.msg || 'Please enter a valid quantity.';
                errEl.style.display = 'block';
            }
            return;
        }
        if (isNaN(unitCost) || unitCost <= 0) {
            if (errCostEl) {
                errCostEl.textContent = 'Unit cost must be greater than 0.';
                errCostEl.style.display = 'block';
            }
            return;
        }
        if (errCostEl) errCostEl.style.display = 'none';
        
        const isLarge = qty > currentStock * 2;
        if (isLarge) {
            const dispQty = (uomType === 'pcs') ? Math.round(qty) : qty.toFixed(2);
            const dispTotal = (uomType === 'pcs') ? Math.round(currentStock + qty) : (currentStock + qty).toFixed(2);
            document.getElementById('addStockConfirmQty').textContent = dispQty;
            document.getElementById('addStockConfirmTotal').textContent = dispTotal;
            document.getElementById('addStockConfirmModal').style.display = 'flex';
            addStockPendingSubmit = true;
            document.getElementById('addStockConfirmBtn').onclick = async () => {
                closeAddStockConfirmModal();
                addStockPendingSubmit = false;
                await doSaveAddStock();
            };
            return;
        }
        await doSaveAddStock();
    }

    async function doSaveAddStock() {
        // Clear previous errors
        document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

        const btn = document.getElementById('addStockBtn');
        btn.disabled = true; btn.textContent = 'Adding...';
        const itemId = document.getElementById('addStockItemId').value;
        const isRoll = document.getElementById('addStockIsRoll').value == 1;
        const uom = document.getElementById('addStockUom').value;
        const qtyStr = document.getElementById('addStockQty').value ? String(document.getElementById('addStockQty').value).trim() : '';
        const uomType = normalizeAddStockUom();
        const qtyCheck = validateAddStockQtyString(qtyStr, uomType);
        const unitCost = parseFloat(document.getElementById('addStockUnitCost')?.value || '0');

        if (!qtyCheck.ok) {
            const errEl = document.getElementById('addStockQtyError');
            if (errEl) {
                errEl.textContent = qtyCheck.msg || 'Please enter a valid quantity.';
                errEl.style.display = 'block';
            }
            btn.disabled = false; btn.textContent = 'Add Stock';
            updateAddStockUI();
            return;
        }

        if (isNaN(unitCost) || unitCost <= 0) {
            const errCostEl = document.getElementById('err-addStockUnitCost');
            if (errCostEl) {
                errCostEl.textContent = 'Unit cost must be greater than 0.';
                errCostEl.style.display = 'block';
            }
            btn.disabled = false; btn.textContent = 'Add Stock';
            updateAddStockUI();
            return;
        }
        const rollCode = document.getElementById('addStockRollCode').value;
        const notes = document.getElementById('addStockNotes').value;
        
        const fd = new FormData();
        fd.set('action', 'record_transaction');
        fd.set('item_id', itemId);
        fd.set('transaction_type', 'purchase');
        const qtyNum = qtyCheck.qtyNum;
        fd.set('quantity', (uomType === 'pcs') ? String(Math.trunc(qtyNum)) : qtyNum.toFixed(2));
        fd.set('uom', uom);
        fd.set('unit_cost', unitCost.toFixed(2));
        fd.set('notes', notes || 'Manual stock entry');
        if (isRoll) {
            fd.set('roll_code', rollCode);
            let w = document.getElementById('addStockWidth').value;
            fd.set('width_ft', w || '1'); // Ensure it's never empty for rolls
        }

        try {
            const res = await fetch(ADMIN_API_BASE + 'inventory_transactions_api.php', { method: 'POST', body: fd });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch (_) {
                console.error('API returned non-JSON:', text.substring(0, 500));
                alert('Server returned an invalid response. See browser console for details.');
                return;
            }
            if (data.success) {
                closeAddStockModal();
                fetchUpdatedTable();
            } else { 
                if (data.errors) {
                    for (let key in data.errors) {
                            let errEl = null;
                            if (key === 'unit_cost') errEl = document.getElementById('err-addStockUnitCost');
                            else if (key === 'quantity') errEl = document.getElementById('addStockQtyError');
                            else errEl = document.getElementById('err-' + key);
                            if (!errEl) errEl = document.getElementById('addStockQtyError');
                        if (errEl) {
                            errEl.textContent = data.errors[key];
                            errEl.style.display = 'block';
                        }
                    }
                } else {
                    alert('Operation Failed: ' + (data.error || data.message || 'Unknown error')); 
                }
            }
        } catch(err) {
            console.error('Add stock error:', err);
            alert('Network communication error. Please try again.');
        }
        finally { btn.disabled = false; btn.textContent = 'Add Stock'; updateAddStockUI(); }
    }

    function editItem(item) {
        openModal('edit', item);
    }

    async function saveItem(e) {
        e.preventDefault();
        
        // Clear previous errors
        document.querySelectorAll('.field-error').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

        updateEditModalUI();
        if (document.getElementById('saveBtn').disabled) return;

        // Hard guard: For create mode + pcs, starting_stock must be > 0.
        // Prevents accidental submission if UI state got out of sync.
        const actionType = document.getElementById('actionType')?.value || '';
        const uom = String(document.getElementById('itemUnit')?.value || '').toLowerCase();
        const startingStockEl = document.getElementById('itemStartingStock');
        if (actionType === 'create_item' && uom === 'pcs' && startingStockEl) {
            const raw = String(startingStockEl.value || '').trim();
            const ok = raw && /^\d+$/.test(raw) && Number(raw) > 0;
            if (!ok) {
                startingStockEl.classList.add('input-error');
                const err = document.getElementById('err-itemStartingStock');
                if (err) {
                    err.textContent = 'Initial stock must be greater than 0 for pcs.';
                    err.style.display = 'block';
                }
                const saveBtn = document.getElementById('saveBtn');
                if (saveBtn) saveBtn.disabled = true;
                return;
            }
        }
        
        const btn = document.getElementById('saveBtn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span>Saving...';

        const uomEl = document.getElementById('itemUnit');
        const trackEl = document.getElementById('itemTrackByRoll');
        const uomWasDisabled = uomEl.disabled;
        const trackWasDisabled = trackEl.disabled;

        uomEl.disabled = false;
        trackEl.disabled = false;
        const formData = new FormData(document.getElementById('itemForm'));
        uomEl.disabled = uomWasDisabled;
        trackEl.disabled = trackWasDisabled;
        try {
            const res = await fetch(ADMIN_API_BASE + 'inventory_items_api.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                // Restore button UI state before closing modal (prevents "Saving..." sticking).
                btn.disabled = false;
                btn.innerHTML = originalText;
                closeModal();
                fetchUpdatedTable();
            } else { 
                if (data.errors) {
                    for (let key in data.errors) {
                        // Map API field names to UI element IDs if different
                        let idMap = { 'name': 'itemName', 'category_id': 'itemCategory', 'unit': 'itemUnit', 'unit_cost': 'itemUnitCost', 'roll_length_ft': 'itemRollLength' };
                        const targetId = idMap[key] || key;
                        const errEl = document.getElementById('err-' + targetId);
                        const inputEl = document.getElementById(targetId);
                        if (errEl) {
                            errEl.textContent = data.errors[key];
                            errEl.style.display = 'block';
                        }
                        if (inputEl) inputEl.classList.add('input-error');
                    }
                } else {
                    alert('Error: ' + data.error); 
                }
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (err) { 
            alert('Request failed.'); 
            btn.disabled = false;
            btn.innerHTML = originalText;
        } 
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    if (!window._pfInvItemsModalClickBound) {
        window._pfInvItemsModalClickBound = true;
        window.addEventListener('click', function (e) {
            if (e.target.classList.contains('modal')) e.target.style.display = 'none';
        });
    }
    if (!window._pfInvItemsPopstateBound) {
        window._pfInvItemsPopstateBound = true;
        window.addEventListener('popstate', function () { location.reload(); });
    }

    /* Toolbar: turbo-init initTree(.main-content). Table rows are plain onclick; fetchUpdatedTable initTree(tbody) after AJAX. */
    function ensureInvItemsAlpineBoot() {}
    window.printflowInitInvItemsPage = ensureInvItemsAlpineBoot;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            printflowInitInvItemsBindings();
            ensureInvItemsAlpineBoot();
        });
    } else {
        printflowInitInvItemsBindings();
        ensureInvItemsAlpineBoot();
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
