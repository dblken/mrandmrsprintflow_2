<?php
require_once __DIR__ . '/../includes/job_order_summary.php';
function assert_line($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
foreach (['Tarpaulin', 'T-shirt', 'Stickers Decals', 'Mugs'] as $service) {
    $own = ['order_item_id' => 13779, 'order_id' => 12180, 'product_name' => $service];
    $resolved = printflow_job_order_find_store_line([$own], 13776);
    assert_line($resolved === $own, 'A foreign staged link must resolve the sole saved line of the same order');
    assert_line(printflow_job_order_service_label_from_sources($resolved, [], 'T-Shirt Printing') === $service,
        'Saved line identity must override the workflow title');
}
$items = [['order_item_id' => 1, 'product_name' => 'T-shirt'], ['order_item_id' => 2, 'product_name' => 'Tarpaulin']];
assert_line(printflow_job_order_find_store_line($items, 2) === $items[1], 'Exact identity must win regardless of position');
assert_line(printflow_job_order_find_store_line($items, 99) === null, 'An ambiguous broken link must never choose an array position');
$source = file_get_contents(__DIR__ . '/../includes/JobOrderService.php');
assert_line(str_contains($source, 'oi.order_id = jo.order_id AND oi.order_item_id'), 'Job detail join must enforce order ownership');
assert_line(str_contains($source, 'HAVING COUNT(*) = 1'), 'SQL fallback must reject multiple items');
echo "Customization job line ownership regressions passed.\n";
