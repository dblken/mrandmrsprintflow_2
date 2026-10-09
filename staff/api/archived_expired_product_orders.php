<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/expired_product_order_archive.php';
require_once __DIR__ . '/../../includes/staff_orders_archived_json.php';
require_once __DIR__ . '/../../includes/staff_access.php';

require_role('Staff');
printflow_require_staff_module('orders');

$staffAccessMeta = printflow_get_staff_access_meta();
if (($staffAccessMeta['key'] ?? '') === 'pos') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Archived expired orders are not available for POS staff.']);
    exit;
}

$branch_ctx = init_branch_context(false);
$staffBranchId = (int)$branch_ctx['selected_branch_id'];
$staffOrderScopeSql = printflow_staff_order_source_sql('o');

try {
    $payload = printflow_staff_archived_expired_orders_payload($staffBranchId, $staffOrderScopeSql, 200);
} catch (Throwable $e) {
    error_log('[archived_expired_product_orders] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load archived orders.',
        'count' => 0,
        'orders' => [],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!empty($_GET['debug']) && defined('PRINTFLOW_DEBUG') && PRINTFLOW_DEBUG) {
    $payload['debug'] = [
        'endpoint' => 'staff/api/archived_expired_product_orders.php',
        'http_status' => 200,
        'branch_id' => $staffBranchId,
        'user_type' => (string)(get_user_type() ?? ''),
        'eligible_count' => $payload['count'],
    ];
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
