<?php
/**
 * Dynamic order-priority / urgent-request resolution for configurable service fields.
 *
 * Priority fields are normal radio/select fields whose options may carry
 * staff_flags (e.g. urgent_request). No hard-coded "Order Priority" labels.
 */

if (!function_exists('printflow_normalize_service_field_staff_flags')) {
    /**
     * @param mixed $flags
     * @return string[]
     */
    function printflow_normalize_service_field_staff_flags($flags): array
    {
        if (!is_array($flags)) {
            return [];
        }

        $out = [];
        foreach ($flags as $flag) {
            $flag = strtolower(trim((string)$flag));
            if ($flag !== '') {
                $out[] = $flag;
            }
        }

        return array_values(array_unique($out));
    }
}

if (!function_exists('printflow_service_field_option_has_staff_flag')) {
    /**
     * @param mixed $option
     */
    function printflow_service_field_option_has_staff_flag($option, string $flag): bool
    {
        $flag = strtolower(trim($flag));
        if ($flag === '') {
            return false;
        }

        if (!is_array($option)) {
            return false;
        }

        return in_array($flag, printflow_normalize_service_field_staff_flags($option['staff_flags'] ?? []), true);
    }
}

if (!function_exists('printflow_service_field_option_is_urgent_request')) {
    /**
     * @param mixed $option
     */
    function printflow_service_field_option_is_urgent_request($option): bool
    {
        if (printflow_service_field_option_has_staff_flag($option, 'urgent_request')
            || printflow_service_field_option_has_staff_flag($option, 'urgent')) {
            return true;
        }

        $value = is_array($option) ? trim((string)($option['value'] ?? '')) : trim((string)$option);

        return $value !== '' && (bool)preg_match('/\burgent\b/i', $value);
    }
}

if (!function_exists('printflow_service_field_config_is_priority_field')) {
    /**
     * @param array<string,mixed> $config
     */
    function printflow_service_field_config_is_priority_field(array $config): bool
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            return false;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $flags = printflow_normalize_service_field_staff_flags($option['staff_flags'] ?? []);
            if ($flags !== []) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('printflow_customization_values_match_option')) {
    /**
     * @param mixed $option
     */
    function printflow_customization_values_match_option(string $submitted, $option): bool
    {
        $submitted = trim($submitted);
        if ($submitted === '') {
            return false;
        }

        $optValue = is_array($option) ? trim((string)($option['value'] ?? '')) : trim((string)$option);
        if ($optValue === '') {
            return false;
        }

        return strcasecmp($submitted, $optValue) === 0;
    }
}

if (!function_exists('printflow_priority_request_default')) {
    /**
     * @return array<string,mixed>
     */
    function printflow_priority_request_default(): array
    {
        return [
            'is_urgent_request' => false,
            'is_regular' => false,
            'has_priority_field' => false,
            'field_key' => null,
            'field_label' => null,
            'selected_value' => null,
            'related_fields' => [],
            'job_priority' => 'NORMAL',
            'source' => 'none',
        ];
    }
}

if (!function_exists('printflow_priority_request_from_snapshot')) {
    /**
     * @param array<string,mixed> $customization
     * @return array<string,mixed>|null
     */
    function printflow_priority_request_from_snapshot(array $customization): ?array
    {
        $snap = $customization['_staff_priority_request'] ?? null;
        if (!is_array($snap)) {
            return null;
        }

        $default = printflow_priority_request_default();
        $hasField = !empty($snap['has_priority_field']);
        $isUrgent = !empty($snap['is_urgent_request']);

        return array_merge($default, [
            'is_urgent_request' => $isUrgent,
            'is_regular' => $hasField && !$isUrgent,
            'has_priority_field' => $hasField,
            'field_key' => $snap['field_key'] ?? null,
            'field_label' => $snap['field_label'] ?? null,
            'selected_value' => $snap['selected_value'] ?? null,
            'related_fields' => is_array($snap['related_fields'] ?? null) ? $snap['related_fields'] : [],
            'job_priority' => $isUrgent ? 'HIGH' : 'NORMAL',
            'source' => 'snapshot',
        ]);
    }
}

