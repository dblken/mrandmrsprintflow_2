<?php
/**
 * Canonical one-time per-design layout pricing helpers.
 *
 * The layout choice is a per-design charge, while all other configured service
 * options remain per-unit charges. Amounts are represented as integer cents at
 * calculation boundaries to avoid multiplying binary floating point values.
 */

if (!function_exists('printflow_money_to_cents')) {
    function printflow_money_to_cents($amount): int
    {
        if (!is_numeric($amount) || !is_finite((float)$amount)) {
            throw new InvalidArgumentException('Price must be a finite number.');
        }
        return (int) round((float)$amount * 100, 0, PHP_ROUND_HALF_UP);
    }
}

if (!function_exists('printflow_money_from_cents')) {
    function printflow_money_from_cents(int $cents): float
    {
        return $cents / 100;
    }
}

if (!function_exists('printflow_service_line_totals_from_cents')) {
    /** @return array{unit_price_cents:int,design_fee_cents:int,quantity:int,line_subtotal_cents:int,line_total_cents:int} */
    function printflow_service_line_totals_from_cents(int $unitPriceCents, int $designFeeCents, int $quantity): array
    {
        if ($unitPriceCents < 0 || $designFeeCents < 0 || $quantity < 1 || $quantity > 1000000) {
            throw new InvalidArgumentException('Invalid service pricing components.');
        }
        $subtotal = $unitPriceCents * $quantity;
        return [
            'unit_price_cents' => $unitPriceCents,
            'design_fee_cents' => $designFeeCents,
            'quantity' => $quantity,
            'line_subtotal_cents' => $subtotal,
            'line_total_cents' => $subtotal + $designFeeCents,
        ];
    }
}

if (!function_exists('printflow_design_fee_option')) {
    /**
     * Resolve the configured layout field's selected option. Layout fees are
     * configuration-owned: never invent a default when the saved option price
     * is missing. A configured layout field must have a valid selection.
     *
     * @return array{ok:bool, fee_cents:int, mode:string, configured:bool, message:string}
     */
    function printflow_design_fee_option(array $fieldValues, array $fieldConfigs): array
    {
        if (function_exists('printflow_service_field_normalize_layout_values')) {
            $fieldValues = printflow_service_field_normalize_layout_values($fieldValues, $fieldConfigs);
        }

        $layoutFields = [];
        foreach ($fieldConfigs as $key => $config) {
            if (empty($config['visible'])) continue;
            $key = (string)$key;
            $label = strtolower(trim((string)($config['label'] ?? $key)));
            if (strpos(strtolower($key), 'layout') === false && strpos($label, 'layout') === false) continue;
            if (!in_array((string)($config['type'] ?? ''), ['radio', 'select'], true)) continue;
            if (function_exists('printflow_service_field_is_active')
                && !printflow_service_field_is_active($config, $fieldValues, $key, $fieldConfigs)) continue;

            $layoutFields[] = [$key, $config];
        }

        if ($layoutFields === []) {
            return ['ok' => true, 'fee_cents' => 0, 'mode' => '', 'configured' => false, 'message' => ''];
        }
        if (count($layoutFields) !== 1) {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => '', 'configured' => false, 'message' => 'This service has multiple active layout selections; pricing cannot be verified.'];
        }

        [$key, $config] = $layoutFields[0];

        $selected = trim((string)($fieldValues[$key] ?? ''));
        if ($selected === '') {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => '', 'configured' => false, 'message' => 'Please select With Layout or Without Layout.'];
        }

        $canonical = function_exists('printflow_customization_layout_mode')
            ? printflow_customization_layout_mode($selected)
            : '';
        if ($canonical === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $selected) ?? '');
            $canonical = strpos($slug, 'without') !== false && strpos($slug, 'layout') !== false
                ? 'without_layout'
                : ((strpos($slug, 'with') !== false && strpos($slug, 'layout') !== false) ? 'with_layout' : '');
        }
        if ($canonical === '') {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => '', 'configured' => false, 'message' => 'The selected layout option is not configured.'];
        }

        $selectedOption = null;
        foreach (($config['options'] ?? []) as $option) {
            $value = is_array($option) ? trim((string)($option['value'] ?? '')) : trim((string)$option);
            $matches = function_exists('printflow_service_option_values_match')
                ? printflow_service_option_values_match($selected, $value)
                : strcasecmp($selected, $value) === 0;
            if ($matches) {
                $selectedOption = $option;
                break;
            }
        }
        if ($selectedOption === null) {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => $canonical, 'configured' => false, 'message' => 'The selected layout option is not configured.'];
        }

        if ($canonical === 'with_layout') {
            return ['ok' => true, 'fee_cents' => 0, 'mode' => $canonical, 'configured' => true, 'message' => ''];
        }

        if (!is_array($selectedOption) || !array_key_exists('price', $selectedOption)
            || $selectedOption['price'] === null || trim((string)$selectedOption['price']) === '') {
            return ['ok' => true, 'fee_cents' => 0, 'mode' => $canonical, 'configured' => true, 'message' => ''];
        }
        if (!is_numeric($selectedOption['price']) || !is_finite((float)$selectedOption['price'])) {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => $canonical, 'configured' => false, 'message' => 'The configured Without Layout fee is invalid.'];
        }
        $cents = printflow_money_to_cents($selectedOption['price']);
        if ($cents < 0) {
            return ['ok' => false, 'fee_cents' => 0, 'mode' => $canonical, 'configured' => true, 'message' => 'The configured layout fee cannot be negative.'];
        }
        return ['ok' => true, 'fee_cents' => $cents, 'mode' => $canonical, 'configured' => true, 'message' => ''];
    }
}

