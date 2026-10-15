<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_custom_transaction_datetime.php';

if (!function_exists('has_role')) {
    function has_role($roles): bool
    {
        return true;
    }
}

$failures = [];
$expect = static function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) {
        $failures[] = $msg;
    }
};

$expect(
    printflow_pos_selected_transaction_datetime(['use_custom_transaction_datetime' => 0]) !== '',
    'Default checkout uses a non-empty Manila timestamp'
);
$expect(
    printflow_pos_resolve_checkout_transaction_datetime([
        'use_custom_transaction_datetime' => 1,
        'custom_transaction_date' => '2026-09-07',
        'custom_transaction_time' => '07:10',
    ]) === '2026-09-07 07:10:00',
    'Custom datetime resolves to the exact selected stamp'
);

try {
    printflow_pos_resolve_checkout_transaction_datetime([
        'use_custom_transaction_datetime' => 1,
        'custom_transaction_date' => '2026-02-31',
        'custom_transaction_time' => '07:10',
    ]);
    $failures[] = 'Invalid calendar dates must be rejected';
} catch (RuntimeException $e) {
    $expect(true, 'Invalid calendar dates throw');
}

if ($failures !== []) {
    fwrite(STDERR, "pos_custom_transaction_datetime_validation_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_custom_transaction_datetime_validation_test passed.\n";
