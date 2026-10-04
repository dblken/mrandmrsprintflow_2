<?php
/**
 * Product checkout order-information notice helpers.
 */

if (!defined('PRINTFLOW_PRODUCT_ORDER_NOTICE_DEFAULT')) {
    define('PRINTFLOW_PRODUCT_ORDER_NOTICE_DEFAULT', 'Ready-made product orders are for pickup only. Once your order is placed, it can no longer be cancelled. If you have questions or special concerns, you may message our staff after checkout.');
}

if (!defined('PRINTFLOW_PRODUCT_ORDER_NOTICE_MAX_LENGTH')) {
    define('PRINTFLOW_PRODUCT_ORDER_NOTICE_MAX_LENGTH', 1000);
}

function printflow_product_order_notice_default(): string {
    return PRINTFLOW_PRODUCT_ORDER_NOTICE_DEFAULT;
}

function printflow_ensure_product_order_notice_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!function_exists('db_table_has_column') || !function_exists('db_execute')) {
        return;
    }

    if (!db_table_has_column('products', 'order_information_notice')) {
        db_execute(
            'ALTER TABLE products ADD COLUMN order_information_notice TEXT NULL AFTER description'
        );
        db_table_has_column('products', 'order_information_notice', true);
    }

    if (!db_table_has_column('products', 'order_information_notice_enabled')) {
        db_execute(
            'ALTER TABLE products ADD COLUMN order_information_notice_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER order_information_notice'
        );
        db_table_has_column('products', 'order_information_notice_enabled', true);
    }
}

function printflow_product_order_notice_clean($value): array {
    $text = str_replace(["\r\n", "\r"], "\n", strip_tags((string)$value));
    $text = trim($text);

    if (function_exists('mb_strlen')) {
        $length = mb_strlen($text, 'UTF-8');
    } else {
        $length = strlen($text);
    }

    if ($length > PRINTFLOW_PRODUCT_ORDER_NOTICE_MAX_LENGTH) {
        return [
            'ok' => false,
            'value' => $text,
            'message' => 'Order Information notice must not exceed ' . PRINTFLOW_PRODUCT_ORDER_NOTICE_MAX_LENGTH . ' characters.',
        ];
    }

    return [
        'ok' => true,
        'value' => $text,
        'message' => '',
    ];
}

function printflow_product_order_notice_display($value): string {
    $text = trim((string)$value);
    return $text !== '' ? $text : printflow_product_order_notice_default();
}

function printflow_product_order_notice_is_enabled($value): bool {
    return (int)$value === 1;
}

function printflow_product_order_notice_for_customer($enabled, $value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return printflow_product_order_notice_default();
    }
    if (!printflow_product_order_notice_is_enabled($enabled)) {
        return '';
    }
    return printflow_product_order_notice_display($text);
}
