<?php
/**
 * Business datetime helpers for inventory ledger rows.
 *
 * These keep the wall-clock time selected for the sale (Asia/Manila) and do
 * not convert it to UTC. Audit columns such as created_at and deducted_at stay
 * on the real processing clock.
 */

function printflow_normalize_business_datetime(?string $value): ?string
{
    $value = trim(str_replace('T', ' ', (string)$value));
    if ($value === '' || str_starts_with($value, '0000-')) {
        return null;
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?/', $value, $match)) {
        return null;
    }
    $stamp = sprintf(
        '%s %02d:%02d:%02d',
        $match[1],
        (int)($match[2] ?? 0),
        (int)($match[3] ?? 0),
        (int)($match[4] ?? 0)
    );
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$parsed
        || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
    ) {
        return null;
    }
    return $parsed->format('Y-m-d H:i:s');
}

/**
 * A date-only filter must cover the whole Manila calendar day. A full
 * datetime is left unchanged so an exact timestamp search stays exact.
 */
function printflow_ledger_datetime_range_bound(string $value, bool $isEnd): string
{
    $trimmed = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        return $trimmed . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }
    return printflow_normalize_business_datetime($trimmed) ?? $trimmed;
}
