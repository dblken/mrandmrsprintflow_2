<?php
declare(strict_types=1);

/**
 * Obsolete Admin Service Material Rules configuration endpoint.
 *
 * The Inventory Items configuration screen was removed after returning to the
 * established Production Assignment / POS material workflows. Existing
 * service_material_rules rows and approval/audit history are preserved.
 * This route remains only so direct callers receive a clear unavailable reply
 * instead of mutating configuration.
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_auth();

http_response_code(410);
echo json_encode([
    'success' => false,
    'error' => 'Service Material Rules configuration is unavailable. Use Production Assignment for material usage and existing POS stock validation workflows. Existing rule and audit data were preserved.',
    'feature' => 'service_material_rules_admin',
    'available' => false,
], JSON_UNESCAPED_SLASHES);
exit;
