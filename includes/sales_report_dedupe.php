<?php
/**
 * Pure sales transaction dedupe helpers (no database bootstrap).
 */

if (defined('PF_SALES_REPORT_DEDUPE_LOADED')) {
    return;
}
define('PF_SALES_REPORT_DEDUPE_LOADED', true);

/**
 * @param list<array<string,mixed>> $transactions
 * @return list<array<string,mixed>>
 */
function pf_reports_deduplicate_official_sales_transactions(array $transactions): array
{
    if ($transactions === []) {
        return [];
    }

    $rank = static function (array $row): int {
        $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
        if ($refType === 'order') {
            return 2;
        }
        if ($refType === 'job') {
            return 1;
        }

        return 0;
    };

    $best = [];
    foreach ($transactions as $row) {
        if (!is_array($row)) {
            continue;
        }
        $storeOrderId = (int)($row['store_order_id'] ?? 0);
        $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
        $id = (int)($row['id'] ?? 0);
        if ($storeOrderId > 0) {
            $key = 'store:' . $storeOrderId;
        } elseif ($refType === 'job' && $id > 0) {
            $key = 'job:' . $id;
        } elseif ($id > 0) {
            $key = 'order:' . $id;
        } else {
            continue;
        }

        if (!isset($best[$key]) || $rank($row) > $rank($best[$key])) {
            $best[$key] = $row;
        }
    }

    $out = array_values($best);
    usort($out, static fn($a, $b) => strcmp((string)($b['sales_date'] ?? ''), (string)($a['sales_date'] ?? '')));

    return $out;
}
