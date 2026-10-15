<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory_business_datetime.php';

if (!function_exists('printflow_pos_can_custom_transaction_datetime')) {
    /** Authorized POS staff may set a business date/time before completing a sale. */
    function printflow_pos_can_custom_transaction_datetime(): bool
    {
        return function_exists('has_role') && has_role(['Admin', 'Staff']);
    }
}

if (!function_exists('printflow_pos_custom_datetime_enabled')) {
    function printflow_pos_custom_datetime_enabled(array $data): bool
    {
        if (!array_key_exists('use_custom_transaction_datetime', $data)) {
            return false;
        }
        $raw = $data['use_custom_transaction_datetime'];
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw === 1;
        }
        $normalized = strtolower(trim((string) $raw));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('printflow_pos_normalize_transaction_time')) {
    function printflow_pos_normalize_transaction_time(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        throw new RuntimeException('Invalid transaction date/time.', 400);
    }
}

if (!function_exists('printflow_pos_manila_now')) {
    function printflow_pos_manila_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }
}

if (!function_exists('printflow_pos_resolve_checkout_transaction_datetime')) {
    /**
     * @return string|null Canonical Y-m-d H:i:s in Manila wall time, or null when not using custom datetime.
     */
    function printflow_pos_resolve_checkout_transaction_datetime(array $data): ?string
    {
        if (!printflow_pos_custom_datetime_enabled($data)) {
            return null;
        }
        if (!printflow_pos_can_custom_transaction_datetime()) {
            throw new RuntimeException('You are not allowed to set a custom transaction date/time.', 403);
        }

        $date = trim((string)($data['custom_transaction_date'] ?? ''));
        $time = trim((string)($data['custom_transaction_time'] ?? ''));
        if ($date === '' || $time === '') {
            throw new RuntimeException('A transaction date and time are required when custom date/time is enabled.', 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Invalid transaction date/time.', 400);
        }

        $time = printflow_pos_normalize_transaction_time($time);
        $stamp = $date . ' ' . $time;
        $normalized = printflow_normalize_business_datetime($stamp);
        if ($normalized === null || $normalized !== $stamp) {
            throw new RuntimeException('Invalid transaction date/time.', 400);
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $normalized,
            new DateTimeZone('Asia/Manila')
        );
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $normalized) {
            throw new RuntimeException('Invalid transaction date/time.', 400);
        }
        if ($parsed > printflow_pos_manila_now()) {
            throw new RuntimeException('Future transaction dates are not allowed.', 400);
        }

        return $normalized;
    }
}

if (!function_exists('printflow_pos_selected_transaction_datetime')) {
    function printflow_pos_selected_transaction_datetime(array $data): string
    {
        $custom = printflow_pos_resolve_checkout_transaction_datetime($data);
        if ($custom !== null) {
            return $custom;
        }
        return printflow_pos_manila_now()->format('Y-m-d H:i:s');
    }
}