if (!function_exists('printflow_calculate_service_line_pricing')) {
    /**
     * Canonical line pricing. unit_price is a per-unit amount excluding the
     * one-time design fee; line_total adds the fee once per design line.
     *
     * @return array{ok:bool,unit_price:float,unit_price_cents:int,design_fee:float,design_fee_cents:int,quantity:int,line_subtotal:float,line_subtotal_cents:int,line_total:float,line_total_cents:int,layout_mode:string,message:string,reason?:string}
     */
    function printflow_calculate_service_line_pricing(int $serviceId, array $customization, int $quantity, ?array $fieldConfigs = null): array
    {
        if ($quantity < 1 || $quantity > 1000000) {
            return ['ok' => false, 'unit_price' => 0.0, 'unit_price_cents' => 0, 'design_fee' => 0.0, 'design_fee_cents' => 0, 'quantity' => $quantity, 'line_subtotal' => 0.0, 'line_subtotal_cents' => 0, 'line_total' => 0.0, 'line_total_cents' => 0, 'layout_mode' => '', 'message' => 'Quantity must be between 1 and 1,000,000.'];
        }
        if ($fieldConfigs === null) {
            if (!function_exists('get_service_field_config')) require_once __DIR__ . '/service_field_config_helper.php';
            $fieldConfigs = get_service_field_config($serviceId);
        }
        $fieldValues = function_exists('printflow_service_field_values_from_customization')
            ? printflow_service_field_values_from_customization($customization, $fieldConfigs)
            : $customization;
        if (function_exists('printflow_service_field_normalize_layout_values')) {
            $fieldValues = printflow_service_field_normalize_layout_values($fieldValues, $fieldConfigs);
        }
        $layout = printflow_design_fee_option($fieldValues, $fieldConfigs);
        if (!$layout['ok']) {
            return ['ok' => false, 'unit_price' => 0.0, 'unit_price_cents' => 0, 'design_fee' => 0.0, 'design_fee_cents' => 0, 'quantity' => $quantity, 'line_subtotal' => 0.0, 'line_subtotal_cents' => 0, 'line_total' => 0.0, 'line_total_cents' => 0, 'layout_mode' => (string)$layout['mode'], 'message' => (string)$layout['message']];
        }

        $unit = function_exists('printflow_calculate_service_unit_price')
            ? printflow_calculate_service_unit_price($serviceId, $customization)
            : ['ok' => false, 'unit_price' => 0.0, 'message' => 'Service pricing is unavailable.'];
        if (empty($unit['ok'])) {
            return ['ok' => false, 'unit_price' => 0.0, 'unit_price_cents' => 0, 'design_fee' => 0.0, 'design_fee_cents' => 0, 'quantity' => $quantity, 'line_subtotal' => 0.0, 'line_subtotal_cents' => 0, 'line_total' => 0.0, 'line_total_cents' => 0, 'layout_mode' => (string)$layout['mode'], 'message' => (string)($unit['message'] ?? 'Service price could not be calculated.'), 'reason' => (string)($unit['reason'] ?? '')];
        }

        $unitCents = printflow_money_to_cents($unit['unit_price']);
        $totals = printflow_service_line_totals_from_cents($unitCents, (int)$layout['fee_cents'], $quantity);
        $subtotalCents = $totals['line_subtotal_cents'];
        $totalCents = $totals['line_total_cents'];
        return [
            'ok' => true,
            'unit_price' => printflow_money_from_cents($unitCents),
            'unit_price_cents' => $unitCents,
            'design_fee' => printflow_money_from_cents((int)$layout['fee_cents']),
            'design_fee_cents' => (int)$layout['fee_cents'],
            'quantity' => $quantity,
            'line_subtotal' => printflow_money_from_cents($subtotalCents),
            'line_subtotal_cents' => $subtotalCents,
            'line_total' => printflow_money_from_cents($totalCents),
            'line_total_cents' => $totalCents,
            'layout_mode' => (string)$layout['mode'],
            'message' => '',
        ];
    }
}

