<?php
/**
 * Shared customer ID verification helpers (customers.id_status, id_image, id_type).
 */

if (!defined('PF_CUSTOMER_ID_REJECTION_OPTIONS')) {
    define('PF_CUSTOMER_ID_REJECTION_OPTIONS', [
        'Invalid or unreadable ID',
        'Expired ID',
        'Unsupported ID type',
        'Information mismatch',
        'Incomplete or cropped ID',
        'Low image quality',
        'Duplicate ID detected',
        'Suspicious or altered ID',
        'Other',
    ]);
}

function pf_customer_id_status_normalize($status): string
{
    $raw = trim((string)$status);
    return match (strtolower($raw)) {
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
}

function pf_customer_id_status_badge_style(string $status): string
{
    return match (pf_customer_id_status_normalize($status)) {
        'Verified' => 'background:#dcfce7;color:#166534;',
        'Rejected' => 'background:#fee2e2;color:#991b1b;',
        default => 'background:#fef9c3;color:#854d0e;',
    };
}

function pf_customer_id_rejection_reason(string $selected, string $custom = ''): string
{
    $selected = trim($selected);
    $custom = trim($custom);

    if (!in_array($selected, PF_CUSTOMER_ID_REJECTION_OPTIONS, true)) {
        $selected = '';
    }

    $custom = sanitize($custom);
    $custom = preg_replace('/\s+/', ' ', $custom ?? '');
    $custom = trim((string)$custom);

    if ($selected === 'Other') {
        if ($custom === '') {
            return 'ID could not be verified. Please resubmit a clearer photo.';
        }
        return mb_substr($custom, 0, 250);
    }

    if ($selected !== '') {
        if ($custom !== '') {
            return mb_substr($selected . ': ' . $custom, 0, 250);
        }
        return $selected;
    }

    return 'ID could not be verified. Please resubmit a clearer photo.';
}

function pf_customer_id_verification_sql_filter(string $status_filter, string $upload_filter = ''): array
{
    $sql = '';
    $types = '';
    $params = [];

    if ($status_filter === 'Verified' || $status_filter === 'Rejected') {
        $sql .= " AND COALESCE(NULLIF(id_status, ''), 'Pending') = ?";
        $types .= 's';
        $params[] = $status_filter;
    } elseif ($status_filter === 'Pending') {
        $sql .= " AND (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified'))";
    }

    if ($upload_filter === 'with_id') {
        $sql .= " AND id_image IS NOT NULL AND TRIM(id_image) <> ''";
    } elseif ($upload_filter === 'without_id') {
        $sql .= " AND (id_image IS NULL OR TRIM(id_image) = '')";
    }

    return [$sql, $types, $params];
}

function pf_build_customer_verification_payload(array $customer, string $base_path): array
{
    $id_image_raw = trim((string)($customer['id_image'] ?? ''));
    return [
        'customer_id' => (int)($customer['customer_id'] ?? 0),
        'first_name' => (string)($customer['first_name'] ?? ''),
        'last_name' => (string)($customer['last_name'] ?? ''),
        'email' => (string)($customer['email'] ?? ''),
        'contact_number' => (string)($customer['contact_number'] ?? ''),
        'created_at' => !empty($customer['created_at']) ? format_date($customer['created_at']) : '',
        'id_type' => (string)($customer['id_type'] ?? ''),
        'id_status' => pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending'),
        'id_image' => $id_image_raw !== '' ? $base_path . '/uploads/ids/' . ltrim($id_image_raw, '/') : null,
        'id_reject_reason' => (string)($customer['id_reject_reason'] ?? ''),
        'has_id_image' => $id_image_raw !== '',
    ];
}

function pf_customer_verification_payload_attr(array $customer, string $base_path): string
{
    return htmlspecialchars(
        json_encode(
            pf_build_customer_verification_payload($customer, $base_path),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}',
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Handle approve/reject POST for customer ID verification.
 */
function pf_process_customer_id_verification_post(string $redirect_url = 'customer_verification.php'): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id_action'])) {
        return;
    }

    $isAjax = !empty($_POST['ajax']);
    $respond = static function (array $payload, int $status = 200) use ($isAjax): void {
        if ($isAjax) {
            http_response_code($status);
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }
    };

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $respond([
            'success' => false,
            'code' => 'csrf_mismatch',
            'error' => 'Your session expired. Please refresh and try again.',
            'csrf_token' => generate_csrf_token(),
        ], 419);
    }

    if (($_SESSION['user_type'] ?? '') !== 'Admin') {
        $respond(['success' => false, 'error' => 'Only admins can verify or reject customer IDs.'], 403);
        http_response_code(403);
        exit;
    }

    $cid = (int)($_POST['cid'] ?? 0);
    $action = trim((string)($_POST['id_action'] ?? ''));
    if ($cid <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        $respond(['success' => false, 'error' => 'Invalid verification request.'], 422);
        header('Location: ' . $redirect_url . '?reviewed=0');
        exit;
    }

    $customerRows = db_query(
        "SELECT customer_id, id_image FROM customers WHERE customer_id = ? LIMIT 1",
        'i',
        [$cid]
    );
    if (empty($customerRows)) {
        $respond(['success' => false, 'error' => 'Customer not found.'], 404);
        header('Location: ' . $redirect_url . '?reviewed=0');
        exit;
    }

    if (trim((string)($customerRows[0]['id_image'] ?? '')) === '') {
        $respond(['success' => false, 'error' => 'This customer has no uploaded ID image to review.'], 422);
        header('Location: ' . $redirect_url . '?reviewed=0');
        exit;
    }

    $reason = '';
    if ($action === 'approve') {
        $updated = db_execute("UPDATE customers SET id_status='Verified', id_reject_reason=NULL WHERE customer_id=?", 'i', [$cid]) !== false;
        if ($updated) {
            try {
                create_notification($cid, 'Customer', 'Your ID has been verified! You can now place orders.', 'System', false, false);
            } catch (Throwable $e) {
                error_log('ID approval notification failed for customer ' . $cid . ': ' . $e->getMessage());
            }
        }
    } else {
        $reason = pf_customer_id_rejection_reason(
            (string)($_POST['reject_reason'] ?? ''),
            (string)($_POST['reject_reason_other'] ?? '')
        );
        $updated = db_execute("UPDATE customers SET id_status='Rejected', id_reject_reason=? WHERE customer_id=?", 'si', [$reason, $cid]) !== false;
        if ($updated) {
            try {
                create_notification($cid, 'Customer', 'Your ID verification was rejected: ' . $reason, 'System', false, false);
            } catch (Throwable $e) {
                error_log('ID rejection notification failed for customer ' . $cid . ': ' . $e->getMessage());
            }
        }
    }

    if (!$updated) {
        $respond(['success' => false, 'error' => 'Failed to update the customer ID status.'], 500);
        header('Location: ' . $redirect_url . '?reviewed=0');
        exit;
    }

    $verifyRows = db_query(
        "SELECT customer_id, id_status, id_reject_reason FROM customers WHERE customer_id = ? LIMIT 1",
        'i',
        [$cid]
    );
    $verifiedRow = $verifyRows[0] ?? [];
    if ($isAjax) {
        $respond([
            'success' => true,
            'customer_id' => $cid,
            'id_status' => pf_customer_id_status_normalize($verifiedRow['id_status'] ?? ''),
            'id_reject_reason' => (string)($verifiedRow['id_reject_reason'] ?? ''),
        ]);
    }

    header('Location: ' . $redirect_url . '?reviewed=1');
    exit;
}

function pf_count_customer_verification_pending(): int
{
    return (int)(db_query(
        "SELECT COUNT(*) AS c FROM customers
         WHERE (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified'))
           AND id_image IS NOT NULL AND TRIM(id_image) <> ''"
    )[0]['c'] ?? 0);
}
