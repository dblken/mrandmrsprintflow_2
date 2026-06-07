<?php
/**
 * Admin: Customer ID verification approve/reject API
 */

require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_id_verification.php';

require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input = str_contains($contentType, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_POST;

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'code' => 'csrf_mismatch',
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only admins can verify or reject customer IDs.']);
    exit;
}

$result = pf_apply_customer_id_verification_decision(
    (int)($input['cid'] ?? 0),
    trim((string)($input['id_action'] ?? '')),
    (string)($input['reject_reason'] ?? ''),
    (string)($input['reject_reason_other'] ?? '')
);

if (!$result['success']) {
    http_response_code(500);
}

echo json_encode($result);
exit;
