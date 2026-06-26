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
 *   GET  ?action=design &order_item_id=ID [&field=design|reference]
 *   POST  action=approve            (order_id)
 *   POST  action=request_revision   (order_id, reason)
 *   POST  action=reject             (order_id)
 *   POST  action=close              (order_id)
 *
 * The old staff/customizations.php and admin/job_orders_api.php remain
 * untouched; this endpoint is additive and self-contained.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/CustomizationService.php';
require_once __DIR__ . '/../../includes/order_items_persistence.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : ''));
}

function cv2_json($payload, int $code = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
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

if ($action === 'design') {
    $orderItemId = (int)($_GET['order_item_id'] ?? 0);
    if ($orderItemId <= 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'order_item_id is required.';
        exit;
    }

    $check = db_query(
        'SELECT oi.order_id, o.branch_id
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         WHERE oi.order_item_id = ?
         LIMIT 1',
        'i',
        [$orderItemId]
    ) ?: [];
    if ($check === []) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Order item not found.';
        exit;
    }

    if ($branchFilter !== null && (int)($check[0]['branch_id'] ?? 0) !== (int)$branchFilter) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'This order belongs to another branch.';
        exit;
    }

    if (function_exists('printflow_heal_order_item_design_from_payload')) {
        printflow_heal_order_item_design_from_payload($orderItemId);
    }

    $_GET['type'] = 'order_item';
    $_GET['id'] = (string)$orderItemId;
    $_GET['field'] = trim((string)($_GET['field'] ?? 'design'));
    require __DIR__ . '/../../public/serve_design.php';
    exit;
}

header('Content-Type: application/json; charset=utf-8');

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
        case 'reject':
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
            } elseif ($action === 'reject') {
                $result = $service->reject($orderId);
            } elseif ($action === 'close') {
                $result = $service->close($orderId);
            } else {
                $result = $service->requestRevision($orderId, (string)($_POST['reason'] ?? ''));
            }

            cv2_json($result, $result['success'] ? 200 : 400);
            break;
        }

        case 'diagnose': {
            // Diagnostic endpoint: explains exactly why a given order does or
            // does not appear in the V2 list. Safe (read-only) and staff-gated.
            $orderId = (int)($_GET['order_id'] ?? 0);
            if ($orderId <= 0) {
                cv2_json(['success' => false, 'message' => 'order_id is required.'], 400);
            }

            $repo = $service->repository();

            $orderRow = db_query(
                'SELECT order_id, customer_id, branch_id, order_type, order_source, status, payment_status, order_date, total_amount
                 FROM orders WHERE order_id = ? LIMIT 1',
                'i',
                [$orderId]
            );
            $orderRow = $orderRow[0] ?? null;

            $itemRows = db_query(
                'SELECT order_item_id, product_id, quantity, design_file,
                        IFNULL(LENGTH(design_image), 0) AS design_blob_len,
                        design_image_name, customization_data
                 FROM order_items WHERE order_id = ?',
                'i',
                [$orderId]
            ) ?: [];
            $itemsInfo = array_map(static function ($r) {
                $cd = (string)($r['customization_data'] ?? '');
                $decoded = function_exists('customer_orders_decode_customization_payload')
                    ? customer_orders_decode_customization_payload($cd)
                    : [];
                return [
                    'order_item_id'       => (int)($r['order_item_id'] ?? 0),
                    'product_id'          => (int)($r['product_id'] ?? 0),
                    'quantity'            => (int)($r['quantity'] ?? 0),
                    'design_file'         => trim((string)($r['design_file'] ?? '')),
                    'design_blob_len'     => (int)($r['design_blob_len'] ?? 0),
                    'design_image_name'   => trim((string)($r['design_image_name'] ?? '')),
                    'has_design_upload_data' => !empty($decoded['design_upload_data']),
                    'design_upload_path'  => trim((string)($decoded['design_upload_path'] ?? ($decoded['design_file'] ?? ''))),
                    'customization_len'   => strlen($cd),
                    'customization_blank' => in_array(trim($cd), ['', '[]', '{}', 'null'], true),
                    'customization_head'  => mb_substr($cd, 0, 160),
                ];
            }, $itemRows);

            $custRows = $repo->hasTable('customizations')
                ? (db_query('SELECT customization_id, order_item_id, status FROM customizations WHERE order_id = ?', 'i', [$orderId]) ?: [])
                : [];

            $jobRows = db_query('SELECT id, status, order_item_id FROM job_orders WHERE order_id = ?', 'i', [$orderId]) ?: [];

            // Does the broad list include it? (run unfiltered + branch-filtered)
            $listAll       = $repo->listOrders(null, null, 1000);
            $listBranch    = $repo->listOrders($branchFilter, null, 1000);
            $inListAll     = false;
            $inListBranch  = false;
            foreach ($listAll as $o) {
                if ((int)($o['order_id'] ?? 0) === $orderId) { $inListAll = true; break; }
            }
            foreach ($listBranch as $o) {
                if ((int)($o['order_id'] ?? 0) === $orderId) { $inListBranch = true; break; }
            }

            // Does the summary builder include it?
            $summaries = $service->listOrderSummaries($branchFilter, null, 1000);
            $inSummaries = false;
            foreach ($summaries as $s) {
                if ((int)($s['order_id'] ?? 0) === $orderId) { $inSummaries = true; break; }
            }

            cv2_json([
                'success' => true,
                'data' => [
                    'order_id'              => $orderId,
                    'resolved_branch_filter' => $branchFilter,
                    'order_row'             => $orderRow,
                    'schema' => [
                        'orders.order_type'            => $repo->hasColumn('orders', 'order_type'),
                        'orders.order_source'          => $repo->hasColumn('orders', 'order_source'),
                        'order_items.customization_data' => $repo->hasColumn('order_items', 'customization_data'),
                        'customizations_table'         => $repo->hasTable('customizations'),
                    ],
                    'order_items_count'     => count($itemRows),
                    'order_items'           => $itemsInfo,
                    'customizations_count'  => count($custRows),
                    'customizations'        => $custRows,
                    'job_orders_count'      => count($jobRows),
                    'job_orders'            => $jobRows,
                    'in_listOrders_all'     => $inListAll,
                    'in_listOrders_branch'  => $inListBranch,
                    'in_listOrderSummaries' => $inSummaries,
                    'total_listOrders_branch' => count($listBranch),
                ],
            ]);
            break;
        }

        case 'repair': {
            $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                cv2_json(['success' => false, 'message' => 'order_id is required.'], 400);
            }
            if (!function_exists('printflow_repair_order_missing_line_items')) {
                cv2_json(['success' => false, 'message' => 'Repair helper unavailable.'], 500);
            }
            $result = printflow_repair_order_missing_line_items($orderId);
            cv2_json([
                'success' => !empty($result['repaired']),
                'data'    => $result,
            ], !empty($result['repaired']) ? 200 : 422);
            break;
        }

        case 'repair_all': {
            if (!function_exists('printflow_repair_all_orders_missing_line_items')) {
                cv2_json(['success' => false, 'message' => 'Repair helper unavailable.'], 500);
            }
            $limit = (int)($_GET['limit'] ?? 500);
            $result = printflow_repair_all_orders_missing_line_items($limit);
            cv2_json(['success' => true, 'data' => $result]);
            break;
        }

        default:
            cv2_json(['success' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('customizations_v2 API error: ' . $e->getMessage());
    cv2_json(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
