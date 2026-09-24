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
    $soldRows = db_query(
        "SELECT oi.order_item_id, oi.quantity, o.reference_id, o.order_type, oi.customization_data
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         WHERE o.status != 'Cancelled'
           AND (
                (LOWER(TRIM(COALESCE(o.order_type, ''))) = 'custom'
                 AND o.reference_id IN ($idPlaceholders))
                OR oi.customization_data LIKE '%\"service_id\"%'
                OR oi.customization_data LIKE '%\"service_type\"%'
           )",
        str_repeat('i', count($serviceIds)),
        $serviceIds
    ) ?: [];

    $serviceIdSet = array_fill_keys($serviceIds, true);
    foreach ($soldRows as $row) {
        $matchedServiceIds = [];
        $referenceId = (int)($row['reference_id'] ?? 0);
        if (strtolower(trim((string)($row['order_type'] ?? ''))) === 'custom'
            && isset($serviceIdSet[$referenceId])) {
            $matchedServiceIds[$referenceId] = true;
        }

        $rawCustomization = (string)($row['customization_data'] ?? '');
        $customization = json_decode($rawCustomization, true);
        if (is_array($customization)) {
            $configuredServiceId = (int)($customization['service_id'] ?? 0);
            if (isset($serviceIdSet[$configuredServiceId])) {
                $matchedServiceIds[$configuredServiceId] = true;
            }
        }

        // Preserve the legacy service_type fallback. The old SQL matched the
        // configured service name anywhere after confirming the payload carried
        // a service_type key, so do the same once per row without an SQL
        // service-count cross product.
        if (stripos($rawCustomization, '"service_type"') !== false) {
            foreach ($services as $serviceId => $serviceName) {
                if ($serviceName !== '' && stripos($rawCustomization, $serviceName) !== false) {
                    $matchedServiceIds[$serviceId] = true;
                }
            }
        }

        $quantity = (int)($row['quantity'] ?? 0);
        foreach (array_keys($matchedServiceIds) as $serviceId) {
            $map[$serviceId]['sold_count'] += $quantity;
        }
    }

    $serviceAliases = [];
    foreach ($services as $serviceId => $name) {
        $aliases = function_exists('printflow_service_name_aliases') ? printflow_service_name_aliases($name) : [$name];
        foreach ($aliases as $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') {
                continue;
            }
            $serviceAliases[$serviceId][$alias] = true;
        }
    }

    if ($serviceAliases !== []) {
        $reviewColumns = array_flip(array_column(db_query('SHOW COLUMNS FROM reviews') ?: [], 'Field'));
        $serviceColumn = isset($reviewColumns['service_type']) ? 'service_type' : '';
        $orderColumn = isset($reviewColumns['order_id']) ? 'order_id' : '';
        $reviewSelect = ['id', 'rating'];
        if ($serviceColumn !== '') $reviewSelect[] = $serviceColumn;
        if ($orderColumn !== '') $reviewSelect[] = $orderColumn;
        $reviewRows = db_query('SELECT ' . implode(', ', $reviewSelect) . ' FROM reviews') ?: [];

        $orderIds = [];
        foreach ($reviewRows as $reviewRow) {
            $orderId = (int)($reviewRow[$orderColumn] ?? 0);
            if ($orderId > 0) $orderIds[$orderId] = true;
        }

        $orderReviewHints = [];
        if ($orderIds !== []) {
            $reviewOrderIds = array_keys($orderIds);
            $orderPlaceholders = implode(',', array_fill(0, count($reviewOrderIds), '?'));
            $hintRows = db_query(
                "SELECT oi.order_id, p.name AS product_name, oi.customization_data
                 FROM order_items oi
                 LEFT JOIN products p ON p.product_id = oi.product_id
                 WHERE oi.order_id IN ($orderPlaceholders)",
                str_repeat('i', count($reviewOrderIds)),
                $reviewOrderIds
            ) ?: [];
            foreach ($hintRows as $hintRow) {
                $orderId = (int)($hintRow['order_id'] ?? 0);
                if ($orderId > 0) $orderReviewHints[$orderId][] = $hintRow;
            }
        }

        $ratingTotals = [];
        foreach ($reviewRows as $reviewRow) {
            $reviewServiceName = $serviceColumn !== '' ? trim((string)($reviewRow[$serviceColumn] ?? '')) : '';
            $orderId = $orderColumn !== '' ? (int)($reviewRow[$orderColumn] ?? 0) : 0;
            foreach ($serviceAliases as $serviceId => $aliases) {
                $matches = false;
                foreach (array_keys($aliases) as $alias) {
                    if ($reviewServiceName !== '' && strcasecmp($reviewServiceName, $alias) === 0) {
                        $matches = true;
                        break;
                    }
                    foreach ($orderReviewHints[$orderId] ?? [] as $hint) {
                        if (strcasecmp(trim((string)($hint['product_name'] ?? '')), $alias) === 0
                            || stripos((string)($hint['customization_data'] ?? ''), $alias) !== false) {
                            $matches = true;
                            break 2;
                        }
                    }
                }
                if (!$matches) continue;

                $ratingTotals[$serviceId]['sum'] = ($ratingTotals[$serviceId]['sum'] ?? 0.0)
                    + (float)($reviewRow['rating'] ?? 0);
                $ratingTotals[$serviceId]['count'] = ($ratingTotals[$serviceId]['count'] ?? 0) + 1;
            }
        }

        foreach ($ratingTotals as $serviceId => $ratingStats) {
            $count = (int)($ratingStats['count'] ?? 0);
            $map[$serviceId]['review_count'] = $count;
            $map[$serviceId]['avg_rating'] = $count > 0
                ? (float)$ratingStats['sum'] / $count
                : 0.0;
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
 * Member products for catalog groups (batch).
 *
 * @param array<int, int|string> $groupIds
 * @return array<int, array<int, array{product_id: int, name: string}>>
 */
function printflow_catalog_group_member_product_rows_batch(array $groupIds): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
    if ($groupIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $rows = db_query(
        "SELECT m.group_id, m.product_id, p.name
         FROM product_catalog_group_members m
         INNER JOIN products p ON p.product_id = m.product_id
         WHERE m.group_id IN ($placeholders)
         ORDER BY m.group_id ASC, m.sort_order ASC, p.name ASC",
        str_repeat('i', count($groupIds)),
        $groupIds
    ) ?: [];

    $byGroup = [];
    foreach ($rows as $row) {
        $gid = (int) ($row['group_id'] ?? 0);
        $pid = (int) ($row['product_id'] ?? 0);
        if ($gid < 1 || $pid < 1) {
            continue;
        }
        $byGroup[$gid][] = [
            'product_id' => $pid,
            'name' => trim((string) ($row['name'] ?? '')),
        ];
    }
    return $byGroup;
}

/**
 * Build UNION ALL subqueries matching product reviews (same rules as card stats).
 *
 * @param array<int, string> $products product_id => name
 * @return array<int, string>
 */
function printflow_catalog_product_review_match_selects(array $products, string &$reviewTypes, array &$reviewParams): array
{
    $productIds = array_keys($products);
    if ($productIds === []) {
        return [];
    }
    $idPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $reviewCols = array_flip(array_column(db_query('SHOW COLUMNS FROM reviews') ?: [], 'Field'));
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
            array_push($reviewParams, (int) $productId, $name);
        }
        if ($productNameRows !== []) {
            $reviewSelects[] = "SELECT product_names.product_id, r.id AS review_id, r.rating
                FROM (" . implode(' UNION ALL ', $productNameRows) . ") product_names
                INNER JOIN reviews r
                    ON r.service_type COLLATE utf8mb4_unicode_ci = product_names.product_name COLLATE utf8mb4_unicode_ci";
        }
    }

    return $reviewSelects;
}

