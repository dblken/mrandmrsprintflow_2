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
    $services = [];
    foreach ($service_rows as $row) {
        $serviceId = (int)($row['service_id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        if ($serviceId < 1) {
            continue;
        }
        $map[$serviceId] = [
            'avg_rating' => 0.0,
            'review_count' => 0,
            'sold_count' => 0,
        ];
        if ($name !== '') {
            $services[$serviceId] = $name;
        }
    }

    if ($services === []) {
        return $map;
    }

    $serviceIds = array_keys($services);
    $idPlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $serviceIdRows = [];
    $serviceIdParams = [];
    foreach ($serviceIds as $serviceId) {
        $serviceIdRows[] = 'SELECT ? AS service_id, ? AS like_colon, ? AS like_colon_sp, ? AS like_quoted';
        array_push(
            $serviceIdParams,
            (int)$serviceId,
            '%"service_id":' . (int)$serviceId . '%',
            '%"service_id": ' . (int)$serviceId . '%',
            '%"service_id":"' . (int)$serviceId . '"%'
        );
    }

    $serviceNameRows = [];
    $serviceNameParams = [];
    foreach ($services as $serviceId => $name) {
        $serviceNameRows[] = 'SELECT ? AS service_id, ? AS service_name';
        array_push($serviceNameParams, (int)$serviceId, $name);
    }

    $soldRows = db_query(
        "SELECT matched.service_id, COALESCE(SUM(matched.quantity), 0) AS cnt
         FROM (
            SELECT oi.order_item_id, oi.quantity, o.reference_id AS service_id
            FROM order_items oi
            INNER JOIN orders o ON o.order_id = oi.order_id
            WHERE o.status != 'Cancelled'
              AND LOWER(TRIM(COALESCE(o.order_type, ''))) = 'custom'
              AND o.reference_id IN ($idPlaceholders)
            UNION
            SELECT oi.order_item_id, oi.quantity, service_ids.service_id
            FROM order_items oi
            INNER JOIN orders o ON o.order_id = oi.order_id
            INNER JOIN (" . implode(' UNION ALL ', $serviceIdRows) . ") service_ids ON (
                oi.customization_data LIKE service_ids.like_colon
                OR oi.customization_data LIKE service_ids.like_colon_sp
                OR oi.customization_data LIKE service_ids.like_quoted
            )
            WHERE o.status != 'Cancelled'
            UNION
            SELECT oi.order_item_id, oi.quantity, service_names.service_id
            FROM order_items oi
            INNER JOIN orders o ON o.order_id = oi.order_id
            INNER JOIN (" . implode(' UNION ALL ', $serviceNameRows) . ") service_names ON (
                oi.customization_data LIKE '%\"service_type\"%'
                AND oi.customization_data COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', service_names.service_name COLLATE utf8mb4_unicode_ci, '%')
            )
            WHERE o.status != 'Cancelled'
         ) matched
         GROUP BY matched.service_id",
        str_repeat('i', count($serviceIds)) . str_repeat('isss', count($serviceIds)) . str_repeat('is', count($services)),
        array_merge($serviceIds, $serviceIdParams, $serviceNameParams)
    ) ?: [];
    foreach ($soldRows as $row) {
        $serviceId = (int)($row['service_id'] ?? 0);
        if (isset($map[$serviceId])) {
            $map[$serviceId]['sold_count'] = (int)($row['cnt'] ?? 0);
        }
    }

    $aliasRows = [];
    $aliasParams = [];
    foreach ($services as $serviceId => $name) {
        $aliases = function_exists('printflow_service_name_aliases') ? printflow_service_name_aliases($name) : [$name];
        foreach ($aliases as $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') {
                continue;
            }
            $aliasRows[] = 'SELECT ? AS service_id, ? AS service_name';
            array_push($aliasParams, (int)$serviceId, $alias);
        }
    }

    if ($aliasRows !== []) {
        $schema = function_exists('printflow_review_schema') ? printflow_review_schema() : ['service_col' => 'service_type'];
        $reviewWhere = [];
        if (!empty($schema['service_col'])) {
            $reviewWhere[] = "r.{$schema['service_col']} COLLATE utf8mb4_unicode_ci = service_alias.service_name COLLATE utf8mb4_unicode_ci";
        }
        $reviewWhere[] = "EXISTS (
            SELECT 1
            FROM order_items oi
            LEFT JOIN products p ON p.product_id = oi.product_id
            WHERE oi.order_id = r.order_id
              AND (
                p.name COLLATE utf8mb4_unicode_ci = service_alias.service_name COLLATE utf8mb4_unicode_ci
                OR oi.customization_data COLLATE utf8mb4_unicode_ci LIKE CONCAT('%', service_alias.service_name COLLATE utf8mb4_unicode_ci, '%')
              )
        )";

        $reviewRows = db_query(
            "SELECT matched.service_id, AVG(matched.rating) AS avg_rating, COUNT(*) AS review_count
             FROM (
                SELECT service_alias.service_id, r.id AS review_id, MAX(r.rating) AS rating
                FROM (" . implode(' UNION ALL ', $aliasRows) . ") service_alias
                INNER JOIN reviews r ON (" . implode(' OR ', $reviewWhere) . ")
                GROUP BY service_alias.service_id, r.id
             ) matched
             GROUP BY matched.service_id",
            str_repeat('is', count($aliasRows)),
            $aliasParams
        ) ?: [];
        foreach ($reviewRows as $row) {
            $serviceId = (int)($row['service_id'] ?? 0);
            if (isset($map[$serviceId])) {
                $map[$serviceId]['avg_rating'] = (float)($row['avg_rating'] ?? 0);
                $map[$serviceId]['review_count'] = (int)($row['review_count'] ?? 0);
            }
        }
    }

    return $map;
}

