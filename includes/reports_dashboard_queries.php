<?php
/**
 * Shared analytics queries: merge product orders + customization (job_orders)
 * for reports dashboard consistency.
 */
if (defined('REPORTS_DASHBOARD_QUERIES_LOADED')) {
    return;
}
define('REPORTS_DASHBOARD_QUERIES_LOADED', true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/branch_context.php';

/** Simple trend-based 3-month forecast from a historical array. */
function pf_forecast3(array $hist): array {
    $n = count($hist);
    if ($n < 3) return array_fill(0, 3, 0);
    $last3 = array_slice($hist, -3);
    $avg   = array_sum($last3) / 3.0;
    $slope = ($last3[2] - $last3[0]) / 2.0;
    $fore  = [];
    for ($i = 1; $i <= 3; $i++) {
        $fore[] = max(0, (int) round($avg + $slope * $i));
    }
    return $fore;
}

/** Single-step linear regression forecast for revenue/orders. */
function pf_linreg(array $values): float {
    $n = count($values);
    if ($n < 2) return max(0, (float)end($values));
    $sumX = $sumY = $sumXY = $sumXX = 0;
    for ($i = 0; $i < $n; $i++) {
        $sumX += $i; $sumY += $values[$i];
        $sumXY += $i * $values[$i]; $sumXX += $i * $i;
    }
    $d = $n * $sumXX - $sumX * $sumX;
    if ($d == 0) return max(0, array_sum($values) / $n);
    $slope = ($n * $sumXY - $sumX * $sumY) / $d;
    $b     = ($sumY - $slope * $sumX) / $n;
    return max(0, round($b + $slope * $n, 2));
}

/** True if branch filter has any orders or job_orders (lifetime). */
function pf_reports_branch_has_activity($branchId): bool {
    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $c1 = (int) (db_query("SELECT COUNT(*) as c FROM orders o WHERE 1=1$b", $bt ?: null, $bp ?: null)[0]['c'] ?? 0);
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $c2 = (int) (db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE 1=1$bj", $btj ?: null, $bpj ?: null)[0]['c'] ?? 0);
        return ($c1 + $c2) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Safe SQL identifier for pre-defined table aliases used in helper fragments. */
function pf_reports_sql_alias(string $alias, string $fallback): string {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    return $alias !== '' ? $alias : $fallback;
}

/** Resolved sold-item label for product-category charts when no live category exists. */
function pf_product_sales_chart_item_label_sql(
    string $productAlias = 'p',
    string $itemAlias = 'oi'
): string {
    $productAlias = pf_reports_sql_alias($productAlias, 'p');
    $itemAlias = pf_reports_sql_alias($itemAlias, 'oi');

    $catalogNameSql = "CASE
        WHEN LOWER(TRIM(COALESCE({$productAlias}.name, ''))) IN (
            'custom order',
            'customer order',
            'service order',
            'service item',
            'order item',
            'sticker pack',
            'merchandise',
            'pos service item',
            'pos-service item',
            'pos service',
            'pos-service'
        ) THEN NULL
        ELSE NULLIF(TRIM({$productAlias}.name), '')
    END";

    return "COALESCE(
                {$catalogNameSql},
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.product_name'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.service_type'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.product_type'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.name'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.item_name'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.service_name'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.job_title'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.title'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.variant_name'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.variant_label'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.Sintra_Type'))), ''),
                NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.sintra_type'))), ''),
                NULLIF(TRIM({$itemAlias}.sku), ''),
                CASE
                    WHEN COALESCE({$itemAlias}.product_id, 0) > 0 THEN CONCAT('Product #', {$itemAlias}.product_id)
                    ELSE CONCAT('Order Item #', {$itemAlias}.order_item_id)
                END
           )";
}

/**
 * SQL GROUP BY expression for "Sales by Product Category" charts:
 * — Rows with a non-empty catalog `products.category` aggregate under that category.
 * — Uncategorized rows stay under each `product_id` so unrelated products are never merged
 *   via material/name fallbacks (legacy / retired SKUs stay on their own product).
 */
function pf_product_sales_chart_bucket_sql(): string {
    $itemLabel = pf_product_sales_chart_item_label_sql('p', 'oi');
    return "CASE
                WHEN NULLIF(TRIM(p.category), '') IS NOT NULL THEN CONCAT('cat:', TRIM(p.category))
                WHEN COALESCE(oi.product_id, 0) > 0 THEN CONCAT('pid:', oi.product_id)
                ELSE CONCAT('label:', {$itemLabel})
            END";
}

/** SELECT list expression for the human-readable slice label (paired with bucket SQL). */
function pf_product_sales_chart_label_sql(): string {
    $itemLabel = pf_product_sales_chart_item_label_sql('p', 'oi');
    return "MAX(CASE
                   WHEN NULLIF(TRIM(p.category), '') IS NOT NULL THEN TRIM(p.category)
                   ELSE {$itemLabel}
               END)";
}

/** Best-effort family/category inference for legacy product rows with weak labels. */
function pf_product_sales_chart_family_sql(string $productAlias = 'p', string $itemAlias = 'oi'): string {
    $productAlias = pf_reports_sql_alias($productAlias, 'p');
    $itemAlias = pf_reports_sql_alias($itemAlias, 'oi');

    $signalSql = "LOWER(CONCAT_WS(' ',
        COALESCE({$productAlias}.category, ''),
        COALESCE({$productAlias}.name, ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.product_name')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.service_type')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.product_type')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.name')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.item_name')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.service_name')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.job_title')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.title')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.Sintra_Type')), ''),
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$itemAlias}.customization_data, '$.sintra_type')), '')
    ))";

    return "COALESCE(
                NULLIF(TRIM({$productAlias}.category), ''),
                CASE
                    WHEN {$signalSql} LIKE '%sticker%' OR {$signalSql} LIKE '%decal%' THEN 'Stickers'
                    WHEN {$signalSql} LIKE '%sintra%' OR {$signalSql} LIKE '%standee%' THEN 'Sintraboard'
                    WHEN {$signalSql} LIKE '%signage%' OR {$signalSql} LIKE '%reflectorized%' OR {$signalSql} LIKE '%sign%' THEN 'Signage'
                    WHEN {$signalSql} LIKE '%t-shirt%' OR {$signalSql} LIKE '%tshirt%' OR {$signalSql} LIKE '%shirt%' THEN 'T-Shirt'
                    WHEN {$signalSql} LIKE '%tarpaulin%' OR {$signalSql} LIKE '%tarp%' THEN 'Tarpaulin'
                    WHEN {$signalSql} LIKE '%souvenir%' THEN 'Souvenirs'
                    WHEN {$signalSql} LIKE '%layout%' THEN 'Layouts'
                    ELSE ''
                END
           )";
}

