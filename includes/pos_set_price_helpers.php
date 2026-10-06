<?php

/**
 * POS walk-in services that need Customizations Set Price (material + final price)
 * even when an estimated unit price is shown in the cart.
 */

/**
 * Services that show an estimate in POS but still need staff material/final pricing in Customizations.
 */
function printflow_service_requires_staff_pricing_flow(int $serviceId, ?string $name = null, ?string $category = null): bool
{
    if ($serviceId <= 0) {
        return false;
    }
    if (printflow_service_requires_pos_set_price($serviceId, $name, $category)) {
        return true;
    }

    if (!function_exists('printflow_catalog_pricing_metadata_map')) {
        require_once __DIR__ . '/customer_service_catalog.php';
    }
    $meta = printflow_catalog_pricing_metadata_map([$serviceId]);
    $pricing = $meta[$serviceId] ?? null;
    if (is_array($pricing) && (string)($pricing['pricing_type'] ?? '') === 'custom') {
        return true;
    }

    return false;
}

function printflow_service_requires_pos_set_price(int $serviceId, ?string $name = null, ?string $category = null): bool
{
    $blob = strtolower(trim((string)$name . ' ' . (string)$category));
    if ($blob !== '') {
        if (str_contains($blob, 'sintraboard') || str_contains($blob, 'standee') || str_contains($blob, 'sintra board')) {
            return true;
        }
    }

    if ($serviceId > 0 && $blob === '') {
        $rows = db_query(
            'SELECT name, category FROM services WHERE service_id = ? LIMIT 1',
            'i',
            [$serviceId]
        ) ?: [];
        if (!empty($rows)) {
            $blob = strtolower(trim((string)($rows[0]['name'] ?? '') . ' ' . (string)($rows[0]['category'] ?? '')));
            if (str_contains($blob, 'sintraboard') || str_contains($blob, 'standee') || str_contains($blob, 'sintra board')) {
                return true;
            }
        }
    }

    return false;
}

function pos_cart_item_customization_array(array $item): array
{
    $custom = $item['customization'] ?? null;
    return is_array($custom) ? $custom : [];
}

function pos_cart_item_requires_pos_set_price(array $item): bool
{
    if (empty($item['is_service']) && !function_exists('pos_cart_item_is_service')) {
        return false;
    }

    $isService = !empty($item['is_service']);
    if (!$isService && function_exists('pos_cart_item_is_service')) {
        $isService = pos_cart_item_is_service($item);
    }
    return $isService;
}

function pos_cart_item_has_final_material(array $item): bool
{
    $custom = pos_cart_item_customization_array($item);
    foreach (['Material Selection', 'Material Brand', 'Material', 'temp_plate_material', 'material_type', 'material_name'] as $key) {
        if (trim((string)($custom[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function pos_cart_item_needs_pos_set_price_completion(array $item): bool
{
    if (!pos_cart_item_requires_pos_set_price($item)) {
        return false;
    }

    return empty($item['price_set']);
}

function printflow_pos_customization_estimated_total(array $customization, int $qty = 1): float
{
    $qty = max(1, $qty);
    $total = (float)($customization['calculated_estimated_price'] ?? 0);
    if ($total > 0) {
        return round($total, 2);
    }

    $unit = (float)($customization['calculated_unit_price'] ?? 0);
    if ($unit > 0) {
        return round($unit * $qty, 2);
    }

    return 0.0;
}

function pos_cart_resolve_price_set_on_add(bool $isService, array $data, array $itemSeed): bool
{
    if (!$isService) {
        return !empty($data['price_set']);
    }

    if (array_key_exists('price_set', $data)) {
        return (bool)$data['price_set'];
    }

    return false;
}
