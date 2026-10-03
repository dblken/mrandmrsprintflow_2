<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/provider_payments.php';

if (!is_logged_in() || get_user_type() !== 'Customer') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Customer access is required.';
    exit;
}

$subjectType = trim((string)($_GET['subject_type'] ?? ''));
$subjectId = (int)($_GET['subject_id'] ?? 0);

if ($subjectType === '' && isset($_GET['job_order_id'])) {
    $subjectType = 'job_order';
    $subjectId = (int)$_GET['job_order_id'];
} elseif ($subjectType === '' && isset($_GET['order_id'])) {
    $subjectType = 'order';
    $subjectId = (int)$_GET['order_id'];
}

if (!in_array($subjectType, ['order', 'job_order'], true) || $subjectId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid order.';
    exit;
}

$customerId = (int)get_user_id();
$subject = printflow_provider_payment_load_subject($subjectType, $subjectId);
if ($subject === [] || (int)($subject['customer_id'] ?? 0) !== $customerId) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Order not found.';
    exit;
}

$payment = printflow_provider_payment_for_customer($customerId, $subjectType, $subjectId);
$public = $payment !== [] ? printflow_provider_payment_public($payment) : [];
$qrUrl = trim((string)($public['qr_image_url'] ?? ''));

if ($qrUrl === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR image is not available. It may have expired. Refresh the payment page to generate a new code.';
    exit;
}

if (!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#', $qrUrl, $match)) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR download is not an image.';
    exit;
}

$imageBytes = base64_decode(str_replace(["\r", "\n"], '', $match[2]), true);
if ($imageBytes === false || strlen($imageBytes) < 100) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR image download failed.';
    exit;
}

$mime = $match[1] === 'jpeg' ? 'image/jpeg' : 'image/png';
$pngMagic = "\x89PNG\r\n\x1a\n";
$jpegMagic = "\xFF\xD8\xFF";
$isPng = str_starts_with($imageBytes, $pngMagic);
$isJpeg = str_starts_with($imageBytes, $jpegMagic);
if (($mime === 'image/png' && !$isPng) || ($mime === 'image/jpeg' && !$isJpeg)) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR download is not an image.';
    exit;
}

$extension = $mime === 'image/jpeg' ? 'jpg' : 'png';
$prefix = $subjectType === 'job_order' ? 'job-order' : 'order';
$filename = sprintf('printflow-qrph-%s-%d.%s', $prefix, $subjectId, $extension);

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($imageBytes));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $imageBytes;