/**
 * Branch/date-filtered paid store revenue by category-or-product bucket.
 *
 * @return list<array{category:string,items_sold:int|string,total:float|string}>
 */
function pf_reports_sales_by_product_category(string $from, string $toEnd, $branchId): array {
    $bucket = pf_product_sales_chart_bucket_sql();
    $label = pf_product_sales_chart_label_sql();
    $family = pf_product_sales_chart_family_sql('p', 'oi');
    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $datePart = '';
        $dTypes = '';
        $dParams = [];
        if ($from !== '' && $toEnd !== '') {
            $datePart = ' AND o.order_date BETWEEN ? AND ?';
            $dTypes = 'ss';
            $dParams = [$from, $toEnd];
        } elseif ($from !== '') {
            $datePart = ' AND o.order_date >= ?';
            $dTypes = 's';
            $dParams = [$from];
        } elseif ($toEnd !== '') {
            $datePart = ' AND o.order_date <= ?';
            $dTypes = 's';
            $dParams = [$toEnd];
        }

        $rows = db_query(
            "SELECT {$label} AS category,
                    MAX({$family}) AS family_category,
                    SUM(oi.quantity) AS items_sold,
                    SUM(oi.quantity * oi.unit_price) AS total
             FROM order_items oi
             LEFT JOIN products p ON p.product_id = oi.product_id
             JOIN orders o ON oi.order_id = o.order_id
             WHERE (
                 LOWER(TRIM(COALESCE(o.payment_status, ''))) IN ('paid', 'fully paid')
                 OR o.status = 'Completed'
               )
               {$datePart} {$b}
             GROUP BY {$bucket}
            ORDER BY total DESC",
            $dTypes . $bt,
            array_merge($dParams, $bp)
        ) ?: [];

        $rows = pf_reports_merge_unknown_product_categories($rows);
        return pf_reports_fold_product_category_slices($rows, 8);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Branch/date-filtered service-category revenue and quantity using the same logic
 * as the "Sales by Service Category" chart.
 *
 * @return list<array{category:string,total:float|string,qty_sold:int|string}>
 */
function pf_reports_sales_by_service_category(string $from, string $toEnd, $branchId): array {
    try {
        [$bjsc, $btjsc, $bpjsc] = branch_where_parts('jo', $branchId);

        $jDatePart = '';
        $jdTypes = '';
        $jdParams = [];
        if ($from !== '' && $toEnd !== '') {
            $jDatePart = ' AND jo.created_at BETWEEN ? AND ?';
            $jdTypes = 'ss';
            $jdParams = [$from, $toEnd];
        } elseif ($from !== '') {
            $jDatePart = ' AND jo.created_at >= ?';
            $jdTypes = 's';
            $jdParams = [$from];
        } elseif ($toEnd !== '') {
            $jDatePart = ' AND jo.created_at <= ?';
            $jdTypes = 's';
            $jdParams = [$toEnd];
        }

        $pfServiceCatBucket = "COALESCE(NULLIF(TRIM(sc.category), ''), NULLIF(TRIM(jo.service_type), ''), NULLIF(TRIM(jo.job_title), ''), 'Customization')";
        return db_query(
            "SELECT {$pfServiceCatBucket} AS category,
                    SUM(CASE WHEN (jo.payment_status = 'PAID' OR jo.status = 'COMPLETED')
                             THEN COALESCE(NULLIF(jo.amount_paid, 0), jo.estimated_total, 0) ELSE 0 END) AS total,
                    SUM(CASE WHEN (jo.payment_status = 'PAID' OR jo.status = 'COMPLETED')
                             THEN COALESCE(jo.quantity, 1) ELSE 0 END) AS qty_sold
             FROM job_orders jo
             LEFT JOIN (
                 SELECT LOWER(TRIM(name)) AS name_key,
                        MAX(NULLIF(TRIM(category), '')) AS category
                 FROM services
                 GROUP BY LOWER(TRIM(name))
             ) sc ON sc.name_key = LOWER(TRIM(COALESCE(jo.service_type, '')))
             WHERE 1=1 {$jDatePart} {$bjsc}
             GROUP BY {$pfServiceCatBucket}
             HAVING SUM(CASE WHEN (jo.payment_status = 'PAID' OR jo.status = 'COMPLETED')
                             THEN COALESCE(NULLIF(jo.amount_paid, 0), jo.estimated_total, 0) ELSE 0 END) > 0
                 OR SUM(CASE WHEN (jo.payment_status = 'PAID' OR jo.status = 'COMPLETED')
                             THEN COALESCE(jo.quantity, 1) ELSE 0 END) > 0
             ORDER BY total DESC",
            $jdTypes . $btjsc,
            array_merge($jdParams, $bpjsc)
        ) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Dashboard: same query grain as Reports → "Sales by Product Category", using the
 * default report window (last 30 calendar days through end of today).
 * Matches admin/reports.php when `from`/`to` are not set in the query string.
 *
 * @return list<array{category:string,items_sold:int|string,total:float|string}>
 */
function pf_dashboard_sales_by_product_category($branchId): array {
    $from = date('Y-m-d', strtotime('-30 days'));
    $toEnd = date('Y-m-d') . ' 23:59:59';
    return pf_reports_sales_by_product_category($from, $toEnd, $branchId);
}

/**
 * Keep the largest product-category slices visible and fold the rest into "Other".
 *
 * @param array<int, array<string,mixed>> $rows
 * @return array<int, array<string,mixed>>
 */
function pf_reports_fold_product_category_slices(array $rows, int $maxVisible = 8): array {
    if ($rows === []) {
        return [];
    }

    $maxVisible = max(2, $maxVisible);
    if (count($rows) <= $maxVisible) {
        return $rows;
    }

    usort($rows, static function ($a, $b) {
        return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
    });

    $keepCount = max(1, $maxVisible - 1);
    $keep = array_slice($rows, 0, $keepCount);
    $fold = array_slice($rows, $keepCount);

    $otherTotal = 0.0;
    $otherItems = 0;
    foreach ($fold as $r) {
        $otherTotal += (float)($r['total'] ?? 0);
        $otherItems += (int)($r['items_sold'] ?? 0);
    }

    if ($otherTotal > 0 || $otherItems > 0) {
        $keep[] = [
            'category' => 'More Available Products',
            'items_sold' => $otherItems,
            'total' => $otherTotal,
        ];
    }

    return $keep;
}

/**
 * Merge legacy "Order Item #..." rows into the nearest active catalog category.
 *
 * @param array<int, array<string,mixed>> $rows
 * @return array<int, array<string,mixed>>
 */
function pf_reports_merge_unknown_product_categories(array $rows): array {
    if ($rows === []) {
        return [];
    }

    $catalogCategories = [];
    $catalogProductsByCategory = [];
    try {
        $catRows = db_query(
            "SELECT TRIM(category) AS category, TRIM(name) AS name
             FROM products
             WHERE status = 'Activated'
               AND NULLIF(TRIM(category), '') IS NOT NULL
               AND NULLIF(TRIM(name), '') IS NOT NULL
             ORDER BY category ASC, name ASC"
        ) ?: [];
        foreach ($catRows as $r) {
            $cat = trim((string)($r['category'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            if ($cat !== '') {
                $catalogCategories[] = $cat;
                if (!isset($catalogProductsByCategory[$cat])) {
                    $catalogProductsByCategory[$cat] = [];
                }
                if ($name !== '' && !in_array($name, $catalogProductsByCategory[$cat], true)) {
                    $catalogProductsByCategory[$cat][] = $name;
                }
            }
        }
        $catalogCategories = array_values(array_unique($catalogCategories));
    } catch (Throwable $e) {
    }

    $resolveCategory = static function (string $family) use ($catalogCategories): string {
        $family = trim($family);
        if ($family === '') {
            return '';
        }
        $familyLower = mb_strtolower($family, 'UTF-8');
        foreach ($catalogCategories as $cat) {
            if (mb_strtolower(trim($cat), 'UTF-8') === $familyLower) {
                return $cat;
            }
        }

        $keywordMap = [
            'stickers' => ['sticker', 'decal'],
            'sintraboard' => ['sintra', 'standee'],
            'signage' => ['signage', 'reflectorized', 'sign'],
            't-shirt' => ['t-shirt', 'tshirt', 'shirt'],
            'tarpaulin' => ['tarpaulin', 'tarp'],
            'souvenirs' => ['souvenir'],
            'layouts' => ['layout'],
        ];

        foreach ($catalogCategories as $cat) {
            $catLower = mb_strtolower(trim($cat), 'UTF-8');
            foreach ($keywordMap as $familyKey => $keywords) {
                if ($familyLower !== $familyKey && !in_array($familyLower, $keywords, true)) {
                    continue;
                }
                foreach ($keywords as $kw) {
                    if (str_contains($catLower, $kw)) {
                        return $cat;
                    }
                }
            }
        }

        return $family;
    };

    $labelForResolvedCategory = static function (string $category) use ($catalogProductsByCategory): string {
        $category = trim($category);
        if ($category === '') {
            return 'Available Products';
        }
        $names = $catalogProductsByCategory[$category] ?? [];
        if (count($names) === 1) {
            return (string)$names[0];
        }
        return $category;
    };

    $merged = [];
    $order = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['category'] ?? ''));
        $family = trim((string)($row['family_category'] ?? ''));
        $key = $label;

        if (preg_match('/^(Order Item|Product) #\d+$/i', $label) && $family !== '') {
            $resolved = $resolveCategory($family);
            if ($resolved !== '') {
                $label = $labelForResolvedCategory($resolved);
                $key = $label;
            }
        }

        if (preg_match('/^(Order Item|Product) #\d+$/i', $label)) {
            $label = 'Available Products';
            $key = $label;
        }

        if (!isset($merged[$key])) {
            $row['category'] = $label;
            $merged[$key] = $row;
            $order[] = $key;
            continue;
        }

        $merged[$key]['total'] = (float)($merged[$key]['total'] ?? 0) + (float)($row['total'] ?? 0);
        $merged[$key]['items_sold'] = (int)($merged[$key]['items_sold'] ?? 0) + (int)($row['items_sold'] ?? 0);
    }

    $out = [];
    foreach ($order as $key) {
        $out[] = $merged[$key];
    }

    usort($out, static function ($a, $b) {
        return ((float)($b['total'] ?? 0) <=> (float)($a['total'] ?? 0));
    });

    return $out;
}

/**
 * Merge specific demo service-category rows into one "Other" bucket for dashboards,
 * preserving total revenue and job counts (overall sums stay unchanged).
 *
 * @param array<int, array<string,mixed>> $rows
 * @param array<int, string>              $exactNames case-insensitive match after trim()
 * @return array<int, array<string,mixed>>
 */
function pf_reports_fold_demo_service_categories(array $rows, array $exactNames): array {
    if ($rows === [] || $exactNames === []) {
        return $rows;
    }

    $keySet = [];
    foreach ($exactNames as $n) {
        $k = mb_strtolower(trim((string) $n), 'UTF-8');
        if ($k !== '') {
            $keySet[$k] = true;
        }
    }
    if ($keySet === []) {
        return $rows;
    }

    $mergedRev = 0.0;
    $mergedQty = 0;
    $out = [];

    foreach ($rows as $r) {
        $catRaw = trim((string) ($r['category'] ?? ''));
        if ($catRaw !== '' && isset($keySet[mb_strtolower($catRaw, 'UTF-8')])) {
            $mergedRev += (float) ($r['total'] ?? 0);
            $mergedQty += (int) ($r['qty_sold'] ?? 0);
            continue;
        }
        $out[] = $r;
    }

    if ($mergedRev == 0.0 && $mergedQty === 0) {
        return $out;
    }

    $otherIdx = null;
    foreach ($out as $i => $r) {
        $lbl = mb_strtolower(trim((string) ($r['category'] ?? '')), 'UTF-8');
        if ($lbl === 'other') {
            $otherIdx = $i;
            break;
        }
    }

    if ($otherIdx !== null) {
        $out[$otherIdx]['total'] = (float) ($out[$otherIdx]['total'] ?? 0) + $mergedRev;
        $out[$otherIdx]['qty_sold'] = (int) ($out[$otherIdx]['qty_sold'] ?? 0) + $mergedQty;
    } else {
        $out[] = [
            'category' => 'Other',
            'total' => $mergedRev,
            'qty_sold' => $mergedQty,
        ];
    }

    usort($out, static function ($a, $b) {
        return ((float) ($b['total'] ?? 0) <=> (float) ($a['total'] ?? 0));
    });

    return $out;
}

/**
 * Calendar years (≤ current year) that have at least one paid order line or job order.
 *
 * @return list<int> newest first
 */
function pf_reports_heatmap_available_years($branchId): array {
    $years = [];
    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $rows = db_query(
            "SELECT DISTINCT YEAR(o.order_date) AS y
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.order_id
             WHERE o.payment_status = 'Paid'
               AND YEAR(o.order_date) <= YEAR(CURDATE())$b",
            $bt ?: null,
            $bp ?: null
        ) ?: [];
        foreach ($rows as $r) {
            $years[(int) $r['y']] = true;
        }
    } catch (Throwable $e) {
    }
    try {
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $jrows = db_query(
            "SELECT DISTINCT YEAR(jo.created_at) AS y
             FROM job_orders jo
             WHERE YEAR(jo.created_at) <= YEAR(CURDATE())$bj",
            $btj ?: null,
            $bpj ?: null
        ) ?: [];
        foreach ($jrows as $r) {
            $years[(int) $r['y']] = true;
        }
    } catch (Throwable $e) {
    }
    $out = array_keys($years);
    rsort($out, SORT_NUMERIC);
    return $out;
}

/**
 * Raw monthly qty sums for heatmap year (paid product lines + all job_orders, same as top-services job side).
 * Excludes months after the current month when viewing the current calendar year (DB CURDATE()).
 *
 * @return array<string, array<int,int>> service label => month 1..12 => qty
 */
function pf_reports_heatmap_sums_for_year(int $heatmap_year, $branchId): array {
    $heatmap_products = [];
    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $hmTypes = 'i' . $bt;
        $hmParams = array_merge([$heatmap_year], $bp);
        $hmRaw = db_query(
            "SELECT p.name AS product, MONTH(o.order_date) AS mo, SUM(oi.quantity) AS qty
             FROM order_items oi
             JOIN products p ON oi.product_id = p.product_id
             JOIN orders o ON oi.order_id = o.order_id
             WHERE YEAR(o.order_date) = ?
               AND o.payment_status = 'Paid'
               AND (
                 YEAR(o.order_date) < YEAR(CURDATE())
                 OR MONTH(o.order_date) <= MONTH(CURDATE())
               )$b
             GROUP BY p.product_id, p.name, MONTH(o.order_date)
             ORDER BY p.name, mo",
            $hmTypes,
            $hmParams
        ) ?: [];
        foreach ($hmRaw as $r) {
            $p = (string) $r['product'];
            if (!isset($heatmap_products[$p])) {
                $heatmap_products[$p] = array_fill(1, 12, 0);
            }
            $heatmap_products[$p][(int) $r['mo']] += (int) $r['qty'];
        }
    } catch (Throwable $e) {
    }

    try {
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $jTypes = 'i' . $btj;
        $jParams = array_merge([$heatmap_year], $bpj);
        $jobRaw = db_query(
            "SELECT COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization') AS product,
                    MONTH(jo.created_at) AS mo,
                    SUM(COALESCE(jo.quantity, 1)) AS qty
             FROM job_orders jo
             WHERE YEAR(jo.created_at) = ?
               AND (
                 YEAR(jo.created_at) < YEAR(CURDATE())
                 OR MONTH(jo.created_at) <= MONTH(CURDATE())
               )$bj
             GROUP BY COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization'), MONTH(jo.created_at)
             ORDER BY product, mo",
            $jTypes,
            $jParams
        ) ?: [];
        foreach ($jobRaw as $r) {
            $p = (string) $r['product'];
            if (!isset($heatmap_products[$p])) {
                $heatmap_products[$p] = array_fill(1, 12, 0);
            }
            $heatmap_products[$p][(int) $r['mo']] += (int) $r['qty'];
        }
    } catch (Throwable $e) {
    }

    return $heatmap_products;
}

/**
 * Add per-cell kind: value | empty | future (future = month not elapsed for selected year).
 *
 * @param array<string, array<int,int>> $sumsByProduct
 * @return array<string, array<int, array{qty:int, kind:string}>>
 */
function pf_reports_heatmap_build_cells(array $sumsByProduct, int $heatmap_year): array {
    [$yNow, $mNow] = pf_reports_heatmap_db_ym();
    $out = [];
    foreach ($sumsByProduct as $prod => $mo) {
        $out[$prod] = [];
        for ($m = 1; $m <= 12; $m++) {
            $qty = (int) ($mo[$m] ?? 0);
            if ($heatmap_year > $yNow) {
                $kind = 'future';
                $qty = 0;
            } elseif ($heatmap_year < $yNow) {
                $kind = $qty > 0 ? 'value' : 'empty';
            } elseif ($m > $mNow) {
                $kind = 'future';
                $qty = 0;
            } else {
                $kind = $qty > 0 ? 'value' : 'empty';
            }
            $out[$prod][$m] = ['qty' => $qty, 'kind' => $kind];
        }
    }
    return $out;
}

/**
 * Top 8 services by total units in year (same sums as heatmap; months excluded by SQL for current year).
 *
 * @return array<string, array<int, array{qty:int, kind:string}>>
 */
function pf_reports_heatmap_matrix(int $heatmap_year, $branchId): array {
    $sums = pf_reports_heatmap_sums_for_year($heatmap_year, $branchId);
    if ($sums === []) {
        return [];
    }
    uasort($sums, static function ($a, $b) {
        return array_sum($b) <=> array_sum($a);
    });
    $sums = array_slice($sums, 0, 8, true);
    return pf_reports_heatmap_build_cells($sums, $heatmap_year);
}

/**
 * Top lines: paid product items + paid customization jobs (merged by name).
 *
 * @return list<array{product_id:?int,product_name:string,qty_sold:int,revenue:float}>
 */
function pf_reports_top_products_merged(string $from, string $toEnd, $branchId, int $limit = 10): array {
    $agg = [];

    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $datePart = "";
        $dParams = [];
        $dTypes = "";
        if ($from !== '' && $toEnd !== '') {
            $datePart = " AND o.order_date BETWEEN ? AND ?";
            $dParams = [$from, $toEnd];
            $dTypes = "ss";
        } elseif ($from !== '') {
            $datePart = " AND o.order_date >= ?";
            $dParams = [$from];
            $dTypes = "s";
        } elseif ($toEnd !== '') {
            $datePart = " AND o.order_date <= ?";
            $dParams = [$toEnd];
            $dTypes = "s";
        }

        $rows = db_query(
            "SELECT p.product_id, p.name AS product_name,
                    SUM(oi.quantity) as qty_sold,
                    SUM(oi.quantity * oi.unit_price) as revenue
             FROM order_items oi
             JOIN products p ON oi.product_id = p.product_id
             JOIN orders o ON oi.order_id = o.order_id
             WHERE o.payment_status = 'Paid' {$datePart} {$b}
             GROUP BY p.product_id, p.name",
            $dTypes . $bt,
            array_merge($dParams, $bp)
        ) ?: [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $k = 'p:' . $pid;
            $agg[$k] = [
                'product_id' => $pid,
                'product_name' => (string) $r['product_name'],
                'qty_sold' => (int) $r['qty_sold'],
                'revenue' => (float) $r['revenue'],
            ];
        }
    } catch (Throwable $e) {
    }

    try {
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $jDatePart = "";
        $jdParams = [];
        $jdTypes = "";
        if ($from !== '' && $toEnd !== '') {
            $jDatePart = " AND jo.created_at BETWEEN ? AND ?";
            $jdParams = [$from, $toEnd];
            $jdTypes = "ss";
        } elseif ($from !== '') {
            $jDatePart = " AND jo.created_at >= ?";
            $jdParams = [$from];
            $jdTypes = "s";
        } elseif ($toEnd !== '') {
            $jDatePart = " AND jo.created_at <= ?";
            $jdParams = [$toEnd];
            $jdTypes = "s";
        }

        $jrows = db_query(
            "SELECT COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization') AS svc,
                    SUM(COALESCE(jo.quantity, 1)) as qty_sold,
                    SUM(CASE WHEN jo.payment_status = 'PAID'
                        THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) as revenue
             FROM job_orders jo
             WHERE 1=1 {$jDatePart} {$bj}
             GROUP BY COALESCE(NULLIF(TRIM(jo.service_type), ''), 'Customization')",
            $jdTypes . $btj,
            array_merge($jdParams, $bpj)
        ) ?: [];
        foreach ($jrows as $r) {
            $name = (string) $r['svc'];
            $k = 's:' . mb_strtolower($name);
            $qty = (int) $r['qty_sold'];
            $rev = (float) $r['revenue'];
            if (!isset($agg[$k])) {
                $agg[$k] = [
                    'product_id' => null,
                    'product_name' => $name,
                    'qty_sold' => $qty,
                    'revenue' => $rev,
                ];
            } else {
                $agg[$k]['qty_sold'] += $qty;
                $agg[$k]['revenue'] += $rev;
            }
        }
    } catch (Throwable $e) {
    }

    $list = array_values($agg);
    usort($list, static fn ($a, $b) => $b['qty_sold'] <=> $a['qty_sold']);
    return array_slice($list, 0, $limit);
}

