<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/customer/api_delete_reviews.php');
$servicePage = file_get_contents($root . '/customer/order_service_dynamic.php');
$detailPage = file_get_contents($root . '/customer/reviews.php');
$checks = [
    'review cleanup endpoint is retired with forbidden response' => str_contains($api, 'http_response_code(403)') && str_contains($api, 'retired'),
    'review cleanup endpoint performs no delete actions' => !preg_match('/\\b(?:DELETE|UPDATE|INSERT)\\b/i', $api),
    'service reviews still render ratings section' => str_contains($servicePage, 'Product Ratings'),
    'service reviews no longer render cleanup controls or modal' => !str_contains($servicePage, 'pfReviewDelete') && !str_contains($servicePage, 'Delete All My Reviews') && !str_contains($servicePage, 'Delete Review'),
    'customer detail route has no cleanup controls' => !str_contains($detailPage, 'Delete All My Reviews') && !str_contains($detailPage, 'Delete Review'),
    'empty review state remains available' => str_contains($servicePage, 'No Reviews Yet'),
];
$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed[] = $name;
}
exit($failed ? 1 : 0);
