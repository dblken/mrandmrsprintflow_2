<?php

function proof_delivery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/staff/api/payment_proof_image.php');
$service = (string)file_get_contents($root . '/includes/payment_proof_serve.php');
$verification = (string)file_get_contents($root . '/includes/payment_verification.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$paymentPage = (string)file_get_contents($root . '/staff/payment_verification.php');
$adminPayment = (string)file_get_contents($root . '/admin/payment.php');
$jobApi = (string)file_get_contents($root . '/admin/job_orders_api.php');
$modalApi = (string)file_get_contents($root . '/staff/get_order_for_modal.php');
$folderRules = (string)file_get_contents($root . '/uploads/secure_payments/.htaccess');
$placeholder = $root . '/public/assets/images/payment-proof-placeholder.svg';

if (!function_exists('printflow_branch_filter_for_user')) {
    function printflow_branch_filter_for_user(): ?int { return 2; }
}
require_once $root . '/includes/payment_proof_serve.php';

proof_delivery_assert(
    strpos($folderRules, 'Require all denied') !== false
        && strpos($folderRules, 'Deny from all') !== false,
    'the secure payment folder remains denied to direct HTTP requests'
);
proof_delivery_assert(
    strpos($endpoint, "['Admin', 'Staff', 'Manager']") !== false
        && strpos($endpoint, 'printflow_payment_proof_staff_can_access($record)') !== false
        && strpos($service, 'printflow_branch_filter_for_user()') !== false,
    'the proof endpoint requires an operational role and branch-scoped authorization'
);
$_SESSION = ['user_type' => 'Staff'];
proof_delivery_assert(
    printflow_payment_proof_staff_can_access(['order_branch_id' => 2, 'branch_id' => 9])
        && !printflow_payment_proof_staff_can_access(['order_branch_id' => 3, 'branch_id' => 2]),
    'runtime authorization accepts the current staff branch and rejects a different order branch'
);
$_SESSION = ['user_type' => 'Manager'];
proof_delivery_assert(
    printflow_payment_proof_staff_can_access(['job_branch_id' => 2]),
    'runtime authorization permits a manager only for the assigned branch'
);
$_SESSION = ['user_type' => 'Customer'];
proof_delivery_assert(
    !printflow_payment_proof_staff_can_access(['branch_id' => 2]),
    'runtime authorization rejects non-staff roles'
);
proof_delivery_assert(
    strpos($endpoint, "\$_GET['id']") !== false
        && strpos($endpoint, "\$_GET['file']") === false
        && strpos($verification, '/staff/api/payment_proof_image.php?') !== false,
    'staff delivery uses database record identifiers and never accepts a browser-supplied file path'
);
proof_delivery_assert(
    strpos($service, "preg_match('#(?:^|/)\\.\\.(?:/|$)#'") !== false
        && strpos($service, 'realpath($root)') !== false
        && strpos($service, 'str_starts_with($normalizedRealFile, $rootPrefix)') !== false,
    'path traversal and symlink escapes are rejected against explicit upload roots'
);
proof_delivery_assert(
    printflow_payment_proof_resolve_file('../config.php') === null
        && printflow_payment_proof_resolve_file('/uploads/secure_payments/../../config.php') === null
        && printflow_payment_proof_resolve_file("bad\0name.jpg") === null,
    'runtime resolver rejects traversal and null-byte path attempts'
);
$storedFiles = array_values(array_filter(
    glob($root . '/uploads/secure_payments/*') ?: [],
    static fn(string $path): bool => is_file($path) && basename($path) !== '.htaccess'
));
if ($storedFiles !== []) {
    $resolved = printflow_payment_proof_resolve_file('/uploads/secure_payments/' . basename($storedFiles[0]));
    proof_delivery_assert(
        $resolved !== null && realpath($resolved) === realpath($storedFiles[0]),
        'runtime resolver finds an existing protected proof only inside an allowed root'
    );
}
proof_delivery_assert(
    strpos($service, 'new finfo(FILEINFO_MIME_TYPE)') !== false
        && strpos($service, "['image/jpeg', 'image/png', 'image/webp', 'application/pdf']") !== false
        && strpos($service, '@getimagesize($filepath)') !== false
        && strpos($service, "header('X-Content-Type-Options: nosniff')") !== false,
    'streaming validates MIME and image integrity and sends safe response headers'
);
proof_delivery_assert(
    strpos($service, "['Admin', 'Staff', 'Manager']") !== false
        && strpos($service, "http_response_code(403)") !== false,
    'the legacy filename endpoint refuses staff access instead of trusting a filename'
);
foreach ([$customizations, $paymentPage, $adminPayment, $jobApi, $modalApi] as $source) {
    proof_delivery_assert(
        strpos($source, 'image_broken.php') === false,
        'staff proof UI no longer references the missing PHP fallback'
    );
}
proof_delivery_assert(
    strpos($customizations, 'payment_proof_image.php?id=') !== false
        && strpos($customizations, 'handlePaymentProofImageError') !== false
        && strpos($customizations, 'proofFallbackApplied') !== false,
    'Customizations uses the secure endpoint and applies its placeholder only once'
);
proof_delivery_assert(
    strpos($paymentPage, 'payment_verification_staff_proof_url') !== false
        && strpos($paymentPage, 'payment_verification_proof_url($previewPath)') === false,
    'Payment Verification uses record-ID proof URLs for thumbnails and full images'
);
proof_delivery_assert(
    is_file($placeholder)
        && str_contains((string)file_get_contents($placeholder), '<svg'),
    'the static payment-proof placeholder exists and is a valid SVG asset'
);

echo "Secure payment-proof delivery regression test passed.\n";