/** @return array<string,bool> */
function pf_reports_customer_table_columns(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (db_query('SHOW COLUMNS FROM customers') ?: [] as $row) {
        if (!empty($row['Field'])) {
            $cache[(string)$row['Field']] = true;
        }
    }
    return $cache;
}

/**
 * SQL expression (customers alias `c`) for city/municipality: structured columns first,
 * then second-to-last comma segment of composite `address` (typical PH layout).
 */
function pf_reports_customer_city_sql_expr(string $alias = 'c'): string {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 'c';
    }
    $cols = pf_reports_customer_table_columns();
    $parts = [];
    foreach (['city', 'municipality', 'town'] as $col) {
        if (!empty($cols[$col])) {
            $parts[] = "NULLIF(TRIM(`{$a}`.`{$col}`), '')";
        }
    }
    $structured = $parts !== []
        ? 'COALESCE(' . implode(', ', $parts) . ')'
        : null;

    $parsedAddress = null;
    if (!empty($cols['address'])) {
        $parsedAddress = "NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT(',', REPLACE(TRIM(`{$a}`.`address`), ', ', ',')), ',', -2), ',', 1)), '')";
    }

    if ($structured !== null && $parsedAddress !== null) {
        return "COALESCE({$structured}, {$parsedAddress})";
    }
    if ($structured !== null) {
        return $structured;
    }
    if ($parsedAddress !== null) {
        return $parsedAddress;
    }
    return 'CAST(NULL AS CHAR)';
}

