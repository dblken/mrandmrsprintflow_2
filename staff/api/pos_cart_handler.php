<?php
/**
 * API: POS Cart Handler
 * Path: staff/api/pos_cart_handler.php
 * Handles session-based cart for POS walk-ins.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';
require_once __DIR__ . '/../../includes/product_option_stock.php';
require_once __DIR__ . '/../../includes/service_field_config_helper.php';
require_once __DIR__ . '/../../includes/service_order_helper.php';
require_once __DIR__ . '/../../includes/pos_draft_lifecycle.php';
require_once __DIR__ . '/../../includes/pos_set_price_helpers.php';

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

function pos_cart_branch_id(): int
{
    $branchId = 0;
    if (function_exists('printflow_branch_filter_for_user')) {
        $branchId = (int)(printflow_branch_filter_for_user() ?? 0);
    }
    if ($branchId <= 0) {
        $selectedBranch = $_SESSION['selected_branch_id'] ?? null;
        if ($selectedBranch !== null && $selectedBranch !== 'all') {
            $branchId = (int)$selectedBranch;
        }
    }
    if ($branchId <= 0) {
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
    }
    return $branchId;
}
function pos_cart_effective_product_stock(int $productId, int $branchId): int
{
    if ($productId <= 0) {
        return 0;
    }

    $optionStock = $branchId > 0 ? printflow_product_option_stock_total($productId, $branchId) : null;
    if ($optionStock !== null) {
        return (int)($optionStock['total_stock'] ?? 0);
    }

    [$effectiveStock] = printflow_product_effective_stock($productId, $branchId);
    return (int)$effectiveStock;
}

function pos_cart_custom_value(array $customization, string $key, ?string $label = null): string
{
    $candidates = [$key];
    if ($label !== null && $label !== '') {
        $candidates[] = $label;
    }
    if ($key === 'branch') {
        $candidates[] = 'branch_id';
    }
    $keyBlob = strtolower(trim($key . ' ' . (string)$label));
    $isDesignUploadKey = in_array(strtolower(trim($key)), ['design_file', 'upload_design', 'design_upload', 'upload_design_file'], true)
        || (str_contains($keyBlob, 'design') && str_contains($keyBlob, 'upload'))
        || str_contains($keyBlob, 'upload design');
    if ($key === 'design_file' || $isDesignUploadKey) {
        array_push(
            $candidates,
            'design_file',
            'upload_design',
            'design_upload',
            'design_upload_path',
            'design_upload_name',
            'design_upload',
            'Upload Design',
            'Design',
            'design_file_link',
            'design_link',
            'Upload Design Link',
            'Design Link',
            $key . '_link',
            ($label !== null && $label !== '' ? $label . ' Link' : '')
        );
    }

    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $customization)) {
            $value = $customization[$candidate];
            if (is_array($value)) {
                $value = implode(' ', array_filter(array_map('strval', $value)));
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function pos_cart_required_message(string $key, string $label, string $type): string
{
    $needle = strtolower($key . ' ' . $label);
    if ($type === 'file' || strpos($needle, 'design') !== false) {
        return 'Please upload a design or paste a design link.';
    }
    if (strpos($needle, 'layout') !== false) {
        return 'Please select a layout.';
    }
    if (strpos($needle, 'needed_date') !== false || strpos($needle, 'needed date') !== false) {
        return 'Please select a needed date.';
    }
    if (strpos($needle, 'quantity') !== false) {
        return 'Quantity must be at least 1.';
    }
    if (in_array($type, ['select', 'radio'], true)) {
        return 'Please select ' . strtolower($label) . '.';
    }
    return 'Please enter ' . strtolower($label) . '.';
}

function pos_cart_config_applies(array $config, array $customization, array $fieldConfigs = [], string $fieldKey = ''): bool
{
    if (!empty($fieldConfigs)) {
        $customization = printflow_service_field_values_from_customization($customization, $fieldConfigs);
    }
    return printflow_service_field_is_active($config, $customization, $fieldKey, $fieldConfigs);
}

function pos_cart_validate_nested_required(array $fieldConfig, array $customization, array &$errors): void
{
    $fieldKey = (string)($fieldConfig['key'] ?? '');
    $selected = pos_cart_custom_value($customization, $fieldKey, (string)($fieldConfig['label'] ?? ''));
    if ($selected === '' || empty($fieldConfig['options']) || !is_array($fieldConfig['options'])) {
        return;
    }

    foreach ($fieldConfig['options'] as $optionIndex => $option) {
        if (!is_array($option)) {
            continue;
        }
        $optionValue = trim((string)($option['value'] ?? ''));
        if ($optionValue === '' || !printflow_service_option_values_match($selected, $optionValue)) {
            continue;
        }
        foreach (($option['nested_fields'] ?? []) as $nestedIndex => $nestedField) {
            if (empty($nestedField['required'])) {
                continue;
            }
            $nestedKey = $fieldKey . '_nested_' . $optionIndex . '_' . $nestedIndex;
            $nestedLabel = trim((string)($nestedField['label'] ?? $nestedKey));
            $nestedType = trim((string)($nestedField['type'] ?? 'text'));
            $value = pos_cart_custom_value($customization, $nestedKey, $nestedLabel);
            if ($nestedType === 'dimension') {
                $w = pos_cart_custom_value($customization, $nestedKey . '_width', $nestedLabel . ' Width');
                $h = pos_cart_custom_value($customization, $nestedKey . '_height', $nestedLabel . ' Height');
                if ($value === '' && ($w === '' || $h === '')) {
                    $errors[$nestedKey] = pos_cart_required_message($nestedKey, $nestedLabel, $nestedType);
                }
            } elseif ($value === '') {
                $errors[$nestedKey] = pos_cart_required_message($nestedKey, $nestedLabel, $nestedType);
            }
        }
        return;
    }
}

function pos_cart_validate_service_payload(int $serviceId, array $customization, int $qty): array
{
    $errors = [];
    $configs = function_exists('get_service_field_config') ? get_service_field_config($serviceId) : [];

    if (empty($configs)) {
        foreach ([
            'branch_id' => ['Branch', 'select'],
            'needed_date' => ['Needed Date', 'date'],
            'quantity' => ['Quantity', 'quantity'],
        ] as $key => [$label, $type]) {
            $value = $key === 'quantity' ? (string)$qty : pos_cart_custom_value($customization, $key, $label);
            if ($key === 'quantity') {
                $quantity = (int)$value;
                if ($quantity < 1) {
                    $errors[$key] = 'Quantity must be at least 1.';
                }
            } elseif ($value === '') {
                $errors[$key] = pos_cart_required_message($key, $label, $type);
            }
        }
        return $errors;
    }

    $fieldValues = printflow_service_field_values_from_customization($customization, $configs);
    $fieldValues = printflow_service_field_normalize_layout_values($fieldValues, $configs);

    foreach ($configs as $fieldKey => $config) {
        if (empty($config['visible']) || empty($config['required']) || !printflow_service_field_is_active($config, $fieldValues, (string)$fieldKey, $configs)) {
            continue;
        }

        $label = trim((string)($config['label'] ?? $fieldKey));
        $type = trim((string)($config['type'] ?? 'text'));
        $value = pos_cart_custom_value($customization, (string)$fieldKey, $label);
        if (in_array($type, ['select', 'radio'], true) && strcasecmp($value, 'Others') === 0 && !empty($config['allow_others'])) {
            $otherValue = pos_cart_custom_value($customization, (string)$fieldKey . '_other', $label . ' (Other)');
            if ($otherValue === '') {
                $errors[(string)$fieldKey] = 'Please specify ' . strtolower($label) . '.';
                continue;
            }
        }

        if ($type === 'dimension') {
            $w = pos_cart_custom_value($customization, $fieldKey . '_width', $label . ' Width');
            $h = pos_cart_custom_value($customization, $fieldKey . '_height', $label . ' Height');
            if ($value === '' && ($w === '' || $h === '')) {
                $errors[(string)$fieldKey] = pos_cart_required_message((string)$fieldKey, $label, $type);
            }
        } elseif ($type === 'quantity') {
            $quantity = (int)($value !== '' ? $value : $qty);
            if ($quantity < 1) {
                $errors[(string)$fieldKey] = 'Quantity must be at least 1.';
            }
        } elseif ($type === 'file') {
            $designMode = service_order_design_input_mode_from_customization($customization, (string) $fieldKey);
            $hasUploadedDesign = service_order_customization_has_design_file($customization, (string) $fieldKey, $label);
            $linkValue = service_order_extract_design_link_from_customization($customization, $label, (string) $fieldKey);

            if ($designMode === 'file') {
                $linkValue = '';
            } elseif ($designMode === 'link') {
                $hasUploadedDesign = false;
            } elseif ($hasUploadedDesign) {
                $linkValue = '';
            }

            if ($designMode === 'link') {
                if ($linkValue === '') {
                    $errors[$fieldKey . '_link'] = 'Please provide the required design link.';
                } else {
                    $linkCheck = service_order_validate_design_link($linkValue);
                    if (!$linkCheck['ok']) {
                        $errors[$fieldKey . '_link'] = $linkCheck['error'];
                    }
                }
            } elseif ($designMode === 'file') {
                if (!$hasUploadedDesign) {
                    $errors[(string) $fieldKey] = 'Please upload the required design file.';
                }
            } elseif ($hasUploadedDesign) {
                // Legacy payloads without design_input_mode but with a staged upload.
            } elseif ($linkValue !== '') {
                $linkCheck = service_order_validate_design_link($linkValue);
                if (!$linkCheck['ok']) {
                    $errors[$fieldKey . '_link'] = $linkCheck['error'];
                }
            } elseif ($value === '' && $linkValue === '') {
                $errors[(string) $fieldKey] = pos_cart_required_message((string) $fieldKey, $label, $type);
            }
        } elseif ($value === '') {
            $errors[(string)$fieldKey] = pos_cart_required_message((string)$fieldKey, $label, $type);
        }

        $fieldConfigForNested = $config;
        $fieldConfigForNested['key'] = (string)$fieldKey;
        pos_cart_validate_nested_required($fieldConfigForNested, $customization, $errors);
    }

    return $errors;
}

class PosCartValidationException extends Exception
{
    public array $errors;

    public function __construct(array $errors)
    {
        $message = 'Some required order details are missing.';
        foreach ($errors as $fieldError) {
            if (is_string($fieldError) && trim($fieldError) !== '') {
                $message = trim($fieldError);
                break;
            }
        }
        parent::__construct($message);
        $this->errors = $errors;
    }
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);
if (!is_array($data)) {
    $data = [];
}
$action = $data['action'] ?? 'get';
if ($action !== 'get' && !verify_csrf_token((string)($data['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

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
            $pos_branch_id = pos_cart_branch_id();

            if ($is_service) {
                $serviceCustomization = is_array($customization) ? $customization : [];
                $serviceFieldConfigs = function_exists('get_service_field_config')
                    ? get_service_field_config($product_id)
                    : [];
                if (!empty($serviceFieldConfigs)) {
                    printflow_apply_layout_canonical_to_customization($serviceCustomization, $serviceFieldConfigs);
                }
                $serviceValidationErrors = pos_cart_validate_service_payload($product_id, $serviceCustomization, $qty);
                if (!empty($serviceValidationErrors)) {
                    throw new PosCartValidationException($serviceValidationErrors);
                }

                $priceCalc = printflow_calculate_service_unit_price($product_id, $serviceCustomization);
                if (!$priceCalc['ok']) {
                    $fallbackUnit = (float)($serviceCustomization['calculated_unit_price'] ?? $price ?? 0);
                    if ($fallbackUnit > 0) {
                        $priceCalc = [
                            'ok' => true,
                            'unit_price' => round($fallbackUnit, 2),
                            'message' => '',
                        ];
                    } else {
                        throw new PosCartValidationException([
                            'price' => (string)($priceCalc['message'] ?? 'Price could not be calculated.'),
                        ]);
                    }
                }
                $price = (float)$priceCalc['unit_price'];
                $preparedCustomization = $serviceCustomization;
                $preparedCustomization['calculated_unit_price'] = number_format($price, 2, '.', '');
                $preparedCustomization['calculated_estimated_price'] = number_format($price * $qty, 2, '.', '');
                $customization = $preparedCustomization;
                $custom_json = json_encode($customization);
            }

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
                $preparedCustomization = is_array($customization) ? $customization : [];
            } elseif (!isset($preparedCustomization)) {
                $preparedCustomization = is_array($customization) ? $customization : [];
            }
            if (!$is_service) {
                if (empty($product)) {
                    throw new Exception('Product not found.');
                }
                $preparedOptionStock = printflow_product_option_stock_prepare_cart_customization(
                    $product_id,
                    $pos_branch_id,
                    $preparedCustomization,
                    $qty,
                    (string)$name
                );
                if (!$preparedOptionStock['ok']) {
                    throw new Exception((string)($preparedOptionStock['message'] ?? 'Please select a valid stock option for this product.'));
                }
                $preparedCustomization = (array)($preparedOptionStock['customization'] ?? []);
                $customization = $preparedCustomization;
                $custom_json = !empty($preparedCustomization) ? json_encode($preparedCustomization) : null;
                $stock = pos_cart_effective_product_stock($product_id, $pos_branch_id);
                if ($stock <= 0) {
                    throw new Exception('Out of stock.');
                }
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
                    if (!$existingIsService) {
                        $nextQty = (int)$item['qty'] + $qty;
                        $mergeCustomization = is_array($item['customization']) ? $item['customization'] : $preparedCustomization;
                        $mergeOptionStock = printflow_product_option_stock_prepare_cart_customization(
                            $product_id,
                            $pos_branch_id,
                            $mergeCustomization,
                            $nextQty,
                            (string)$name
                        );
                        if (!$mergeOptionStock['ok']) {
                            throw new Exception((string)($mergeOptionStock['message'] ?? 'Cannot add more. Insufficient stock.'));
                        }
                        $item['customization'] = (array)($mergeOptionStock['customization'] ?? $mergeCustomization);
                        if ($stock !== null && $nextQty > $stock && !printflow_product_option_stock_has_rows($product_id, $pos_branch_id)) {
                            throw new Exception('Cannot add more. Insufficient stock.');
                        }
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
                if (!$is_service && $stock !== null && $qty > $stock && !printflow_product_option_stock_has_rows($product_id, $pos_branch_id)) {
                    throw new Exception('Insufficient stock.');
                }
                
                $cartSeed = [
                    'product_id' => $product_id,
                    'name' => (string)$name,
                    'customization' => $preparedCustomization ?? (is_array($customization) ? $customization : []),
                    'is_service' => $is_service,
                ];
                $_SESSION['pos_cart'][] = [
                    'product_id' => $product_id,
                    'name' => $name,
                    'price' => $price,
                    'qty' => $qty,
                    'stock' => $stock,
                    'customization' => $preparedCustomization,
                    'is_service' => $is_service,
                    'price_set' => pos_cart_resolve_price_set_on_add($is_service, $data, $cartSeed),
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
                    $pos_branch_id = pos_cart_branch_id();
                    $item['stock'] = pos_cart_effective_product_stock((int)$item['product_id'], $pos_branch_id);
                    $itemCustomization = is_array($item['customization']) ? $item['customization'] : [];
                    $updateOptionStock = printflow_product_option_stock_prepare_cart_customization(
                        (int)$item['product_id'],
                        $pos_branch_id,
                        $itemCustomization,
                        $qty,
                        (string)($item['name'] ?? '')
                    );
                    if (!$updateOptionStock['ok']) {
                        throw new Exception((string)($updateOptionStock['message'] ?? 'Insufficient stock for selected option.'));
                    }
                    $item['customization'] = (array)($updateOptionStock['customization'] ?? $itemCustomization);
                } else {
                    $item['stock'] = null;
                }
                if (
                    !$isServiceItem
                    && $item['stock'] !== null
                    && $qty > $item['stock']
                    && !printflow_product_option_stock_has_rows((int)$item['product_id'], $pos_branch_id)
                ) {
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
            if (!empty($data['customization']) && is_array($data['customization'])) {
                $existing = is_array($_SESSION['pos_cart'][$index]['customization'] ?? null)
                    ? $_SESSION['pos_cart'][$index]['customization']
                    : [];
                $_SESSION['pos_cart'][$index]['customization'] = array_merge($existing, $data['customization']);
            }
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
                $draftOrderIds = pos_cart_item_draft_order_ids((array)$_SESSION['pos_cart'][$index]);
                array_splice($_SESSION['pos_cart'], $index, 1);
                pos_void_unfinalized_drafts($draftOrderIds);
            }
            break;

        case 'clear':
            $draftOrderIds = [];
            foreach ($_SESSION['pos_cart'] as $cartItem) {
                $draftOrderIds = array_merge($draftOrderIds, pos_cart_item_draft_order_ids((array)$cartItem));
            }
            if (isset($_SESSION['pos_pending_orders']) && is_array($_SESSION['pos_pending_orders'])) {
                foreach ($_SESSION['pos_pending_orders'] as $pendingOrderId) {
                    $draftOrderIds[] = (int)$pendingOrderId;
                }
            }
            $_SESSION['pos_cart'] = [];
            pos_void_unfinalized_drafts($draftOrderIds);
            break;

        case 'void_draft':
            $orderId = (int)($data['order_id'] ?? 0);
            $voidResult = pos_void_unfinalized_draft($orderId);
            if (!$voidResult['success']) {
                throw new Exception($voidResult['message'] ?? 'Unable to void draft order.');
            }
            break;

        case 'get':
        default:
            // Just return the cart
            break;
    }

    // Auto-remove any cart items whose linked customization has been completed.
    // This handles the case where staff marks a job Completed from the Customizations
    // page directly (without using the POS set-price flow), so stale items are cleaned up.
    $completedCustomizationIds = [];
    foreach ($_SESSION['pos_cart'] as $cartItem) {
        $custId = (int)($cartItem['pending_customization_id'] ?? 0);
        if ($custId > 0) {
            $completedCustomizationIds[] = $custId;
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $doneIds = [];
    if (!empty($completedCustomizationIds)) {
        $safeIds = array_values(array_unique(array_filter(
            array_map('intval', $completedCustomizationIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!empty($safeIds)) {
            $inStr = implode(',', $safeIds);
            $doneRows = db_query(
                "SELECT id FROM customizations
                 WHERE id IN ({$inStr})
                   AND UPPER(TRIM(COALESCE(status, ''))) IN ('COMPLETED', 'CANCELLED', 'CLOSED')"
            ) ?: [];
            if (!empty($doneRows)) {
                $doneIds = array_flip(array_column($doneRows, 'id'));
            }
        }
    }

    // Briefly reopen the session only to apply cleanup and snapshot the cart.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        SessionManager::start();
    }
    if (!empty($doneIds)) {
        $_SESSION['pos_cart'] = array_values(array_filter(
            $_SESSION['pos_cart'],
            function ($item) use ($doneIds) {
                $cid = (int)($item['pending_customization_id'] ?? 0);
                return $cid <= 0 || !isset($doneIds[$cid]);
            }
        ));
    }

    // Release the session before per-item stock lookups so checkout cannot block
    // behind cart refreshes when the cart grows to several lines.
    $pos_branch_id = pos_cart_branch_id();
    $cartResponse = array_values($_SESSION['pos_cart'] ?? []);
    session_write_close();
    foreach ($cartResponse as &$cartItem) {
        $isServiceItem = pos_cart_item_is_service((array)$cartItem);
        $cartItem['is_service'] = $isServiceItem;
        if ($isServiceItem) {
            $cartItem['stock'] = null;
            continue;
        }
        $cartItem['stock'] = pos_cart_effective_product_stock((int)($cartItem['product_id'] ?? 0), $pos_branch_id);
    }
    unset($cartItem);

    echo json_encode([
        'success' => true,
        'cart' => $cartResponse
    ]);

} catch (PosCartValidationException $e) {
    session_write_close();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'errors' => $e->errors,
        'cart' => array_values($_SESSION['pos_cart'] ?? [])
    ]);
} catch (Exception $e) {
    session_write_close();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'cart' => array_values($_SESSION['pos_cart'] ?? [])
    ]);
}
