<?php
/**
 * Inventory stock status and threshold helpers.
 *
 * Status priority (when stock exists):
 *   Out of Stock (qty = 0)
 *   Critical     (qty <= critical level)
 *   Low Stock    (qty <= reorder level)
 *   In Stock     (otherwise)
 */

function printflow_ensure_inv_items_threshold_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $cols = db_query("SHOW COLUMNS FROM inv_items LIKE 'critical_level'") ?: [];
        if (empty($cols)) {
            db_execute(
                "ALTER TABLE inv_items ADD COLUMN critical_level DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER reorder_level"
            );
            db_execute(
                "UPDATE inv_items
                 SET critical_level = GREATEST(0, CEIL(reorder_level * 0.25))
                 WHERE reorder_level > 0"
            );
        }
        $alertCols = db_query("SHOW COLUMNS FROM inv_items LIKE 'last_stock_alert_key'") ?: [];
        if (empty($alertCols)) {
            db_execute(
                "ALTER TABLE inv_items ADD COLUMN last_stock_alert_key VARCHAR(20) NULL DEFAULT NULL AFTER critical_level"
            );
        }
    } catch (Throwable $e) {
        error_log('printflow_ensure_inv_items_threshold_schema: ' . $e->getMessage());
    }
}

function printflow_suggest_reorder_level(float $quantity): int {
    $qty = max(0, $quantity);
    if ($qty <= 0) {
        return 0;
    }
    return (int)ceil($qty * 0.20);
}

function printflow_suggest_critical_level(float $quantity): int {
    $qty = max(0, $quantity);
    if ($qty <= 0) {
        return 0;
    }
    return (int)ceil($qty * 0.05);
}

/**
 * Live thresholds from current quantity (20% / 5% with ceiling, minimum 1).
 *
 * @return array{reorder:float,critical:float}
 */
function printflow_thresholds_for_quantity(float $quantity): array {
    return [
        'reorder' => (float)printflow_suggest_reorder_level($quantity),
        'critical' => (float)printflow_suggest_critical_level($quantity),
    ];
}

/** Stored reorder policy from inv_items (does not change with stock movements). */
function printflow_item_stored_reorder_level(array $item): float {
    return max(0, (float)($item['reorder_level'] ?? 0));
}

/** Stored critical policy from inv_items (does not change with stock movements). */
function printflow_item_stored_critical_level(array $item): float {
    return max(0, (float)($item['critical_level'] ?? 0));
}

/** @deprecated Use printflow_item_stored_critical_level() for policy evaluation. */
function printflow_item_critical_level(array $item): float {
    return printflow_item_stored_critical_level($item);
}

/** @deprecated Use printflow_item_stored_reorder_level() for policy evaluation. */
function printflow_item_reorder_level(array $item): float {
    return printflow_item_stored_reorder_level($item);
}

/**
 * @return array{reorder:float,critical:float,status:array}
 */
function printflow_item_stock_status(array $item, float $currentStock, bool $isNewItemWithoutStock = false): array {
    $reorder = printflow_item_stored_reorder_level($item);
    $critical = printflow_item_stored_critical_level($item);
    $status = printflow_resolve_stock_status(
        $currentStock,
        $reorder,
        $critical,
        $isNewItemWithoutStock
    );
    return [
        'reorder' => $reorder,
        'critical' => $critical,
        'status' => $status,
    ];
}

/**
 * Validate configured inventory policy thresholds.
 */
function printflow_validate_item_thresholds(float $reorderLevel, float $criticalLevel): ?string {
    if ($reorderLevel < 0 || $criticalLevel < 0) {
        return 'Reorder Level and Critical Level must be zero or greater.';
    }
    if ($reorderLevel > 0 && $criticalLevel >= $reorderLevel) {
        return 'Critical Level must be lower than Reorder Level.';
    }
    return null;
}

/** Apply suggested thresholds from a reference quantity (Add Material / explicit reset only). */
function printflow_apply_suggested_item_thresholds(int $itemId, float $referenceQuantity): array {
    $thresholds = printflow_thresholds_for_quantity($referenceQuantity);
    db_execute(
        'UPDATE inv_items SET reorder_level = ?, critical_level = ? WHERE id = ?',
        'ddi',
        [$thresholds['reorder'], $thresholds['critical'], $itemId]
    );
    return $thresholds;
}

/** Persist computed thresholds after quantity changes (cache for reporting). */
function printflow_sync_item_thresholds(int $itemId, float $quantity): void {
    printflow_apply_suggested_item_thresholds($itemId, $quantity);
}