/**
 * Normalize locality labels for BI maps (merge distant places into nearest branch catchment).
 *
 * @param string $rawCol SQL column reference (e.g. z.city_raw)
 */
function pf_reports_customer_location_city_map_sql(string $rawCol): string {
    return "CASE
                WHEN LOWER(TRIM({$rawCol})) IN ('city of cabuyao', 'cabuyao', 'calauan') THEN 'Cabuyao'
                ELSE TRIM({$rawCol})
            END";
}

/**
 * Roll up customer locality from transaction rows: each store order and each job order counts once.
 * Uses the customer's address fields linked to that transaction (best available city label).
 *
 * @return list<array{city:string, orders:int, revenue?:float}>
 */
function pf_reports_customer_locations_merged(
    string $from,
    string $toEnd,
    $branchId,
    int $limit = 12,
    bool $includeRevenue = false
): array {
    $limit = max(1, min(100, $limit));
    $cityExpr = pf_reports_customer_city_sql_expr('c');

    $oDate = '';
    $joDate = '';
    $dateTypes = '';
    $dateParams = [];
    if ($from !== '' && $toEnd !== '') {
        $oDate = ' AND o.order_date BETWEEN ? AND ? ';
        $joDate = ' AND jo.created_at BETWEEN ? AND ? ';
        $dateTypes = 'ss';
        $dateParams = [$from, $toEnd];
    } elseif ($from !== '') {
        $oDate = ' AND o.order_date >= ? ';
        $joDate = ' AND jo.created_at >= ? ';
        $dateTypes = 's';
        $dateParams = [$from];
    } elseif ($toEnd !== '') {
        $oDate = ' AND o.order_date <= ? ';
        $joDate = ' AND jo.created_at <= ? ';
        $dateTypes = 's';
        $dateParams = [$toEnd];
    }

    [$bo, $bto, $bpo] = branch_where_parts('o', $branchId);
    [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);

    $revO = $includeRevenue ? 'CAST(o.total_amount AS DECIMAL(14,2))' : '0';
    $revJ = $includeRevenue
        ? 'CAST(COALESCE(NULLIF(jo.amount_paid, 0), jo.estimated_total, 0) AS DECIMAL(14,2))'
        : '0';

    $outerAgg = $includeRevenue
        ? 'COUNT(*) AS orders, COALESCE(SUM(x.line_rev), 0) AS revenue'
        : 'COUNT(*) AS orders';

    $cityMap = pf_reports_customer_location_city_map_sql('z.city_raw');

    $sql = "
SELECT TRIM(x.mapped_city) AS city, {$outerAgg}
FROM (
    SELECT {$cityMap} AS mapped_city, z.line_rev
    FROM (
        SELECT {$cityExpr} AS city_raw, {$revO} AS line_rev
        FROM orders o
        INNER JOIN customers c ON c.customer_id = o.customer_id
        WHERE o.customer_id IS NOT NULL
          {$oDate}
          {$bo}
        UNION ALL
        SELECT {$cityExpr} AS city_raw, {$revJ} AS line_rev
        FROM job_orders jo
        INNER JOIN customers c ON c.customer_id = jo.customer_id
        WHERE jo.customer_id IS NOT NULL
          {$joDate}
          {$bj}
    ) z
    WHERE z.city_raw IS NOT NULL
      AND TRIM(z.city_raw) <> ''
      AND CHAR_LENGTH(TRIM(z.city_raw)) > 2
) x
WHERE x.mapped_city IS NOT NULL
  AND TRIM(x.mapped_city) <> ''
  AND CHAR_LENGTH(TRIM(x.mapped_city)) > 2
GROUP BY TRIM(x.mapped_city)
ORDER BY orders DESC
LIMIT {$limit}
";

    $types = $dateTypes . $bto . $dateTypes . $btj;
    $params = array_merge($dateParams, $bpo, $dateParams, $bpj);

    try {
        $rows = db_query($sql, $types !== '' ? $types : null, $params !== [] ? $params : null) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $item = [
            'city' => (string)($r['city'] ?? ''),
            'orders' => (int)($r['orders'] ?? 0),
        ];
        if ($includeRevenue) {
            $item['revenue'] = (float)($r['revenue'] ?? 0);
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * @return list<array{
 *   branch_name:string,
 *   orders:int,
 *   revenue:float,
 *   orders_store:int,
 *   revenue_store:float,
 *   orders_jobs:int,
 *   revenue_jobs:float
 * }>
 */
function pf_reports_branch_performance_merged(string $from, string $toEnd, $branchId = 'all'): array {
    $map = [];
    $names = [];
    $branchIdInt = ($branchId === 'all' || $branchId === null) ? 0 : (int)$branchId;
    $branchWhere = '';
    $branchTypes = '';
    $branchParams = [];
    if ($branchIdInt > 0) {
        $branchWhere = ' AND id = ?';
        $branchTypes = 'i';
        $branchParams = [$branchIdInt];
    }

    // 1. Initialize map with registered branches (archived excluded from reporting)
    try {
        $allBranches = db_query(
            'SELECT id, branch_name FROM branches WHERE status != \'Archived\'' . $branchWhere . ' ORDER BY id',
            $branchTypes ?: null,
            $branchParams ?: null
        ) ?: [];
        foreach ($allBranches as $b) {
            $bid = (int) $b['id'];
            $rawName = (string) $b['branch_name'];
            $names[$bid] = $rawName;
            $map[$bid] = [
                'orders_store' => 0,
                'revenue_store' => 0.0,
                'orders_jobs' => 0,
                'revenue_jobs' => 0.0,
            ];
        }
    } catch (Throwable $e) {
    }

    // 2. Merge Store Orders data
    try {
        $oDatePart = "";
        $odParams = [];
        $odTypes = "";
        if ($from !== '' && $toEnd !== '') {
            $oDatePart = " AND o.order_date BETWEEN ? AND ?";
            $odParams = [$from, $toEnd];
            $odTypes = "ss";
        } elseif ($from !== '') {
            $oDatePart = " AND o.order_date >= ?";
            $odParams = [$from];
            $odTypes = "s";
        } elseif ($toEnd !== '') {
            $oDatePart = " AND o.order_date <= ?";
            $odParams = [$toEnd];
            $odTypes = "s";
        }
        if ($branchIdInt > 0) {
            $oDatePart .= " AND o.branch_id = ?";
            $odParams[] = $branchIdInt;
            $odTypes .= "i";
        } else {
            $oDatePart .= " AND o.branch_id IN (SELECT id FROM branches WHERE status != 'Archived')";
        }

        $oRows = db_query(
            "SELECT o.branch_id,
                    COUNT(*) AS ord,
                    SUM(CASE WHEN o.payment_status = 'Paid' THEN o.total_amount ELSE 0 END) AS rev
             FROM orders o
             WHERE o.branch_id IS NOT NULL {$oDatePart}
             GROUP BY o.branch_id",
            $odTypes,
            $odParams
        ) ?: [];
        foreach ($oRows as $r) {
            $id = (int) $r['branch_id'];
            if (isset($map[$id])) {
                $map[$id]['orders_store'] = (int) $r['ord'];
                $map[$id]['revenue_store'] = (float) $r['rev'];
            }
        }
    } catch (Throwable $e) {
    }

    // 3. Merge Customization Jobs data
    try {
        $jDatePart = "";
        $jdParams = [];
        $jdTypes = "";
        if ($from !== '' && $toEnd !== '') {
            $jDatePart = " AND jo.created_at BETWEEN ? AND ?";
            $jdParams = [$from, $toEnd];
            $jdTypes = "ss";
        } elseif ($from !== '') {
            $jDatePart = " AND jo.created_at >= ?";
            $jdParams = [$from];
            $jdTypes = "s";
        } elseif ($toEnd !== '') {
            $jDatePart = " AND jo.created_at <= ?";
            $jdParams = [$toEnd];
            $jdTypes = "s";
        }
        if ($branchIdInt > 0) {
            $jDatePart .= " AND jo.branch_id = ?";
            $jdParams[] = $branchIdInt;
            $jdTypes .= "i";
        } else {
            $jDatePart .= " AND jo.branch_id IN (SELECT id FROM branches WHERE status != 'Archived')";
        }

        $jRows = db_query(
            "SELECT jo.branch_id,
                    COUNT(*) AS ord,
                    SUM(CASE WHEN jo.payment_status = 'PAID'
                        THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) AS rev
             FROM job_orders jo
             WHERE jo.branch_id IS NOT NULL {$jDatePart}
             GROUP BY jo.branch_id",
            $jdTypes,
            $jdParams
        ) ?: [];
        foreach ($jRows as $r) {
            $id = (int) $r['branch_id'];
            if (isset($map[$id])) {
                $map[$id]['orders_jobs'] += (int) $r['ord'];
                $map[$id]['revenue_jobs'] += (float) $r['rev'];
            }
        }
    } catch (Throwable $e) {
    }

    // 4. Final aggregation and formatting
    $out = [];
    foreach ($map as $bid => $v) {
        $ord = (int) $v['orders_store'] + (int) $v['orders_jobs'];
        $rev = (float) $v['revenue_store'] + (float) $v['revenue_jobs'];

        $rawName = $names[$bid] ?? ('Branch #' . $bid);
        $pretty = function_exists('mb_convert_case')
            ? mb_convert_case(trim($rawName), MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower(trim($rawName)));

        $out[] = [
            'branch_name' => $pretty,
            'orders' => $ord,
            'revenue' => $rev,
            'orders_store' => (int) $v['orders_store'],
            'revenue_store' => (float) $v['revenue_store'],
            'orders_jobs' => (int) $v['orders_jobs'],
            'revenue_jobs' => (float) $v['revenue_jobs'],
        ];
    }

    // Sort by revenue descending (branches with 0 revenue still included at bottom)
    usort($out, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
    return $out;
}

/** Server calendar year/month from DB (matches YEAR/MONTH(CURDATE()) in heatmap SQL). */
function pf_reports_heatmap_db_ym(): array {
    try {
        $r = db_query('SELECT YEAR(CURDATE()) AS y, MONTH(CURDATE()) AS m');
        if (!empty($r[0])) {
            return [(int) $r[0]['y'], (int) $r[0]['m']];
        }
    } catch (Throwable $e) {
    }
    return [(int) date('Y'), (int) date('n')];
}

/** @return list<string> Short month labels (Jan … Dec) for heatmap headers */
function pf_reports_heatmap_month_short_labels(): array {
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $out[] = date('M', mktime(0, 0, 0, $m, 1));
    }
    return $out;
}

/** CSS tier for cells with kind=value: low|med|high based on max value in chart */
function pf_reports_heatmap_value_tier(int $v, int $max_v): string {
    if ($v <= 0 || $max_v <= 0) {
        return 'low';
    }
    $pct = ($v / $max_v) * 100;
    if ($pct <= 25) {
        return 'low';
    }
    if ($pct <= 65) {
        return 'med';
    }
    return 'high';
}

/**
 * HTML/CSS grid heatmap: fixed label column + 12 responsive month columns (no ApexCharts).
 *
 * @param array<string, array<int, array{qty:int, kind:string}>> $cellsByService
 */
function pf_reports_render_heatmap_html(array $cellsByService, int $displayYear): string {
    if ($cellsByService === []) {
        return '';
    }
    $months = pf_reports_heatmap_month_short_labels();
    [$yNow, $mNow] = pf_reports_heatmap_db_ym();
    $h = '<div class="pf-hm-root" id="pf-hm-root">';
    $h .= '<div class="pf-hm-grid" role="grid" aria-label="Seasonal demand by service and month">';
    $h .= '<div class="pf-hm-corner" aria-hidden="true"></div>';
    $h .= '<div class="pf-hm-months" role="row">';
    foreach ($months as $idx => $ml) {
        $mi = $idx + 1;
        $mh = 'pf-hm-month';
        if ($displayYear === $yNow && $mi > $mNow) {
            $mh .= ' pf-hm-month--future';
        }
        $h .= '<div class="' . $mh . '" role="columnheader">' . htmlspecialchars($ml, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $h .= '</div>';

    // Global max for dynamic thresholds
    $max_v = 0;
    foreach ($cellsByService as $prod => $mo) {
        for ($m = 1; $m <= 12; $m++) {
            $max_v = max($max_v, (int)($mo[$m]['qty'] ?? 0));
        }
    }

    foreach ($cellsByService as $prod => $mo) {
        $prodE = htmlspecialchars((string) $prod, ENT_QUOTES, 'UTF-8');
        $h .= '<div class="pf-hm-label-col"><span class="pf-hm-label-text" title="' . $prodE . '">' . $prodE . '</span></div>';
        $h .= '<div class="pf-hm-tiles" role="row">';
        for ($m = 1; $m <= 12; $m++) {
            $cell = $mo[$m] ?? ['qty' => 0, 'kind' => 'empty'];
            $qty = (int) ($cell['qty'] ?? 0);
            $kind = (string) ($cell['kind'] ?? 'empty');
            $ml = $months[$m - 1];
            if ($kind === 'future') {
                $tip = htmlspecialchars("{$prod} · {$ml} — No data yet", ENT_QUOTES, 'UTF-8');
                $h .= '<div class="pf-hm-cell pf-hm-cell--future" role="gridcell" aria-disabled="true" title="' . $tip . '">';
                $h .= '<span class="pf-hm-val"></span></div>';
            } elseif ($kind === 'empty') {
                $tip = htmlspecialchars("{$prod} · {$ml} — No transactions", ENT_QUOTES, 'UTF-8');
                $h .= '<div class="pf-hm-cell pf-hm-cell--nodata" role="gridcell" tabindex="0" title="' . $tip . '">';
                $h .= '<span class="pf-hm-val"></span></div>';
            } else {
                $tier = pf_reports_heatmap_value_tier($qty, $max_v);
                $tip = htmlspecialchars("{$prod} · {$ml} · {$qty} units", ENT_QUOTES, 'UTF-8');
                $h .= '<div class="pf-hm-cell pf-hm-cell--' . $tier . '" role="gridcell" tabindex="0" title="' . $tip . '">';
                $h .= '<span class="pf-hm-val">' . htmlspecialchars((string) $qty, ENT_QUOTES, 'UTF-8') . '</span></div>';
            }
        }
        $h .= '</div>';
    }
    $h .= '</div></div>';
    return $h;
}
/**
 * Daily or monthly sales series for a specific period (from -> toEnd).
 * Merges Store Orders + Customization Jobs.
 *
 * @param string $from  'YYYY-MM-DD'
 * @param string $toEnd 'YYYY-MM-DD 23:59:59'
 * @return array{labels:string[], revStore:float[], revCustom:float[], revTotal:float[], orders:int[]}
 */
function pf_reports_period_sales_merged(string $from, string $toEnd, $branchId): array {
    $labels = []; $revStore = []; $revCustom = []; $revTotal = []; $orders = [];
    
    // Validate inputs
    if (empty($from) && empty($toEnd)) {
        // If no date range specified, use last 30 days as fallback
        $from = date('Y-m-d', strtotime('-30 days'));
        $toEnd = date('Y-m-d') . ' 23:59:59';
    }
    
    // 1. Determine grouping (Daily if < 90 days, else Monthly)
    $tsFrom = strtotime($from ?: '2020-01-01');
    $tsTo   = strtotime($toEnd ?: date('Y-m-d H:i:s'));
    
    if ($tsFrom === false || $tsTo === false) {
        error_log('[PrintFlow] Invalid date format in pf_reports_period_sales_merged: from=' . $from . ', to=' . $toEnd);
        return ['labels' => [], 'revStore' => [], 'revCustom' => [], 'revTotal' => [], 'orders' => []];
    }
    
    $days   = ($tsTo - $tsFrom) / 86400;
    $groupBy = ($days > 90) ? 'MONTH' : 'DAY';
    
    error_log('[PrintFlow] Sales chart query params: from=' . $from . ', to=' . $toEnd . ', branchId=' . $branchId . ', groupBy=' . $groupBy . ', days=' . $days);

    // 2. Fetch Store Orders
    $mapStore = [];
    try {
        [$b, $bt, $bp] = branch_where_parts('o', $branchId);
        $datePart = ""; $dPs = []; $dTs = "";
        if ($from !== '' && $toEnd !== '') {
            $datePart = " AND o.order_date BETWEEN ? AND ?";
            $dPs = [$from, $toEnd]; $dTs = "ss";
        } elseif ($from !== '') {
            $datePart = " AND o.order_date >= ?";
            $dPs = [$from]; $dTs = "s";
        } elseif ($toEnd !== '') {
            $datePart = " AND o.order_date <= ?";
            $dPs = [$toEnd]; $dTs = "s";
        }

        $fmt = ($groupBy === 'MONTH') ? '%Y-%m' : '%Y-%m-%d';
        $sql = "SELECT DATE_FORMAT(o.order_date,'{$fmt}') as d,
                       COUNT(*) as cnt,
                       SUM(CASE WHEN o.payment_status='Paid' THEN o.total_amount ELSE 0 END) as rev
                FROM orders o WHERE 1=1 {$datePart} {$b}
                GROUP BY d ORDER BY d";
        
        error_log('[PrintFlow] Store orders SQL: ' . $sql . ' | Types: ' . ($dTs . $bt) . ' | Params: ' . json_encode(array_merge($dPs, $bp)));
        
        $rows = db_query($sql, $dTs . $bt, array_merge($dPs, $bp)) ?: [];
        error_log('[PrintFlow] Store orders result count: ' . count($rows));
        
        foreach ($rows as $r) {
            $mapStore[$r['d']] = ['cnt' => (int)$r['cnt'], 'rev' => (float)$r['rev']];
        }
    } catch (Throwable $e) {
        error_log('[PrintFlow] Error fetching store orders: ' . $e->getMessage());
    }

    // 3. Fetch Customization Jobs
    $mapJobs = [];
    try {
        [$bj, $btj, $bpj] = branch_where_parts('jo', $branchId);
        $jDatePart = ""; $jdPs = []; $jdTs = "";
        if ($from !== '' && $toEnd !== '') {
            $jDatePart = " AND jo.created_at BETWEEN ? AND ?";
            $jdPs = [$from, $toEnd]; $jdTs = "ss";
        } elseif ($from !== '') {
            $jDatePart = " AND jo.created_at >= ?";
            $jdPs = [$from]; $jdTs = "s";
        } elseif ($toEnd !== '') {
            $jDatePart = " AND jo.created_at <= ?";
            $jdPs = [$toEnd]; $jdTs = "s";
        }

        $fmt = ($groupBy === 'MONTH') ? '%Y-%m' : '%Y-%m-%d';
        $sql = "SELECT DATE_FORMAT(jo.created_at,'{$fmt}') as d,
                       COUNT(*) as cnt,
                       SUM(CASE WHEN jo.payment_status='PAID' THEN COALESCE(jo.amount_paid, jo.estimated_total, 0) ELSE 0 END) as rev
                FROM job_orders jo WHERE 1=1 {$jDatePart} {$bj}
                GROUP BY d ORDER BY d";
        
        $jrows = db_query($sql, $jdTs . $btj, array_merge($jdPs, $bpj)) ?: [];
        error_log('[PrintFlow] Job orders result count: ' . count($jrows));
        
        foreach ($jrows as $r) {
            $mapJobs[$r['d']] = ['cnt' => (int)$r['cnt'], 'rev' => (float)$r['rev']];
        }
    } catch (Throwable $e) {
        error_log('[PrintFlow] Error fetching job orders: ' . $e->getMessage());
    }

    // 4. Generate linear range and fill
    if ($groupBy === 'MONTH') {
        $curr = date('Y-m', $tsFrom);
        $end  = date('Y-m', $tsTo);
        while ($curr <= $end) {
            $labels[] = date('M Y', strtotime($curr . '-01'));
            $s = $mapStore[$curr] ?? ['cnt'=>0,'rev'=>0];
            $j = $mapJobs[$curr] ?? ['cnt'=>0,'rev'=>0];
            $rs = (float)$s['rev'];
            $rj = (float)$j['rev'];
            $revStore[]  = $rs;
            $revCustom[] = $rj;
            $revTotal[]  = $rs + $rj;
            $orders[]    = (int)($s['cnt'] + $j['cnt']);
            $curr = date('Y-m', strtotime($curr . '-01 +1 month'));
        }
    } else {
        // Daily
        $curr = date('Y-m-d', $tsFrom);
        $end  = date('Y-m-d', $tsTo);
        while ($curr <= $end) {
            $labels[] = date('M d', strtotime($curr));
            $s = $mapStore[$curr] ?? ['cnt'=>0,'rev'=>0];
            $j = $mapJobs[$curr] ?? ['cnt'=>0,'rev'=>0];
            $rs = (float)$s['rev'];
            $rj = (float)$j['rev'];
            $revStore[]  = $rs;
            $revCustom[] = $rj;
            $revTotal[]  = $rs + $rj;
            $orders[]    = (int)($s['cnt'] + $j['cnt']);
            $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
        }
    }
    
    $result = [
        'labels'    => $labels,
        'revStore'  => $revStore,
        'revCustom' => $revCustom,
        'revTotal'  => $revTotal,
        'orders'    => $orders
    ];
    
    error_log('[PrintFlow] Final sales chart result: ' . json_encode([
        'labels_count' => count($labels),
        'sample_labels' => array_slice($labels, 0, 3),
        'revStore_sum' => array_sum($revStore),
        'revCustom_sum' => array_sum($revCustom),
        'orders_sum' => array_sum($orders)
    ]));

    return $result;
}
