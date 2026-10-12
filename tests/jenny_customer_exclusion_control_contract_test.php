<?php
declare(strict_types=1);

$endpoint = (string)file_get_contents(__DIR__ . '/../admin/api_jenny_customer_exclusion.php');
$page = (string)file_get_contents(__DIR__ . '/../admin/archived_orders.php');
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };

$check(str_contains($endpoint, "const PF_JENNY_EXCLUSION_CUSTOMER_ID = 25"), 'target customer ID is fixed server-side');
$check(str_contains($endpoint, "if (!is_admin()") && str_contains($endpoint, "verify_csrf_token"), 'Admin authorization and CSRF validation are enforced');
$check(str_contains($endpoint, "SELECT order_id FROM orders WHERE customer_id = ? ORDER BY order_id"), 'order exclusions are dynamically derived from the exact customer owner');
$check(str_contains($endpoint, 'GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR') && str_contains($endpoint, 'LEFT JOIN order_items oi ON oi.order_id = o.order_id') && str_contains($endpoint, 'LEFT JOIN products p ON p.product_id = oi.product_id') && !str_contains($endpoint, 'o.order_sku'), 'preview derives order codes from the live order-item/product relationship');
$check(str_contains($endpoint, "begin_transaction()") && str_contains($endpoint, "commit()") && str_contains($endpoint, "rollback()"), 'exclusion updates use a transaction with rollback');
$check(str_contains($endpoint, "['activate', 'restore']") && str_contains($endpoint, "'inactive'"), 'archive is reversible through explicit inactive status');
$check(str_contains($endpoint, "data_records_deleted_or_modified' => false"), 'endpoint documents that transaction records are not modified');
$check(!preg_match('/\bDELETE\s+FROM\s+(?!customer_exclusion)/i', $endpoint), 'endpoint does not delete business data');
$check(str_contains($page, 'api_jenny_cleanup_preview.php') && str_contains($page, "preview.database?.name!=='u618446170_printflow'"), 'Admin controls require a live target database preview');
$check(str_contains($page, 'api_jenny_customer_exclusion.php') && str_contains($page, "openDialog('restore')"), 'Admin page exposes the reversible restore action');
$check(str_contains($page, 'Related records preview') && str_contains($page, 'dispositionLabel') && str_contains($page, 'toLowerCase()') && str_contains($page, 'pf-scope-table'), 'archive preview uses readable dispositions and a wrapping table layout');
$check(str_contains($page, 'preview_token:archivePreviewToken') && str_contains($endpoint, "hash('sha256', \$previewToken)"), 'a fresh single-use preview token is required before changes');
$check(str_contains($endpoint, 'reason LIKE ?') && str_contains($endpoint, '$existingReference ==='), 'restoration affects only order exclusions tagged by this archive reference');

if ($failures !== []) {
    fwrite(STDERR, "FAIL jenny_customer_exclusion_control_contract_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK jenny_customer_exclusion_control_contract_test\n";
