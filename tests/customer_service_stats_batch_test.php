<?php
declare(strict_types=1);

function db_query(string $sql, string $types = '', array $params = []): array
{
    if (str_contains($sql, 'SELECT oi.order_item_id, oi.quantity')) {
        return [
            ['order_item_id' => 1, 'quantity' => 2, 'reference_id' => 1, 'order_type' => 'custom', 'customization_data' => '{}'],
            ['order_item_id' => 2, 'quantity' => 3, 'reference_id' => 0, 'order_type' => 'custom', 'customization_data' => '{"service_id":2}'],
            ['order_item_id' => 3, 'quantity' => 4, 'reference_id' => 0, 'order_type' => 'custom', 'customization_data' => '{"service_type":"Alpha Printing"}'],
            // Matching the same service through two legacy sources must count the item once.
            ['order_item_id' => 4, 'quantity' => 5, 'reference_id' => 1, 'order_type' => 'custom', 'customization_data' => '{"service_id":1}'],
        ];
    }
    if ($sql === 'SHOW COLUMNS FROM reviews') {
        return array_map(static fn(string $field): array => ['Field' => $field], ['id', 'rating', 'service_type', 'order_id']);
    }
    if (str_starts_with($sql, 'SELECT id, rating')) {
        return [
            ['id' => 1, 'rating' => 5, 'service_type' => 'Alpha Printing', 'order_id' => 0],
            ['id' => 2, 'rating' => 3, 'service_type' => '', 'order_id' => 22],
            ['id' => 3, 'rating' => 4, 'service_type' => '', 'order_id' => 33],
        ];
    }
    if (str_contains($sql, 'SELECT oi.order_id, p.name AS product_name')) {
        return [
            ['order_id' => 22, 'product_name' => '', 'customization_data' => '{"service_type":"Beta Printing"}'],
            ['order_id' => 33, 'product_name' => 'Alpha Printing', 'customization_data' => '{}'],
        ];
    }
    throw new RuntimeException('Unexpected query in service stats fixture: ' . $sql);
}

function printflow_service_name_aliases(string $name): array
{
    return [$name];
}

require_once __DIR__ . '/../includes/customer_catalog_perf.php';

$stats = printflow_catalog_service_card_stats_map([
    ['service_id' => 1, 'name' => 'Alpha Printing'],
    ['service_id' => 2, 'name' => 'Beta Printing'],
]);

if (($stats[1]['sold_count'] ?? null) !== 11 || ($stats[2]['sold_count'] ?? null) !== 3) {
    throw new RuntimeException('FAIL: batched sold counts changed legacy matching or de-duplication semantics');
}
if (($stats[1]['review_count'] ?? null) !== 2 || abs(($stats[1]['avg_rating'] ?? 0) - 4.5) > 0.0001) {
    throw new RuntimeException('FAIL: batched Alpha review aggregation changed');
}
if (($stats[2]['review_count'] ?? null) !== 1 || abs(($stats[2]['avg_rating'] ?? 0) - 3.0) > 0.0001) {
    throw new RuntimeException('FAIL: batched Beta review aggregation changed');
}

echo "Customer service batched stats fixture passed.\n";
