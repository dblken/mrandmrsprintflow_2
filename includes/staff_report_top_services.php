<?php
/**
 * Staff reports: resolve real catalog service names for Top Selling Services.
 *
 * Rule: count job_orders + legacy service_orders by catalog service_id when available,
 * otherwise resolve service_type / job_title / service_name to a services row.
 * Pseudo labels (Customization, Service, Online, …) and unresolvable rows are excluded
 * from the ranking — historical rows are not deleted or rewritten.
 */

/**
 * Labels that must never appear as a Top Selling Service catalog entry.
 */
function printflow_staff_report_is_pseudo_service_name(string $name): bool {
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
    if ($normalized === '') {
        return true;
    }
    $pseudo = [
        'customization',
        'customizations',
        'service',
        'services',
        'online',
        'general',
        'custom product',
        'custom order',
        'order item',
        'service order',
        'pos service',
        'pos-service',
    ];
    return in_array($normalized, $pseudo, true);
}

/**
 * @return array{service_id:int,name:string}|null
 */
function printflow_staff_report_resolve_service_from_hints(int $serviceId, string $serviceType, string $jobTitle, string $serviceName = ''): ?array {
    if ($serviceId > 0 && function_exists('customer_orders_resolve_service_name_by_id')) {
        $byId = trim(customer_orders_resolve_service_name_by_id($serviceId));
        if ($byId !== '' && !printflow_staff_report_is_pseudo_service_name($byId)) {
            return ['service_id' => $serviceId, 'name' => $byId];
        }
    }

    $hints = array_unique(array_filter(array_map('trim', [$serviceType, $jobTitle, $serviceName]), static fn($v) => $v !== ''));
    foreach ($hints as $hint) {
        if (printflow_staff_report_is_pseudo_service_name($hint)) {
            continue;
        }
        $resolvedId = function_exists('printflow_resolve_service_catalog_service_id')
            ? (int)printflow_resolve_service_catalog_service_id($hint)
            : 0;
        if ($resolvedId > 0 && function_exists('customer_orders_resolve_service_name_by_id')) {
            $catalogName = trim(customer_orders_resolve_service_name_by_id($resolvedId));
            if ($catalogName !== '' && !printflow_staff_report_is_pseudo_service_name($catalogName)) {
                return ['service_id' => $resolvedId, 'name' => $catalogName];
            }
        }
        // Exact catalog name match (includes archived services for historical reporting).
        $rows = db_query(
            'SELECT service_id, name FROM services WHERE LOWER(TRIM(COALESCE(name, \'\'))) = LOWER(?) ORDER BY service_id ASC LIMIT 2',
            's',
            [$hint]
        );
        if (is_array($rows) && count($rows) === 1) {
            $catalogName = trim((string)($rows[0]['name'] ?? ''));
            if ($catalogName !== '' && !printflow_staff_report_is_pseudo_service_name($catalogName)) {
                return ['service_id' => (int)($rows[0]['service_id'] ?? 0), 'name' => $catalogName];
            }
        }
    }

    return null;
}

/**
 * @param array{
 *   job_date_condition:string,
 *   service_date_condition:string,
 *   branch_id:int,
 *   job_status_sql:string,
 *   service_status_sql:string,
 *   limit?:int
 * } $opts
 * @return list<array{name:string,total_sold:int,service_id?:int}>
 */