/**
 * @param array<int, array{product_id: int, name?: string}> $product_rows
 * @return array{avg_rating: float, review_count: int, sold_count: int}
 */
function printflow_catalog_products_aggregate_stats(array $product_rows): array
{
    $defaults = ['avg_rating' => 0.0, 'review_count' => 0, 'sold_count' => 0];
    $products = [];
    foreach ($product_rows as $row) {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }
        $products[$productId] = trim((string) ($row['name'] ?? ''));
    }
    if ($products === []) {
        return $defaults;
    }

    $cardRows = [];
    foreach ($products as $productId => $name) {
        $cardRows[] = ['product_id' => $productId, 'name' => $name];
    }
    $cardMap = printflow_catalog_product_card_stats_map($cardRows);
    $soldTotal = 0;
    foreach ($cardMap as $stats) {
        $soldTotal += (int) ($stats['sold_count'] ?? 0);
    }

    $reviewTypes = '';
    $reviewParams = [];
    $reviewSelects = printflow_catalog_product_review_match_selects($products, $reviewTypes, $reviewParams);
    $reviewCount = 0;
    $ratingSum = 0.0;
    if ($reviewSelects !== []) {
        $aggRows = db_query(
            "SELECT COALESCE(SUM(matched.rating), 0) AS rating_sum, COUNT(*) AS review_count
             FROM (
                SELECT raw_matches.review_id, MAX(raw_matches.rating) AS rating
                FROM (" . implode(' UNION ALL ', $reviewSelects) . ") raw_matches
                GROUP BY raw_matches.review_id
             ) matched",
            $reviewTypes,
            $reviewParams
        ) ?: [];
        if (!empty($aggRows[0])) {
            $reviewCount = (int) ($aggRows[0]['review_count'] ?? 0);
            $ratingSum = (float) ($aggRows[0]['rating_sum'] ?? 0);
        }
    }

    return [
        'avg_rating' => $reviewCount > 0 ? $ratingSum / $reviewCount : 0.0,
        'review_count' => $reviewCount,
        'sold_count' => $soldTotal,
    ];
}

