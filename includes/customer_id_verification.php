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

function pf_decode_display_text(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_contains($decoded, '&')) {
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $decoded;
}

function pf_format_id_type_display(?string $id_type, bool $has_id = true): string
{
    $decoded = pf_decode_display_text((string)($id_type ?? ''));
    if ($decoded === '') {
        return $has_id ? 'Not specified' : '—';
    }

    return $decoded;
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

function pf_ensure_customer_id_verification_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $conn;
    if (empty($conn)) {
        return;
    }

    if (empty(db_query("SHOW COLUMNS FROM customers LIKE 'id_uploaded_at'"))) {
        @$conn->query('ALTER TABLE customers ADD COLUMN id_uploaded_at DATETIME NULL DEFAULT NULL AFTER id_reject_reason');
    }
    if (empty(db_query("SHOW COLUMNS FROM customers LIKE 'id_reviewed_at'"))) {
        @$conn->query('ALTER TABLE customers ADD COLUMN id_reviewed_at DATETIME NULL DEFAULT NULL AFTER id_uploaded_at');
    }

    if (!empty(db_query("SHOW COLUMNS FROM customers LIKE 'id_uploaded_at'"))) {
        @$conn->query(
            "UPDATE customers
             SET id_uploaded_at = created_at
             WHERE id_image IS NOT NULL AND TRIM(id_image) <> '' AND id_uploaded_at IS NULL"
        );
    }
}

function pf_customer_verification_pending_sql(): string
{
    return " (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified')) ";
}

function pf_customer_verification_has_image_sql(string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'id_image';
    return " ({$col} IS NOT NULL AND TRIM({$col}) <> '') ";
}

function pf_customer_id_verification_sql_filter(string $status_filter, string $upload_filter = ''): array
{
    pf_ensure_customer_id_verification_columns();

    $sql = '';
    $types = '';
    $params = [];

    if ($status_filter === 'Verified' || $status_filter === 'Rejected') {
        $sql .= " AND COALESCE(NULLIF(id_status, ''), 'Pending') = ?";
        $types .= 's';
        $params[] = $status_filter;
    } elseif ($status_filter === 'Pending') {
        $sql .= ' AND ' . pf_customer_verification_pending_sql();
    }

    if ($upload_filter === 'with_id') {
        $sql .= ' AND ' . pf_customer_verification_has_image_sql();
    } elseif ($upload_filter === 'without_id') {
        $sql .= ' AND (id_image IS NULL OR TRIM(id_image) = \'\')';
    }

    return [$sql, $types, $params];
}

function pf_customer_verification_sort_clause(string $sort_by): string
{
    return match ($sort_by) {
        'oldest' => ' ORDER BY COALESCE(id_uploaded_at, created_at) ASC',
        'az' => ' ORDER BY first_name ASC, last_name ASC',
        'za' => ' ORDER BY first_name DESC, last_name DESC',
        default => ' ORDER BY COALESCE(id_uploaded_at, created_at) DESC',
    };
}

function pf_customer_verification_status_counts(string $branchSql = '', string $branchTypes = '', array $branchParams = []): array
{
    pf_ensure_customer_id_verification_columns();

    $base = "SELECT COUNT(*) AS c FROM customers WHERE 1=1" . $branchSql;
    $pending = (int)(db_query(
        $base . ' AND ' . pf_customer_verification_pending_sql() . ' AND ' . pf_customer_verification_has_image_sql(),
        $branchTypes,
        $branchParams
    )[0]['c'] ?? 0);
    $verified = (int)(db_query(
        $base . " AND COALESCE(NULLIF(id_status, ''), 'Pending') = 'Verified'",
        $branchTypes,
        $branchParams
    )[0]['c'] ?? 0);
    $rejected = (int)(db_query(
        $base . " AND id_status = 'Rejected'",
        $branchTypes,
        $branchParams
    )[0]['c'] ?? 0);
    $total = (int)(db_query($base, $branchTypes, $branchParams)[0]['c'] ?? 0);

    return [
        'all' => $total,
        'pending' => $pending,
        'verified' => $verified,
        'rejected' => $rejected,
        'new' => (int)(db_query(
            $base . ' AND ' . pf_customer_verification_pending_sql()
                . ' AND ' . pf_customer_verification_has_image_sql()
                . ' AND id_reviewed_at IS NULL',
            $branchTypes,
            $branchParams
        )[0]['c'] ?? 0),
    ];
}

