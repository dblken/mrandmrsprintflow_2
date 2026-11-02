<?php
/** Shared server-side pricing rules for fixed catalog products. */

if (!function_exists('printflow_product_parse_positive_price')) {
    function printflow_product_parse_positive_price($raw): ?float
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return null;
        }
        $value = trim((string)$raw);
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            return null;
        }
        $price = (float)$value;
        return is_finite($price) && $price > 0 ? round($price, 2) : null;
    }
}

/** Admin products retain the existing maximum while allowing any positive cent value. */
if (!function_exists('printflow_product_validate_admin_price')) {
    function printflow_product_validate_admin_price($raw, string $mode): array
    {
        $text = is_scalar($raw) ? trim((string)$raw) : '';
        if ($mode === 'option' && ($text === '' || strcasecmp($text, 'N/A') === 0)) {
            return ['ok' => true, 'stored' => 0.0, 'message' => ''];
        }
        $price = printflow_product_parse_positive_price($raw);
        if ($price === null || $price > 1000000.0) {
            return ['ok' => false, 'stored' => null, 'message' => 'Enter a valid positive price up to ₱1,000,000.00, or leave it blank for option-based pricing.'];
        }
        return ['ok' => true, 'stored' => $price, 'message' => ''];
    }
}

if (!function_exists('printflow_product_pricing_field_configs')) {
    /** Read existing option configuration without creating or altering schema. */
    function printflow_product_pricing_field_configs(int $productId): array
    {
        if ($productId < 1 || !function_exists('db_table_has_column') || !db_table_has_column('product_field_configs', 'product_id')) {
            return [];
        }
        $rows = db_query(
            "SELECT * FROM product_field_configs WHERE product_id = ? ORDER BY display_order ASC, config_id ASC",
            'i',
            [$productId]
        ) ?: [];
        $configs = [];
        foreach ($rows as $row) {
            $configs[(string)$row['field_key']] = [
                'label' => (string)($row['field_label'] ?? ''),
                'type' => (string)($row['field_type'] ?? ''),
                'options' => !empty($row['field_options']) ? (json_decode((string)$row['field_options'], true) ?: []) : [],
                'visible' => (bool)($row['is_visible'] ?? 0),
                'required' => (bool)($row['is_required'] ?? 0),
                'unit' => (string)($row['unit'] ?? 'ft'),
                'allow_others' => isset($row['allow_others']) ? (bool)$row['allow_others'] : true,
            ];
        }
        return $configs;
    }
}

if (!function_exists('printflow_product_active_variants')) {
    function printflow_product_active_variants(int $productId): array
    {
        if ($productId < 1 || !function_exists('db_table_has_column') || !db_table_has_column('product_variants', 'variant_id')) {
            return [];
        }
        return db_query("SELECT variant_id, price, status FROM product_variants WHERE product_id = ? AND status = 'Active' ORDER BY variant_id", 'i', [$productId]) ?: [];
    }
}

/**
 * Detect and validate the product's single price-driving field configuration.
 * Multiple independently priced fields are ambiguous without an explicit
 * business rule, so they fail closed instead of being summed.
 */
