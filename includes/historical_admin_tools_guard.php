<?php
declare(strict_types=1);

/** Keep historical maintenance tools deployed but unavailable from the UI/routes. */
function printflow_block_historical_admin_tool(): never
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found";
    exit;
}