/**
 * Stats for product cards, resolved after pagination so product listings do not run correlated
 * sold/review subqueries for every row candidate.
 *
 * @param array<int, array<string, mixed>> $product_rows
 * @return array<int, array{avg_rating: float, review_count: int, sold_count: int}>
 */
function printflow_catalog_product_card_stats_map(array $product_rows): array {
    $map = [];
    $products = [];
    foreach ($product_rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }
        $products[$productId] = trim((string)($row['name'] ?? ''));
        $map[$productId] = [
            'avg_rating' => 0.0,
            'review_count' => 0,
            'sold_count' => 0,
        ];
    }

    if ($products === []) {
        return $map;
    }

    $productIds = array_keys($products);
    $idPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $soldRows = db_query(
        "SELECT oi.product_id, COALESCE(SUM(oi.quantity), 0) AS cnt
         FROM order_items oi
         INNER JOIN orders o ON oi.order_id = o.order_id
         WHERE oi.product_id IN ($idPlaceholders)
           AND o.status != 'Cancelled'
           AND (
                LOWER(TRIM(COALESCE(o.order_type, ''))) != 'custom'
                OR oi.customization_data LIKE '%\"config_id\"%'
                OR oi.customization_data LIKE '%\"form_type\":\"dynamic\"%'
                OR oi.customization_data LIKE '%\"form_type\": \"dynamic\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"products\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"product\"%'
                OR oi.customization_data LIKE '%\"source_page\":\"dynamic_form\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"products\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"product\"%'
                OR oi.customization_data LIKE '%\"source_page\": \"dynamic_form\"%'
           )
         GROUP BY oi.product_id",
        str_repeat('i', count($productIds)),
        $productIds
    ) ?: [];
    foreach ($soldRows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        if (isset($map[$productId])) {
            $map[$productId]['sold_count'] = (int)($row['cnt'] ?? 0);
        }
    }

    $reviewCols = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
    $reviewSelects = [];
    $reviewTypes = '';
    $reviewParams = [];

    if (isset($reviewCols['reference_id'], $reviewCols['review_type'])) {
        $reviewSelects[] = "SELECT r.reference_id AS product_id, r.id AS review_id, r.rating
            FROM reviews r
            WHERE r.review_type = 'product' AND r.reference_id IN ($idPlaceholders)";
        $reviewTypes .= str_repeat('i', count($productIds));
        array_push($reviewParams, ...$productIds);
    }

    if (isset($reviewCols['order_id'])) {
        $reviewSelects[] = "SELECT order_products.product_id, r.id AS review_id, r.rating
            FROM reviews r
            INNER JOIN (
                SELECT DISTINCT order_id, product_id
                FROM order_items
                WHERE product_id IN ($idPlaceholders)
            ) order_products ON order_products.order_id = r.order_id";
        $reviewTypes .= str_repeat('i', count($productIds));
        array_push($reviewParams, ...$productIds);
    }

    if (isset($reviewCols['service_type'])) {
        $productNameRows = [];
        foreach ($products as $productId => $name) {
            if ($name === '') {
                continue;
            }
            $productNameRows[] = 'SELECT ? AS product_id, ? AS product_name';
            $reviewTypes .= 'is';
            array_push($reviewParams, (int)$productId, $name);
        }
        if ($productNameRows !== []) {
            $reviewSelects[] = "SELECT product_names.product_id, r.id AS review_id, r.rating
                FROM (" . implode(' UNION ALL ', $productNameRows) . ") product_names
                INNER JOIN reviews r
                    ON r.service_type COLLATE utf8mb4_unicode_ci = product_names.product_name COLLATE utf8mb4_unicode_ci";
        }
    }

    if ($reviewSelects !== []) {
        $reviewRows = db_query(
            "SELECT matched.product_id, AVG(matched.rating) AS avg_rating, COUNT(*) AS review_count
             FROM (
                SELECT raw_matches.product_id, raw_matches.review_id, MAX(raw_matches.rating) AS rating
                FROM (" . implode(' UNION ALL ', $reviewSelects) . ") raw_matches
                GROUP BY raw_matches.product_id, raw_matches.review_id
             ) matched
             GROUP BY matched.product_id",
            $reviewTypes,
            $reviewParams
        ) ?: [];
        foreach ($reviewRows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if (isset($map[$productId])) {
                $map[$productId]['avg_rating'] = (float)($row['avg_rating'] ?? 0);
                $map[$productId]['review_count'] = (int)($row['review_count'] ?? 0);
            }
        }
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
