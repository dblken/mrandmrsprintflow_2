<?php
declare(strict_types=1);

/**
 * Retired legacy bulk-deletion tool.
 * The former implementation contained fixed customer ID lists and is unsafe
 * for the current customer-specific cleanup workflow.
 */
require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin()) {
    printflow_json_response(['success' => false, 'error' => 'Admin authorization is required.'], 403);
}

printflow_json_response([
    'success' => false,
    'error' => 'This legacy bulk deletion tool has been retired. No records were changed.',
], 410);