if (!function_exists('printflow_apply_configured_layout_fee_to_cart_item')) {
    /**
     * Add the saved layout fee to an existing service cart line without
     * recalculating its unit price. Dedicated service forms may calculate
     * custom-area/material pricing outside the dynamic service price resolver.
     * Checkout still performs its own authoritative price calculation.
     *
     * @return array{ok:bool,fee:float,message:string}
     */
    function printflow_apply_configured_layout_fee_to_cart_item(array &$item): array
    {
        $custom = $item['customization'] ?? [];
        if (is_string($custom)) {
            $decoded = json_decode($custom, true);
            $custom = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($custom)) $custom = [];

        $isService = strcasecmp((string)($item['type'] ?? ''), 'service') === 0
            || strcasecmp((string)($item['item_type'] ?? ''), 'service') === 0
            || strtolower(trim((string)($item['source_page'] ?? ''))) === 'services'
            || !empty($custom['service_id'])
            || !empty($item['service_id']);
        if (!$isService) return ['ok' => true, 'fee' => 0.0, 'message' => ''];

        $serviceId = printflow_design_fee_resolve_cart_service_id($item, $custom);
        if ($serviceId <= 0 || !function_exists('get_service_field_config')) {
            return ['ok' => false, 'fee' => 0.0, 'message' => 'This service could not be matched to its configured layout pricing.'];
        }

        $configs = get_service_field_config($serviceId) ?: [];
        if (!is_array($configs)) $configs = [];
        if (function_exists('printflow_service_field_values_from_customization')) {
            $values = printflow_service_field_values_from_customization($custom, $configs);
        } else {
            $values = $custom;
        }
        if (is_array($item['dynamic_form_data'] ?? null)) {
            $values = array_replace($values, $item['dynamic_form_data']);
        }
        $layout = printflow_design_fee_option($values, $configs);
        if (empty($layout['ok'])) {
            return ['ok' => false, 'fee' => 0.0, 'message' => (string)($layout['message'] ?? 'The layout fee could not be verified.')];
        }

        $fee = printflow_money_from_cents((int)$layout['fee_cents']);
        $custom['service_id'] = $serviceId;
        $custom['design_fee'] = number_format($fee, 2, '.', '');
        $custom['design_fee_version'] = 1;
        $item['service_id'] = $serviceId;
        $item['design_fee'] = $fee;
        $item['customization'] = $custom;
        return ['ok' => true, 'fee' => $fee, 'message' => ''];
    }
}

if (!function_exists('printflow_design_fee_resolve_cart_service_id')) {
    /** Resolve legacy service cart lines that store the service name in category. */
    function printflow_design_fee_resolve_cart_service_id(array $item, array $customization = []): int
    {
        $custom = $customization !== [] ? $customization : ($item['customization'] ?? []);
        if (is_string($custom)) {
            $decoded = json_decode($custom, true);
            $custom = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($custom)) $custom = [];
        $serviceId = (int)($item['service_id'] ?? $custom['service_id'] ?? 0);
        if ($serviceId > 0) return $serviceId;

        $resolverLine = $item;
        $resolverLine['customization'] = $custom;
        if (function_exists('printflow_resolve_service_catalog_service_id_from_cart_line')) {
            $serviceId = (int)printflow_resolve_service_catalog_service_id_from_cart_line($resolverLine);
            if ($serviceId > 0) return $serviceId;
        }
        if (!function_exists('printflow_resolve_service_catalog_service_id')) return 0;

        foreach ([(string)($custom['service_type'] ?? ''), (string)($item['name'] ?? ''), (string)($item['category'] ?? '')] as $hint) {
            $hint = trim($hint);
            if ($hint === '') continue;
            $serviceId = (int)printflow_resolve_service_catalog_service_id($hint);
            if ($serviceId > 0) return $serviceId;
        }
        return 0;
    }
}

if (!function_exists('printflow_order_items_has_design_fee_column')) {
    function printflow_order_items_has_design_fee_column(): bool
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = function_exists('db_table_has_column') && db_table_has_column('order_items', 'design_fee');
        }
        return $hasColumn;
    }
}

if (!function_exists('printflow_order_items_has_line_total_override_column')) {
    function printflow_order_items_has_line_total_override_column(): bool
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = function_exists('db_table_has_column') && db_table_has_column('order_items', 'line_total_override');
        }
        return $hasColumn;
    }
}

