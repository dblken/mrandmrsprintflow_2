<?php

require_once __DIR__ . '/../includes/job_order_summary.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$largeDataUri = 'data:image/png;base64,' . str_repeat('A', 5 * 1024 * 1024);
$fixture = [
    'id' => 42,
    'order_id' => 420,
    'order_type' => 'JOB',
    'order_code' => 'ORD-00420',
    'status' => 'PENDING',
    'service_type' => 'Tarpaulin Printing',
    'job_title' => 'Tarpaulin Printing - 2pcs',
    'first_name' => 'Sample',
    'last_name' => 'Customer',
    'width_ft' => '4',
    'height_ft' => '6',
    'quantity' => 2,
    'created_at' => '2026-08-29 12:00:00',
    'updated_at' => '2026-08-29 12:00:00',
    'items' => [[
        'order_item_id' => 99,
        'product_name' => 'Tarpaulin Printing',
        'quantity' => 2,
        'customization' => ['finish' => 'With eyelets'],
    ]],
    // Every one of these fields must remain detail-only.
    'artwork_path' => $largeDataUri,
    'payment_proof_path' => $largeDataUri,
    'customization_details' => ['design_upload_data' => $largeDataUri],
    'specifications' => ['reference_upload_data' => $largeDataUri],
    'materials' => [['metadata' => $largeDataUri]],
    'provider_payment' => ['provider_payload' => $largeDataUri],
];

$summary = jo_api_summary_row($fixture);
$json = json_encode($summary, JSON_UNESCAPED_SLASHES);
$legacyJson = json_encode($fixture, JSON_UNESCAPED_SLASHES);

foreach (['artwork_path', 'payment_proof_path', 'customization_details', 'specifications', 'materials', 'provider_payment'] as $forbidden) {
    $assert(!array_key_exists($forbidden, $summary), "{$forbidden} leaked into a summary row");
}
$assert(is_string($json) && strlen($json) < 4096, 'representative summary row must remain below 4 KB');
$assert(is_string($legacyJson) && strlen($legacyJson) > 25 * 1024 * 1024, 'fixture must reproduce a large legacy row');
$assert(($summary['order_id'] ?? null) === 420, 'summary identity was not preserved');
$assert(($summary['status'] ?? null) === 'PENDING', 'summary status was not preserved');

$serviceSource = (string)file_get_contents(__DIR__ . '/../includes/JobOrderService.php');
$methodStart = strpos($serviceSource, 'function getStoreOrderItemSummariesBatch');
$methodEnd = strpos($serviceSource, 'function getStoreOrderItemsPayloadsBatch', $methodStart ?: 0);
$methodSource = $methodStart !== false && $methodEnd !== false
    ? substr($serviceSource, $methodStart, $methodEnd - $methodStart)
    : '';
$assert($methodSource !== '', 'summary batch method is missing');
$assert(strpos($methodSource, 'oi.design_image') === false, 'summary query must not select design_image');
$assert(strpos($methodSource, 'oi.customization_data, oi.specifications') === false, 'summary query must not select raw customization/specification columns');
$assert(strpos($methodSource, "'specifications_raw'") === false, 'summary rows must not return raw specifications');

$pageSource = (string)file_get_contents(__DIR__ . '/../staff/customizations.php');
$assert(strpos($pageSource, "summary_only: '1'") !== false, 'both list requests must use summary mode');
$assert(strpos($pageSource, 'ordersSummaryPageSize: 15') !== false, 'initial merged summary response must remain near 30 rows');
$assert(strpos($pageSource, 'loadNextOrderSummaryPage') !== false, 'older summaries must remain reachable on demand');
$assert(strpos($pageSource, 'ensureFilterCoverage') !== false, 'search and filters must page through older summaries on demand');
$assert(strpos($pageSource, 'customization_counts') !== false, 'status tabs must use the lightweight count endpoint');

$ordersPageSource = (string)file_get_contents(__DIR__ . '/../staff/orders.php');
$assert(strpos($ordersPageSource, 'ordersMinimumRefreshAgeMs') !== false, 'orders focus/visibility refreshes must have a minimum age');
$assert(strpos($ordersPageSource, 'if (silent && ordersFetchController) return;') !== false, 'orders background refreshes must be single-flight');

$v2ServiceSource = (string)file_get_contents(__DIR__ . '/../includes/CustomizationService.php');
$assert(strpos($v2ServiceSource, 'JobOrderService::getStoreOrderItemSummariesBatch($missing, false)') !== false, 'V2 list preloading must use compact store payloads');

echo sprintf(
    "Customization performance contract tests passed. Synthetic legacy=%d bytes, summary=%d bytes.\n",
    strlen($legacyJson),
    strlen($json)
);