function printflow_staff_report_top_selling_services(array $opts): array {
    $branchId = (int)($opts['branch_id'] ?? 0);
    $jobDateCondition = (string)($opts['job_date_condition'] ?? '1=1');
    $serviceDateCondition = (string)($opts['service_date_condition'] ?? '1=1');
    $jobStatusSql = (string)($opts['job_status_sql'] ?? '');
    $serviceStatusSql = (string)($opts['service_status_sql'] ?? '');
    $limit = max(1, (int)($opts['limit'] ?? 5));

    $map = [];

    $addSold = static function (?array $resolved, int $qty) use (&$map): void {
        if ($resolved === null || $qty <= 0) {
            return;
        }
        $serviceId = (int)($resolved['service_id'] ?? 0);
        $name = trim((string)($resolved['name'] ?? ''));
        if ($name === '' || printflow_staff_report_is_pseudo_service_name($name)) {
            return;
        }
        $key = $serviceId > 0 ? 'id:' . $serviceId : 'name:' . (function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name));
        if (!isset($map[$key])) {
            $map[$key] = [
                'name' => $name,
                'total_sold' => 0,
                'service_id' => $serviceId,
            ];
        }
        $map[$key]['total_sold'] += $qty;
    };

    $jobRows = db_query(
        "SELECT jo.quantity,
                jo.service_type,
                jo.job_title,
                jo.order_item_id,
                oi.service_id AS oi_service_id,
                oi.customization_data,
                o.reference_id
         FROM job_orders jo
         LEFT JOIN order_items oi ON oi.order_item_id = jo.order_item_id
         LEFT JOIN orders o ON o.order_id = jo.order_id
         WHERE {$jobDateCondition}
           AND jo.branch_id = ?
           {$jobStatusSql}",
        'i',
        [$branchId]
    ) ?: [];

    foreach ($jobRows as $row) {
        $qty = max(1, (int)($row['quantity'] ?? 1));
        $serviceId = (int)($row['oi_service_id'] ?? 0);
        $custom = [];
        if (!empty($row['customization_data']) && function_exists('customer_orders_decode_customization_payload')) {
            $custom = customer_orders_decode_customization_payload($row['customization_data']);
        } elseif (!empty($row['customization_data'])) {
            $decoded = json_decode((string)$row['customization_data'], true);
            $custom = is_array($decoded) ? $decoded : [];
        }
        if ($serviceId <= 0) {
            $serviceId = (int)($custom['service_id'] ?? 0);
        }
        if ($serviceId <= 0 && function_exists('printflow_resolve_service_catalog_service_id_for_order_line')) {
            $item = [
                'service_id' => (int)($custom['service_id'] ?? 0),
                'item_type' => 'service',
            ];
            $order = ['reference_id' => (int)($row['reference_id'] ?? 0)];
            $serviceId = (int)printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item);
        }

        $resolved = printflow_staff_report_resolve_service_from_hints(
            $serviceId,
            (string)($row['service_type'] ?? ''),
            (string)($row['job_title'] ?? '')
        );
        $addSold($resolved, $qty);
    }

    $serviceOrderRows = db_query(
        "SELECT so.service_name, COUNT(*) AS total_sold
         FROM service_orders so
         WHERE {$serviceDateCondition}
           AND (so.branch_id = ? OR so.branch_id IS NULL)
           {$serviceStatusSql}
         GROUP BY so.service_name",
        'i',
        [$branchId]
    ) ?: [];

    foreach ($serviceOrderRows as $row) {
        $qty = max(0, (int)($row['total_sold'] ?? 0));
        if ($qty <= 0) {
            continue;
        }
        $serviceName = trim((string)($row['service_name'] ?? ''));
        $resolved = printflow_staff_report_resolve_service_from_hints(0, '', '', $serviceName);
        $addSold($resolved, $qty);
    }

    $top = array_values($map);
    usort($top, static fn($a, $b) => ($b['total_sold'] <=> $a['total_sold']) ?: strcmp($a['name'], $b['name']));

    return array_slice($top, 0, $limit);
}

/**
 * Render Top Selling Services rows HTML for staff reports (page + AJAX refresh).
 */
function printflow_staff_report_render_top_services_html(array $top_services): string {
    if ($top_services === []) {
        return '<div class="top-list-empty">No customized products or services found for this selected period.</div>';
    }

    $html = '';
    $rank = 1;
    foreach ($top_services as $service) {
        $name = htmlspecialchars((string)($service['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sold = (int)($service['total_sold'] ?? 0);
        $html .= '<div class="top-product-row">';
        $html .= '<div class="tp-name truncate-ellipsis" title="' . $name . '">';
        $html .= '<span class="tp-rank">#' . $rank++ . '</span> ' . $name;
        $html .= '</div>';
        $html .= '<div class="tp-sold">' . $sold . ' <span class="tp-sold-label">sold</span></div>';
        $html .= '</div>';
    }

    return $html;
}