/** Notification copy for stock alert tiers. */
function printflow_stock_alert_message(array $item, string $statusKey): string {
    $name = trim((string)($item['name'] ?? 'Material'));
    switch ($statusKey) {
        case 'low':
            return "{$name} has reached the reorder level.";
        case 'critical':
            return "{$name} is below the critical level. Immediate replenishment is recommended.";
        case 'out':
            return "{$name} is out of stock.";
        default:
            return '';
    }
}

/**
 * Evaluate stock against stored policy; notify only when alert tier changes.
 */
function printflow_evaluate_stock_alert_notification(array $item, int $itemId, float $currentStock): void {
    $reorder = printflow_item_stored_reorder_level($item);
    $critical = printflow_item_stored_critical_level($item);
    $status = printflow_resolve_stock_status($currentStock, $reorder, $critical);
    $alertKey = in_array($status['key'], ['low', 'critical', 'out'], true) ? $status['key'] : 'in';
    $prevKey = trim((string)($item['last_stock_alert_key'] ?? 'in'));
    if ($prevKey === '') {
        $prevKey = 'in';
    }

    if ($alertKey === $prevKey) {
        return;
    }

    db_execute(
        'UPDATE inv_items SET last_stock_alert_key = ? WHERE id = ?',
        'si',
        [$alertKey, $itemId]
    );

    if ($alertKey === 'in' || !function_exists('notify_shop_users')) {
        return;
    }

    $msg = printflow_stock_alert_message($item, $alertKey);
    if ($msg !== '') {
        notify_shop_users($msg, 'Stock', false, false, $itemId, ['Admin', 'Manager']);
    }
}

/**
 * @return array{
 *   key:string,
 *   label:string,
 *   text_color:string,
 *   bg_color:string,
 *   border_color:string,
 *   row_class:string
 * }
 */
function printflow_resolve_stock_status(
    float $quantity,
    float $reorderLevel,
    float $criticalLevel,
    bool $isNewItemWithoutStock = false
): array {
    $qty = max(0, $quantity);
    $reorder = max(0, $reorderLevel);
    $critical = max(0, $criticalLevel);

    if ($isNewItemWithoutStock && $qty <= 0) {
        return [
            'key' => 'no_stock_yet',
            'label' => 'No stock added yet.',
            'text_color' => '#6b7280',
            'bg_color' => '#f3f4f6',
            'border_color' => '#e5e7eb',
            'row_class' => '',
        ];
    }

    if ($qty <= 0) {
        return [
            'key' => 'out',
            'label' => 'Out of Stock',
            'text_color' => '#991b1b',
            'bg_color' => '#fef2f2',
            'border_color' => '#fecaca',
            'row_class' => 'low-stock-row stock-status-out',
        ];
    }

    if ($qty <= $critical) {
        return [
            'key' => 'critical',
            'label' => 'Critical',
            'text_color' => '#c2410c',
            'bg_color' => '#fff7ed',
            'border_color' => '#fdba74',
            'row_class' => 'low-stock-row stock-status-critical',
        ];
    }

    if ($qty <= $reorder) {
        return [
            'key' => 'low',
            'label' => 'Low Stock',
            'text_color' => '#92400e',
            'bg_color' => '#fef3c7',
            'border_color' => '#fde68a',
            'row_class' => 'low-stock-row stock-status-low',
        ];
    }

    return [
        'key' => 'in',
        'label' => 'In Stock',
        'text_color' => '#166534',
        'bg_color' => '#dcfce7',
        'border_color' => '#bbf7d0',
        'row_class' => 'stock-status-in',
    ];
}

function printflow_stock_status_badge_html(array $status, bool $withMargin = true): string {
    $margin = $withMargin ? 'margin-left:8px;' : '';
    if ($status['key'] === 'no_stock_yet') {
        return '';
    }
    return sprintf(
        '<span class="pf-stock-status-badge pf-stock-status-%s" style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:%s;color:%s;border:1px solid %s;%s">%s</span>',
        htmlspecialchars($status['key'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($status['bg_color'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($status['text_color'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($status['border_color'], ENT_QUOTES, 'UTF-8'),
        $margin,
        htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8')
    );
}

function printflow_stock_matches_filter(array $status, string $filter): bool {
    $filter = strtolower(trim($filter));
    if ($filter === '') {
        return true;
    }
    if ($filter === 'in') {
        return $status['key'] === 'in';
    }
    if ($filter === 'low') {
        return $status['key'] === 'low';
    }
    if ($filter === 'critical') {
        return $status['key'] === 'critical';
    }
    if ($filter === 'out') {
        return $status['key'] === 'out';
    }
    return true;
}
