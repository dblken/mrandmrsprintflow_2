<?php
/**
 * API: Get Products for POS
 * Path: staff/api/get_products.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';

// Require staff or admin role
if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

try {
    printflow_ensure_product_branch_stock_table();
    $staffBranch = (int)(printflow_branch_filter_for_user() ?? 0);
    if ($staffBranch <= 0) {
        $selectedBranch = $_SESSION['selected_branch_id'] ?? null;
        if ($selectedBranch !== null && $selectedBranch !== 'all') {
            $staffBranch = (int)$selectedBranch;
        }
    }
    if ($staffBranch <= 0) {
        $staffBranch = (int)($_SESSION['branch_id'] ?? 0);
    }

    $usesBaseProductStock = $staffBranch > 0 && printflow_product_branch_uses_base_stock($staffBranch);
    $join = '';
    $params = [];
    $types = '';
    $stockSel = 'p.stock_quantity';
    $lowSel = 'COALESCE(p.low_stock_level, 10)';
    if ($staffBranch > 0) {
        $join = ' LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ? ';
        if ($usesBaseProductStock) {
            $stockSel = 'COALESCE(p.stock_quantity, 0)';
            $lowSel = 'COALESCE(p.low_stock_level, 10)';
        } else {
            $stockSel = 'COALESCE(pbs.stock_quantity, 0)';
            $lowSel = 'COALESCE(pbs.low_stock_level, p.low_stock_level, 10)';
        }
        $params[] = $staffBranch;
        $types = 'i';
    }
    $rows = db_query(
        "
        SELECT 
            p.product_id, 
            p.name as product_name, 
            p.sku, 
            p.category, 
            p.price, 
            p.product_type,
            ({$stockSel}) as stock_quantity, 
            ({$lowSel}) as low_stock_level,
            p.product_image 
        FROM products p
        {$join}
        WHERE p.status = 'Activated' 
        AND p.category IN ('Tarpaulin', 'T-Shirt', 'Stickers', 'Glass/Wall', 'Transparent Stickers', 'Reflectorized', 'Sintraboard', 'Standees', 'Souvenirs', 'Apparel', 'Signage', 'Merchandise', 'Decals & Stickers', 'T-Shirt Printing')
        ORDER BY p.category ASC, p.name ASC
    ",
        $types ?: null,
        $params ?: null
    );
    $products = [];
    foreach ($rows ?: [] as $p) {
        $p['stock_status'] = get_stock_status($p['stock_quantity'], $p['low_stock_level']);
        $p['quantity'] = (int) ($p['stock_quantity'] ?? 0);
        $products[] = $p;
    }
    echo json_encode(['success' => true, 'products' => $products]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
