<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();

http_response_code(403);
echo json_encode([
    'success' => false,
    'error' => 'This temporary review cleanup endpoint has been retired.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
