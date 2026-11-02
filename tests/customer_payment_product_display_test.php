<?php

$root = dirname(__DIR__);
define('BASE_URL', '');

function printflow_decode_modal_customization_payload($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function printflow_notification_local_media_exists(string $urlPath): bool {
    $path = parse_url($urlPath, PHP_URL_PATH);
    return is_string($path) && is_file(dirname(__DIR__) . $path);
}

require_once $root . '/includes/order_ui_helper.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$productLine = [
    'item_type' => 'product',
    'product_id' => 49,
    'product_image' => '',
    'customization_data' => json_encode([
        'source_page' => 'products',
        'service_type' => 'T-Shirt Printing',
        'Size' => 'XS',
    ]),
];
$serviceLine = [
    'item_type' => 'service',
    'service_id' => 64,
    'product_image' => '',
    'customization_data' => json_encode(['service_type' => 'Poster Printing']),
];
$legacyCatalogLine = [
    'item_type' => 'service',
    'service_id' => 64,
    'product_id' => 49,
    'type' => 'Product',
    'product_image' => '',
    'customization_data' => json_encode([
        'source_page' => 'products',
        'service_type' => 'T-Shirt Printing',
        'Size' => 'XS',
    ]),
];
$explicitServiceLine = [
    'item_type' => 'product',
    'product_id' => 49,
    'customization_data' => json_encode([
        'source_page' => 'services',
        'service_type' => 'Poster Printing',
    ]),
];
$ambiguousProductLine = [
    'item_type' => 'product',
    'product_id' => 49,
    'customization_data' => json_encode(['service_type' => 'Poster Printing']),
];

$assert(pf_order_ui_is_product_item($productLine), 'Persisted product item type wins over a legacy service_type customization label.');
$assert(!pf_order_ui_is_product_item($serviceLine), 'Persisted service item type remains classified as a service.');
$assert(pf_order_ui_is_product_item($legacyCatalogLine), 'Persisted Products-page origin wins over legacy service type tagging and service_type labels.');
$assert(!pf_order_ui_is_product_item($explicitServiceLine), 'An explicit Services-page origin remains classified as a service.');
$assert(!pf_order_ui_is_product_item($ambiguousProductLine), 'A product type without source metadata does not override conflicting service customization metadata.');

$GLOBALS['pf_test_order_items'] = [];
function db_table_has_column(string $table, string $column): bool {
    return $table === 'order_items' && in_array($column, ['item_type', 'service_id'], true);
}
function db_query(string $sql, string $types = '', array $params = []): array {
    if (stripos($sql, 'FROM order_items') !== false) {
        return $GLOBALS['pf_test_order_items'];
    }
    return [];
}
$extractFunction = static function (string $source, string $name): string {
    $tokens = token_get_all("<?php\n" . $source);
    $functionIndex = null;
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) {
                $functionIndex = $i;
            }
            break;
        }
        if ($functionIndex !== null) break;
    }
    if ($functionIndex === null) return '';
    $depth = 0;
    $code = '';
    $started = false;
    for ($i = $functionIndex, $count = count($tokens); $i < $count; $i++) {
        $tokenText = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        $code .= $tokenText;
        if ($tokenText === '{') {
            $depth++;
            $started = true;
        } elseif ($tokenText === '}' && $started && --$depth === 0) {
            return $code;
        }
    }
    return '';
};
$functionsSource = (string)file_get_contents($root . '/includes/functions.php');
$identityHelperCode = $extractFunction($functionsSource, 'printflow_order_line_is_verified_catalog_product_source');
$readyMadeClassifierCode = $extractFunction($functionsSource, 'printflow_is_ready_made_product_order');
$assert($identityHelperCode !== '' && $readyMadeClassifierCode !== '', 'Actual runtime classification functions can be isolated for tests.');
if ($identityHelperCode !== '' && $readyMadeClassifierCode !== '') {
    eval($identityHelperCode);
    eval($readyMadeClassifierCode);
    $catalogOrder = ['order_id' => 28745, 'order_type' => 'product', 'reference_id' => 49];
    $GLOBALS['pf_test_order_items'] = [[
        'product_id' => 49,
        'item_type' => 'service',
        'service_id' => 64,
        'customization_data' => json_encode(['source_page' => 'products', 'service_type' => 'T-Shirt Printing']),
    ]];
    $assert(printflow_order_line_is_verified_catalog_product_source($catalogOrder, $GLOBALS['pf_test_order_items'][0]), 'Explicit Products-page source recovers a legacy service-tagged line.');
    $assert(printflow_is_ready_made_product_order($catalogOrder), 'A ready-made product order remains ready-made despite legacy service tagging when the saved line source is Products.');
    $GLOBALS['pf_test_order_items'][0]['customization_data'] = json_encode(['source_page' => 'services', 'service_type' => 'T-Shirt Printing']);
    $assert(!printflow_order_line_is_verified_catalog_product_source($catalogOrder, $GLOBALS['pf_test_order_items'][0]), 'An explicit Services-page source is never recovered as a catalog product.');
    $assert(!printflow_is_ready_made_product_order($catalogOrder), 'A Services-page line remains in the service workflow.');
    $GLOBALS['pf_test_order_items'][0] = [
        'product_id' => 49,
        'item_type' => 'product',
        'service_id' => null,
        'customization_data' => json_encode(['service_type' => 'T-Shirt Printing']),
    ];
    $assert(!printflow_is_ready_made_product_order($catalogOrder), 'A product type alone does not suppress conflicting service markers when the saved source is ambiguous.');
}

$paymentSource = (string)file_get_contents($root . '/customer/payment.php');
$persistenceSource = (string)file_get_contents($root . '/includes/order_items_persistence.php');
$orderItemsApiSource = (string)file_get_contents($root . '/customer/get_order_items.php');
$expectedFallback = '/public/assets/images/services/catalog-placeholder.svg';
$assert(str_contains($paymentSource, '$item[\'product_image\'] = $catalogFallback;') && str_contains($paymentSource, $expectedFallback), 'The payment page uses a valid product catalog fallback rather than the missing services/default.png.');
$assert(str_contains($paymentSource, "COALESCE(NULLIF(p.photo_path, ''), NULLIF(p.product_image, '')) AS product_image"), 'A blank photo_path does not hide a valid products.product_image.');
$assert(str_contains($paymentSource, '$can_download_payment_receipt = $is_paid_ui && $is_ready_made_product && $has_persisted_receipt_item;'), 'The receipt action requires paid state, ready-made classification, and a saved order item.');
$assert(str_contains($functionsSource, "in_array(\$sourcePage, ['products', 'product'], true)"), 'Legacy catalog recovery requires an explicit persisted Products-page source.');
$assert(str_contains($persistenceSource, "in_array(\$sourcePage, ['products', 'product'], true)"), 'New order item tagging respects the persisted Products-page origin.');
$assert(str_contains($orderItemsApiSource, '$first_item_verified_catalog_product') && str_contains($orderItemsApiSource, '$probeVerifiedCatalogProduct'), 'Order details and receipt data classify each catalog line using the same persisted source.');
$assert(str_contains($orderItemsApiSource, "if (\$is_ready_made_product && \$payment_received && \$paid_waiting_label !== '')") && str_contains($orderItemsApiSource, "\$paid_waiting_label = 'Paid';"), 'Paid ready-made product details show Paid rather than an awaiting-production label.');
$assert(is_file($root . $expectedFallback), 'The configured product fallback URL maps to an existing local image asset.');

if ($failures !== []) {
    fwrite(STDERR, "Customer payment product display test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Customer payment product display test passed.\n";
