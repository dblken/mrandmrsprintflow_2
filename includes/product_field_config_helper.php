<?php
/**
 * Product Field Configuration Helper
 * Manages configurable custom fields for fixed products.
 */

function printflow_ensure_product_field_config_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db_execute(
        "CREATE TABLE IF NOT EXISTS product_field_configs (
            config_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            field_key VARCHAR(100) NOT NULL,
            field_label VARCHAR(255) DEFAULT NULL,
            field_type ENUM('select', 'radio', 'file', 'textarea', 'dimension') NOT NULL,
            field_options JSON DEFAULT NULL,
            is_visible TINYINT(1) DEFAULT 1,
            is_required TINYINT(1) DEFAULT 1,
            default_value VARCHAR(255) DEFAULT NULL,
            unit VARCHAR(20) DEFAULT 'ft',
            allow_others TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_product_field (product_id, field_key),
            KEY idx_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

function get_product_field_config(int $product_id): array
{
    printflow_ensure_product_field_config_table();

    $rows = db_query(
        "SELECT * FROM product_field_configs WHERE product_id = ? ORDER BY display_order ASC, config_id ASC",
        'i',
        [$product_id]
    ) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $result[$row['field_key']] = [
            'label' => (string)($row['field_label'] ?? ''),
            'type' => (string)($row['field_type'] ?? 'textarea'),
            'options' => !empty($row['field_options']) ? (json_decode((string)$row['field_options'], true) ?: []) : [],
            'visible' => (bool)($row['is_visible'] ?? 0),
            'required' => (bool)($row['is_required'] ?? 0),
            'default' => $row['default_value'] ?? null,
            'unit' => (string)($row['unit'] ?? 'ft'),
            'allow_others' => isset($row['allow_others']) ? (bool)$row['allow_others'] : true,
            'order' => (int)($row['display_order'] ?? 0),
        ];
    }

    return $result;
}

function product_has_field_config(int $product_id): bool
{
    printflow_ensure_product_field_config_table();
    $row = db_query(
        "SELECT COUNT(*) AS cnt FROM product_field_configs WHERE product_id = ?",
        'i',
        [$product_id]
    );

    return ((int)($row[0]['cnt'] ?? 0)) > 0;
}

function save_product_field_config(int $product_id, string $field_key, array $config): void
{
    printflow_ensure_product_field_config_table();

    $existing = db_query(
        "SELECT config_id FROM product_field_configs WHERE product_id = ? AND field_key = ? LIMIT 1",
        'is',
        [$product_id, $field_key]
    );

    $options_json = isset($config['options']) ? json_encode($config['options']) : null;
    $unit = (string)($config['unit'] ?? 'ft');
    $allow_others = !array_key_exists('allow_others', $config) || !empty($config['allow_others']) ? 1 : 0;

    if (!empty($existing)) {
        db_execute(
            "UPDATE product_field_configs
             SET field_label = ?, field_type = ?, field_options = ?, is_visible = ?, is_required = ?,
                 default_value = ?, unit = ?, allow_others = ?, display_order = ?, updated_at = NOW()
             WHERE product_id = ? AND field_key = ?",
            'sssiissiiis',
            [
                $config['label'],
                $config['type'],
                $options_json,
                !empty($config['visible']) ? 1 : 0,
                !empty($config['required']) ? 1 : 0,
                $config['default'] ?? null,
                $unit,
                $allow_others,
                (int)($config['order'] ?? 0),
                $product_id,
                $field_key,
            ]
        );
        return;
    }

    db_execute(
        "INSERT INTO product_field_configs
         (product_id, field_key, field_label, field_type, field_options, is_visible, is_required, default_value, unit, allow_others, display_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'issssiissii',
        [
            $product_id,
            $field_key,
            $config['label'],
            $config['type'],
            $options_json,
            !empty($config['visible']) ? 1 : 0,
            !empty($config['required']) ? 1 : 0,
            $config['default'] ?? null,
            $unit,
            $allow_others,
            (int)($config['order'] ?? 0),
        ]
    );
}

function delete_product_field_config(int $product_id, string $field_key): void
{
    printflow_ensure_product_field_config_table();
    db_execute(
        "DELETE FROM product_field_configs WHERE product_id = ? AND field_key = ?",
        'is',
        [$product_id, $field_key]
    );
}

function printflow_product_field_normalize_option_rows(array $options, string $type): array
{
    $normalized = [];
    foreach ($options as $option) {
        if (is_array($option)) {
            $value = trim((string)($option['value'] ?? ''));
            $price = (float)($option['price'] ?? 0);
        } else {
            $value = trim((string)$option);
            $price = 0.0;
        }

        if ($value === '') {
            continue;
        }

        $normalized[] = [
            'value' => $value,
            'price' => max(0, $price),
        ];
    }

    if ($type === 'dimension') {
        usort($normalized, static function ($a, $b) {
            return strcmp((string)$a['value'], (string)$b['value']);
        });
    }

    return $normalized;
}
