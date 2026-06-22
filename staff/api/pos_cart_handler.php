<?php
/**
 * API: POS Cart Handler
 * Path: staff/api/pos_cart_handler.php
 * Handles session-based cart for POS walk-ins.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';

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

function pos_cart_item_is_service(array $item): bool
{
    if (!empty($item['is_service'])) {
        return true;
    }

    $customization = $item['customization'] ?? null;
    if (is_array($customization)) {
        if (!empty($customization['service_id']) || !empty($customization['service_type'])) {
            return true;
        }
    }

    return false;
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
            $pos_branch_id = (int)($_SESSION['branch_id'] ?? 0);

            $product = db_query("SELECT name, price FROM products WHERE product_id = ?", 'i', [$product_id]);

            // Fill missing display values from catalog when available.
            if ($name === null) {
                $name = !empty($product) ? (string)$product[0]['name'] : 'Service';
            }
            if ($price === null) {
                $price = !empty($product) ? (float)$product[0]['price'] : 0.0;
            }

            // Services do not consume products.stock_quantity.
            // For products, always use branch-effective stock so POS checks are accurate.
            $stock = null;
            if (!$is_service) {
                if (empty($product)) {
                    throw new Exception('Product not found.');
                }
                [$effectiveStock] = printflow_product_effective_stock($product_id, $pos_branch_id);
                $stock = (int)$effectiveStock;
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
                    $existingIsService = pos_cart_item_is_service($item) || $is_service;
                    if (!$existingIsService && $stock !== null && ($item['qty'] + $qty) > $stock) {
                        throw new Exception('Cannot add more. Insufficient stock.');
                    }
                    
                    $item['qty'] += $qty;
                    // Preserve service flag when legacy cart rows are missing it.
                    $item['is_service'] = $existingIsService;
                    if ($existingIsService) {
                        $item['stock'] = null;
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Stock check for new item
                if (!$is_service && $stock !== null && $qty > $stock) {
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
                $isServiceItem = pos_cart_item_is_service($item);
                $item['is_service'] = $isServiceItem;
                if (!$isServiceItem) {
                    $pos_branch_id = (int)($_SESSION['branch_id'] ?? 0);
                    [$latestStock] = printflow_product_effective_stock((int)$item['product_id'], $pos_branch_id);
                    $item['stock'] = (int)$latestStock;
                } else {
                    $item['stock'] = null;
                }
                if (!$isServiceItem && $item['stock'] !== null && $qty > $item['stock']) {
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

    // Normalize legacy cart rows so service items never hit product stock checks.
    $pos_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    foreach ($_SESSION['pos_cart'] as &$cartItem) {
        $isServiceItem = pos_cart_item_is_service((array)$cartItem);
        $cartItem['is_service'] = $isServiceItem;
        if ($isServiceItem) {
            $cartItem['stock'] = null;
            continue;
        }
        [$latestStock] = printflow_product_effective_stock((int)($cartItem['product_id'] ?? 0), $pos_branch_id);
        $cartItem['stock'] = (int)$latestStock;
    }
    unset($cartItem);

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