function pf_customer_verification_is_new_submission(array $customer): bool
{
    $status = pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending');
    $hasImage = trim((string)($customer['id_image'] ?? '')) !== '';
    $reviewedAt = trim((string)($customer['id_reviewed_at'] ?? ''));

    return $status === 'Pending' && $hasImage && $reviewedAt === '';
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
        'id_uploaded_at' => !empty($customer['id_uploaded_at']) ? format_date($customer['id_uploaded_at']) : '',
        'is_new_submission' => pf_customer_verification_is_new_submission($customer),
        'id_type' => pf_decode_display_text((string)($customer['id_type'] ?? '')),
        'id_status' => pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending'),
        'id_image' => $id_image_raw !== '' ? $base_path . '/uploads/ids/' . ltrim($id_image_raw, '/') : null,
        'id_reject_reason' => pf_decode_display_text((string)($customer['id_reject_reason'] ?? '')),
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
    pf_ensure_customer_id_verification_columns();

    if ($action === 'approve') {
        $updated = db_execute("UPDATE customers SET id_status='Verified', id_reject_reason=NULL, id_reviewed_at=NOW() WHERE customer_id=?", 'i', [$cid]) !== false;
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
        $updated = db_execute("UPDATE customers SET id_status='Rejected', id_reject_reason=?, id_reviewed_at=NOW() WHERE customer_id=?", 'si', [$reason, $cid]) !== false;
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
    pf_ensure_customer_id_verification_columns();

    return (int)(db_query(
        'SELECT COUNT(*) AS c FROM customers
         WHERE ' . pf_customer_verification_pending_sql() . ' AND ' . pf_customer_verification_has_image_sql()
    )[0]['c'] ?? 0);
}

function pf_render_verification_table_rows(array $customers, string $base_path): string
{
    ob_start();

    if (empty($customers)) {
        echo '<tr id="emptyVerificationRow"><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td></tr>';
        return ob_get_clean();
    }

    echo '<tr id="emptyVerificationRow" style="display:none;"><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td></tr>';

    foreach ($customers as $customer) {
        $id_status = pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending');
        $status_style = pf_customer_id_status_badge_style($id_status);
        $payload_attr = pf_customer_verification_payload_attr($customer, $base_path);
        $has_id = trim((string)($customer['id_image'] ?? '')) !== '';
        $uploaded_label = !empty($customer['id_uploaded_at'])
            ? format_date($customer['id_uploaded_at'])
            : '—';
        $row_class = match ($id_status) {
            'Verified' => 'verification-row verification-row--approved',
            'Rejected' => 'verification-row verification-row--rejected',
            default => 'verification-row verification-row--pending',
        };
        $name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
        $email = strtolower((string)($customer['email'] ?? ''));
        $cid = (int)($customer['customer_id'] ?? 0);
        ?>
        <tr class="<?php echo $row_class; ?>" data-customer-id="<?php echo $cid; ?>" data-customer="<?php echo $payload_attr; ?>" onclick="openVerificationModal(<?php echo $cid; ?>, this)">
            <td style="color:#1f2937;"><?php echo $cid; ?></td>
            <td style="font-weight:500;color:#1f2937;">
                <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($name); ?>">
                    <?php echo htmlspecialchars($name); ?>
                </div>
            </td>
            <td style="text-transform:lowercase;">
                <div style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($email); ?>">
                    <?php echo htmlspecialchars($email); ?>
                </div>
            </td>
            <td><?php echo htmlspecialchars(pf_format_id_type_display($customer['id_type'] ?? '', $has_id)); ?></td>
            <td style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars($uploaded_label); ?></td>
            <td style="color:#6b7280;font-size:12px;"><?php echo format_date($customer['created_at']); ?></td>
            <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $status_style; ?>"><?php echo htmlspecialchars($id_status === 'Verified' ? 'Approved' : $id_status); ?></span></td>
            <td style="text-align:right;" class="no-print actions" onclick="event.stopPropagation()">
                <button type="button" onclick="event.stopPropagation();openVerificationModal(<?php echo $cid; ?>, this.closest('tr'))" class="btn-action blue">Review</button>
                <button type="button" onclick="event.stopPropagation();window.location.href='<?php echo $base_path; ?>/admin/customers_management.php?open_customer=<?php echo $cid; ?>'" class="btn-action teal">Profile</button>
            </td>
        </tr>
        <?php
    }

    return ob_get_clean();
}
