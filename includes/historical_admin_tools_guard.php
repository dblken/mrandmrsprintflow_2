<?php
declare(strict_types=1);

/** Keep historical maintenance tools deployed but unavailable from the UI/routes. */
function printflow_block_historical_admin_tool(): never
{
    http_response_code(404);
    exit;
}
