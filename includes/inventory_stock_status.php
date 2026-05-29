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
                "ALTER TABLE inv_items ADD COLUMN critical_level DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER reorder_level"
            );
            db_execute(
                "UPDATE inv_items
                 SET critical_level = GREATEST(1, CEIL(reorder_level * 0.25))
                 WHERE reorder_level > 0"
            );
        }
    } catch (Throwable $e) {
        error_log('printflow_ensure_inv_items_threshold_schema: ' . $e->getMessage());
    }
}

function printflow_suggest_reorder_level(float $quantity): int {
    $qty = max(0, $quantity);
    if ($qty <= 0) {
        return 1;
    }
    return max(1, (int)ceil($qty * 0.20));
}

function printflow_suggest_critical_level(float $quantity): int {
    $qty = max(0, $quantity);
    if ($qty <= 0) {
        return 1;
    }
    return max(1, (int)ceil($qty * 0.05));
}

function printflow_item_critical_level(array $item): float {
    if (array_key_exists('critical_level', $item) && $item['critical_level'] !== null && $item['critical_level'] !== '') {
        return max(1.0, (float)$item['critical_level']);
    }
    $reorder = (float)($item['reorder_level'] ?? 0);
    if ($reorder >= 1) {
        return (float)max(1, (int)ceil($reorder * 0.25));
    }
    return 1.0;
}

function printflow_item_reorder_level(array $item): float {
    return max(1.0, (float)($item['reorder_level'] ?? 1));
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
    $reorder = max(1, $reorderLevel);
    $critical = max(1, min($criticalLevel, $reorder));

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
            'text_color' => '#7f1d1d',
            'bg_color' => '#f3f4f6',
            'border_color' => '#d1d5db',
            'row_class' => 'low-stock-row stock-status-out',
        ];
    }

    if ($qty <= $critical) {
        return [
            'key' => 'critical',
            'label' => 'Critical',
            'text_color' => '#991b1b',
            'bg_color' => '#fef2f2',
            'border_color' => '#fecaca',
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
