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
$apiSource = (string)file_get_contents(__DIR__ . '/../admin/api/demo_seed_data.php');
$settingsSource = (string)file_get_contents(__DIR__ . '/../admin/settings.php');
$assert(str_contains($source, 'demo_seed_batches'), 'defines demo_seed_batches registry table');
$assert(str_contains($source, 'demo_seed_rows'), 'defines demo_seed_rows registry table');
$assert(str_contains($source, '_seed_batch_id'), 'stores hidden batch marker in customization JSON');
$assert(str_contains($source, 'pos_checkout') === false, 'does not call POS checkout');
$assert(!preg_match('/DELETE FROM\\s+provider_payments/i', $source), 'protects provider payment rows during demo deletion');
$assert(!preg_match('/DELETE FROM\\s+payment_submissions/i', $source), 'protects payment submission rows during demo deletion');
$assert(!str_contains($source, 'INSERT INTO job_order_materials'), 'import path does not insert job_order_materials');
$assert(count(demo_seed_required_csv_columns()) >= 20, 'exports required CSV column list');
$assert(DEMO_SEED_DATE_MAX === '2026-10-01 09:30:00', 'enforces Oct 1 09:30 cutoff constant');

$assert(str_contains($source, 'demo_seed_resolve_service_catalog'), 'service catalog auto-resolution helper exists');
$assert(str_contains($source, 'demo_seed_resolve_job_service_enum'), 'job enum auto-resolution helper exists');
$assert(str_contains($source, 'demo_seed_resolve_branch_id'), 'branch auto-resolution helper exists');
$assert(str_contains($source, 'demo_seed_resolve_staff_user_id'), 'staff auto-resolution helper exists');
$assert(str_contains($source, 'row_resolutions'), 'validation returns row resolution preview payload');
$assert(str_contains($source, 'demo_seed_require_insert_id'), 'import uses strict insert id checks');
$assert(str_contains($source, 'demo_seed_verify_batch_integrity'), 'post-import integrity verification exists');
$assert(!str_contains($source, 'DELETE FROM inventory_transactions'), 'demo delete must not touch inventory ledger');
$assert(str_contains($source, "'safe_to_delete' =>"), 'preview blocks unsafe batch deletion');
$assert(str_contains($source, 'FOR UPDATE'), 'deletion locks the exact selected batch');
$assert(str_contains($apiSource, "require_role('Admin')"), 'demo data endpoint requires admin authorization');
$assert(str_contains($apiSource, 'verify_csrf_token'), 'demo data endpoint checks CSRF');
$assert(str_contains($apiSource, "\$_POST['batch_id']"), 'delete endpoint requires an explicitly selected batch');
$assert(str_contains($settingsSource, "fd.append('batch_id', selectedDemoBatchId)"), 'UI submits the batch shown in preview');
$assert(str_contains($settingsSource, "DELETE MEETING DATA"), 'UI displays the server confirmation phrase');
$assert(str_contains($settingsSource, 'deletePreviewReady'), 'UI gates deletion on a valid preview');

echo "Demo seed validation rules test: {$passed} passed.\n";
