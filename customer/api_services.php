<?php
/**
 * Customer service catalog API.
 *
 * Pricing metadata is explicit so clients never infer custom pricing from a
 * zero/null value or reuse an order-specific staff quotation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_service_catalog.php';

require_role('Customer');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $rows = db_query(
        "SELECT service_id, name, category
         FROM services
         WHERE status = 'Activated'
           AND COALESCE(visible_to_customer, 1) = 1
         ORDER BY name ASC"
    ) ?: [];
    $pricingByService = printflow_catalog_pricing_metadata_map(array_column($rows, 'service_id'));
    $basePath = pf_app_base_path();

    $services = [];
    foreach ($rows as $row) {
        $serviceId = (int)($row['service_id'] ?? 0);
        if ($serviceId < 1) continue;
        $pricing = $pricingByService[$serviceId] ?? printflow_catalog_pricing_metadata_from_fields([]);
        $services[] = [
            'service_id' => $serviceId,
            'name' => (string)($row['name'] ?? ''),
            'category' => (string)($row['category'] ?? ''),
            'order_url' => $basePath . '/customer/order_service_dynamic.php?service_id=' . $serviceId,
            'pricing_type' => $pricing['pricing_type'],
            'display_price' => $pricing['display_price'],
            'minimum_price' => $pricing['minimum_price'],
            'price_label' => $pricing['price_label'],
        ];
    }

    echo json_encode(
        ['success' => true, 'services' => $services],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $error) {
    error_log('[customer_service_catalog] ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'The service catalog is temporarily unavailable.',
    ]);
}