if (!function_exists('printflow_product_option_pricing_config')) {
    function printflow_product_option_pricing_config(array $fieldConfigs): array
    {
        $pricingFields = [];
        foreach ($fieldConfigs as $fieldKey => $config) {
            if (!is_array($config) || empty($config['visible'])) {
                continue;
            }
            $type = strtolower(trim((string)($config['type'] ?? '')));
            if (!in_array($type, ['select', 'radio', 'dimension'], true)) {
                continue;
            }
            $options = [];
            foreach ((array)($config['options'] ?? []) as $option) {
                $value = is_array($option) ? trim((string)($option['value'] ?? '')) : trim((string)$option);
                if ($value === '') {
                    continue;
                }
                $price = is_array($option) && array_key_exists('price', $option)
                    ? printflow_product_parse_positive_price($option['price'])
                    : null;
                if ($price === null) {
                    return ['ok' => false, 'mode' => 'option', 'message' => 'Every selectable product option must have a valid price greater than ₱0.00, with up to two decimal places.'];
                }
                foreach ($options as $existingOption) {
                    if (strcasecmp($existingOption['value'], $value) === 0) {
                        return ['ok' => false, 'mode' => 'option', 'message' => 'Product option values must be unique so each selected option has exactly one price.'];
                    }
                }
                $options[] = ['value' => $value, 'price' => $price];
            }
            if ($options === []) {
                continue;
            }
            if (!empty($config['allow_others'])) {
                return ['ok' => false, 'mode' => 'option', 'message' => 'Disable “Others” for the price field because an unpriced custom option cannot be used for checkout.'];
            }
            $pricingFields[] = [
                'key' => (string)$fieldKey,
                'label' => trim((string)($config['label'] ?? $fieldKey)),
                'type' => $type,
                'unit' => trim((string)($config['unit'] ?? '')),
                'options' => $options,
            ];
        }
        if (count($pricingFields) > 1) {
            return ['ok' => false, 'mode' => 'ambiguous', 'message' => 'This product has more than one price-driving option field. Keep one configured price field so the final unit price is unambiguous.'];
        }
        return ['ok' => true, 'mode' => $pricingFields === [] ? 'base' : 'option', 'field' => $pricingFields[0] ?? null];
    }
}

if (!function_exists('printflow_product_resolve_field_unit_price')) {
    function printflow_product_resolve_field_unit_price($basePrice, array $fieldConfigs, array $customization): array
    {
        $pricing = printflow_product_option_pricing_config($fieldConfigs);
        if (empty($pricing['ok'])) {
            return ['ok' => false, 'mode' => (string)($pricing['mode'] ?? 'invalid'), 'unit_price' => null, 'message' => (string)($pricing['message'] ?? 'Product pricing is invalid.')];
        }
        if ($pricing['mode'] === 'base') {
            $price = printflow_product_parse_positive_price($basePrice);
            return $price === null
                ? ['ok' => false, 'mode' => 'base', 'unit_price' => null, 'message' => 'This product requires a valid base price greater than ₱0.00.']
                : ['ok' => true, 'mode' => 'base', 'unit_price' => $price, 'message' => ''];
        }

        $field = $pricing['field'];
        $selected = trim((string)($customization[$field['label']] ?? $customization[$field['key']] ?? ''));
        if ($field['type'] === 'dimension' && $field['unit'] !== '') {
            $selected = preg_replace('/\s+' . preg_quote($field['unit'], '/') . '$/i', '', $selected) ?? $selected;
        }
        if ($selected === '') {
            return ['ok' => false, 'mode' => 'option', 'unit_price' => null, 'message' => 'Select a priced product option before continuing.'];
        }
        foreach ($field['options'] as $option) {
            if (hash_equals($option['value'], $selected)) {
                return ['ok' => true, 'mode' => 'option', 'unit_price' => $option['price'], 'message' => ''];
            }
        }
        return ['ok' => false, 'mode' => 'option', 'unit_price' => null, 'message' => 'The selected product option is no longer available. Please choose a configured option.'];
    }
}

