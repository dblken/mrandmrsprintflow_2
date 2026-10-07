<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/demo_seed_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!is_logged_in() || get_user_type() !== 'Admin') {
    http_response_code(is_logged_in() ? 403 : 401);
    echo json_encode(['success' => false, 'message' => 'Admin authorization required. Please sign in as an Admin.']);
    exit;
}
require_role('Admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$adminId = (int)get_user_id();

try {
    if (!in_array($action, ['delete_preview', 'delete_batch', 'list_batches', 'trace_seed_row'], true)) demo_seed_ensure_tables();

    if ($action === 'list_batches') {
        echo json_encode(['success' => true, 'batches' => demo_seed_list_batches()]);
        exit;
    }

    if ($action === 'validate_csv') {
        if (empty($_FILES['csv_file']) || !is_uploaded_file((string)($_FILES['csv_file']['tmp_name'] ?? ''))) {
            throw new RuntimeException('Please choose a CSV file to upload.');
        }
        $uploadError = demo_seed_validate_uploaded_file($_FILES['csv_file']);
        if ($uploadError !== null) {
            throw new RuntimeException($uploadError);
        }
        $content = file_get_contents((string)$_FILES['csv_file']['tmp_name']);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Could not read the uploaded CSV.');
        }
        $parsed = demo_seed_parse_csv_content($content);
        if ($parsed['errors'] !== []) {
            echo json_encode([
                'success' => false,
                'valid' => false,
                'message' => 'CSV structure validation failed.',
                'row_errors' => $parsed['errors'],
            ]);
            exit;
        }
        $validation = demo_seed_validate_rows($parsed['rows'], [
            'fallback_staff_user_id' => $adminId,
        ]);
        $token = null;
        if ($validation['valid']) {
            $token = demo_seed_store_preview(
                $validation,
                basename((string)($_FILES['csv_file']['name'] ?? 'upload.csv'))
            );
        }
        echo json_encode([
            'success' => true,
            'valid' => (bool)$validation['valid'],
            'preview_token' => $token,
            'summary' => $validation['summary'],
            'row_errors' => $validation['row_errors'],
            'row_resolutions' => $validation['row_resolutions'] ?? [],
            'cabuyao_branch_id' => demo_seed_cabuyao_branch_id(),
        ]);
        exit;
    }

    if ($action === 'resolver_reference') {
        echo json_encode([
            'success' => true,
            'reference' => demo_seed_resolver_reference($adminId),
        ]);
        exit;
    }

    if ($action === 'import_csv') {
        $token = trim((string)($_POST['preview_token'] ?? ''));
        $preview = demo_seed_get_preview($token);
        if ($preview === null || empty($preview['normalized_rows'])) {
            throw new RuntimeException('Validated preview expired or not found. Upload and validate the CSV again.');
        }
        $rows = $preview['normalized_rows'];
        $batchId = trim((string)($preview['summary']['seed_batch_id'] ?? ''));
        if ($batchId === '') {
            $batchId = 'meet_' . date('Ymd_His');
        }
        $revalidate = demo_seed_validate_rows(array_map(static function (array $row): array {
            return [
                'seed_row_key' => (string)$row['seed_row_key'],
                'seed_batch_id' => (string)$row['seed_batch_id'],
                'order_datetime' => (string)$row['order_datetime'],
                'order_status' => (string)$row['order_status'],
                'customization_status' => (string)$row['customization_status'],
                'job_status' => (string)$row['job_status'],
                'service_display_name' => (string)$row['service_display_name'],
                'job_service_type_enum' => (string)$row['job_service_type_enum'],
                'service_catalog_id' => (string)$row['service_catalog_id'],
                'unit_price' => (string)$row['unit_price'],
                'quantity' => '1',
                'payment_method' => 'Cash',
                'payment_status' => 'Paid',
                'amount_paid' => (string)$row['amount_paid'],
                'customer_first_name' => (string)$row['customer']['first_name'],
                'customer_last_name' => (string)$row['customer']['last_name'],
                'customer_email' => (string)$row['customer']['email'],
                'customer_phone' => (string)$row['customer']['phone'],
                'customer_street' => (string)$row['customer']['street'],
                'customer_barangay' => (string)$row['customer']['barangay'],
                'customer_city' => (string)$row['customer']['city'],
                'customer_province' => (string)$row['customer']['province'],
                'customer_postal' => (string)$row['customer']['postal'],
                'spec_summary' => (string)$row['spec_summary'],
                'staff_user_id' => (string)$row['staff_user_id'],
                'branch_id' => (string)$row['branch_id'],
            ];
        }, $rows), [
            'fallback_staff_user_id' => $adminId,
            'skip_active_batch_check' => true,
            'skip_customer_email_db_check' => true,
        ]);
        if (!$revalidate['valid']) {
            $first = $revalidate['row_errors'][0]['message'] ?? 'Validation failed.';
            $key = $revalidate['row_errors'][0]['seed_row_key'] ?? '';
            throw new RuntimeException(
                ($key !== '' ? ('[' . $key . '] ') : '') . 'Import blocked: ' . $first
            );
        }
        demo_seed_set_last_import_debug(null);
        $result = demo_seed_import_rows(
            $rows,
            $adminId,
            (string)($preview['file_name'] ?? 'upload.csv'),
            $batchId
        );
        echo json_encode(['success' => true, 'message' => 'Demo batch imported successfully.', 'result' => $result]);
        exit;
    }

    if ($action === 'delete_preview') {
        $batchId = (string)($_POST['batch_id'] ?? '');
        $verifiedOnly = (string)($_POST['verified_only'] ?? '') === '1';
        $preview = demo_seed_delete_preview($batchId, $verifiedOnly);
        $token = bin2hex(random_bytes(24));
        $_SESSION['demo_seed_deletion_previews'] = array_filter($_SESSION['demo_seed_deletion_previews'] ?? [], static fn($item) => ($item['expires_at'] ?? 0) > time());
        $_SESSION['demo_seed_deletion_previews'][$token] = [
            'batch_id' => $batchId, 'fingerprint' => $preview['fingerprint'],
            'verified_only' => $verifiedOnly, 'admin_id' => $adminId, 'expires_at' => time() + 900,
        ];
        if (count($_SESSION['demo_seed_deletion_previews']) > 10) array_shift($_SESSION['demo_seed_deletion_previews']);
        echo json_encode(['success' => true, 'preview' => $preview, 'preview_token' => $token]);
        exit;
    }

    if ($action === 'delete_batch') {
        $batchId = (string)($_POST['batch_id'] ?? '');
        $confirm = (string)($_POST['confirm_text'] ?? '');
        DemoSeedDeletionTool::authorize(get_user_type(), true, $confirm, (string)($_POST['backup_confirmed'] ?? '') === '1');
        $token = (string)($_POST['preview_token'] ?? '');
        $verifiedOnly = (string)($_POST['verified_only'] ?? '') === '1';
        $prior = $_SESSION['demo_seed_deletion_results'][$token] ?? null;
        if ($prior && $prior['batch_id'] === $batchId && $prior['admin_id'] === $adminId && $prior['expires_at'] > time()) {
            echo json_encode(['success' => !$prior['result']['partial'], 'completed' => true, 'message' => 'This deletion request already completed. No additional records deleted.', 'result' => ['batch_id' => $batchId, 'no_records' => true, 'deleted_counts' => [], 'protected_counts' => $prior['result']['protected_counts'], 'remaining_registry_rows' => $prior['result']['remaining_registry_rows'], 'partial' => $prior['result']['partial']]]);
            exit;
        }
        $stored = $_SESSION['demo_seed_deletion_previews'][$token] ?? null;
        if (!$stored || $stored['expires_at'] <= time() || $stored['admin_id'] !== $adminId || $stored['batch_id'] !== $batchId || $stored['verified_only'] !== $verifiedOnly) throw new RuntimeException('Dry run expired or does not match this batch and deletion mode. Run Dry Run again.');
        $result = demo_seed_delete_batch($batchId, $adminId, $stored['fingerprint'], $verifiedOnly);
        unset($_SESSION['demo_seed_deletion_previews'][$token]);
        $_SESSION['demo_seed_deletion_results'][$token] = ['batch_id' => $batchId, 'admin_id' => $adminId, 'result' => $result, 'expires_at' => time() + 900];
        if (count($_SESSION['demo_seed_deletion_results']) > 10) array_shift($_SESSION['demo_seed_deletion_results']);
        echo json_encode([
            'success' => !$result['partial'], 'completed' => true,
            'message' => $result['partial'] ? 'Verified records deleted. Protected or unverified records remain; the batch is not fully deleted.' : (!empty($result['no_records']) ? 'No demo records found.' : 'Demo batch deleted and verified.'),
            'result' => $result,
        ]);
        exit;
    }

    if ($action === 'active_batch') {
        $active = demo_seed_active_batch();
        echo json_encode(['success' => true, 'active_batch' => $active]);
        exit;
    }

    if ($action === 'trace_seed_row') {
        $batchId = trim((string)($_POST['batch_id'] ?? ''));
        if ($batchId === '') {
            $active = demo_seed_active_batch();
            $batchId = (string)($active['batch_id'] ?? '');
        }
        $seedRowKey = trim((string)($_POST['seed_row_key'] ?? ''));
        if ($batchId === '' || $seedRowKey === '') {
            throw new RuntimeException('batch_id and seed_row_key are required.');
        }
        echo json_encode([
            'success' => true,
            'trace' => demo_seed_trace_seed_row($batchId, $seedRowKey),
        ]);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    http_response_code(400);
    $message = $e->getMessage();
    if ($message === '') {
        $message = 'The demo data request failed.';
    }
    if ($action === 'delete_batch' || $action === 'delete_preview') {
        if (!str_starts_with($message, 'Demo batch')) {
            $message = 'Demo batch delete failed: ' . $message;
        }
    } elseif ($action === 'import_csv') {
        if (!str_starts_with($message, '[') && !str_contains($message, 'Import blocked')
            && !str_contains($message, 'active demo batch') && !str_contains($message, 'integrity')) {
            $message = 'Demo import failed: ' . $message;
        }
    } elseif ($action === 'validate_csv') {
        if (!str_contains($message, 'valid') && !str_contains($message, 'CSV') && !str_contains($message, 'Demo')) {
            $message = 'CSV validation failed: ' . $message;
        }
    }

    $parsed = demo_seed_parse_import_failure_message($message);
    $payload = [
        'success' => false,
        'message' => $message,
        'action' => $action,
        'seed_row_key' => $parsed['seed_row_key'],
        'failed_step' => $parsed['step'],
        'error_detail' => $parsed['message'],
    ];
    if ($action === 'import_csv') {
        $debug = demo_seed_get_last_import_debug();
        if ($debug !== null) {
            $payload['import_debug'] = $debug;
        }
    }
    echo json_encode($payload);
    error_log('[demo_seed_data API] action=' . $action . ' ' . $e->getMessage());
}
