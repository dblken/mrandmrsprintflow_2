<?php

declare(strict_types=1);

/**
 * Former staff Archived Expired Orders modal endpoint.
 * The archive UI was removed; automatic 24-hour unpaid product-order cleanup remains.
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'The Archived expired-orders view has been removed. Overdue unpaid product orders still leave active lists after 24 hours via automatic cleanup.',
    'count' => 0,
    'orders' => [],
], JSON_UNESCAPED_SLASHES);
