<?php
/**
 * Customer order review submission endpoint and modal form fragment.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_rate_order.php';

require_role('Customer');
ensure_ratings_table_exists();
ensure_order_status_values(['To Rate', 'Rated']);

$customer_id = get_user_id();
$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$app_base = function_exists('pf_app_base_path') ? pf_app_base_path() : '';
$is_fragment = isset($_GET['fragment']) && (string)$_GET['fragment'] === '1';
$is_ajax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    if ($notif_id > 0) {
        db_execute('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND customer_id = ?', 'ii', [$notif_id, $customer_id]);
    }
}

if ($order_id <= 0) {
    if ($is_fragment || $is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid order selected for rating.']);
        exit;
    }
    $_SESSION['error'] = 'Invalid order selected for rating.';
    redirect($app_base . '/customer/orders.php?tab=completed');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = customer_rate_order_handle_post($order_id, $customer_id, $_POST, $_FILES);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    if (!empty($result['success'])) {
        $_SESSION['success'] = (string)($result['message'] ?? 'Thank you! Your review has been submitted.');
        redirect($app_base . '/customer/orders.php?tab=completed&highlight=' . $order_id);
    }

    $error = (string)($result['error'] ?? 'Could not submit your review.');
}

$context = customer_rate_order_load($order_id, $customer_id);
if ($context === null) {
    if ($is_fragment || $is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found or not eligible for review.']);
        exit;
    }
    $_SESSION['error'] = 'Order not found.';
    redirect($app_base . '/customer/orders.php?tab=completed');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$is_fragment) {
    redirect($app_base . '/customer/orders.php?tab=completed&highlight=' . $order_id . '&review_prompt=1');
}

if (!$context['can_submit']) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'You already submitted a review for this order.']);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$form_id_prefix = 'pfRateModal';
require __DIR__ . '/partials/rate_order_form.php';
