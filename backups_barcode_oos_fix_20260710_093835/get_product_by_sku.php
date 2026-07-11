<?php
/**
 * API: Get one POS product by SKU for barcode scans.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';

if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$sku = trim((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    echo json_encode(['success' => false, 'message' => 'SKU is required.']);
    exit;
}

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
        $stockSel = $usesBaseProductStock ? 'COALESCE(p.stock_quantity, 0)' : 'COALESCE(pbs.stock_quantity, 0)';
        $lowSel = $usesBaseProductStock ? 'COALESCE(p.low_stock_level, 10)' : 'COALESCE(pbs.low_stock_level, p.low_stock_level, 10)';
        $params[] = $staffBranch;
        $types .= 'i';
    }

    $posCategories = [
        'Tarpaulin',
        'T-Shirt',
        'Stickers',
        'Glass/Wall',
        'Transparent Stickers',
        'Reflectorized',
        'Sintraboard',
        'Standees',
        'Souvenirs',
        'Apparel',
        'Signage',
        'Merchandise',
        'Decals & Stickers',
        'T-Shirt Printing',
    ];

    $params[] = $sku;
    $types .= 's';

    $rows = db_query(
        "
        SELECT
            p.product_id,
            p.name as product_name,
            p.sku,
            p.category,
            p.price,
            p.product_type,
            p.status,
            ({$stockSel}) as stock_quantity,
            ({$lowSel}) as low_stock_level,
            p.product_image
        FROM products p
        {$join}
        WHERE LOWER(p.sku) = LOWER(?)
        LIMIT 1
        ",
        $types,
        $params
    );
    if (empty($rows)) {
        echo json_encode(['success' => true, 'product' => null]);
        exit;
    }

    $product = $rows[0];
    $status = (string)($product['status'] ?? '');
    $category = (string)($product['category'] ?? '');
    $availability = 'available';
    if (strcasecmp($status, 'Archived') === 0) {
        $availability = 'archived';
    } elseif (strcasecmp($status, 'Activated') !== 0) {
        $availability = 'inactive';
    } elseif (!in_array($category, $posCategories, true)) {
        $availability = 'pos_unavailable';
    }

    $product['stock_status'] = get_stock_status($product['stock_quantity'], $product['low_stock_level']);
    $product['quantity'] = (int)($product['stock_quantity'] ?? 0);

    echo json_encode([
        'success' => true,
        'availability' => $availability,
        'product' => $product,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
