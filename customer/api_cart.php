<?php
/**
 * Customer Cart API
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_option_stock.php';

$session_user_type = trim((string)(get_user_type() ?? ''));
if (!is_logged_in() || strcasecmp($session_user_type, 'Customer') !== 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal server error', 'error' => $error['message']]);
        exit;
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$customer_id = get_customer_id();
if ($customer_id && empty($_SESSION['cart'])) {
    load_customer_cart_into_session($customer_id);
}

define('CART_MAX', 99);

function cart_key(int $product_id, ?int $variant_id): string {
    return $product_id . '_' . ($variant_id ?? '0');
}

function cart_count(): int {
    return array_sum(array_column($_SESSION['cart'], 'quantity'));
}

// -----------------------------------------------------------------------
// ADD
// -----------------------------------------------------------------------
if ($action === 'add') {
    $product_id = (int)($input['product_id'] ?? 0);
    $variant_id = isset($input['variant_id']) && $input['variant_id'] !== '' ? (int)$input['variant_id'] : null;
    $quantity   = max(1, (int)($input['quantity'] ?? 1));

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }

    $product = db_query(
        "SELECT product_id, name, price, category, product_type FROM products WHERE product_id = ? AND status = 'Activated'",
        'i', [$product_id]
    );
    if (empty($product)) {
        echo json_encode(['success' => false, 'message' => 'Product not available.']);
        exit;
    }
    $product = $product[0];
    $price        = (float)$product['price'];
    $variant_name = '';

    if ($variant_id !== null) {
        $variant = db_query(
            "SELECT variant_id, variant_name, price FROM product_variants WHERE variant_id = ? AND product_id = ? AND status = 'Active'",
            'ii', [$variant_id, $product_id]
        );
        if (empty($variant)) {
            echo json_encode(['success' => false, 'message' => 'Selected variant is not available.']);
            exit;
        }
        $price        = (float)$variant[0]['price'];
        $variant_name = $variant[0]['variant_name'];
    } else {
        $has_variants = db_query(
            "SELECT COUNT(*) as cnt FROM product_variants WHERE product_id = ? AND status = 'Active'",
            'i', [$product_id]
        );
        if (!empty($has_variants) && (int)$has_variants[0]['cnt'] > 0) {
            $first_variant = db_query(
                "SELECT variant_id, variant_name, price FROM product_variants WHERE product_id = ? AND status = 'Active' ORDER BY variant_id ASC LIMIT 1",
                'i', [$product_id]
            );
            if (!empty($first_variant)) {
                $variant_id   = (int)$first_variant[0]['variant_id'];
                $price        = (float)$first_variant[0]['price'];
                $variant_name = (string)$first_variant[0]['variant_name'];
            }
        }
    }

    $key           = cart_key($product_id, $variant_id);
    $current_total = cart_count();
    $available     = CART_MAX - $current_total;

    if ($available <= 0) {
        echo json_encode(['success' => false, 'message' => 'Your cart is full.', 'cart_count' => $current_total]);
        exit;
    }

    $quantity = min($quantity, $available);

    if (isset($_SESSION['cart'][$key])) {
        $nextQty = min(CART_MAX, $_SESSION['cart'][$key]['quantity'] + $quantity);
        $stockCheck = printflow_product_option_stock_validate(
            $product_id,
            (int)($_SESSION['cart'][$key]['branch_id'] ?? 0),
            (array)($_SESSION['cart'][$key]['customization'] ?? []),
            $nextQty
        );
        if (!empty($stockCheck['uses_option_stock']) && empty($stockCheck['ok'])) {
            echo json_encode(['success' => false, 'message' => $stockCheck['message'] ?? 'Insufficient stock for selected variant.']);
            exit;
        }
        $_SESSION['cart'][$key]['quantity'] = $nextQty;
    } else {
        $_SESSION['cart'][$key] = [
            'product_id'   => $product_id,
            'variant_id'   => $variant_id,
            'name'         => $product['name'],
            'category'     => $product['category'],
            'source_page'  => 'products',
            'variant_name' => $variant_name,
            'quantity'     => $quantity,
            'price'        => $price,
        ];
    }

    if ($customer_id) sync_cart_to_db($customer_id);

    echo json_encode(['success' => true, 'message' => 'Added to cart!', 'cart_count' => cart_count()]);
    exit;
}

// -----------------------------------------------------------------------
// UPDATE QTY
// -----------------------------------------------------------------------
if ($action === 'update') {
    $key      = $input['cart_key'] ?? '';
    $quantity = (int)($input['quantity'] ?? 0);

    if (!isset($_SESSION['cart'][$key])) {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    if ($quantity <= 0) {
        unset($_SESSION['cart'][$key]);
    } else {
        $item = $_SESSION['cart'][$key];
        $stockCheck = printflow_product_option_stock_validate(
            (int)($item['product_id'] ?? 0),
            (int)($item['branch_id'] ?? 0),
            (array)($item['customization'] ?? []),
            $quantity
        );
        if (!empty($stockCheck['uses_option_stock']) && empty($stockCheck['ok'])) {
            echo json_encode(['success' => false, 'message' => $stockCheck['message'] ?? 'Insufficient stock for selected variant.']);
            exit;
        }
        $current_item_qty  = $_SESSION['cart'][$key]['quantity'] ?? 0;
        $other_total       = cart_count() - $current_item_qty;
        if ($other_total + $quantity > CART_MAX) {
            echo json_encode(['success' => false, 'message' => 'Your cart is full.', 'cart_count' => cart_count()]);
            exit;
        }
        $_SESSION['cart'][$key]['quantity'] = $quantity;
    }

    if ($customer_id) sync_cart_to_db($customer_id);
    echo json_encode(['success' => true, 'cart_count' => cart_count()]);
    exit;
}

// -----------------------------------------------------------------------
// REMOVE
// -----------------------------------------------------------------------
if ($action === 'remove') {
    $key = $input['cart_key'] ?? '';
    if (isset($_SESSION['cart'][$key])) {
        unset($_SESSION['cart'][$key]);
        if ($customer_id) sync_cart_to_db($customer_id);
        echo json_encode(['success' => true, 'cart_count' => cart_count()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item not found in cart.']);
    }
    exit;
}

// -----------------------------------------------------------------------
// GET COUNT
// -----------------------------------------------------------------------
if ($action === 'get_count') {
    echo json_encode(['success' => true, 'cart_count' => cart_count()]);
    exit;
}

// -----------------------------------------------------------------------
// CLEAR
// -----------------------------------------------------------------------
if ($action === 'clear') {
    $_SESSION['cart'] = [];
    if ($customer_id) sync_cart_to_db($customer_id);
    echo json_encode(['success' => true, 'cart_count' => 0]);
    exit;
}

// -----------------------------------------------------------------------
// TOGGLE SELECT
// -----------------------------------------------------------------------
if ($action === 'toggle_select') {
    $key = $input['cart_key'] ?? '';
    $selected = (bool)($input['selected'] ?? true);
    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['selected'] = $selected;
    }
    echo json_encode(['success' => true]);
    exit;
}

// -----------------------------------------------------------------------
// SELECT ALL
// -----------------------------------------------------------------------
if ($action === 'select_all') {
    $selected = (bool)($input['selected'] ?? true);
    foreach ($_SESSION['cart'] as &$item) {
        $item['selected'] = $selected;
    }
    unset($item);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
