<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_profile_completion.php';

require_role('Customer');

printflow_block_order_submission_if_profile_incomplete();

$product_id = (int)($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    redirect('cart.php');
}

$product = db_query(
    "SELECT product_id, name, price, category, product_type, status
     FROM products
     WHERE product_id = ? AND status = 'Activated'
     LIMIT 1",
    'i',
    [$product_id]
);

if (empty($product)) {
    redirect('cart.php');
}
$product = $product[0];

$variant_id = null;
$variant_name = '';
$price = (float)$product['price'];
$first_variant = db_query(
    "SELECT variant_id, variant_name, price
     FROM product_variants
     WHERE product_id = ? AND status = 'Active'
     ORDER BY variant_id ASC
     LIMIT 1",
    'i',
    [$product_id]
);
if (!empty($first_variant)) {
    $variant_id = (int)$first_variant[0]['variant_id'];
    $variant_name = (string)$first_variant[0]['variant_name'];
    $price = (float)$first_variant[0]['price'];
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$key = $product_id . '_' . ($variant_id ?? '0');
if (isset($_SESSION['cart'][$key])) {
    $_SESSION['cart'][$key]['quantity'] = (int)($_SESSION['cart'][$key]['quantity'] ?? 0) + 1;
} else {
    $_SESSION['cart'][$key] = [
        'product_id' => $product_id,
        'variant_id' => $variant_id,
        'name' => (string)$product['name'],
        'category' => (string)$product['category'],
        'source_page' => 'products',
        'variant_name' => $variant_name,
        'quantity' => 1,
        'price' => $price,
        'selected' => true,
    ];
}

$cid = get_customer_id();
if (!$cid) {
    $cid = (int)(get_user_id() ?? 0);
}
if ($cid > 0 && function_exists('sync_cart_to_db')) {
    sync_cart_to_db($cid);
}

redirect('cart.php');