/** Resolve either a variant price or a configured field price, never both. */
if (!function_exists('printflow_product_resolve_unit_price')) {
    function printflow_product_resolve_unit_price($basePrice, array $fieldConfigs, array $customization, array $variants = [], ?int $variantId = null): array
    {
        $fieldPricing = printflow_product_option_pricing_config($fieldConfigs);
        if (empty($fieldPricing['ok'])) {
            return ['ok' => false, 'mode' => $fieldPricing['mode'] ?? 'invalid', 'unit_price' => null, 'message' => $fieldPricing['message'] ?? 'Product pricing is invalid.'];
        }
        $activeVariants = array_values(array_filter($variants, static function ($variant): bool {
            return is_array($variant) && (!isset($variant['status']) || (string)$variant['status'] === 'Active');
        }));
        if ($activeVariants !== [] && $fieldPricing['mode'] === 'option') {
            return ['ok' => false, 'mode' => 'ambiguous', 'unit_price' => null, 'message' => 'This product has both priced variants and priced fields. Keep one pricing source so the unit price is unambiguous.'];
        }
        if ($activeVariants !== []) {
            foreach ($activeVariants as $variant) {
                if (printflow_product_parse_positive_price($variant['price'] ?? null) === null) {
                    return ['ok' => false, 'mode' => 'variant', 'unit_price' => null, 'message' => 'Every selectable product variant must have a valid price greater than ₱0.00.'];
                }
            }
            if ($variantId === null || $variantId < 1) {
                return ['ok' => false, 'mode' => 'variant', 'unit_price' => null, 'message' => 'Select a priced product option before continuing.'];
            }
            foreach ($activeVariants as $variant) {
                if ((int)($variant['variant_id'] ?? 0) === $variantId) {
                    return ['ok' => true, 'mode' => 'variant', 'unit_price' => printflow_product_parse_positive_price($variant['price']), 'message' => ''];
                }
            }
            return ['ok' => false, 'mode' => 'variant', 'unit_price' => null, 'message' => 'The selected product option is no longer available.'];
        }
        return printflow_product_resolve_field_unit_price($basePrice, $fieldConfigs, $customization);
    }
}

if (!function_exists('printflow_product_resolve_catalog_unit_price')) {
    function printflow_product_resolve_catalog_unit_price(int $productId, array $customization, ?int $variantId = null): array
    {
        if ($productId < 1) {
            return ['ok' => false, 'mode' => 'invalid', 'unit_price' => null, 'message' => 'Product could not be verified.'];
        }
        $products = db_query("SELECT product_id, price FROM products WHERE product_id = ? AND status = 'Activated' LIMIT 1", 'i', [$productId]);
        if (empty($products)) {
            return ['ok' => false, 'mode' => 'invalid', 'unit_price' => null, 'message' => 'Product is no longer available.'];
        }
        $variants = printflow_product_active_variants($productId);
        return printflow_product_resolve_unit_price(
            $products[0]['price'] ?? null,
            printflow_product_pricing_field_configs($productId),
            $customization,
            $variants,
            $variantId
        );
    }
}

if (!function_exists('printflow_product_catalog_display_price')) {
    function printflow_product_catalog_display_price(int $productId, $basePrice): array
    {
        $fields = printflow_product_pricing_field_configs($productId);
        $fieldPricing = printflow_product_option_pricing_config($fields);
        if (empty($fieldPricing['ok'])) {
            return ['ok' => false, 'mode' => $fieldPricing['mode'] ?? 'invalid', 'price' => null];
        }
        $variants = printflow_product_active_variants($productId);
        if ($variants !== [] && $fieldPricing['mode'] === 'option') {
            return ['ok' => false, 'mode' => 'ambiguous', 'price' => null];
        }
        if ($variants !== []) {
            $prices = [];
            foreach ($variants as $variant) {
                $price = printflow_product_parse_positive_price($variant['price'] ?? null);
                if ($price === null) {
                    return ['ok' => false, 'mode' => 'variant', 'price' => null];
                }
                $prices[] = $price;
            }
            return ['ok' => true, 'mode' => 'variant', 'price' => min($prices)];
        }
        if ($fieldPricing['mode'] === 'option') {
            return ['ok' => true, 'mode' => 'option', 'price' => min(array_column($fieldPricing['field']['options'], 'price')), 'field_key' => $fieldPricing['field']['key']];
        }
        $price = printflow_product_parse_positive_price($basePrice);
        return $price === null
            ? ['ok' => false, 'mode' => 'base', 'price' => null]
            : ['ok' => true, 'mode' => 'base', 'price' => $price];
    }
}

