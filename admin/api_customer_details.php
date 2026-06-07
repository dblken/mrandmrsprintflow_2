<?php
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/customer_id_verification.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

if (!isset($_GET['id'])) {
    echo json_encode(
        ['success' => false, 'error' => 'No customer ID provided'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$id = intval($_GET['id']);

try {
    $viewerBranch = printflow_branch_filter_for_user();
    if (get_user_type() !== 'Admin' && $viewerBranch) {
        [$custWhere, $custTypes, $custParams] = branch_customers_belong_where_sql((int)$viewerBranch, 'c');
        $allowed = db_query(
            "SELECT c.customer_id FROM customers c WHERE c.customer_id = ?" . $custWhere . " LIMIT 1",
            'i' . $custTypes,
            array_merge([$id], $custParams)
        );
        if (empty($allowed)) {
            echo json_encode(
                ['success' => false, 'error' => 'Customer not found'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }
    }

    $customer = db_query("SELECT * FROM customers WHERE customer_id = ?", "i", [$id]);

    if (empty($customer)) {
        echo json_encode(
            ['success' => false, 'error' => 'Customer not found'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
    
    $c = $customer[0];
    $normalized_id_status = match (strtolower(trim((string)($c['id_status'] ?? '')))) {
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
    
    // Format profile picture path
    $profile_picture = null;
    if (!empty($c['profile_picture'])) {
        $profile_picture = $base_path . '/public/assets/uploads/profiles/' . $c['profile_picture'];
    }
    
    // Format Data
    $data = [
        'customer_id' => $c['customer_id'],
        'first_name' => $c['first_name'],
        'middle_name' => $c['middle_name'] ?? '',
        'last_name' => $c['last_name'],
        'email' => $c['email'],
        'contact_number' => $c['contact_number'] ?? '',
        'address' => $c['address'] ?? '',
        'dob' => $c['dob'] ? date('m/d/Y', strtotime($c['dob'])) : '',
        'gender' => $c['gender'] ?? '',
        'created_at' => date('M j, Y', strtotime($c['created_at'])),
        'profile_picture' => $profile_picture,
        'initial' => strtoupper(substr($c['first_name'], 0, 1)),
        'id_status' => $normalized_id_status,
        'id_type'   => pf_decode_display_text((string)($c['id_type'] ?? '')),
        'id_image'  => !empty($c['id_image']) ? $base_path . '/uploads/ids/' . $c['id_image'] : null,
        'id_reject_reason' => pf_decode_display_text((string)($c['id_reject_reason'] ?? ''))
    ];

    echo json_encode(
        ['success' => true, 'customer' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(
        ['success' => false, 'error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
?>
