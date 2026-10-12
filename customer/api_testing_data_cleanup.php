<?php
declare(strict_types=1);

/** Legacy customer-only cleanup route retired. Use the Admin-only audit workflow. */
require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
http_response_code(410);
echo json_encode([
    'success' => false,
    'error' => 'The legacy customer cleanup route is retired. Use the Admin-authorized cleanup audit.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
