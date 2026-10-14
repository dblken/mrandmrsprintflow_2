<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/customer_exclusion_archive_batch.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };
$order = static fn(int $id, string $orderStatus = '', string $mapStatus = ''): array => [
    'order_id' => $id,
    'order_exclusion_status' => $orderStatus,
    'archive_map_status' => $mapStatus,
];

$mixed = printflow_customer_archive_batch_counts([$order(1, '', 'archived'), $order(2, 'active'), $order(3)], false);
$check($mixed === ['total_orders_found' => 3, 'already_archived_count' => 2, 'newly_archived_count' => 1], 'mixed archived and visible order state is counted without conflict');

$visible = printflow_customer_archive_batch_counts([$order(10), $order(11)], false);
$check($visible['already_archived_count'] === 0 && $visible['newly_archived_count'] === 2, 'all visible orders are counted as newly archived');

$archived = printflow_customer_archive_batch_counts([$order(20, '', 'archived'), $order(21, 'active')], false);
$check($archived['already_archived_count'] === 2 && $archived['newly_archived_count'] === 0, 'all already archived orders are an idempotent success');

$repeatedHide = printflow_customer_archive_batch_counts([$order(30), $order(31)], true);
$check($repeatedHide['already_archived_count'] === 2 && $repeatedHide['newly_archived_count'] === 0, 'repeated hide recognizes customer-wide archive state');

$restoredState = [$order(40, 'inactive'), $order(41, 'inactive', 'archived')];
$restored = printflow_customer_archive_batch_counts($restoredState, false);
$rehide = printflow_customer_archive_batch_counts($restoredState, false);
$check($restored['already_archived_count'] === 1 && $restored['newly_archived_count'] === 1, 'restore leaves legacy archive mappings protected and makes the other orders visible');
$jennyReason = 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.';
$check(printflow_customer_archive_managed_reason($jennyReason) && printflow_customer_archive_order_reason_is_managed(25, $jennyReason) && !printflow_customer_archive_order_reason_is_managed(44, $jennyReason), 'Jenny Ferr archive reasons stay managed only for customer 25');
$check(printflow_customer_archive_reference('Archive reference: JNY-25-20261012-010101-ABC123; kept') === 'JNY-25-20261012-010101-ABC123', 'existing Jenny archive references still parse');
$check(printflow_customer_archive_order_reason_is_managed(44, 'Archive reference: ARC-44-20261012-010101-ABC123; Admin-authorized reversible customer archive.') && !printflow_customer_archive_order_reason_is_managed(25, 'Archive reference: ARC-44-20261012-010101-ABC123; Admin-authorized reversible customer archive.'), 'archive references do not cross customers');
$check(!printflow_customer_archive_managed_reason('A separate manual exclusion'), 'unrelated exclusion reasons are not overwritten by this archive');
$check($rehide['total_orders_found'] === 2 && $rehide['newly_archived_count'] === 1, 'restore and re-hide recompute current per-order state');

if ($failures !== []) {
    fwrite(STDERR, "FAIL customer_exclusion_archive_batch_test\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "OK customer_exclusion_archive_batch_test\n";
