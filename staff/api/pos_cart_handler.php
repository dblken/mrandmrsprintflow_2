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
require_once __DIR__ . '/../../includes/JobOrderService.php';
require_once __DIR__ . '/../../includes/pos_quantity_validation.php';
require_once __DIR__ . '/../../includes/pos_service_stock_validation.php';

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

function pos_cart_parse_positive_quantity($value): int
{
    try {
        return printflow_pos_parse_positive_quantity($value);
    } catch (InvalidArgumentException $error) {
        throw new Exception($error->getMessage());
    }
}

/** Validate aggregate product demand so separate cart lines cannot exceed stock. */
function pos_cart_validate_product_demand(array $cart, int $branchId): void
{
    $demands = [];
    foreach ($cart as $item) {
        if (!is_array($item) || pos_cart_item_is_service($item)) {
            continue;
        }
        $productId = (int)($item['product_id'] ?? 0);
        $qty = pos_cart_parse_positive_quantity($item['qty'] ?? null);
        if ($productId <= 0) {
            throw new Exception('A cart product is invalid.');
        }
        $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        $usesOptions = printflow_product_option_stock_has_rows($productId, $branchId);
        $selection = $usesOptions ? printflow_product_option_stock_find_selected_value($productId, $customization) : null;
        if ($usesOptions && $selection === null) {
            throw new Exception('A valid stock option must be selected for ' . (string)($item['name'] ?? 'this product') . '.');
        }
        $key = $productId . ':' . ($usesOptions ? strtolower((string)$selection['option_value']) : 'base');
        if (!isset($demands[$key])) {
            $demands[$key] = [
                'product_id' => $productId,
                'qty' => 0,
                'uses_options' => $usesOptions,
                'customization' => $customization,
                'name' => (string)($item['name'] ?? 'Product'),
            ];
        }
        $demands[$key]['qty'] += $qty;
    }

    foreach ($demands as $demand) {
        if ($demand['uses_options']) {
            $stock = printflow_product_option_stock_validate(
                (int)$demand['product_id'],
                $branchId,
                (array)$demand['customization'],
                (int)$demand['qty']
            );
            if (empty($stock['ok'])) {
                $available = (int)($stock['available'] ?? 0);
                throw new Exception('Only ' . $available . ' items are available for ' . $demand['name'] . '.');
            }
            continue;
        }
        $available = pos_cart_effective_product_stock((int)$demand['product_id'], $branchId);
        if ((int)$demand['qty'] > $available) {
            throw new Exception('Only ' . $available . ' items are available for ' . $demand['name'] . '.');
        }
    }
}

function pos_cart_validate_assigned_service_stock(array $item): void
{
    if (!pos_cart_item_is_service($item)) {
        return;
    }
    $productId = (int)($item['product_id'] ?? 0);
    $orderId = (int)($item['pending_order_id'] ?? 0);
    if ($orderId <= 0 && $productId > 0) {
        $orderId = (int)($_SESSION['pos_pending_orders'][$productId] ?? 0);
    }
    $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    $orderItemId = (int)($item['pending_order_item_id'] ?? ($customization['pending_order_item_id'] ?? 0));
    if ($orderId <= 0) {
        return;
    }

    $jobSql = 'SELECT id FROM job_orders WHERE order_id = ?';
    $jobTypes = 'i';
    $jobParams = [$orderId];
    if ($orderItemId > 0 && db_table_has_column('job_orders', 'order_item_id')) {
        $jobSql .= ' AND order_item_id = ?';
        $jobTypes .= 'i';
        $jobParams[] = $orderItemId;
    }
    $jobSql .= ' ORDER BY id ASC LIMIT 1';
    $jobs = db_query($jobSql, $jobTypes, $jobParams) ?: [];
    $jobId = (int)($jobs[0]['id'] ?? 0);
    if ($jobId <= 0) {
        return;
    }
    $assignments = JobOrderService::getScopedMaterialsPublic($jobId, false, false);
    if ($assignments === []) {
        return;
    }
    $qty = pos_cart_parse_positive_quantity($item['qty'] ?? null);
    $issues = JobOrderService::validatePendingMaterialStockPublic($jobId, $qty);
    if ($issues !== []) {
        $issue = $issues[0];
        $uom = trim((string)($issue['uom'] ?? ''));
        $required = rtrim(rtrim(number_format((float)$issue['required'], 3, '.', ''), '0'), '.');
        $available = rtrim(rtrim(number_format((float)$issue['available'], 3, '.', ''), '0'), '.');
        throw new Exception((string)$issue['name'] . ' has only ' . $available . ($uom !== '' ? ' ' . $uom : '') . ' available; ' . $required . ($uom !== '' ? ' ' . $uom : '') . ' required.');
    }
}