/**
 * @param array<int, int|string> $groupIds
 * @return array<int, array{avg_rating: float, review_count: int, sold_count: int}>
 */
function printflow_catalog_group_card_stats_map(array $groupIds): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
    $map = [];
    foreach ($groupIds as $gid) {
        $map[$gid] = ['avg_rating' => 0.0, 'review_count' => 0, 'sold_count' => 0];
    }
    if ($groupIds === []) {
        return $map;
    }

    $membersByGroup = printflow_catalog_group_member_product_rows_batch($groupIds);
    foreach ($groupIds as $gid) {
        $members = $membersByGroup[$gid] ?? [];
        if ($members === []) {
            continue;
        }
        $map[$gid] = printflow_catalog_products_aggregate_stats($members);
    }
    return $map;
}

/**
 * Reviews for catalog products (deduped by review id).
 *
 * @param array<int, array{product_id: int, name?: string}> $product_rows
 * @return array<int, array<string, mixed>>
 */
function printflow_catalog_products_reviews_list(array $product_rows): array
{
    $products = [];
    foreach ($product_rows as $row) {
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }
        $products[$productId] = trim((string) ($row['name'] ?? ''));
    }
    if ($products === []) {
        return [];
    }

    $reviewTypes = '';
    $reviewParams = [];
    $reviewSelects = printflow_catalog_product_review_match_selects($products, $reviewTypes, $reviewParams);
    if ($reviewSelects === []) {
        return [];
    }

    $matchRows = db_query(
        "SELECT matched.product_id, matched.review_id, matched.rating
         FROM (
            SELECT raw_matches.product_id, raw_matches.review_id, MAX(raw_matches.rating) AS rating
            FROM (" . implode(' UNION ALL ', $reviewSelects) . ") raw_matches
            GROUP BY raw_matches.product_id, raw_matches.review_id
         ) matched",
        $reviewTypes,
        $reviewParams
    ) ?: [];

    $reviewToProduct = [];
    $reviewIds = [];
    foreach ($matchRows as $row) {
        $reviewId = (int) ($row['review_id'] ?? 0);
        $productId = (int) ($row['product_id'] ?? 0);
        if ($reviewId < 1 || $productId < 1) {
            continue;
        }
        if (!isset($reviewToProduct[$reviewId])) {
            $reviewToProduct[$reviewId] = $productId;
            $reviewIds[] = $reviewId;
        }
    }
    if ($reviewIds === []) {
        return [];
    }

    $reviewCols = array_flip(array_column(db_query('SHOW COLUMNS FROM reviews') ?: [], 'Field'));
    $reviewCustomerExpr = isset($reviewCols['customer_id']) ? 'r.customer_id' : (isset($reviewCols['user_id']) ? 'r.user_id' : '0');
    $reviewCommentExpr = isset($reviewCols['comment']) ? 'r.comment' : (isset($reviewCols['message']) ? 'r.message' : "''");
    $reviewCreatedExpr = isset($reviewCols['created_at']) ? 'r.created_at' : 'NOW()';

    $idPlaceholders = implode(',', array_fill(0, count($reviewIds), '?'));
    $reviewRows = db_query(
        "SELECT r.id, r.rating, {$reviewCommentExpr} AS comment, {$reviewCreatedExpr} AS created_at,
                {$reviewCustomerExpr} AS customer_id, c.first_name, c.last_name, c.profile_picture
         FROM reviews r
         LEFT JOIN customers c ON {$reviewCustomerExpr} = c.customer_id
         WHERE r.id IN ($idPlaceholders)
         ORDER BY {$reviewCreatedExpr} DESC",
        str_repeat('i', count($reviewIds)),
        $reviewIds
    ) ?: [];

    $repliesByReview = [];
    $replyTable = db_query("SHOW TABLES LIKE 'review_replies'") ?: [];
    if ($replyTable !== []) {
        $replyRows = db_query(
            "SELECT rr.review_id, rr.reply_message, rr.created_at, u.first_name, u.last_name
             FROM review_replies rr
             INNER JOIN users u ON u.user_id = rr.staff_id
             WHERE rr.review_id IN ($idPlaceholders)
             ORDER BY rr.created_at ASC",
            str_repeat('i', count($reviewIds)),
            $reviewIds
        ) ?: [];
        foreach ($replyRows as $replyRow) {
            $rid = (int) ($replyRow['review_id'] ?? 0);
            if ($rid < 1) {
                continue;
            }
            $repliesByReview[$rid][] = $replyRow;
        }
    }

    $out = [];
    foreach ($reviewRows as $row) {
        $reviewId = (int) ($row['id'] ?? 0);
        $productId = (int) ($reviewToProduct[$reviewId] ?? 0);
        $row['product_id'] = $productId;
        $row['product_name'] = $products[$productId] ?? '';
        $row['replies'] = $repliesByReview[$reviewId] ?? [];
        $out[] = $row;
    }
    return $out;
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
