<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/demo_seed_data.php';

require_role('Admin');

header('Content-Type: application/json; charset=utf-8');

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
    demo_seed_ensure_tables();

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
        $validation = demo_seed_validate_rows($parsed['rows']);
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
            'cabuyao_branch_id' => demo_seed_cabuyao_branch_id(),
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
        }, $rows));
        if (!$revalidate['valid']) {
            throw new RuntimeException('Import blocked because validation no longer passes.');
        }
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
        $preview = demo_seed_delete_preview();
        echo json_encode(['success' => true, 'preview' => $preview]);
        exit;
    }

    if ($action === 'delete_batch') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        if ($confirm !== 'DELETE MEETING DATA') {
            throw new RuntimeException('Confirmation text did not match.');
        }
        $batchId = trim((string)($_POST['batch_id'] ?? ''));
        if ($batchId === '') {
            $active = demo_seed_active_batch();
            $batchId = (string)($active['batch_id'] ?? '');
        }
        if ($batchId === '') {
            throw new RuntimeException('No active demo batch is available to delete.');
        }
        $result = demo_seed_delete_batch($batchId, $adminId);
        echo json_encode(['success' => true, 'message' => 'Demo batch deleted successfully.', 'result' => $result]);
        exit;
    }

    if ($action === 'active_batch') {
        $active = demo_seed_active_batch();
        echo json_encode(['success' => true, 'active_batch' => $active]);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Request could not be completed. Please review the CSV and try again.',
    ]);
    error_log('[demo_seed_data API] ' . $e->getMessage());
}
