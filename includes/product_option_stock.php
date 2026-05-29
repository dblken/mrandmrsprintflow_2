<?php
/**
 * Per-option stock for configured product fields such as Size / Variant.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_field_config_helper.php';
require_once __DIR__ . '/product_branch_stock.php';

function printflow_ensure_product_option_stock_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_execute(
        "CREATE TABLE IF NOT EXISTS product_option_stock (
            option_stock_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            branch_id INT NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            option_value VARCHAR(255) NOT NULL,
            stock_quantity INT NOT NULL DEFAULT 0,
            low_stock_level INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_product_option_branch (product_id, branch_id, field_key, option_value),
            KEY idx_product_branch (product_id, branch_id),
            KEY idx_branch_field (branch_id, field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function printflow_product_option_stock_normalize_value(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function printflow_product_stock_bearing_field_keywords(): array
{
    return ['size', 'sizes', 'variant', 'variants'];
}

function printflow_product_resolve_stock_field_config(int $productId): ?array
{
    $configs = get_product_field_config($productId);
    if (empty($configs)) {
        return null;
    }

    $best = null;
    $bestScore = -1;
    foreach ($configs as $fieldKey => $config) {
        $type = strtolower(trim((string)($config['type'] ?? '')));
        if (!in_array($type, ['select', 'radio', 'dimension'], true)) {
            continue;
        }

        $options = array_values(array_filter(array_map(static function ($option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
            return printflow_product_option_stock_normalize_value($value);
        }, (array)($config['options'] ?? []))));

        if (empty($options)) {
            continue;
        }

        $label = strtolower(trim((string)($config['label'] ?? '')));
        $key = strtolower(trim((string)$fieldKey));
        $score = 0;
        foreach (printflow_product_stock_bearing_field_keywords() as $keyword) {
            if (strpos($label, $keyword) !== false) {
                $score += ($keyword === 'size') ? 20 : 10;
            }
            if (strpos($key, $keyword) !== false) {
                $score += ($keyword === 'size') ? 16 : 8;
            }
        }
        if ($type === 'dimension') {
            $score += 4;
        }
        if (!empty($config['required'])) {
            $score += 2;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'field_key' => (string)$fieldKey,
                'field_label' => (string)($config['label'] ?? $fieldKey),
                'field_type' => $type,
                'options' => $options,
                'config' => $config,
            ];
        }
    }

    return $bestScore > 0 ? $best : null;
}

function printflow_product_has_option_stock_config(int $productId): bool
{
    return printflow_product_resolve_stock_field_config($productId) !== null;
}

function printflow_product_option_stock_rows(int $productId, int $branchId): array
{
    printflow_ensure_product_option_stock_table();
    if ($productId <= 0 || $branchId <= 0) {
        return [];
    }

    return db_query(
        "SELECT field_key, option_value, stock_quantity, low_stock_level
         FROM product_option_stock
         WHERE product_id = ? AND branch_id = ?
         ORDER BY option_value ASC",
        'ii',
        [$productId, $branchId]
    ) ?: [];
}

function printflow_product_option_stock_map(int $productId, int $branchId): array
{
    $field = printflow_product_resolve_stock_field_config($productId);
    if ($field === null || $branchId <= 0) {
        return [];
    }

    $rows = printflow_product_option_stock_rows($productId, $branchId);
    $byOption = [];
    foreach ($rows as $row) {
        $optionValue = printflow_product_option_stock_normalize_value((string)($row['option_value'] ?? ''));
        if ($optionValue === '') {
            continue;
        }
        $byOption[$optionValue] = [
            'stock_quantity' => (int)($row['stock_quantity'] ?? 0),
            'low_stock_level' => (int)($row['low_stock_level'] ?? 0),
        ];
    }

    $result = [];
    foreach ($field['options'] as $optionValue) {
        $resolved = $byOption[$optionValue] ?? ['stock_quantity' => 0, 'low_stock_level' => 0];
        $result[] = [
            'field_key' => $field['field_key'],
            'field_label' => $field['field_label'],
            'option_value' => $optionValue,
            'stock_quantity' => (int)$resolved['stock_quantity'],
            'low_stock_level' => (int)$resolved['low_stock_level'],
        ];
    }

    return $result;
}

function printflow_product_option_stock_has_rows(int $productId, int $branchId): bool
{
    if ($productId <= 0 || $branchId <= 0) {
        return false;
    }
    $rows = db_query(
        "SELECT COUNT(*) AS cnt
         FROM product_option_stock
         WHERE product_id = ? AND branch_id = ?",
        'ii',
        [$productId, $branchId]
    ) ?: [];

    return (int)($rows[0]['cnt'] ?? 0) > 0;
}

function printflow_product_option_stock_total(int $productId, int $branchId): ?array
{
    $field = printflow_product_resolve_stock_field_config($productId);
    if ($field === null || $branchId <= 0) {
        return null;
    }

    $rows = printflow_product_option_stock_map($productId, $branchId);
    if (empty($rows) && !printflow_product_option_stock_has_rows($productId, $branchId)) {
        return null;
    }

    $stock = 0;
    $low = 0;
    foreach ($rows as $row) {
        $stock += (int)($row['stock_quantity'] ?? 0);
        $low += (int)($row['low_stock_level'] ?? 0);
    }

    return [
        'field_key' => $field['field_key'],
        'field_label' => $field['field_label'],
        'total_stock' => $stock,
        'total_low_stock' => $low,
        'options' => $rows,
    ];
}

function printflow_product_option_stock_upsert(
    int $productId,
    int $branchId,
    string $fieldKey,
    string $optionValue,
    int $stockQty,
    int $lowLevel
): bool {
    printflow_ensure_product_option_stock_table();
    $optionValue = printflow_product_option_stock_normalize_value($optionValue);
    $fieldKey = trim($fieldKey);
    if ($productId <= 0 || $branchId <= 0 || $fieldKey === '' || $optionValue === '' || $stockQty < 0 || $lowLevel < 0) {
        return false;
    }

    $result = db_execute(
        "INSERT INTO product_option_stock (product_id, branch_id, field_key, option_value, stock_quantity, low_stock_level)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity), low_stock_level = VALUES(low_stock_level)",
        'iissii',
        [$productId, $branchId, $fieldKey, $optionValue, $stockQty, $lowLevel]
    );

    return $result !== false;
}

function printflow_product_option_stock_find_selected_value(int $productId, array $customization): ?array
{
    $field = printflow_product_resolve_stock_field_config($productId);
    if ($field === null) {
        return null;
    }

    $candidates = [
        $customization[$field['field_key']] ?? null,
        $customization[$field['field_label']] ?? null,
    ];

    foreach ($customization as $key => $value) {
        $keyNorm = strtolower(trim((string)$key));
        if (in_array($keyNorm, printflow_product_stock_bearing_field_keywords(), true)) {
            $candidates[] = $value;
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = printflow_product_option_stock_normalize_value((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        $plainCandidate = preg_replace('/\s+(ft|in|cm)$/i', '', $candidate) ?? $candidate;
        $plainCandidate = printflow_product_option_stock_normalize_value($plainCandidate);
        foreach ($field['options'] as $optionValue) {
            if (strcasecmp($optionValue, $plainCandidate) === 0 || strcasecmp($optionValue, $candidate) === 0) {
                return [
                    'field_key' => $field['field_key'],
                    'field_label' => $field['field_label'],
                    'option_value' => $optionValue,
                ];
            }
        }
    }

    return null;
}

function printflow_product_option_stock_validate(
    int $productId,
    int $branchId,
    array $customization,
    int $requestedQty
): array {
    $selection = printflow_product_option_stock_find_selected_value($productId, $customization);
    if ($selection === null) {
        return ['uses_option_stock' => false, 'ok' => true];
    }

    if (!printflow_product_option_stock_has_rows($productId, $branchId)) {
        return ['uses_option_stock' => false, 'ok' => true];
    }

    $rows = printflow_product_option_stock_map($productId, $branchId);
    $current = null;
    foreach ($rows as $row) {
        if (strcasecmp((string)$row['option_value'], (string)$selection['option_value']) === 0) {
            $current = $row;
            break;
        }
    }

    $available = (int)($current['stock_quantity'] ?? 0);
    $requestedQty = max(1, $requestedQty);
    if ($available < $requestedQty) {
        return [
            'uses_option_stock' => true,
            'ok' => false,
            'selected_option' => $selection['option_value'],
            'available' => $available,
            'message' => "Only {$available} stock left for {$selection['option_value']} size.",
        ];
    }

    return [
        'uses_option_stock' => true,
        'ok' => true,
        'selected_option' => $selection['option_value'],
        'available' => $available,
    ];
}

function printflow_product_option_stock_deduct(
    int $productId,
    int $branchId,
    array $customization,
    int $qty
): array {
    $selection = printflow_product_option_stock_find_selected_value($productId, $customization);
    if ($selection === null || !printflow_product_option_stock_has_rows($productId, $branchId)) {
        return ['handled' => false, 'success' => false];
    }

    $currentRows = printflow_product_option_stock_map($productId, $branchId);
    $currentRow = null;
    foreach ($currentRows as $row) {
        if (strcasecmp((string)$row['option_value'], (string)$selection['option_value']) === 0) {
            $currentRow = $row;
            break;
        }
    }

    $oldStock = (int)($currentRow['stock_quantity'] ?? 0);
    $lowLevel = (int)($currentRow['low_stock_level'] ?? 0);
    $qty = max(1, $qty);
    if ($oldStock < $qty) {
        return [
            'handled' => true,
            'success' => false,
            'message' => "Only {$oldStock} stock left for {$selection['option_value']} size.",
        ];
    }

    $result = db_execute(
        "UPDATE product_option_stock
         SET stock_quantity = stock_quantity - ?
         WHERE product_id = ? AND branch_id = ? AND field_key = ? AND option_value = ? AND stock_quantity >= ?",
        'iiissi',
        [$qty, $productId, $branchId, $selection['field_key'], $selection['option_value'], $qty]
    );

    return [
        'handled' => true,
        'success' => $result !== false,
        'field_key' => $selection['field_key'],
        'field_label' => $selection['field_label'],
        'option_value' => $selection['option_value'],
        'previous_stock' => $oldStock,
        'new_stock' => $oldStock - $qty,
        'low_stock_level' => $lowLevel,
    ];
}
