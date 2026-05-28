<?php
/**
 * Staff Products (Inventory) Page
 * PrintFlow - Printing Shop PWA
 * Read-only view for staff
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';

require_role('Staff');
printflow_require_staff_module('products');
require_once __DIR__ . '/../includes/staff_pending_check.php';

printflow_ensure_product_branch_stock_table();
printflow_ensure_product_inventory_transaction_schema();
$staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
$staffBranchNameRow = db_query('SELECT branch_name FROM branches WHERE id = ? LIMIT 1', 'i', [$staffBranchId]);
$staffBranchName = trim((string)($staffBranchNameRow[0]['branch_name'] ?? 'Assigned Branch'));
$usesBaseProductStock = printflow_product_branch_uses_base_stock($staffBranchId);

function staff_products_table_exists(string $table): bool {
    try {
        return !empty(db_query("SHOW TABLES LIKE ?", 's', [$table]));
    } catch (Throwable $e) {
        return false;
    }
}

function staff_products_column_exists(string $table, string $column): bool {
    try {
        return !empty(db_query("SHOW COLUMNS FROM `{$table}` LIKE ?", 's', [$column]));
    } catch (Throwable $e) {
        return false;
    }
}

// Get filter parameters
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$stockExpr = $usesBaseProductStock
    ? 'COALESCE(p.stock_quantity, 0)'
    : 'COALESCE(pbs.stock_quantity, 0)';
$lowStockExpr = $usesBaseProductStock
    ? 'COALESCE(p.low_stock_level, 10)'
    : 'COALESCE(pbs.low_stock_level, p.low_stock_level, 10)';
$sql = "SELECT p.product_id, p.sku, p.name, p.category, p.product_type, p.price, p.status, p.description, p.photo_path,
               {$stockExpr} AS stock_quantity, {$lowStockExpr} AS low_stock_level
        FROM products p
        LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
        WHERE p.status = 'Activated'";
$params = [$staffBranchId];
$types = 'i';

if (!empty($category)) {
    $sql .= " AND p.category = ?";
    $params[] = $category;
    $types .= 's';
}

if (!empty($search)) {
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $search_term = '%' . $search . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

// Pagination settings
$items_per_page = 15;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

// Count total items for pagination
$count_sql = "SELECT COUNT(*) as total
              FROM products p
              LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
              WHERE p.status = 'Activated'";
$count_params = [$staffBranchId];
$count_types = 'i';

if (!empty($category)) {
    $count_sql .= " AND p.category = ?";
    $count_params[] = $category;
    $count_types .= 's';
}

if (!empty($search)) {
    $count_sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $count_params[] = '%' . $search . '%';
    $count_params[] = '%' . $search . '%';
    $count_types .= 'ss';
}

$total_result = db_query($count_sql, $count_types, $count_params);
$total_items = $total_result[0]['total'] ?? 0;
$total_pages = ceil($total_items / $items_per_page);

$sort = $_GET['sort'] ?? 'az';
$sort_clause = match($sort) {
    'za'      => " ORDER BY p.name DESC",
    'price_high' => " ORDER BY p.price DESC",
    'price_low'  => " ORDER BY p.price ASC",
    'stock_low'  => " ORDER BY {$stockExpr} ASC",
    default   => " ORDER BY p.name ASC"
};

$sql .= $sort_clause . " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

$products = db_query($sql, $types, $params);
$categories = db_query("SELECT DISTINCT category FROM products WHERE status = 'Activated' ORDER BY category ASC");

$inventory_items = [];
$inventory_ledger = [];
$show_branch_inventory = false;

try {
    $hasInvItems = staff_products_table_exists('inv_items');
    $hasInvTx = staff_products_table_exists('inventory_transactions');
    $hasItemStatus = $hasInvItems && staff_products_column_exists('inv_items', 'status');
    $hasTrackByRoll = $hasInvItems && staff_products_column_exists('inv_items', 'track_by_roll');
    $hasReorderLevel = $hasInvItems && staff_products_column_exists('inv_items', 'reorder_level');
    $hasUnitOfMeasure = $hasInvItems && staff_products_column_exists('inv_items', 'unit_of_measure');
    $hasTxBranch = $hasInvTx && staff_products_column_exists('inventory_transactions', 'branch_id');
    $hasTxDirection = $hasInvTx && staff_products_column_exists('inventory_transactions', 'direction');
    $hasTxDate = $hasInvTx && staff_products_column_exists('inventory_transactions', 'transaction_date');
    $hasTxUom = $hasInvTx && staff_products_column_exists('inventory_transactions', 'unit_of_measure');

    if ($hasInvItems && $hasInvTx && $hasTxDirection && $hasTxDate) {
        $show_branch_inventory = true;

        $itemStatusWhere = $hasItemStatus ? "WHERE i.status = 'ACTIVE'" : '';
        $itemTrackByRollSelect = $hasTrackByRoll ? 'i.track_by_roll' : '0 AS track_by_roll';
        $itemReorderSelect = $hasReorderLevel ? 'i.reorder_level' : '0 AS reorder_level';
        $itemUomSelect = $hasUnitOfMeasure ? 'i.unit_of_measure' : "'UNIT' AS unit_of_measure";
        $itemBranchFilter = $hasTxBranch ? ' AND t.branch_id = ?' : '';
        $itemTypes = $hasTxBranch ? 'i' : '';
        $itemParams = $hasTxBranch ? [$staffBranchId] : [];

        $inventory_items = db_query("
            SELECT i.id, i.name, {$itemUomSelect}, {$itemReorderSelect}, {$itemTrackByRollSelect},
                   COALESCE((
                       SELECT SUM(IF(t.direction = 'IN', t.quantity, -t.quantity))
                       FROM inventory_transactions t
                       WHERE t.item_id = i.id{$itemBranchFilter}
                   ), 0) AS current_stock
            FROM inv_items i
            {$itemStatusWhere}
            ORDER BY i.name ASC
            LIMIT 12
        ", $itemTypes, $itemParams) ?: [];

        $ledgerBranchWhere = $hasTxBranch ? 'WHERE t.branch_id = ?' : '';
        $ledgerTypes = $hasTxBranch ? 'i' : '';
        $ledgerParams = $hasTxBranch ? [$staffBranchId] : [];
        $ledgerUomSelect = $hasTxUom ? 't.unit_of_measure' : "'UNIT' AS unit_of_measure";

        $inventory_ledger = db_query("
            SELECT t.transaction_date, t.direction, t.quantity,
                   CASE
                       WHEN UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT')
                           THEN 'pcs'
                       ELSE {$ledgerUomSelect}
                   END AS unit_of_measure,
                   t.ref_type, t.ref_id, t.notes,
                   COALESCE(
                       " . (db_table_has_column('inventory_transactions', 'product_id') ? "NULLIF(TRIM(p_direct.name), '')," : "") . "
                       NULLIF(TRIM(p_item.name), ''),
                       NULLIF(TRIM(p_ref.name), ''),
                       i.name,
                       CASE
                           WHEN UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT') THEN CONCAT('Product #', COALESCE(t.ref_id, t.item_id))
                           ELSE CONCAT('Item #', t.item_id)
                       END
                   ) AS item_name
            FROM inventory_transactions t
            LEFT JOIN inv_items i ON i.id = t.item_id
            " . (db_table_has_column('inventory_transactions', 'product_id') ? "LEFT JOIN products p_direct ON p_direct.product_id = t.product_id" : "") . "
            LEFT JOIN products p_item ON p_item.product_id = t.item_id AND UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT')
            LEFT JOIN products p_ref ON p_ref.product_id = t.ref_id AND UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT')
            {$ledgerBranchWhere}
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT 12
        ", $ledgerTypes, $ledgerParams) ?: [];
    }
} catch (Throwable $e) {
    error_log('staff/products.php inventory sections disabled: ' . $e->getMessage());
    $inventory_items = [];
    $inventory_ledger = [];
    $show_branch_inventory = false;
}

$page_title = 'Products & Inventory - Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .page-title, h1, h2, h3, .kpi-value, .kpi-label { font-family: 'Outfit', sans-serif; }
        .staff-products-table-card {
            margin-top: 20px;
        }
        .staff-products-readonly-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 24px;
        }
        .staff-products-readonly-card {
            margin-top: 0;
        }
        .products-table {
            table-layout: fixed;
            width: 100%;
        }
        .products-table th,
        .products-table td {
            overflow: hidden;
        }
        .products-table th:nth-child(1) { width: 90px; }
        .products-table th:nth-child(2) { width: 160px; }
        .products-table th:nth-child(3) { width: 120px; }
        .products-table th:nth-child(4) { width: 100px; }
        .products-table th:nth-child(5) { width: 80px; }
        .products-table th:nth-child(6) { width: 80px; }
        .products-table td:nth-child(2),
        .products-table td:nth-child(3) {
            max-width: 0;
        }
        .products-table td:nth-child(2) > .truncate-ellipsis,
        .products-table td:nth-child(3) > .truncate-ellipsis {
            max-width: 100%;
        }

        .truncate-ellipsis {
            display: block;
            width: 100%;
            max-width: 150px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .inventory-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }
        .inventory-chip.roll {
            background: #ede9fe;
            color: #6d28d9;
        }
        .inventory-chip.unit {
            background: #e0f2fe;
            color: #0369a1;
        }
        .inventory-chip.in {
            background: #dcfce7;
            color: #166534;
        }
        .inventory-chip.out {
            background: #fee2e2;
            color: #991b1b;
        }
        .stock-value-low {
            color: #dc2626;
            font-weight: 700;
        }
        .stock-value-ok {
            color: #16a34a;
            font-weight: 500;
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
        #productsTableBody tr {
            cursor: pointer;
            transition: background-color 0.18s ease;
        }
        #productsTableBody tr:hover {
            background: linear-gradient(90deg, rgba(6, 161, 161, 0.05) 0%, rgba(158, 215, 196, 0.10) 100%);
        }
        #view-product-modal-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.55);
            z-index: 11000;
        }
        #view-product-modal-overlay.active {
            display: flex;
        }
        #view-product-modal {
            width: min(100%, 800px);
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        }
        .products-view-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 24px 16px;
            border-bottom: 1px solid #eef2f7;
        }
        .products-view-modal-body {
            padding: 24px;
        }
        .products-view-modal-footer {
            padding-top: 20px;
            margin-top: 24px;
            border-top: 1px solid #eef2f7;
            display: flex;
            justify-content: flex-end;
        }
        .view-label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
        }
        .view-value-box {
            min-height: 38px;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
            color: #111827;
            font-size: 13px;
            line-height: 1.45;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #view-product-name.view-value-box {
            white-space: nowrap;
        }
        .view-status-box {
            min-height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }
        .view-modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            min-width: 0;
        }
        .view-modal-stack {
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-width: 0;
        }
        @media (max-width: 768px) {
            .view-modal-grid {
                grid-template-columns: 1fr;
            }
            .products-view-modal-header,
            .products-view-modal-body {
                padding: 18px;
            }
            .products-table {
                width: 100%;
                min-width: 0;
                table-layout: fixed;
            }
            .products-table th,
            .products-table td {
                padding-left: 6px;
                padding-right: 6px;
            }
            .products-table th:nth-child(1),
            .products-table td:nth-child(1) {
                width: 104px;
            }
            .products-table th:nth-child(2),
            .products-table td:nth-child(2) {
                width: auto;
                max-width: none;
            }
            .products-table th:nth-child(2) .truncate-ellipsis,
            .products-table td:nth-child(2) .truncate-ellipsis {
                max-width: 100%;
            }
            .products-table th:nth-child(3),
            .products-table td:nth-child(3),
            .products-table th:nth-child(4),
            .products-table td:nth-child(4),
            .products-table th:nth-child(5),
            .products-table td:nth-child(5),
            .products-table th:nth-child(6),
            .products-table td:nth-child(6) {
                display: none;
            }

            /* Readonly Inventory Tables Mobile */
            .staff-products-readonly-grid table thead {
                display: none;
            }
            .staff-products-readonly-grid table, .staff-products-readonly-grid tbody, .staff-products-readonly-grid tr, .staff-products-readonly-grid td {
                display: block;
                width: 100%;
            }
            .staff-products-readonly-grid tr {
                border-bottom: 1px solid #eef2f7;
                padding: 12px 0;
            }
            .staff-products-readonly-grid td {
                padding: 4px 0;
                border: none;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .staff-products-readonly-grid td::before {
                content: attr(data-label);
                font-weight: 700;
                font-size: 11px;
                color: #64748b;
                text-transform: uppercase;
                margin-right: 12px;
                flex-shrink: 0;
            }
        }
        @media (max-width: 960px) {
            .staff-products-readonly-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title">Products & Inventory</h1>
                <p class="page-subtitle">View and monitor items and stock levels</p>
            </div>
        </header>

        <main x-data="{ filterOpen: false, sortOpen: false, hasActiveFilters: <?php echo (!empty($search) || !empty($category)) ? 'true' : 'false'; ?> }">
            <?php
            // Calculate KPIs for products
            $total_products = db_query("
                SELECT COUNT(*) as count
                FROM products p
                LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
                WHERE p.status = 'Activated'
            ", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $low_stock_count = db_query("
                SELECT COUNT(*) as count
                FROM products p
                LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
                WHERE p.status = 'Activated'
                  AND {$stockExpr} <= {$lowStockExpr}
            ", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $fixed_count = db_query("
                SELECT COUNT(*) as count
                FROM products p
                LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
                WHERE p.status = 'Activated' AND p.product_type = 'fixed'
            ", 'i', [$staffBranchId])[0]['count'] ?? 0;
            $variable_count = db_query("
                SELECT COUNT(*) as count
                FROM products p
                LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
                WHERE p.status = 'Activated' AND p.product_type = 'variable'
            ", 'i', [$staffBranchId])[0]['count'] ?? 0;
            ?>

            <!-- Standardized KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <span class="kpi-label">Total Products</span>
                    <span class="kpi-value"><?php echo number_format($total_products); ?></span>
                    <span class="kpi-sub"><?php echo htmlspecialchars($staffBranchName); ?> catalog items</span>
                </div>
                <div class="kpi-card rose">
                    <span class="kpi-label">Low Stock</span>
                    <span class="kpi-value"><?php echo $low_stock_count; ?></span>
                    <span class="kpi-sub">Items below threshold (10)</span>
                </div>
                <div class="kpi-card emerald">
                    <span class="kpi-label">In Stock</span>
                    <span class="kpi-value"><?php echo number_format($total_products - $low_stock_count); ?></span>
                    <span class="kpi-sub">Sufficient quantity available</span>
                </div>
                <div class="kpi-card amber">
                    <span class="kpi-label">Inventory Status</span>
                    <span class="kpi-value" style="font-size:18px; line-height:36px;"><?php echo round((($total_products - $low_stock_count) / max(1, $total_products)) * 100); ?>%</span>
                    <span class="kpi-sub">Overall availability health</span>
                </div>
            </div>

            <!-- Inventory List Container -->
            <div class="card staff-products-table-card">
                <div class="toolbar-container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">
                        Product List
                    </h3>
                    <div class="toolbar-group" style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
    

                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{ active: sortOpen || ('<?php echo $sort; ?>' !== 'az') }" @click="sortOpen = !sortOpen; filterOpen = false">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                                <span style="font-weight:400;">Sort by</span>
                            </button>
                            <div class="dropdown-panel sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'az'         => 'A → Z',
                                    'za'         => 'Z → A',
                                    'price_high' => 'Price: High to Low',
                                    'price_low'  => 'Price: Low to High',
                                    'stock_low'  => 'Lowest Stock First',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <a href="products.php?sort=<?php echo urlencode($key); ?><?php echo !empty($category) ? '&category='.urlencode($category) : ''; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" class="sort-option <?php echo $sort === $key ? 'active' : ''; ?>" style="text-decoration:none;">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="'<?php echo $sort; ?>' === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                <span style="font-weight:400;">Filter</span>
                                <template x-if="hasActiveFilters">
                                    <span class="filter-badge"><?php echo (int)!empty($category) + (int)!empty($search); ?></span>
                                </template>
                            </button>

                            <!-- Filter Panel -->
                            <div class="dropdown-panel filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <form id="products-filter-form" method="GET" action="products.php">
                                    <?php if (!empty($sort) && $sort !== 'az'): ?>
                                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                                    <?php endif; ?>
                                    <div class="filter-header">Filter Products</div>
                                    
                                    <!-- Category -->
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Category</span>
                                            <button type="button" onclick="document.forms['products-filter-form'].elements['category'].value=''; document.getElementById('products-filter-form').submit()" class="filter-reset-link">Reset</button>
                                        </div>
                                        <select name="category" class="filter-select" onchange="document.getElementById('products-filter-form').submit()">
                                            <option value="">All Categories</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['category']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Keyword search -->
                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Keyword search</span>
                                            <button type="button" onclick="document.getElementById('productSearchInput').value=''; document.getElementById('products-filter-form').submit()" class="filter-reset-link">Reset</button>
                                        </div>
                                        <input type="text" id="productSearchInput" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" onchange="document.getElementById('products-filter-form').submit()">
                                    </div>

                                    <div class="filter-footer">
                                        <a href="products.php" class="filter-btn-reset" style="display:flex; align-items:center; justify-content:center; text-decoration:none; width: 100%;">Reset all filters</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="overflow-x-auto" style="max-width:100%; overflow-x:auto;">
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php foreach ($products as $product): ?>
                                <?php
                                $viewPayload = [
                                    'product_id' => (int)($product['product_id'] ?? 0),
                                    'name' => (string)($product['name'] ?? ''),
                                    'sku' => (string)($product['sku'] ?? ''),
                                    'category' => (string)($product['category'] ?? ''),
                                    'price' => (float)($product['price'] ?? 0),
                                    'stock_quantity' => (int)($product['stock_quantity'] ?? 0),
                                    'low_stock_level' => (int)($product['low_stock_level'] ?? 10),
                                    'status' => (string)($product['status'] ?? ''),
                                    'description' => (string)($product['description'] ?? ''),
                                    'photo_path' => (string)($product['photo_path'] ?? ''),
                                ];
                                ?>
                                <tr data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>"
                                    data-sku="<?php echo htmlspecialchars(strtolower($product['sku'])); ?>"
                                    data-category="<?php echo htmlspecialchars(strtolower($product['category'])); ?>"
                                    data-product="<?php echo htmlspecialchars(json_encode($viewPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="openViewModal(this)">
                                    <td data-label="SKU" style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($product['sku']); ?></td>
                                    <td data-label="Name" style="font-weight:500; min-width:0; max-width:0;">
                                        <div class="truncate-ellipsis" style="width:100%; max-width:100%;" title="<?php echo htmlspecialchars($product['name']); ?>">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Category" style="min-width:0; max-width:0;">
                                        <div class="truncate-ellipsis" style="width:100%; max-width:100%;" title="<?php echo htmlspecialchars($product['category']); ?>">
                                            <?php echo htmlspecialchars($product['category']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Price" style="font-weight:600;"><?php echo format_currency($product['price']); ?></td>
                                    <td data-label="Stock">
                                        <?php $productLowLevel = (int)($product['low_stock_level'] ?? 10); ?>
                                        <?php if ((int)$product['stock_quantity'] <= $productLowLevel): ?>
                                            <span style="color:#dc2626; font-weight:700;"><?php echo $product['stock_quantity']; ?></span>
                                            <span style="font-size:11px; color:#dc2626; font-weight:600;">LOW</span>
                                        <?php else: ?>
                                            <span style="color:#16a34a;"><?php echo $product['stock_quantity']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Action">
                                        <button
                                            type="button"
                                            class="table-action-btn"
                                            onclick="event.stopPropagation(); openViewModal(this.closest('tr'))"
                                        >View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php echo render_pagination($current_page, $total_pages, ['category' => $category, 'search' => $search]); ?>
            </div>

            <?php if ($show_branch_inventory): ?>
            <div class="staff-products-readonly-grid">
                <div class="card staff-products-readonly-card">
                    <div class="toolbar-container" style="margin-bottom: 16px;">
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Inventory Items</h3>
                        <span style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars($staffBranchName); ?> only</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Unit</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inventory_items)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; color:#64748b;">No inventory items found for this branch.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventory_items as $item): ?>
                                        <?php $itemStock = (float)($item['current_stock'] ?? 0); ?>
                                        <?php $itemReorder = (float)($item['reorder_level'] ?? 0); ?>
                                        <tr>
                                            <td data-label="Item" style="min-width: 0; flex: 1;">
                                                <span class="truncate-ellipsis" style="max-width: 100%;" title="<?php echo htmlspecialchars($item['name']); ?>">
                                                    <?php echo htmlspecialchars($item['name']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Unit">
                                                <span class="inventory-chip <?php echo !empty($item['track_by_roll']) ? 'roll' : 'unit'; ?>">
                                                    <?php echo !empty($item['track_by_roll']) ? 'ROLL' : htmlspecialchars(strtoupper((string)($item['unit_of_measure'] ?? 'UNIT'))); ?>
                                                </span>
                                            </td>
                                            <td data-label="Stock">
                                                <span class="<?php echo $itemStock <= $itemReorder ? 'stock-value-low' : 'stock-value-ok'; ?>">
                                                    <?php echo rtrim(rtrim(number_format($itemStock, 2), '0'), '.'); ?>
                                                </span>
                                            </td>
                                            <td data-label="Status">
                                                <?php
                                                if ($itemStock <= 0) {
                                                    echo '<span class="inventory-chip out">OUT</span>';
                                                } elseif ($itemStock <= $itemReorder) {
                                                    echo '<span class="inventory-chip out">LOW</span>';
                                                } else {
                                                    echo '<span class="inventory-chip in">OK</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card staff-products-readonly-card">
                    <div class="toolbar-container" style="margin-bottom: 16px;">
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Inventory Ledger</h3>
                        <span style="font-size:12px;color:#64748b;">Latest branch activity</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Item</th>
                                    <th>Type</th>
                                    <th>Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inventory_ledger)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; color:#64748b;">No inventory ledger entries found for this branch.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventory_ledger as $entry): ?>
                                        <tr>
                                            <td data-label="Date">
                                                <span class="truncate-ellipsis" style="max-width: 100%;" title="<?php echo htmlspecialchars(format_datetime($entry['transaction_date'])); ?>">
                                                    <?php echo htmlspecialchars(format_datetime($entry['transaction_date'])); ?>
                                                </span>
                                            </td>
                                            <td data-label="Item" style="min-width: 0; flex: 1;">
                                                <span class="truncate-ellipsis" style="max-width: 100%;" title="<?php echo htmlspecialchars($entry['item_name']); ?>">
                                                    <?php echo htmlspecialchars($entry['item_name']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Type">
                                                <span class="inventory-chip <?php echo strtoupper((string)$entry['direction']) === 'IN' ? 'in' : 'out'; ?>">
                                                    <?php echo htmlspecialchars(strtoupper((string)$entry['direction'])); ?>
                                                </span>
                                            </td>
                                            <td data-label="Qty">
                                                <span class="truncate-ellipsis" style="max-width: 100%;" title="<?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$entry['quantity'], 2), '0'), '.') . ' ' . ($entry['unit_of_measure'] ?? '')); ?>">
                                                    <?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$entry['quantity'], 2), '0'), '.') . ' ' . ($entry['unit_of_measure'] ?? '')); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<div id="view-product-modal-overlay" onclick="handleViewOverlayClick(event)">
    <div id="view-product-modal">
        <div class="products-view-modal-header">
            <h3 style="font-size:18px; font-weight:700; margin:0;">Product Details</h3>
            <button type="button" onclick="closeViewModal()" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="products-view-modal-body">
            <div class="view-modal-grid">
                <div class="view-modal-stack">
                    <div>
                        <label class="view-label">Product Name</label>
                        <div id="view-product-name" class="view-value-box">-</div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">SKU</label>
                            <div id="view-product-sku" class="view-value-box">-</div>
                        </div>
                        <div>
                            <label class="view-label">Category</label>
                            <div id="view-product-category" class="view-value-box">-</div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">Price</label>
                            <div id="view-product-price" class="view-value-box">-</div>
                        </div>
                        <div>
                            <label class="view-label">Stock Status</label>
                            <div id="view-product-stock-status" class="view-status-box">-</div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">Current Quantity</label>
                            <div id="view-product-stock" class="view-value-box" style="font-weight:700;">-</div>
                        </div>
                        <div>
                            <label class="view-label">Low Stock Warning</label>
                            <div id="view-product-low-stock" class="view-value-box">-</div>
                        </div>
                    </div>

                    <div>
                        <label class="view-label">Product Visibility</label>
                        <div id="view-product-status" class="view-status-box">-</div>
                    </div>
                </div>

                <div class="view-modal-stack">
                    <div>
                        <label class="view-label">Product Photo</label>
                        <div style="width:100%; height:200px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                            <img id="view-product-photo-img" src="" alt="" style="width:100%; height:100%; object-fit:cover; display:none;">
                            <span id="view-product-photo-text" style="color:#9ca3af; font-size:12px; text-align:center; padding:20px;">No photo available</span>
                        </div>
                    </div>
                    <div>
                        <label class="view-label">Description</label>
                        <div id="view-product-description" class="view-value-box" style="min-height:80px; white-space:pre-wrap;">-</div>
                    </div>
                </div>
            </div>

            <div class="products-view-modal-footer">
                <button type="button" onclick="closeViewModal()" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const productSearch = document.getElementById('productSearchInput');
const productCategory = document.getElementById('productCategorySelect');
const productsTableBody = document.getElementById('productsTableBody');
const productRows = productsTableBody ? Array.from(productsTableBody.querySelectorAll('tr')) : [];

function filterProductsLocally() {
    const q = (productSearch?.value || '').trim().toLowerCase();
    const cat = (productCategory?.value || '').trim().toLowerCase();

    productRows.forEach((row) => {
        const name = row.getAttribute('data-name') || '';
        const sku = row.getAttribute('data-sku') || '';
        const category = row.getAttribute('data-category') || '';
        const matchesText = q === '' || name.includes(q) || sku.includes(q);
        const matchesCategory = cat === '' || category === cat;
        row.style.display = (matchesText && matchesCategory) ? '' : 'none';
    });
}

if (productSearch) {
    productSearch.addEventListener('input', filterProductsLocally);
}
if (productCategory) {
    productCategory.addEventListener('change', filterProductsLocally);
}
filterProductsLocally();

function pfProductStockStatusLabel(qty, low) {
    qty = parseInt(qty, 10) || 0;
    low = parseInt(low, 10) || 10;
    if (qty <= 0) return 'Out of Stock';
    if (qty <= low) return 'Low Stock';
    return 'In Stock';
}

function pfStockBadgeStyle(label) {
    if (label === 'In Stock') return 'background:#dcfce7;color:#166534;';
    if (label === 'Low Stock') return 'background:#fef9c3;color:#854d0e;';
    if (label === 'Out of Stock') return 'background:#fee2e2;color:#991b1b;';
    return 'background:#f3f4f6;color:#374151;';
}

function pfVisibilityStatusStyle(status) {
    if (status === 'Activated') return 'background:#dcfce7;color:#166534;';
    if (status === 'Deactivated') return 'background:#fee2e2;color:#991b1b;';
    if (status === 'Archived') return 'background:#f3f4f6;color:#374151;';
    return 'background:#fef9c3;color:#854d0e;';
}

function openViewModal(trigger) {
    if (!trigger) return;

    let product = null;
    try {
        product = JSON.parse(trigger.dataset.product || '{}');
    } catch (error) {
        console.error('Failed to parse product view payload.', error);
        return;
    }

    const stockLabel = pfProductStockStatusLabel(product.stock_quantity, product.low_stock_level);
    const price = Number.parseFloat(product.price);

    document.getElementById('view-product-name').textContent = product.name || '-';
    document.getElementById('view-product-sku').textContent = product.sku || '-';
    document.getElementById('view-product-category').textContent = product.category || '-';
    document.getElementById('view-product-price').textContent = Number.isNaN(price)
        ? '-'
        : `PHP ${price.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    const stockStatusEl = document.getElementById('view-product-stock-status');
    stockStatusEl.textContent = stockLabel;
    stockStatusEl.style.cssText = pfStockBadgeStyle(stockLabel);

    document.getElementById('view-product-stock').textContent = product.stock_quantity ?? '-';
    document.getElementById('view-product-low-stock').textContent = product.low_stock_level ?? '-';

    const visibilityEl = document.getElementById('view-product-status');
    visibilityEl.textContent = product.status || '-';
    visibilityEl.style.cssText = pfVisibilityStatusStyle(product.status || '');

    const imageEl = document.getElementById('view-product-photo-img');
    const imageTextEl = document.getElementById('view-product-photo-text');
    if (product.photo_path) {
        imageEl.src = product.photo_path;
        imageEl.style.display = 'block';
        imageTextEl.style.display = 'none';
    } else {
        imageEl.removeAttribute('src');
        imageEl.style.display = 'none';
        imageTextEl.style.display = 'block';
    }

    document.getElementById('view-product-description').textContent = (product.description || '').trim() || '-';

    const overlay = document.getElementById('view-product-modal-overlay');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    const overlay = document.getElementById('view-product-modal-overlay');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function handleViewOverlayClick(event) {
    if (event.target.id === 'view-product-modal-overlay') {
        closeViewModal();
    }
}
</script>

</body>
</html>
