<?php

declare(strict_types=1);

/**
 * Former admin Clear Archived Expired Orders endpoint.
 * Manual purge UI was removed; cron/page-load 24-hour unpaid product-order cleanup remains.
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'Clear Archived Expired Orders has been removed. Overdue unpaid product orders are still cleaned up automatically after 24 hours.',
    'eligible_count' => 0,
    'deleted_count' => 0,
], JSON_UNESCAPED_SLASHES);
