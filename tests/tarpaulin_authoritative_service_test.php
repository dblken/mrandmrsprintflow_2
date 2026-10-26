<?php
declare(strict_types=1);

$fixtureJob = ['id' => 9, 'order_id' => 70, 'order_item_id' => 700];
$fixtureItem = ['order_item_id' => 700, 'order_id' => 70, 'service_id' => 40, 'customization_data' => '{"service_id":40}'];
$fixtureDuplicateJob = false;

function db_table_has_column(string $table, string $column): bool {
    return ($table === 'job_orders' && $column === 'order_item_id') || ($table === 'order_items' && $column === 'service_id');
}
function db_query(string $sql, string $types = '', array $params = []) {
    global $fixtureJob, $fixtureItem, $fixtureDuplicateJob;
    if (str_contains($sql, 'FROM job_orders')) return $fixtureDuplicateJob ? [$fixtureJob, $fixtureJob] : [$fixtureJob];
    if (str_contains($sql, 'FROM order_items')) return $fixtureItem ? [$fixtureItem] : [];
    if (str_contains($sql, 'FROM services')) return [(int)($params[0] ?? 0) > 0 ? ['service_id' => (int)$params[0]] : []];
    return [];
}

require_once dirname(__DIR__) . '/includes/tarpaulin_material_calculation.php';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(printflow_tarpaulin_authoritative_job_service_id(9) === 40, 'A linked persisted Tarpaulin order item resolves service 40.');
$assert(printflow_tarpaulin_assert_versioned_job(['tarpaulin_calculation_version' => 'linear_ft_v1'], 9), 'A valid Tarpaulin job accepts the version marker.');

$fixtureItem['service_id'] = 64;
$fixtureItem['customization_data'] = '{"service_id":64}';
$assert(printflow_tarpaulin_authoritative_job_service_id(9) === 64, 'A different linked service resolves to its own persisted service ID.');
try {
    printflow_tarpaulin_assert_versioned_job(['tarpaulin_calculation_version' => 'linear_ft_v1'], 9);
    throw new RuntimeException('A forged marker on a non-Tarpaulin service must be rejected.');
} catch (RuntimeException $expected) {
    $assert(str_contains($expected->getMessage(), 'only for'), 'The non-Tarpaulin marker rejection is explicit.');
}
$assert(!printflow_tarpaulin_assert_versioned_job([], 9), 'Legacy unmarked assignments remain on the legacy path.');

$fixtureJob['order_item_id'] = 0;
$assert(printflow_tarpaulin_authoritative_job_service_id(9) === 0, 'Missing job-to-order-item links fail closed.');
$fixtureJob['order_item_id'] = 700;
$fixtureItem['service_id'] = 40;
$fixtureItem['customization_data'] = '{"service_id":64}';
$assert(printflow_tarpaulin_authoritative_job_service_id(9) === 0, 'Conflicting persisted service identities are treated as ambiguous.');
$fixtureItem['customization_data'] = '{"service_id":40}';
$fixtureDuplicateJob = true;
$assert(printflow_tarpaulin_authoritative_job_service_id(9) === 0, 'Ambiguous job identity fails closed.');

echo "Tarpaulin authoritative service identity tests passed.\n";
