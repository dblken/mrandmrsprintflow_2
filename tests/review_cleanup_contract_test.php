<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/customer/api_delete_reviews.php');
$servicePage = file_get_contents($root . '/customer/order_service_dynamic.php');
$detailPage = file_get_contents($root . '/customer/reviews.php');
$report = file_get_contents($root . '/admin/review_cleanup_report.php');
$checks = [
    'customer role and session required' => str_contains($api, "['user_type'] ?? ''") && str_contains($api, "!== 'Customer'") && str_contains($api, 'get_user_id()'),
    'CSRF required before mutations' => strpos($api, 'verify_csrf_token') < strpos($api, 'if (!in_array($action'),
    'single delete verifies owner in SQL' => (bool) preg_match('/WHERE id = \\? AND \{\$ownerCol\} = \\?/s', $api),
    'bulk operation derives owner from session' => str_contains($api, 'WHERE {$ownerCol} = ?') && !str_contains($api, "input['customer_id']"),
    'transaction with rollback' => str_contains($api, 'begin_transaction()') && str_contains($api, '->commit()') && str_contains($api, '->rollback()'),
    'only direct review notifications are deleted' => str_contains($api, 'DELETE FROM notifications WHERE review_id IN'),
    'media deletion limited to dedicated upload directories' => str_contains($api, 'uploads/(reviews_images|reviews_videos)') && str_contains($api, 'is_file($candidate)') && str_contains($api, '@unlink($candidate)'),
    'controls are on the Service review cards shown to customers' => str_contains($servicePage, 'pfDeleteOwnedReview') && str_contains($servicePage, "\$review['user_id'] ?? 0) === \$current_customer_id"),
    'bulk control depends on session-owned review count' => str_contains($servicePage, "\$customer_review_counts['reviews'] > 0") && str_contains($servicePage, 'Delete All My Reviews'),
    'confirmation displays review and media counts' => str_contains($servicePage, 'data-image-count=') && str_contains($servicePage, 'data-video-count=') && str_contains($servicePage, 'This will permanently delete '),
    'customer detail route does not render duplicate cleanup controls' => !str_contains($detailPage, 'Delete All My Reviews') && !str_contains($detailPage, 'deleteOneReview'),
    'empty review state remains available' => str_contains($servicePage, 'No Reviews Yet'),
    'admin report is role protected' => str_contains($report, "require_role('Admin')") && str_contains($report, 'review_cleanup_audit'),
];
$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed[] = $name;
}
exit($failed ? 1 : 0);
