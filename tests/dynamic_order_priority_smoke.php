<?php
/**
 * Smoke tests for dynamic order priority / urgent request resolution.
 */

require_once __DIR__ . '/../includes/service_field_priority_helper.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';

$assertions = 0;
$failures = 0;

$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$configs = [
    'order_priority' => [
        'label' => 'Order Priority',
        'type' => 'radio',
        'visible' => true,
        'required' => true,
        'options' => [
            ['value' => 'Regular Order', 'price' => 0],
            ['value' => 'Urgent Order', 'price' => 0, 'staff_flags' => ['urgent_request']],
        ],
    ],
];

if (!function_exists('get_service_field_config')) {
    function get_service_field_config(int $serviceId): array
    {
        global $configs;
        return $serviceId === 99 ? $configs : [];
    }
}

$regularCustom = [
    'service_id' => 99,
    'Order Priority' => 'Regular Order',
    'Needed Date' => 'September 20, 2026',
];

$urgentCustom = [
    'service_id' => 99,
    'Order Priority' => 'Urgent Order',
    'Urgency Reason' => 'Needed for an event tomorrow.',
    '_staff_priority_request' => [
        'field_key' => 'order_priority',
        'field_label' => 'Order Priority',
        'selected_value' => 'Urgent Order',
        'is_urgent_request' => true,
        'has_priority_field' => true,
        'related_fields' => ['Urgency Reason' => 'Needed for an event tomorrow.'],
    ],
];

$regularPriority = printflow_resolve_dynamic_order_priority(99, $regularCustom);
$assert($regularPriority['has_priority_field'] === true, 'configured regular selection is recognized as a priority field');
$assert($regularPriority['is_urgent_request'] === false, 'regular selection is not urgent');
$assert($regularPriority['job_priority'] === 'NORMAL', 'regular selection maps to NORMAL job priority');

$urgentPriority = printflow_resolve_dynamic_order_priority(99, $urgentCustom);
$assert($urgentPriority['is_urgent_request'] === true, 'snapshot urgent request is preserved');
$assert($urgentPriority['selected_value'] === 'Urgent Order', 'snapshot keeps selected value');
$assert($urgentPriority['related_fields']['Urgency Reason'] === 'Needed for an event tomorrow.', 'snapshot keeps related nested values');

$deletedConfigCustom = [
    'Order Priority' => 'Urgent Order',
    '_staff_priority_request' => [
        'field_key' => 'order_priority',
        'field_label' => 'Order Priority',
        'selected_value' => 'Urgent Order',
        'is_urgent_request' => true,
        'has_priority_field' => true,
        'related_fields' => [],
    ],
];
$deletedConfigPriority = printflow_resolve_dynamic_order_priority(0, $deletedConfigCustom);
$assert($deletedConfigPriority['is_urgent_request'] === true, 'deleted field config still resolves from snapshot');

$row = [];
printflow_apply_priority_request_to_row($row, $urgentPriority);
$assert(!empty($row['is_urgent_request']), 'row enrichment exposes urgent flag');
$assert(($row['priority_request_label'] ?? '') === 'Order Priority', 'row enrichment exposes field label');

$assert(printflow_is_internal_customization_key('_staff_priority_request'), 'internal snapshot key is hidden from UI');
$assert(printflow_is_internal_customization_key('priority_request_label'), 'internal API keys are hidden from UI');
$assert(!printflow_is_internal_customization_key('Order Priority'), 'configured field labels remain visible');

$normalized = pf_order_ui_normalize_review_customization($urgentCustom, [], true);
$assert(!array_key_exists('_staff_priority_request', $normalized), 'review normalization removes internal snapshot key');
$assert(($normalized['Order Priority'] ?? '') === 'Urgent Order', 'review normalization keeps configured field value');

$neededRaw = printflow_resolve_needed_date_from_customization(['Needed Date' => '2026-09-18'], 0);
$assert($neededRaw === '2026-09-18', 'needed date resolves from configured label');
$assert(printflow_format_needed_date_display('2026-09-18') === 'Sep 18, 2026', 'needed date display format is human readable');

echo "Priority helper smoke test: {$assertions} assertions, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
