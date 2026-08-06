<?php
declare(strict_types=1);

/**
 * PayMongo Live Mode webhook endpoint.
 *
 * Keep this URL unregistered until PAYMONGO_LIVE_ENABLED is explicitly true
 * and the dedicated live API/webhook credentials are configured.
 */

define('PRINTFLOW_PAYMONGO_WEBHOOK_MODE', 'live');
require __DIR__ . '/paymongo.php';
