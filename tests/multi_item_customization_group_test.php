<?php
/**
 * Multi-item POS carts must group admin list rows by store order_id.
 */

$root = dirname(__DIR__);
$summary = (string)file_get_contents($root . '/includes/job_order_summary.php');
$admin = (string)file_get_contents($root . '/admin/customizations.php');
$jobService = (string)file_get_contents($root . '/includes/JobOrderService.php');
$modal = (string)file_get_contents($root . '/staff/get_order_for_modal.php');

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$assert(str_contains($summary, 'function printflow_group_admin_customization_job_rows'), 'admin grouping helper must exist');
$assert(str_contains($summary, 'function printflow_store_order_service_summary'), 'store order service summary helper must exist');
$assert(str_contains($summary, 'function printflow_enrich_store_order_items_for_display'), 'per-line modal enrichment helper must exist');
$assert(str_contains($admin, 'printflow_group_admin_customization_job_rows($jobs)'), 'admin list must group by order_id');
$assert(str_contains($admin, 'COUNT(DISTINCT CASE WHEN jo.order_id'), 'admin pagination count must use grouped order keys');
$assert(str_contains($jobService, 'printflow_enrich_store_order_items_for_display($order[\'items\'])'), 'job detail API must enrich all store lines');
$assert(str_contains($jobService, "saved_job_order_item_id'] ?? 0) > 0"), 'job detail API must not collapse unrelated multi-item carts');
$assert(str_contains($modal, 'printflow_enrich_store_order_items_for_display($data[\'items\'])'), 'staff modal API must enrich all store lines');
$assert(str_contains($modal, '$forceSingleLineView'), 'staff modal must distinguish cart view vs linked-line view');

if ($failures !== []) {
    fwrite(STDERR, "Multi-item customization group test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Multi-item customization group test passed.\n";
