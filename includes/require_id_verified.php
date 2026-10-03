<?php
/**
 * Customer ID verification gate for customizable orders.
 */

require_once __DIR__ . '/customer_id_verification.php';

function printflow_custom_order_id_status(array $customer): array
{
    $display = pf_customer_id_profile_status_display($customer);
    $status = (string)($display['status'] ?? 'None');
    $label = (string)($display['label'] ?? 'Not Submitted');

    if ($status === 'None') {
        $label = 'Not Submitted';
    }

    return [
        'status' => $status,
        'label' => $label,
        'verified' => $status === 'Verified',
    ];
}

function printflow_cart_customization_payload($custom): array
{
    if (is_array($custom)) {
        return $custom;
    }
    if (is_string($custom) && trim($custom) !== '') {
        if (function_exists('printflow_decode_modal_customization_payload')) {
            $decoded = printflow_decode_modal_customization_payload($custom);
            return is_array($decoded) ? $decoded : [];
        }
        $decoded = json_decode($custom, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function printflow_cart_item_requires_id_verification(array $item): bool
{
    $custom = printflow_cart_customization_payload($item['customization'] ?? []);
    $sourcePage = strtolower(trim((string)($item['source_page'] ?? ($custom['source_page'] ?? ''))));
    $itemType = strtolower(trim((string)($item['type'] ?? ($custom['type'] ?? ''))));
    $cartKey = strtolower(trim((string)($item['_cart_key'] ?? ($custom['_cart_key'] ?? ''))));

    if ($sourcePage === 'services' || $itemType === 'service' || str_starts_with($cartKey, 'service_')) {
        return true;
    }

    if ($sourcePage === 'dynamic_form') {
        return true;
    }

    foreach (['service_id', 'service_type', 'design_upload_path', 'design_link', '_uploaded_files'] as $key) {
        if (!empty($custom[$key]) || !empty($item[$key])) {
            return true;
        }
    }

    $productId = (int)($item['product_id'] ?? 0);
    if ($productId > 0 && function_exists('db_table_has_column') && db_table_has_column('products', 'product_type')) {
        $rows = db_query('SELECT product_type FROM products WHERE product_id = ? LIMIT 1', 'i', [$productId]);
        $productType = strtolower(trim((string)($rows[0]['product_type'] ?? '')));
        if ($productType !== '' && $productType !== 'fixed' && preg_match('/custom|service|made|dynamic/', $productType)) {
            return true;
        }
    }

    return false;
}

function printflow_cart_requires_id_verification(array $cartItems): bool
{
    foreach ($cartItems as $item) {
        if (is_array($item) && printflow_cart_item_requires_id_verification($item)) {
            return true;
        }
    }
    return false;
}

function printflow_custom_order_id_message(array $customer): string
{
    $state = printflow_custom_order_id_status($customer);
    return match ($state['status']) {
        'Pending' => 'Your ID is currently under review. PrintFlow requires an approved ID before customizable orders can be finalized.',
        'Rejected' => 'Your previous ID submission was rejected. Please submit a new valid government-issued ID before finalizing a customizable order.',
        'Verified' => 'Your ID is verified.',
        default => 'PrintFlow requires ID verification before customizable orders can be finalized. Fixed-product orders do not require ID verification.',
    };
}

function printflow_redirect_customer_to_id_verification(array $customer, ?string $return_to = null): void
{
    if ($return_to === null && function_exists('printflow_profile_completion_return_url')) {
        $return_to = printflow_profile_completion_return_url();
    }
    if ($return_to !== null && $return_to !== '') {
        $_SESSION['profile_return_after_complete'] = $return_to;
    }
    $_SESSION['profile_completion_flash'] = printflow_custom_order_id_message($customer);

    $target = rtrim(AUTH_REDIRECT_BASE, '/') . '/customer/profile.php?complete_profile=1';
    if ($return_to !== null && $return_to !== '') {
        $target .= '&return=' . rawurlencode($return_to);
    }
    $target .= '#section-security';

    header('Location: ' . $target, true, 302);
    exit;
}