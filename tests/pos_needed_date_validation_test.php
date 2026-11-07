<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/inventory_business_datetime.php';
require_once __DIR__ . '/../includes/service_field_renderer.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$today = printflow_pos_business_today();
$expect(preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1, 'business today is a Y-m-d Manila calendar day');
$expect(
    $today === (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
    'business today matches Asia/Manila wall clock'
);

$GLOBALS['printflow_allow_past_needed_date'] = true;
$minAttr = printflow_service_field_date_min_attr('needed_date');
$expect(
    str_contains($minAttr, 'min="' . $today . '"'),
    'Needed Date min stays today even when the legacy allow-past flag is set'
);

$manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$yesterday = $manilaNow->modify('-1 day')->format('Y-m-d');
$tomorrow = $manilaNow->modify('+1 day')->format('Y-m-d');

$handler = (string)file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
$expect(str_contains($handler, 'printflow_pos_business_today'), 'cart Needed Date check uses Manila business today');
$expect(str_contains($handler, 'Needed date cannot be in the past.'), 'cart add rejects past Needed Date with a clear message');

$fnStart = strpos($handler, 'function pos_cart_date_value_not_before_today(string $value): bool');
$fnEnd = $fnStart === false ? false : strpos($handler, "\nfunction ", $fnStart + 1);
$fnBody = ($fnStart !== false && $fnEnd !== false) ? substr($handler, $fnStart, $fnEnd - $fnStart) : '';
$expect($fnBody !== '', 'pos_cart_date_value_not_before_today is present');
$expect(str_contains($fnBody, 'printflow_pos_business_today()'), 'server comparator uses printflow_pos_business_today()');

$notBeforeToday = static function (string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return true;
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
        return false;
    }
    return $matches[1] >= printflow_pos_business_today();
};

$expect($notBeforeToday($yesterday) === false, 'yesterday is rejected');
$expect($notBeforeToday($today) === true, 'today is accepted');
$expect($notBeforeToday($tomorrow) === true, 'future date is accepted');
$expect($notBeforeToday($yesterday . ' 10:00:00') === false, 'manually entered past datetime date portion is rejected');
$expect($notBeforeToday('31/10/2026') === false, 'non-ISO past date input is rejected');
$expect($notBeforeToday('') === true, 'empty values are left to required-field validation');

$pos = (string)file_get_contents(__DIR__ . '/../staff/pos.php');
$fields = (string)file_get_contents(__DIR__ . '/../staff/api/pos_service_fields.php');
$expect(str_contains($pos, "\$GLOBALS['printflow_allow_past_needed_date'] = false"), 'POS page does not enable past Needed Dates');
$expect(str_contains($pos, 'POS_BUSINESS_TODAY'), 'POS exposes Manila business today to the client');
$expect(str_contains($pos, 'function posEnforceNeededDateMinimum'), 'POS enforces min on dynamically loaded Needed Date inputs');
$expect(!str_contains($pos, 'function posAllowPastNeededDateInputs'), 'POS no longer strips Needed Date min attributes');
$expect(str_contains($pos, 'posNeededDateIsNotBeforeToday'), 'client validation uses Manila business today, not UTC');
$expect(!str_contains($pos, "new Date().toISOString().split('T')[0]"), 'client Needed Date checks no longer use UTC midnight');
$expect(str_contains($fields, "\$GLOBALS['printflow_allow_past_needed_date'] = false"), 'service-fields endpoint keeps Needed Date min enabled');
$expect(str_contains($fields, 'printflow_pos_business_today()'), 'fallback Needed Date uses Manila business today');

if ($failures !== []) {
    fwrite(STDERR, "pos_needed_date_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_needed_date_validation_test passed.\n";
