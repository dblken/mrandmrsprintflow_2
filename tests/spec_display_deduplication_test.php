<?php
/**
 * Display specification deduplication (dimensions, material, exact duplicates).
 */

$root = dirname(__DIR__);
require_once $root . '/includes/customization_normalizer.php';
require_once $root . '/includes/job_order_summary.php';

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$stickerDup = printflow_deduplicate_display_specifications([
    'Dimensions' => '1 x 1 ft',
    'Dimensions And Finish Other' => '1x1 ft',
    'Layout' => 'with layout',
    'Material' => 'Sticker Black',
    'Order Priority' => 'Regular',
]);
$assert(isset($stickerDup['Dimensions']) && !isset($stickerDup['Dimensions And Finish Other']), 'duplicate dimension labels collapse to Dimensions once');
$assert($stickerDup['Dimensions'] === '1 x 1 ft', 'first valid dimension value is preserved');
$assert(!isset($stickerDup['Material']), 'material is omitted from display specs');
$assert(isset($stickerDup['Layout']), 'non-dimension specs remain');

$formatDup = printflow_deduplicate_display_specifications([
    'Size' => '2 × 3 ft',
    'Custom Size' => '2 x 3 ft',
]);
$assert(count($formatDup) === 1, 'dimension formatting variants dedupe to one row');
$assert(isset($formatDup['Dimensions']) || isset($formatDup['Size']), 'one dimension label remains');

$differentValues = printflow_deduplicate_display_specifications([
    'Dimensions' => '1 x 1 ft',
    'Dimensions And Finish Other' => '2 x 3 ft',
]);
$assert(
    isset($differentValues['Dimensions']) && isset($differentValues['Dimensions And Finish Other']),
    'different dimension values are both kept'
);

$withParts = printflow_deduplicate_display_specifications([
    'Dimensions' => '2 x 3 ft',
    'Width (ft)' => '2 ft',
    'Height (ft)' => '3 ft',
    'dimensions_and_finish_other' => '2x3 ft',
]);
$matchingDimLabels = 0;
foreach ($withParts as $label => $text) {
    if (printflow_customization_canonical_spec_bucket($label) !== 'dimensions') {
        continue;
    }
    $fp = printflow_normalize_display_spec_value_fingerprint('dimensions', $text);
    if ($fp === printflow_normalize_display_spec_value_fingerprint('dimensions', '2 x 3 ft')) {
        $matchingDimLabels++;
    }
}
$assert($matchingDimLabels === 1, 'combined dimensions and redundant parts collapse to one matching dimension row');

$filtered = printflow_filter_admin_customization_display_specifications([
    'Dimensions' => '1 x 1 ft',
    'dimensions_and_finish_other' => '1x1ft',
    'Material' => 'Vinyl',
    'Needed Date' => 'Sep 8, 2026',
]);
$assert(!isset($filtered['Material']), 'admin filter hides material');
$assert(
    !isset($filtered['dimensions_and_finish_other']) || !isset($filtered['Dimensions'])
        || printflow_normalize_display_spec_value_fingerprint('dimensions', $filtered['Dimensions'])
            !== printflow_normalize_display_spec_value_fingerprint('dimensions', $filtered['dimensions_and_finish_other']),
    'admin filter dedupes matching dimensions'
);

$staffJs = (string)file_get_contents($root . '/staff/customizations.php');
$adminJs = (string)file_get_contents($root . '/admin/customizations.php');
$assert(str_contains($staffJs, 'staffDeduplicateDisplaySpecRows'), 'staff modal applies dedupe layer');
$assert(str_contains($staffJs, 'staffCanonicalSpecBucket'), 'staff uses canonical spec buckets');
$assert(str_contains($adminJs, 'adminCanonicalSpecBucket'), 'admin uses canonical spec buckets');

if ($failures !== []) {
    fwrite(STDERR, "Spec display deduplication test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Spec display deduplication test passed.\n";
