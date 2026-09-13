<?php
/**
 * Customer catalog performance helpers (services/products listings).
 * Keeps rating/sold stats consistent with service detail pages.
 */

/**
 * Softer cache policy for catalog navigation pages (allows bfcache where supported).
 * Session cookies still apply; HTML is not publicly cached.
 */
function pf_customer_catalog_navigation_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}

/**
 * Stats for service cards — same sources as order_service_dynamic.php / service_order_get_page_stats().
 *
 * @param array<int, array<string, mixed>> $service_rows
 * @return array<int, array{avg_rating: float, review_count: int, sold_count: int}>
 */
function printflow_catalog_service_card_stats_map(array $service_rows): array {
    $map = [];
    foreach ($service_rows as $row) {
        $serviceId = (int)($row['service_id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        if ($serviceId < 1 || $name === '') {
            continue;
        }

        $reviewStats = function_exists('printflow_get_service_review_stats')
            ? printflow_get_service_review_stats($name)
            : ['avg_rating' => 0.0, 'review_count' => 0];

        $map[$serviceId] = [
            'avg_rating' => (float)($reviewStats['avg_rating'] ?? 0),
            'review_count' => (int)($reviewStats['review_count'] ?? 0),
            'sold_count' => function_exists('printflow_service_units_sold')
                ? printflow_service_units_sold($serviceId)
                : 0,
        ];
    }

    return $map;
}

/**
 * @param array<int, int|string> $service_ids
 * @return array<int, true> service_id => true when field config exists
 */
function service_ids_with_field_config(array $service_ids): array {
    $service_ids = array_values(array_unique(array_filter(array_map('intval', $service_ids), static fn(int $id): bool => $id > 0)));
    if ($service_ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
    $rows = db_query(
        "SELECT DISTINCT service_id FROM service_field_configs WHERE service_id IN ($placeholders)",
        str_repeat('i', count($service_ids)),
        $service_ids
    ) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $id = (int)($row['service_id'] ?? 0);
        if ($id > 0) {
            $map[$id] = true;
        }
    }
    return $map;
}
