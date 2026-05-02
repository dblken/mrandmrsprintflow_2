<?php
/**
 * API: POS Cart Handler
 * Path: staff/api/pos_cart_handler.php
 * Handles session-based cart for POS walk-ins.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Require staff or admin role
if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

// Initialize cart if not exists
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
if (!is_array($data)) {
    $data = [];
}
$action = $data['action'] ?? 'get';

try {
    switch ($action) {
        case 'add':
            if (empty($data['product_id'])) {
                throw new Exception('Product ID is required.');
            }
            $product_id = (int)$data['product_id'];
            $qty = (int)($data['qty'] ?? 1);
            if ($qty < 1 || $qty > 100) throw new Exception('Quantity must be between 1 and 100.');
            
            $price = isset($data['price']) ? (float)$data['price'] : null;
            $name = $data['name'] ?? null;
            $customization = $data['customization'] ?? null;
            $custom_json = $customization ? json_encode($customization) : null;
            $is_service = !empty($data['is_service']);

            // Fetch product info if missing price or name
            if ($price === null || $name === null) {
                $product = db_query("SELECT name, price, stock_quantity FROM products WHERE product_id = ?", 'i', [$product_id]);
                if (empty($product)) throw new Exception('Product not found.');
                if ($name === null) $name = $product[0]['name'];
                if ($price === null) $price = (float)$product[0]['price'];
                $stock = ($product[0]['stock_quantity'] !== null) ? (int)$product[0]['stock_quantity'] : null;
            } else {
                // For custom items, we might not have stock info easily without a lookup
                $product = db_query("SELECT stock_quantity FROM products WHERE product_id = ?", 'i', [$product_id]);
                $stock = (!empty($product) && $product[0]['stock_quantity'] !== null) ? (int)$product[0]['stock_quantity'] : null;
            }

            // Check if item already exists in cart (match product_id, price, and customization)
            $found = false;
            foreach ($_SESSION['pos_cart'] as &$item) {
                $item_custom_json = isset($item['customization']) ? json_encode($item['customization']) : null;
                if ($item['product_id'] == $product_id && 
                    abs($item['price'] - $price) < 0.01 && 
                    $item_custom_json === $custom_json &&
                    $item['name'] === $name) {
                    
                    // Stock check
                    if ($stock !== null && ($item['qty'] + $qty) > $stock) {
                        throw new Exception('Cannot add more. Insufficient stock.');
                    }
                    
                    $item['qty'] += $qty;
                    // Preserve service flag when legacy cart rows are missing it.
                    $item['is_service'] = !empty($item['is_service']) || $is_service;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Stock check for new item
                if ($stock !== null && $qty > $stock) {
                    throw new Exception('Insufficient stock.');
                }
                
                $_SESSION['pos_cart'][] = [
                    'product_id' => $product_id,
                    'name' => $name,
                    'price' => $price,
                    'qty' => $qty,
                    'stock' => $stock,
                    'customization' => $customization,
                    'is_service' => $is_service
                ];
            }
            break;

        case 'update':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index])) {
                throw new Exception('Invalid cart item.');
            }
            $qty = (int)$data['qty'];
            if ($qty <= 0) {
                array_splice($_SESSION['pos_cart'], $index, 1);
            } else {
                if ($qty > 100) throw new Exception('Quantity cannot exceed 100.');
                $item = &$_SESSION['pos_cart'][$index];
                if ($item['stock'] !== null && $qty > $item['stock']) {
                    throw new Exception('Insufficient stock.');
                }
                $item['qty'] = $qty;
            }
            break;

        case 'update_price':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index])) {
                throw new Exception('Invalid cart item.');
            }
            $price = (float)$data['price'];
            if ($price < 0) throw new Exception('Price cannot be negative.');
            $_SESSION['pos_cart'][$index]['price'] = $price;
            $_SESSION['pos_cart'][$index]['price_set'] = true;
            break;

        case 'update_service_link':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index])) {
                throw new Exception('Invalid cart item.');
            }

            $pending_order_id = (int)($data['pending_order_id'] ?? 0);
            $customization_id = (int)($data['customization_id'] ?? 0);
            if ($pending_order_id <= 0) {
                throw new Exception('Pending order ID is required.');
            }

            $_SESSION['pos_cart'][$index]['pending_order_id'] = $pending_order_id;
            if ($customization_id > 0) {
                $_SESSION['pos_cart'][$index]['pending_customization_id'] = $customization_id;
            }
            break;

        case 'remove':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index >= 0 && isset($_SESSION['pos_cart'][$index])) {
                array_splice($_SESSION['pos_cart'], $index, 1);
            }
            break;

        case 'clear':
            $_SESSION['pos_cart'] = [];
            break;

        case 'get':
        default:
            // Just return the cart
            break;
    }

    session_write_close();
    echo json_encode([
        'success' => true,
        'cart' => array_values($_SESSION['pos_cart'])
    ]);

} catch (Exception $e) {
    session_write_close();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'cart' => array_values($_SESSION['pos_cart'] ?? [])
    ]);
}
