<?php
/**
 * Staff Customizations V2 API
 * --------------------------------------------------------------------------
 * JSON endpoints for the brand-new V2 customizations module. Reads directly
 * from the existing database (order_items first) and reproduces exactly what
 * the customer submitted.
 *
 * Actions:
 *   GET  ?action=list   [&source=all|online|pos]
 *   GET  ?action=detail &order_id=ID
 *   POST  action=approve            (order_id)
 *   POST  action=request_revision   (order_id, reason)
 *   POST  action=close              (order_id)
 *
 * The old staff/customizations.php and admin/job_orders_api.php remain
 * untouched; this endpoint is additive and self-contained.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/CustomizationService.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('BASE_URL')) {
    define('BASE_URL', defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : ''));
}

function cv2_json($payload, int $code = 200): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

if (!is_logged_in() || !has_role(['Admin', 'Staff', 'Manager'])) {
    cv2_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$service = new CustomizationService();

// Branch scoping: staff are limited to their branch; admins/managers see all
// unless a specific branch filter applies.
$branchFilter = function_exists('printflow_branch_filter_for_user')
    ? printflow_branch_filter_for_user()
    : null;

try {
    switch ($action) {
        case 'list': {
            $source = strtolower(trim((string)($_GET['source'] ?? 'all')));
            if (!in_array($source, ['all', 'online', 'pos'], true)) {
                $source = 'all';
            }
            $sourceArg = $source === 'all' ? null : $source;
            $rows = $service->listOrderSummaries($branchFilter, $sourceArg, 250);
            cv2_json(['success' => true, 'data' => $rows]);
            break;
        }

        case 'detail': {
            $orderId = (int)($_GET['order_id'] ?? 0);
            if ($orderId <= 0) {
                cv2_json(['success' => false, 'message' => 'order_id is required.'], 400);
            }
            $detail = $service->getOrderDetail($orderId);
            if ($detail === null) {
                cv2_json(['success' => false, 'message' => 'Order not found.'], 404);
            }
            // Enforce branch scoping for staff.
            if ($branchFilter !== null && (int)$detail['branch']['id'] !== (int)$branchFilter) {
                cv2_json(['success' => false, 'message' => 'This order belongs to another branch.'], 403);
            }
            cv2_json(['success' => true, 'data' => $detail]);
            break;
        }

        case 'approve':
        case 'request_revision':
        case 'close': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                cv2_json(['success' => false, 'message' => 'POST required.'], 405);
            }
            // CSRF protection (consistent with the rest of the app).
            $token = $_POST['csrf_token'] ?? '';
            if (function_exists('verify_csrf_token') && !verify_csrf_token($token)) {
                cv2_json(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.'], 419);
            }

            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                cv2_json(['success' => false, 'message' => 'order_id is required.'], 400);
            }

            // Branch scoping for staff.
            if ($branchFilter !== null) {
                $order = $service->repository()->getOrder($orderId);
                if ($order === null || (int)($order['branch_id'] ?? 0) !== (int)$branchFilter) {
                    cv2_json(['success' => false, 'message' => 'This order belongs to another branch.'], 403);
                }
            }

            if ($action === 'approve') {
                $result = $service->approve($orderId);
            } elseif ($action === 'close') {
                $result = $service->close($orderId);
            } else {
                $result = $service->requestRevision($orderId, (string)($_POST['reason'] ?? ''));
            }

            cv2_json($result, $result['success'] ? 200 : 400);
            break;
        }

        default:
            cv2_json(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('customizations_v2 API error: ' . $e->getMessage());
    cv2_json(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