if (!function_exists('printflow_product_catalog_display_price_map')) {
    /** Batch display-price resolution for catalog pages without per-card queries. */
    function printflow_product_catalog_display_price_map(array $products): array
    {
        $basePrices = [];
        foreach ($products as $product) {
            $id = (int)($product['product_id'] ?? 0);
            if ($id > 0) {
                $basePrices[$id] = $product['price'] ?? null;
            }
        }
        $ids = array_keys($basePrices);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $hasFieldTable = function_exists('db_table_has_column') && db_table_has_column('product_field_configs', 'product_id');
        $fieldRows = $hasFieldTable ? (db_query(
            "SELECT product_id, field_key, field_label, field_type, field_options, is_visible, is_required, unit, allow_others, display_order FROM product_field_configs WHERE product_id IN ({$placeholders}) ORDER BY display_order, config_id",
            $types,
            $ids
        ) ?: []) : [];
        $fields = [];
        foreach ($fieldRows as $row) {
            $pid = (int)$row['product_id'];
            $fields[$pid][(string)$row['field_key']] = [
                'label' => (string)($row['field_label'] ?? ''),
                'type' => (string)($row['field_type'] ?? ''),
                'options' => !empty($row['field_options']) ? (json_decode((string)$row['field_options'], true) ?: []) : [],
                'visible' => (bool)($row['is_visible'] ?? 0),
                'required' => (bool)($row['is_required'] ?? 0),
                'unit' => (string)($row['unit'] ?? 'ft'),
                'allow_others' => isset($row['allow_others']) ? (bool)$row['allow_others'] : true,
            ];
        }
        $hasVariantTable = function_exists('db_table_has_column') && db_table_has_column('product_variants', 'variant_id');
        $variantRows = $hasVariantTable ? (db_query(
            "SELECT product_id, variant_id, price FROM product_variants WHERE product_id IN ({$placeholders}) AND status = 'Active' ORDER BY product_id, variant_id",
            $types,
            $ids
        ) ?: []) : [];
        $variants = [];
        foreach ($variantRows as $row) {
            $variants[(int)$row['product_id']][] = $row;
        }

        $result = [];
        foreach ($basePrices as $id => $basePrice) {
            $fieldPricing = printflow_product_option_pricing_config($fields[$id] ?? []);
            if (empty($fieldPricing['ok'])) {
                $result[$id] = ['ok' => false, 'mode' => $fieldPricing['mode'] ?? 'invalid', 'price' => null];
                continue;
            }
            if (!empty($variants[$id]) && $fieldPricing['mode'] === 'option') {
                $result[$id] = ['ok' => false, 'mode' => 'ambiguous', 'price' => null];
                continue;
            }
            if (!empty($variants[$id])) {
                $prices = [];
                foreach ($variants[$id] as $variant) {
                    $price = printflow_product_parse_positive_price($variant['price'] ?? null);
                    if ($price === null) {
                        $prices = [];
                        break;
                    }
                    $prices[] = $price;
                }
                $result[$id] = $prices === []
                    ? ['ok' => false, 'mode' => 'variant', 'price' => null]
                    : ['ok' => true, 'mode' => 'variant', 'price' => min($prices)];
                continue;
            }
            if ($fieldPricing['mode'] === 'option') {
                $result[$id] = ['ok' => true, 'mode' => 'option', 'price' => min(array_column($fieldPricing['field']['options'], 'price')), 'field_key' => $fieldPricing['field']['key']];
                continue;
            }
            $price = printflow_product_parse_positive_price($basePrice);
            $result[$id] = $price === null
                ? ['ok' => false, 'mode' => 'base', 'price' => null]
                : ['ok' => true, 'mode' => 'base', 'price' => $price];
        }
        return $result;
    }
}

if (!function_exists('printflow_product_option_minimum_price')) {
    function printflow_product_option_minimum_price(array $fieldConfigs): ?float
    {
        $pricing = printflow_product_option_pricing_config($fieldConfigs);
        if (empty($pricing['ok']) || ($pricing['mode'] ?? '') !== 'option') {
            return null;
        }
        return min(array_column($pricing['field']['options'], 'price'));
    }
}