if (!function_exists('printflow_build_staff_priority_request_snapshot')) {
    /**
     * @param array<string,mixed> $priority
     * @return array<string,mixed>|null
     */
    function printflow_build_staff_priority_request_snapshot(array $priority): ?array
    {
        if (empty($priority['has_priority_field'])) {
            return null;
        }

        return [
            'field_key' => $priority['field_key'] ?? null,
            'field_label' => $priority['field_label'] ?? null,
            'selected_value' => $priority['selected_value'] ?? null,
            'is_urgent_request' => !empty($priority['is_urgent_request']),
            'has_priority_field' => true,
            'related_fields' => is_array($priority['related_fields'] ?? null) ? $priority['related_fields'] : [],
            'captured_at' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('printflow_resolve_dynamic_order_priority_heuristic')) {
    /**
     * @param array<string,mixed> $customization
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    function printflow_resolve_dynamic_order_priority_heuristic(array $customization, array $default): array
    {
        foreach ($customization as $key => $val) {
            if (!is_string($key) || $key === '' || $key[0] === '_') {
                continue;
            }
            if (!is_scalar($val)) {
                continue;
            }

            $submitted = trim((string)$val);
            if ($submitted === '' || !preg_match('/\burgent\b/i', $submitted)) {
                continue;
            }

            return array_merge($default, [
                'is_urgent_request' => true,
                'is_regular' => false,
                'has_priority_field' => true,
                'field_label' => $key,
                'selected_value' => $submitted,
                'job_priority' => 'HIGH',
                'source' => 'heuristic',
            ]);
        }

        return $default;
    }
}

if (!function_exists('printflow_collect_priority_related_fields')) {
    /**
     * @param mixed $matchedOption
     * @param array<string,mixed> $customization
     * @return array<string,string>
     */
    function printflow_collect_priority_related_fields($matchedOption, array $customization): array
    {
        $related = [];
        if (!is_array($matchedOption) || empty($matchedOption['nested_fields']) || !is_array($matchedOption['nested_fields'])) {
            return $related;
        }

        foreach ($matchedOption['nested_fields'] as $nested) {
            if (!is_array($nested)) {
                continue;
            }
            $label = trim((string)($nested['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $value = trim((string)($customization[$label] ?? ''));
            if ($value !== '') {
                $related[$label] = $value;
            }
        }

        return $related;
    }
}

if (!function_exists('printflow_resolve_dynamic_order_priority')) {
    /**
     * Resolve customer priority/urgent request from service field config + submitted customization.
     *
     * @param array<string,mixed> $customization
     * @return array<string,mixed>
     */
    function printflow_resolve_dynamic_order_priority(int $serviceId, array $customization): array
    {
        $default = printflow_priority_request_default();

        $fromSnapshot = printflow_priority_request_from_snapshot($customization);
        if ($fromSnapshot !== null) {
            return $fromSnapshot;
        }

        if ($serviceId <= 0) {
            $serviceId = (int)($customization['service_id'] ?? 0);
        }

        if ($serviceId <= 0) {
            return printflow_resolve_dynamic_order_priority_heuristic($customization, $default);
        }

        if (!function_exists('get_service_field_config')) {
            require_once __DIR__ . '/service_field_config_helper.php';
        }

        $configs = get_service_field_config($serviceId);
        foreach ($configs as $fieldKey => $config) {
            if (!in_array((string)($config['type'] ?? ''), ['radio', 'select'], true)) {
                continue;
            }
            if (!printflow_service_field_config_is_priority_field($config)) {
                continue;
            }

            $label = trim((string)($config['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $submitted = trim((string)($customization[$label] ?? ''));
            if ($submitted === '') {
                continue;
            }

            $options = is_array($config['options'] ?? null) ? $config['options'] : [];
            $matchedOption = null;
            foreach ($options as $option) {
                if (printflow_customization_values_match_option($submitted, $option)) {
                    $matchedOption = $option;
                    break;
                }
            }

            $isUrgent = $matchedOption !== null && printflow_service_field_option_is_urgent_request($matchedOption);

            return array_merge($default, [
                'is_urgent_request' => $isUrgent,
                'is_regular' => !$isUrgent,
                'has_priority_field' => true,
                'field_key' => (string)$fieldKey,
                'field_label' => $label,
                'selected_value' => $submitted,
                'related_fields' => printflow_collect_priority_related_fields($matchedOption, $customization),
                'job_priority' => $isUrgent ? 'HIGH' : 'NORMAL',
                'source' => 'config',
            ]);
        }

        return printflow_resolve_dynamic_order_priority_heuristic($customization, $default);
    }
}

if (!function_exists('printflow_apply_priority_request_to_row')) {
    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $priority
     */
    function printflow_apply_priority_request_to_row(array &$row, array $priority): void
    {
        $row['is_urgent_request'] = !empty($priority['is_urgent_request']);
        $row['is_regular_priority'] = !empty($priority['has_priority_field']) && empty($priority['is_urgent_request']);
        $row['has_priority_field'] = !empty($priority['has_priority_field']);
        $row['priority_request_label'] = $priority['field_label'] ?? null;
        $row['priority_request_value'] = $priority['selected_value'] ?? null;
        $row['priority_request_related'] = is_array($priority['related_fields'] ?? null) ? $priority['related_fields'] : [];

        if (!empty($priority['job_priority']) && empty($row['priority'])) {
            $row['priority'] = (string)$priority['job_priority'];
        }
    }
}

if (!function_exists('printflow_order_has_urgent_request')) {
    function printflow_order_has_urgent_request(int $orderId): bool
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            return false;
        }

        if (!function_exists('customer_orders_decode_customization_payload')) {
            require_once __DIR__ . '/order_ui_helper.php';
        }

        $items = db_query(
            'SELECT customization_data, specifications FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC',
            'i',
            [$orderId]
        ) ?: [];

        foreach ($items as $item) {
            $custom = customer_orders_decode_customization_payload((string)($item['customization_data'] ?? ''));
            $specs = customer_orders_decode_customization_payload((string)($item['specifications'] ?? ''));
            if ($specs !== []) {
                $custom = function_exists('printflow_overlay_nonempty_assoc')
                    ? printflow_overlay_nonempty_assoc($custom, $specs)
                    : array_merge($custom, $specs);
            }

            $serviceId = (int)($custom['service_id'] ?? 0);
            $priority = printflow_resolve_dynamic_order_priority($serviceId, $custom);
            if (!empty($priority['is_urgent_request'])) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('printflow_order_priority_needed_date_label')) {
    function printflow_order_priority_needed_date_label(int $orderId): string
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            return '';
        }

        if (!function_exists('customer_orders_decode_customization_payload')) {
            require_once __DIR__ . '/order_ui_helper.php';
        }

        $items = db_query(
            'SELECT customization_data, specifications FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC LIMIT 1',
            'i',
            [$orderId]
        ) ?: [];

        if (empty($items[0])) {
            return '';
        }

        $custom = customer_orders_decode_customization_payload((string)($items[0]['customization_data'] ?? ''));
        $specs = customer_orders_decode_customization_payload((string)($items[0]['specifications'] ?? ''));
        if ($specs !== []) {
            $custom = function_exists('printflow_overlay_nonempty_assoc')
                ? printflow_overlay_nonempty_assoc($custom, $specs)
                : array_merge($custom, $specs);
        }

        foreach (['Needed Date', 'needed_date'] as $key) {
            $value = trim((string)($custom[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
