<?php
/**
 * Authorized payment-proof file streaming (staff or owning customer).
 *
 * Canonical implementation used by api_view_proof.php (root) and staff/api_view_proof.php.
 */

function printflow_serve_payment_proof(): void {
    $file = rawurldecode((string)($_GET['file'] ?? ''));
    $normalized_file = str_replace('\\', '/', $file);
    $basename = basename($normalized_file);

    if ($basename === '' || $basename === '.' || $basename === '..') {
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    $projectRoot = dirname(__DIR__);

    $candidates = [
        $projectRoot . '/uploads/secure_payments/' . $basename,
        $projectRoot . '/uploads/payments/' . $basename,
        $projectRoot . '/public/uploads/payments/' . $basename,
        $projectRoot . '/public/uploads/secure_payments/' . $basename,
        $projectRoot . '/public/assets/uploads/payments/' . $basename,
        $projectRoot . '/public/assets/uploads/secure_payments/' . $basename,
    ];

    if (strpos($normalized_file, '/printflow/') === 0) {
        $sub = substr($normalized_file, strlen('/printflow'));
        $sub = '/' . ltrim((string)$sub, '/');
        $candidates[] = $projectRoot . $sub;
        $candidates[] = $projectRoot . '/public/assets' . $sub;
        $candidates[] = $projectRoot . '/public' . $sub;
    }

    if (preg_match('#^/?uploads/#i', $normalized_file)) {
        $rel = '/' . ltrim($normalized_file, '/');
        $candidates[] = $projectRoot . $rel;
        $candidates[] = $projectRoot . '/public' . $rel;
        $candidates[] = $projectRoot . '/public/assets' . $rel;
    }

    $uploads_pos = stripos($normalized_file, '/uploads/');
    if ($uploads_pos !== false) {
        $sub = substr($normalized_file, $uploads_pos);
        $candidates[] = $projectRoot . $sub;
        $candidates[] = $projectRoot . '/public/assets' . $sub;
        $candidates[] = $projectRoot . '/public' . $sub;
    }

    $filepath = '';
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '' || !is_file($candidate)) {
            continue;
        }

        $real = realpath($candidate);
        if ($real === false) {
            continue;
        }

        $real_n = str_replace('\\', '/', strtolower((string)$real));

        if (strpos($real_n, '/uploads/') !== false) {
            $filepath = $real;
            break;
        }
    }

    if ($filepath === '') {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }

    // 1. Staff-facing roles (orders / modal)
    $is_staff = isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['Admin', 'Staff', 'Manager'], true);

    $is_owner = false;
    if (!$is_staff && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Customer') {
        $customer_id = (int)$_SESSION['user_id'];
        $has_needs_reset = false;
        if (function_exists('db_table_has_column')) {
            $has_needs_reset = db_table_has_column('orders', 'payment_proof_needs_resubmit');
        }

        // job_orders linkage
        $check = db_query(
            'SELECT id FROM job_orders '
            . 'WHERE customer_id = ? '
            . 'AND (payment_proof_path = ? OR payment_proof_path LIKE CONCAT(\'%\', ?, \'%\')) '
            . 'LIMIT 1',
            'iss',
            [$customer_id, $normalized_file, $basename]
        );
        if (!empty($check)) {
            $is_owner = true;
        }

        // Store orders (include rejected-but-proof-retained flows).
        // Wrap OR arms in parentheses so we never widen beyond this customer_id.
        if (!$is_owner) {
            $orderInner = '(payment_proof = ? OR payment_proof LIKE CONCAT(\'%\', ?, \'%\'))';
            $types_o = 'iss';
            $params_o = [$customer_id, $normalized_file, $basename];

            if (function_exists('db_table_has_column') && db_table_has_column('orders', 'payment_proof_path')) {
                $orderInner .= ' OR (payment_proof_path = ? OR payment_proof_path LIKE CONCAT(\'%\', ?, \'%\'))';
                $types_o .= 'ss';
                $params_o[] = $normalized_file;
                $params_o[] = $basename;
            }

            $orderSql = 'SELECT order_id FROM orders WHERE customer_id = ? AND (' . $orderInner . ') LIMIT 1';
            $check_o = db_query($orderSql, $types_o, $params_o);
            if (!empty($check_o)) {
                $is_owner = true;
            }
        }

        // Fallback: awaiting resubmit — compare basename against rows this customer owns
        if (!$is_owner && $has_needs_reset) {
            $check_need = db_query(
                'SELECT order_id, payment_proof FROM orders WHERE customer_id = ? AND payment_proof_needs_resubmit = 1 '
                . 'ORDER BY order_date DESC LIMIT 30',
                'i',
                [$customer_id]
            ) ?: [];
            foreach ($check_need as $row) {
                $pp = trim((string)($row['payment_proof'] ?? ''));
                if ($pp === '') {
                    continue;
                }
                if ($pp === $normalized_file
                    || strcasecmp(basename(str_replace('\\', '/', $pp)), $basename) === 0
                ) {
                    $is_owner = true;
                    break;
                }
            }
        }
    }

    if (!$is_staff && !$is_owner) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $mime = @mime_content_type($filepath) ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($filepath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    readfile($filepath);
    exit;
}
