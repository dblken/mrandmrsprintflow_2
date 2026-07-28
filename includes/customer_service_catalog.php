<?php
/**
 * Default copy shown in the customer “service detail” modal (editable per service in Admin).
 */
function printflow_default_customer_service_modal_text(): string {
    return 'Choose this service to start your customization. You will be able to select specific materials, sizes, and upload your layout on the next page to complete your order.';
}

/**
 * Default customer service tiles (used for DB seed + image/link fallback by service name).
 */
function printflow_default_customer_service_catalog(): array {
    return [
        ['name' => 'Tarpaulin', 'category' => 'Signage', 'img' => BASE_PATH . '/public/images/products/product_42.jpg', 'link' => 'order_tarpaulin.php'],
        ['name' => 'T-Shirt', 'category' => 'Apparel', 'img' => BASE_PATH . '/public/images/products/product_31.jpg', 'link' => 'order_tshirt.php'],
        ['name' => 'Stickers', 'category' => 'Decals', 'img' => BASE_PATH . '/public/images/products/product_21.jpg', 'link' => 'order_stickers.php'],
        ['name' => 'Glass/Wall', 'category' => 'Decals', 'img' => BASE_PATH . '/public/images/products/Glass Stickers  Wall  Frosted Stickers.png', 'link' => 'order_glass_stickers.php'],
        ['name' => 'Transparent', 'category' => 'Decals', 'img' => BASE_PATH . '/public/images/products/product_26.jpg', 'link' => 'order_transparent.php'],
        ['name' => 'Reflectorized', 'category' => 'Signage', 'img' => BASE_PATH . '/public/images/products/signage.jpg', 'link' => 'order_reflectorized.php'],
        ['name' => 'Sintraboard Standees', 'category' => 'Signage', 'img' => BASE_PATH . '/public/images/products/standeeflat.jpg', 'link' => 'order_sintraboard.php'],
        ['name' => 'Souvenirs', 'category' => 'Merchandise', 'img' => BASE_PATH . '/public/assets/images/services/default.png', 'link' => 'order_souvenirs.php'],
    ];
}

/**
 * Treat disabled/deleted option JSON as unavailable without depending on a
 * particular Admin UI schema version.
 */
function printflow_catalog_pricing_node_is_enabled(array $node): bool {
    foreach (['enabled', 'is_enabled', 'active', 'is_active'] as $key) {
        if (!array_key_exists($key, $node)) continue;
        $value = filter_var($node[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === false || ($value === null && (string)$node[$key] === '0')) return false;
    }
    foreach (['disabled', 'is_disabled', 'deleted', 'is_deleted'] as $key) {
        if (!array_key_exists($key, $node)) continue;
        $value = filter_var($node[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === true || ($value === null && (string)$node[$key] === '1')) return false;
    }
    if (!empty($node['deleted_at'])) return false;

    $status = strtolower(trim((string)($node['status'] ?? '')));
    return !in_array($status, ['disabled', 'inactive', 'deleted', 'archived'], true);
}

/**
 * Collect positive preset prices from service_field_configs.field_options.
 * A value of 1.00 is the historic service placeholder and is never a valid
 * customer-facing starting price.
 */
function printflow_catalog_collect_option_prices(mixed $options, array &$prices, bool &$hasChoices): void {
    if (!is_array($options)) return;

    foreach ($options as $key => $option) {
        if (is_string($option)) {
            if (trim($option) !== '') $hasChoices = true;
            continue;
        }
        if (is_numeric($option) && !is_int($key)) {
            $hasChoices = true;
            $price = (float)$option;
            if (is_finite($price) && $price > 1.0) $prices[] = round($price, 2);
            continue;
        }
        if (!is_array($option) || !printflow_catalog_pricing_node_is_enabled($option)) continue;

        $optionValue = trim((string)($option['value'] ?? $option['label'] ?? $option['name'] ?? ''));
        if ($optionValue !== '' || array_key_exists('price', $option)) $hasChoices = true;

        if (isset($option['price']) && is_numeric($option['price'])) {
            $price = (float)$option['price'];
            if (is_finite($price) && $price > 1.0) $prices[] = round($price, 2);
        }

        foreach (['options', 'field_options', 'nested_fields', 'children'] as $nestedKey) {
            if (isset($option[$nestedKey])) {
                printflow_catalog_collect_option_prices($option[$nestedKey], $prices, $hasChoices);
            }
        }
    }
}

/**
 * Build the explicit pricing contract used by customer catalog consumers.
 *
 * Catalog pricing comes only from enabled preset choices. services.price,
 * orders.total_amount, job_orders.estimated_total, and staff quotations are
 * deliberately outside this function.
 */
function printflow_catalog_pricing_metadata_from_fields(array $fieldRows): array {
    $prices = [];
    $hasChoices = false;

    foreach ($fieldRows as $field) {
        if (!is_array($field) || !printflow_catalog_pricing_node_is_enabled($field)) continue;
        if (array_key_exists('is_visible', $field) && (int)$field['is_visible'] !== 1) continue;
        if (array_key_exists('visible', $field) && !$field['visible']) continue;

        $options = $field['field_options'] ?? $field['options'] ?? null;
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        printflow_catalog_collect_option_prices($options, $prices, $hasChoices);
    }

    if ($prices !== []) {
        $minimum = min($prices);
        return [
            'pricing_type' => 'options',
            'display_price' => $minimum,
            'minimum_price' => $minimum,
            'price_label' => 'Starts at ₱' . number_format($minimum, 2, '.', ','),
        ];
    }

    return [
        'pricing_type' => 'custom',
        'display_price' => null,
        'minimum_price' => null,
        'price_label' => $hasChoices ? 'Price after review' : 'Custom Pricing',
    ];
}

/**
 * Resolve pricing metadata for a set of catalog service IDs in one query.
 */
function printflow_catalog_pricing_metadata_map(array $serviceIds): array {
    $serviceIds = array_values(array_unique(array_filter(
        array_map('intval', $serviceIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($serviceIds === []) return [];

    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $rows = db_query(
        "SELECT service_id, field_type, field_options, is_visible
         FROM service_field_configs
         WHERE service_id IN ({$placeholders})",
        str_repeat('i', count($serviceIds)),
        $serviceIds
    ) ?: [];

    $fieldsByService = [];
    foreach ($rows as $row) {
        $serviceId = (int)($row['service_id'] ?? 0);
        if ($serviceId > 0) $fieldsByService[$serviceId][] = $row;
    }

    $result = [];
    foreach ($serviceIds as $serviceId) {
        $result[$serviceId] = printflow_catalog_pricing_metadata_from_fields($fieldsByService[$serviceId] ?? []);
    }
    return $result;
}
