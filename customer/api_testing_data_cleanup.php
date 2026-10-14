<?php
declare(strict_types=1);

/** Legacy customer-only cleanup route retired. Use the Admin-only audit workflow. */
require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
http_response_code(403);
echo json_encode([
    'success' => false,
    'error' => 'The legacy customer cleanup route is retired.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
