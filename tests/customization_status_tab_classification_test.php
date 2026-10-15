<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/admin/job_orders_api.php');
$page = (string)file_get_contents($root . '/staff/customizations.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(
    str_contains($api, 'function jo_api_map_store_order_status_to_list_enum')
        && str_contains($api, 'function jo_api_pick_authoritative_list_row')
        && str_contains($api, 'function jo_api_apply_authoritative_store_status')
        && str_contains($api, 'o.status AS store_order_status'),
    'Job orders API resolves list status from authoritative store orders.status'
);
$expect(
    str_contains($api, 'jo_api_pick_authoritative_list_row($dedupedByOrder[$orderKey], $row)')
        && str_contains($api, 'jo_api_pick_authoritative_list_row($countRowsByKey[$key], $row)'),
    'Server-side list and count dedupe prefer authoritative status over first row or newest mirror'
);
$expect(
    str_contains($page, 'resolveAuthoritativeListStatus')
        && str_contains($page, 'applyAuthoritativeListStatus')
        && str_contains($page, 'getPosWalkInBucket(jo)'),
    'Staff customizations classify POS tabs from authoritative list status'
);
$expect(
    str_contains($page, "existingType === 'ORDER' ? existing : (incomingType === 'ORDER' ? incoming : null)")
        && str_contains($page, 'loadStatusCounts({ force: true })'),
    'POS row merge prefers ORDER records and refreshes tab counts after completion'
);
$expect(
    str_contains($page, 'staff_customizations_status_authority_20261016'),
    'Customizations page busts stale cached list/count responses after status authority fix'
);

if ($failures !== []) {
    fwrite(STDERR, "customization_status_tab_classification_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "customization_status_tab_classification_test passed.\n";