if (!function_exists('printflow_order_item_calculated_line_total')) {
    /** Historical rows without the additive columns naturally resolve fee and override to zero/null. */
    function printflow_order_item_calculated_line_total(array $row): float
    {
        if (array_key_exists('line_total_override', $row) && $row['line_total_override'] !== null && $row['line_total_override'] !== '') {
            return round(max(0, (float)$row['line_total_override']), 2);
        }
        $quantity = max(0, (int)($row['quantity'] ?? 0));
        $unitPrice = max(0, (float)($row['unit_price'] ?? 0));
        $fee = max(0, (float)($row['design_fee'] ?? 0));
        return round(($quantity * $unitPrice) + $fee, 2);
    }
}

if (!function_exists('printflow_apply_service_line_pricing_to_cart_item')) {
    /** Rebuild a service cart line from current server-side configuration. */
    function printflow_apply_service_line_pricing_to_cart_item(array &$item): array
    {
        $custom = $item['customization'] ?? [];
        if (is_string($custom)) {
            $custom = json_decode($custom, true);
        }
        if (!is_array($custom)) $custom = [];
        $serviceId = printflow_design_fee_resolve_cart_service_id($item, $custom);
        $quantity = (int)($item['quantity'] ?? $item['qty'] ?? 1);
        if ($serviceId <= 0 || $quantity < 1 || $quantity > 1000000) {
            return ['ok' => false, 'message' => 'Service pricing could not be validated.'];
        }
        $custom['service_id'] = $serviceId;
        if (!function_exists('printflow_calculate_service_line_pricing')) {
            return ['ok' => false, 'message' => 'Service pricing is unavailable.'];
        }
        $pricing = printflow_calculate_service_line_pricing($serviceId, $custom, $quantity);
        if (empty($pricing['ok'])) {
            if (($pricing['reason'] ?? '') !== 'unconfigured') {
                return ['ok' => false, 'message' => (string)($pricing['message'] ?? 'Service price could not be verified.')];
            }
            $configs = function_exists('get_service_field_config') ? get_service_field_config($serviceId) : [];
            $values = function_exists('printflow_service_field_values_from_customization')
                ? printflow_service_field_values_from_customization($custom, $configs) : $custom;
            $layout = printflow_design_fee_option($values, $configs);
            if (empty($layout['ok'])) return ['ok' => false, 'message' => (string)$layout['message']];
            // Dedicated customer service forms already calculate their
            // dimension/material price server-side before storing the cart
            // line. Preserve that server-created amount when the catalog has
            // no generic unit-price formula. Generic unpriced services remain
            // zero-priced until staff sets their price.
            $storedUnitPrice = $custom['calculated_unit_price'] ?? $item['price'] ?? 0;
            if (!is_numeric($storedUnitPrice) || !is_finite((float)$storedUnitPrice) || (float)$storedUnitPrice < 0) {
                return ['ok' => false, 'message' => 'The service price could not be verified. Please review the service details.'];
            }
            $unitCents = printflow_money_to_cents($storedUnitPrice);
            $feeCents = (int)$layout['fee_cents'];
            $lineTotals = printflow_service_line_totals_from_cents($unitCents, $feeCents, $quantity);
            $pricing = [
                'ok' => true, 'unit_price' => printflow_money_from_cents($unitCents), 'unit_price_cents' => $unitCents,
                'design_fee' => printflow_money_from_cents($feeCents), 'design_fee_cents' => $feeCents,
                'quantity' => $quantity,
                'line_subtotal' => printflow_money_from_cents($lineTotals['line_subtotal_cents']),
                'line_subtotal_cents' => $lineTotals['line_subtotal_cents'],
                'line_total' => printflow_money_from_cents($lineTotals['line_total_cents']),
                'line_total_cents' => $lineTotals['line_total_cents'],
                'layout_mode' => (string)$layout['mode'], 'message' => '',
            ];
            $custom['price_unconfigured'] = '1';
        }
        $custom['design_fee'] = number_format((float)$pricing['design_fee'], 2, '.', '');
        $custom['design_fee_version'] = 1;
        $custom['calculated_unit_price'] = number_format((float)$pricing['unit_price'], 2, '.', '');
        $custom['calculated_estimated_price'] = number_format((float)$pricing['line_total'], 2, '.', '');
        $item['service_id'] = $serviceId;
        $item['quantity'] = $quantity;
        $item['price'] = (float)$pricing['unit_price'];
        $item['design_fee'] = (float)$pricing['design_fee'];
        $item['estimated_price'] = (float)$pricing['line_total'];
        $item['customization'] = $custom;
        return $pricing;
    }
}
