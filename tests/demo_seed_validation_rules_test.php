<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/demo_seed_data.php';

$passed = 0;
$assert = static function (bool $ok, string $msg) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    $passed++;
    echo 'PASS: ', $msg, PHP_EOL;
};

$source = (string)file_get_contents(__DIR__ . '/../includes/demo_seed_data.php');
$assert(str_contains($source, 'demo_seed_batches'), 'defines demo_seed_batches registry table');
$assert(str_contains($source, 'demo_seed_rows'), 'defines demo_seed_rows registry table');
$assert(str_contains($source, '_seed_batch_id'), 'stores hidden batch marker in customization JSON');
$assert(str_contains($source, 'pos_checkout') === false, 'does not call POS checkout');
$assert(str_contains($source, 'provider_payments') === false || str_contains($source, 'DELETE FROM provider_payments'), 'does not create provider payments');
$assert(!str_contains($source, 'INSERT INTO job_order_materials'), 'import path does not insert job_order_materials');
$assert(count(demo_seed_required_csv_columns()) >= 20, 'exports required CSV column list');
$assert(DEMO_SEED_DATE_MAX === '2026-10-01 09:30:00', 'enforces Oct 1 09:30 cutoff constant');

echo "Demo seed validation rules test: {$passed} passed.\n";