function pos_cart_service_stock_message(array $validation): string
{
    $issue = $validation['issues'][0] ?? null;
    if (!is_array($issue)) return 'Service material stock is unavailable for validation.';
    $format = static fn($value): string => rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    $uom = trim((string)($issue['uom'] ?? ''));
    return 'Insufficient ' . (string)($issue['name'] ?? 'material') . ' stock. Available: '
        . $format($issue['available'] ?? 0) . ($uom !== '' ? ' ' . $uom : '')
        . '. Required: ' . $format($issue['required'] ?? 0) . ($uom !== '' ? ' ' . $uom : '') . '.';
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

function pos_cart_is_needed_date_field(string $key, string $label): bool
{
    $needle = strtolower($key . ' ' . $label);
    return strpos($needle, 'needed_date') !== false || strpos($needle, 'needed date') !== false;
}

function pos_cart_date_value_not_before_today(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return true;
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
        return false;
    }
    return $matches[1] >= date('Y-m-d');
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
                try { printflow_pos_parse_positive_quantity($value); }
                catch (InvalidArgumentException $error) { $errors[$key] = $error->getMessage(); }
            } elseif ($value === '') {
                $errors[$key] = pos_cart_required_message($key, $label, $type);
            } elseif ($type === 'date' && pos_cart_is_needed_date_field($key, $label) && !pos_cart_date_value_not_before_today($value)) {
                $errors[$key] = 'Needed date cannot be in the past.';
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
        } elseif ($type === 'date' && $value !== '' && pos_cart_is_needed_date_field((string)$fieldKey, $label) && !pos_cart_date_value_not_before_today($value)) {
            $errors[(string)$fieldKey] = 'Needed date cannot be in the past.';
        } elseif ($type === 'quantity') {
            try { printflow_pos_parse_positive_quantity($value !== '' ? $value : $qty); }
            catch (InvalidArgumentException $error) { $errors[(string)$fieldKey] = $error->getMessage(); }
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
    $cartBeforeAction = array_values($_SESSION['pos_cart'] ?? []);
    switch ($action) {
        case 'add':
            if (empty($data['product_id'])) {
                throw new Exception('Product ID is required.');
            }
            $product_id = (int)$data['product_id'];
            $qty = pos_cart_parse_positive_quantity($data['qty'] ?? 1);
            
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
                    } elseif (($priceCalc['reason'] ?? '') === 'unconfigured') {
                        $serviceCustomization['price_unconfigured'] = '1';
                        $priceCalc = [
                            'ok' => true,
                            'unit_price' => 0.0,
                            'message' => '',
                        ];
                    } else {
                        throw new PosCartValidationException([
                            'price' => (string)($priceCalc['message'] ?? 'Price could not be calculated.'),
                        ]);
                    }
                }
                $price = (float)$priceCalc['unit_price'];
                service_order_persist_validated_design_links($serviceCustomization, $serviceFieldConfigs);
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
                    throw new Exception('Only 0 items are available for ' . (string)$name . '.');
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

        case 'validate_service_stock':
            $serviceId = (int)($data['service_id'] ?? $data['product_id'] ?? 0);
            $qty = pos_cart_parse_positive_quantity($data['qty'] ?? null);
            $customization = is_array($data['customization'] ?? null) ? $data['customization'] : [];
            $candidate = [
                'product_id' => $serviceId,
                'qty' => $qty,
                'customization' => $customization,
                'is_service' => true,
            ];
            $validation = printflow_pos_validate_service_stock(
                array_merge(array_values($_SESSION['pos_cart'] ?? []), [$candidate]),
                pos_cart_branch_id()
            );
            $serviceStockValidation = $validation;
            break;

        case 'validate_service_materials':
            $index = isset($data['cart_index']) ? (int)$data['cart_index'] : -1;
            $pendingOrderId = (int)($data['pending_order_id'] ?? 0);
            $materials = $data['materials'] ?? null;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index]) || $pendingOrderId <= 0 || !is_array($materials)) {
                throw new Exception('The POS Set Price material check could not be matched to the cart item. Reopen Set Price and try again.');
            }
            $cartItem = (array)$_SESSION['pos_cart'][$index];
            $productId = (int)($cartItem['product_id'] ?? 0);
            $sessionOrderId = (int)($cartItem['pending_order_id'] ?? ($_SESSION['pos_pending_orders'][$productId] ?? 0));
            if (!pos_cart_item_is_service($cartItem) || $sessionOrderId !== $pendingOrderId) {
                throw new Exception('The POS Set Price material check could not be matched to the current service. Reopen Set Price and try again.');
            }
            $cartItem['pos_material_assignments'] = $materials;
            $candidateCart = array_values($_SESSION['pos_cart']);
            $candidateCart[$index] = $cartItem;
            $serviceStockValidation = printflow_pos_validate_service_stock($candidateCart, pos_cart_branch_id());
            if (empty($serviceStockValidation['ok'])) {
                throw new Exception(pos_cart_service_stock_message($serviceStockValidation));
            }
            break;

        case 'update':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index])) {
                throw new Exception('Invalid cart item.');
            }
            $qty = pos_cart_parse_positive_quantity($data['qty'] ?? null);
            {
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
                $item['qty'] = $qty;
            }
            break;

        case 'update_price':
            $index = isset($data['index']) ? (int)$data['index'] : -1;
            if ($index < 0 || !isset($_SESSION['pos_cart'][$index])) {
                throw new Exception('Invalid cart item.');
            }
            try {
                $price = printflow_pos_parse_final_price($data['price'] ?? null);
            } catch (InvalidArgumentException $priceError) {
                throw new Exception($priceError->getMessage());
            }
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
            $pending_order_item_id = (int)($data['pending_order_item_id'] ?? 0);
            if ($pending_order_item_id > 0) {
                $_SESSION['pos_cart'][$index]['pending_order_item_id'] = $pending_order_item_id;
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

        case 'abandon_qrph':
            $checkoutToken = strtolower(trim((string)($data['checkout_token'] ?? '')));
            if ($checkoutToken !== '' && !preg_match('/^[a-f0-9]{32,64}$/', $checkoutToken)) {
                throw new Exception('Invalid checkout token.');
            }
            $explicitOrderId = (int)($data['order_id'] ?? 0);
            pos_void_session_pending_paymongo_checkouts(
                $checkoutToken !== '' ? $checkoutToken : null,
                $explicitOrderId
            );
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
            pos_void_session_pending_paymongo_checkouts();
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

    if (in_array($action, ['add', 'update', 'update_service_link', 'validate_stock'], true)) {
        pos_cart_validate_product_demand($_SESSION['pos_cart'], pos_cart_branch_id());
        $serviceStockValidation = printflow_pos_validate_service_stock(
            array_values($_SESSION['pos_cart']),
            pos_cart_branch_id()
        );
        if (empty($serviceStockValidation['ok'])) {
            throw new Exception(pos_cart_service_stock_message($serviceStockValidation));
        }
        foreach ($_SESSION['pos_cart'] as $cartItem) {
            pos_cart_validate_assigned_service_stock((array)$cartItem);
        }
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
        $productId = (int)($cartItem['product_id'] ?? 0);
        $customization = is_array($cartItem['customization'] ?? null) ? $cartItem['customization'] : [];
        $selectedStock = printflow_product_option_stock_validate($productId, $pos_branch_id, $customization, 1);
        $cartItem['stock'] = !empty($selectedStock['uses_option_stock'])
            ? (int)($selectedStock['available'] ?? 0)
            : pos_cart_effective_product_stock($productId, $pos_branch_id);
    }
    unset($cartItem);

    echo json_encode([
        'success' => true,
        'cart' => $cartResponse,
        'stock_validation' => $serviceStockValidation ?? null,
    ]);

} catch (PosCartValidationException $e) {
    if (isset($cartBeforeAction) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pos_cart'] = $cartBeforeAction;
    }
    session_write_close();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'errors' => $e->errors,
        'cart' => array_values($_SESSION['pos_cart'] ?? [])
    ]);
} catch (Exception $e) {
    if (isset($cartBeforeAction) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pos_cart'] = $cartBeforeAction;
    }
    session_write_close();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'cart' => array_values($_SESSION['pos_cart'] ?? [])
    ]);
}
