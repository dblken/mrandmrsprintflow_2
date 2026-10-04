<?php
declare(strict_types=1);

$dbQueries = [];
$soldCall = [];
$reviewCall = '';

function db_query(string $sql, string $types = '', array $params = []): array
{
    global $dbQueries;
    $dbQueries[] = [$sql, $types, $params];
    return [];
}

function printflow_service_units_sold(int $service_id, string $service_name = ''): int
{
    global $soldCall;
    $soldCall = [$service_id, $service_name];
    return 86;
}

function printflow_get_service_review_stats(string $service_name): array
{
    global $reviewCall;
    $reviewCall = $service_name;
    return ['avg_rating' => 4.5, 'review_count' => 2];
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

require_once __DIR__ . '/../includes/service_order_helper.php';

$stats = service_order_get_page_stats('', 67, 'Brochure');

$assert($dbQueries === [], 'resolved service metadata avoids extra service lookup queries');
$assert($soldCall === [67, 'Brochure'], 'sold-count query receives the already-loaded service name');
$assert($reviewCall === 'Brochure', 'review stats use the selected service name');
$assert($stats === [
    'sold_count' => 86,
    'avg_rating' => 4.5,
    'review_count' => 2,
    'service_id' => 67,
], 'service page stats preserve their existing result shape and values');
