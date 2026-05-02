<?php
/**
 * Customer Rate Order Page
 * Enhanced with multiple images and video support
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');
ensure_ratings_table_exists();
ensure_order_status_values(['To Rate', 'Rated']);

$customer_id = get_user_id();
$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$app_base = function_exists('pf_app_base_path') ? pf_app_base_path() : '';

$review_cols_raw = db_query("SHOW COLUMNS FROM reviews") ?: [];
$review_cols = array_map(static function ($col) {
    return (string)($col['Field'] ?? '');
}, $review_cols_raw);
$review_cols = array_filter($review_cols, static fn($v) => $v !== '');
$review_user_col = in_array('user_id', $review_cols, true) ? 'user_id' : (in_array('customer_id', $review_cols, true) ? 'customer_id' : 'user_id');
$review_message_col = in_array('comment', $review_cols, true) ? 'comment' : (in_array('message', $review_cols, true) ? 'message' : 'comment');
$review_has_video = in_array('video_path', $review_cols, true);
$review_has_type = in_array('review_type', $review_cols, true);
$review_has_ref = in_array('reference_id', $review_cols, true);
$review_has_service = in_array('service_type', $review_cols, true);

if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    if ($notif_id > 0) {
        db_execute("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND customer_id = ?", 'ii', [$notif_id, $customer_id]);
    }
}

if ($order_id <= 0) {
    $_SESSION['error'] = 'Invalid order selected for rating.';
    redirect($app_base . '/customer/orders.php?tab=completed');
}

$order_rows = db_query("
    SELECT o.order_id, o.customer_id, o.status,
           (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) AS order_sku,
           (SELECT oi.customization_data FROM order_items oi WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS customization_data,
           (SELECT p.name FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS product_name,
           (SELECT oi.order_item_id FROM order_items oi WHERE oi.order_id = o.order_id ORDER BY oi.order_item_id ASC LIMIT 1) AS first_item_id
    FROM orders o
    WHERE o.order_id = ? AND o.customer_id = ?
    LIMIT 1
", 'ii', [$order_id, $customer_id]);

if (empty($order_rows)) {
    $_SESSION['error'] = 'Order not found.';
    redirect($app_base . '/customer/orders.php?tab=completed');
}

$order = $order_rows[0];
if (!in_array((string)$order['status'], ['Completed', 'To Rate', 'Rated'], true)) {
    $_SESSION['error'] = 'You can only rate completed orders.';
    redirect($app_base . '/customer/orders.php');
}

$existing = db_query("SELECT id, rating, {$review_message_col} AS review_message, created_at FROM reviews WHERE order_id = ? LIMIT 1", 'i', [$order_id]);
$already_rated = !empty($existing);
$review_id = $already_rated ? (int)$existing[0]['id'] : 0;
$existing_rating = $already_rated ? (int)$existing[0]['rating'] : 0;
$existing_message = $already_rated ? (string)($existing[0]['review_message'] ?? '') : '';
$needs_message_update = $already_rated && (trim($existing_message) === '' || $existing_message === '(No comment provided)');

function resolve_service_type_label(array $order): string
{
    $service = '';
    if (!empty($order['customization_data'])) {
        $json = json_decode((string)$order['customization_data'], true);
        if (is_array($json)) {
            $service = (string)($json['service_type'] ?? $json['product_type'] ?? '');
        }
    }
    if ($service === '') {
        $service = (string)($order['product_name'] ?? 'Print Service');
    }
    return normalize_service_name($service, 'Print Service');
}

$service_type_label = resolve_service_type_label($order);
$order_code = printflow_format_order_code($order['order_id'] ?? 0, $order['order_sku'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif ($already_rated && !$needs_message_update) {
        $error = 'You already rated this order.';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));

        if ($rating < 1 || $rating > 5) {
            $error = 'Please select a star rating from 1 to 5.';
        } elseif (mb_strlen($message) < 5) {
            $error = 'Please write at least 5 characters in your feedback.';
        } elseif (mb_strlen($message) > 500) {
            $error = 'Feedback message is too long (max 500 characters).';
        } else {
            $video_path = null;
            $uploaded_images = [];

            if (!empty($_FILES['review_video']['name'])) {
                $ext = strtolower(pathinfo($_FILES['review_video']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'mp4') {
                    $error = 'Video must be in MP4 format.';
                } elseif ($_FILES['review_video']['size'] > 15 * 1024 * 1024) {
                    $error = 'Video size exceeds 15MB limit.';
                } else {
                    $video_name = 'review_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.mp4';
                    $upload = upload_file($_FILES['review_video'], ['mp4'], 'reviews_videos', $video_name);
                    if (empty($upload['success'])) {
                        $error = $upload['error'] ?? 'Failed to upload video.';
                    } else {
                        $video_path = $upload['file_path'];
                    }
                }
            }

            if ($error === '' && !empty($_FILES['review_images']['name'])) {
                $files = $_FILES['review_images'];
                $file_count = is_array($files['name']) ? count($files['name']) : 1;

                if ($file_count > 5) {
                    $error = 'You can only upload up to 5 images.';
                } else {
                    for ($i = 0; $i < $file_count; $i++) {
                        $f = [
                            'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                            'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                            'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                            'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                            'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
                        ];

                        if (empty($f['name'])) {
                            continue;
                        }

                        $upload = upload_file($f, ['jpg', 'jpeg', 'png', 'webp'], 'reviews_images');
                        if (empty($upload['success'])) {
                            $error = $upload['error'] ?? 'Failed to upload image ' . ($i + 1);
                            break;
                        }
                        $uploaded_images[] = $upload['file_path'];
                    }
                }
            }

            if ($error === '') {
                try {
                    $ref_id = 0;
                    $rev_type = 'custom';

                    $item_ref = db_query("SELECT product_id FROM order_items WHERE order_id = ? LIMIT 1", 'i', [$order_id]);
                    if (!empty($item_ref) && !empty($item_ref[0]['product_id'])) {
                        $ref_id = (int)$item_ref[0]['product_id'];
                        $rev_type = 'product';
                    }

                    if ($needs_message_update) {
                        $update_cols = "rating = ?, {$review_message_col} = ?";
                        $update_types = 'is';
                        $update_vals = [$rating, $message];
                        if ($review_has_video) {
                            $update_cols .= ", video_path = COALESCE(?, video_path)";
                            $update_types .= 's';
                            $update_vals[] = $video_path;
                        }
                        $update_cols .= " WHERE id = ?";
                        $update_types .= 'i';
                        $update_vals[] = $review_id;

                        $updated = db_execute(
                            "UPDATE reviews SET {$update_cols}",
                            $update_types,
                            $update_vals
                        );
                        if ($updated === false) {
                            throw new RuntimeException('Failed to update your review.');
                        }
                        $new_review_id = $review_id;
                    } else {
                        $cols = ['order_id', $review_user_col, 'rating', $review_message_col];
                        $vals = [$order_id, $customer_id, $rating, $message];
                        $types = 'iiss';

                        if ($review_has_service) {
                            $cols[] = 'service_type';
                            $vals[] = $service_type_label;
                            $types .= 's';
                        }
                        if ($review_has_video) {
                            $cols[] = 'video_path';
                            $vals[] = $video_path;
                            $types .= 's';
                        }
                        if ($review_has_type) {
                            $cols[] = 'review_type';
                            $vals[] = $rev_type;
                            $types .= 's';
                        }
                        if ($review_has_ref) {
                            $cols[] = 'reference_id';
                            $vals[] = $ref_id;
                            $types .= 'i';
                        }

                        $placeholders = implode(',', array_fill(0, count($cols), '?'));
                        $cols[] = 'created_at';
                        $insert_result = db_execute(
                            "INSERT INTO reviews (" . implode(', ', $cols) . ") VALUES ({$placeholders}, NOW())",
                            $types,
                            $vals
                        );
                        if ($insert_result === false) {
                            throw new RuntimeException('Failed to save your review.');
                        }

                        $new_review_id = is_numeric($insert_result) ? (int)$insert_result : 0;
                        if ($new_review_id <= 0) {
                            $last_insert = db_query("SELECT LAST_INSERT_ID() as id");
                            $new_review_id = (int)($last_insert[0]['id'] ?? 0);
                        }
                        if ($new_review_id <= 0) {
                            throw new RuntimeException('Could not confirm the saved review ID.');
                        }
                    }

                    foreach ($uploaded_images as $img) {
                        $image_result = db_execute("INSERT INTO review_images (review_id, image_path) VALUES (?, ?)", 'is', [$new_review_id, $img]);
                        if ($image_result === false) {
                            throw new RuntimeException('Failed to save one of the review images.');
                        }
                    }

                    $order_update = db_execute("UPDATE orders SET status = 'Rated' WHERE order_id = ?", 'i', [$order_id]);
                    if ($order_update === false) {
                        throw new RuntimeException('Failed to update the order status.');
                    }

                    $staff_msg = "Customer submitted a review for Order #{$order_id}: {$rating}/5 stars.";
                    notify_shop_users($staff_msg, 'Rating', false, false, $order_id, ['Staff', 'Admin', 'Manager']);

                    $_SESSION['success'] = 'Thank you! Your review has been submitted.';
                    redirect($app_base . '/customer/orders.php?tab=completed&highlight=' . $order_id);
                } catch (Throwable $e) {
                    error_log('[rate_order] order_id=' . $order_id . ' customer_id=' . $customer_id . ' error=' . $e->getMessage());
                    $error = 'Could not submit your review: ' . $e->getMessage();
                }
            }
        }
    }
}

$page_title = 'Rate Order - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.rate-page {
    --pf-accent: #53c5e0;
    --pf-accent-dark: #32a1c4;
    --pf-border: #e2e8f0;
    --pf-muted: #64748b;
    --pf-soft: #f8fafc;
}
.rate-wrap { max-width: 1100px; margin: 0 auto; padding: 0 1rem; }
.rate-shell { background: rgba(0, 49, 61, 0.88); border: 1px solid rgba(83, 197, 224, 0.18); border-radius: 12px; overflow: hidden; }
.rate-shell-head { background: rgba(0, 28, 36, 0.98); border-bottom: 1px solid rgba(83, 197, 224, 0.15); padding: 1rem 1.25rem; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; }
.rate-shell-title,
.rate-shell-head .rate-shell-title,
.rate-shell-head h1.rate-shell-title { margin: 0; font-size: 1.35rem; font-weight: 800; color: #ffffff !important; }
.rate-shell-sub { margin: 0.2rem 0 0; font-size: 0.92rem; color: #94a3b8; font-weight: 600; }
.rate-shell-copy { min-width: 0; }
.rate-back-link { display: inline-flex; align-items: center; gap: 0.45rem; color: #d7e7ee; text-decoration: none; font-weight: 700; font-size: 0.92rem; align-self: flex-start; }
.rate-back-link:hover { color: #ffffff; }
.rate-body { background: #ffffff; padding: 1.5rem; }
.rate-card { background: #ffffff; border: 1px solid var(--pf-border); border-radius: 12px; padding: 1.5rem; }
.rate-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.9rem; margin-bottom: 1.5rem; }
.rate-info-item { background: var(--pf-soft); border: 1px solid var(--pf-border); border-radius: 10px; padding: 0.9rem 1rem; }
.rate-info-label { display: block; font-size: 0.72rem; color: var(--pf-muted); font-weight: 800; text-transform: uppercase; margin-bottom: 0.25rem; }
.rate-info-value { font-size: 1rem; color: #0f172a; font-weight: 800; }
.rate-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; }
.rate-panel { border: 1px solid var(--pf-border); border-radius: 12px; background: #ffffff; padding: 1.25rem; }
.rate-stars { display: flex; gap: 10px; margin-bottom: 1.25rem; flex-wrap: wrap; }
.rate-star-btn { width: 52px; height: 52px; border: 1px solid #cbd5e1; border-radius: 0.85rem; background: #ffffff; color: #cbd5e1; font-size: 32px; line-height: 1; cursor: pointer; transition: all 0.24s; display: flex; align-items: center; justify-content: center; padding-bottom: 4px; font-family: inherit; }
.rate-star-btn:hover { border-color: #f59e0b; color: #f59e0b; background: #fff7ed; transform: translateY(-2px); }
.rate-star-btn.active { border-color: #f59e0b; background: #fff7ed; color: #f59e0b; box-shadow: 0 6px 15px rgba(245, 158, 11, 0.16); }
.rate-star-btn:disabled { cursor: default; transform: none !important; opacity: 1 !important; }
.rate-label { display: block; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--pf-muted); margin-bottom: 0.6rem; font-family: inherit; }
.rate-textarea { width: 100%; min-height: 220px; border: 1px solid #cbd5e1; border-radius: 12px; background: #ffffff; color: #0f172a; padding: 1rem 1.05rem; font-size: 0.98rem; font-family: inherit; resize: vertical; outline: none; transition: all 0.2s; line-height: 1.6; box-sizing: border-box; }
.rate-textarea:focus { border-color: var(--pf-accent); box-shadow: 0 0 0 4px rgba(83, 197, 224, 0.18); }
.rate-textarea::placeholder { color: #94a3b8; font-family: inherit; }
.upload-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 10px; margin-top: 10px; }
.upload-box { position: relative; aspect-ratio: 1; border: 2px dashed #cbd5e1; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--pf-muted); cursor: pointer; transition: all 0.2s; overflow: hidden; background: var(--pf-soft); font-family: inherit; }
.upload-box:hover { border-color: var(--pf-accent); background: #f0f9ff; color: #0369a1; }
.upload-box img, .upload-box video { width: 100%; height: 100%; object-fit: cover; }
.upload-box .remove-btn { position: absolute; top: 4px; right: 4px; background: rgba(220, 38, 38, 0.92); color: white; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; border: none; cursor: pointer; font-family: inherit; }
.rate-actions { margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; }
.rate-btn-primary { background: linear-gradient(135deg, var(--pf-accent), var(--pf-accent-dark)); color: #ffffff !important; border: none; border-radius: 10px; padding: 0.95rem 1.5rem; font-weight: 800; font-size: 0.95rem; font-family: inherit; cursor: pointer; transition: all 0.25s; box-shadow: 0 6px 18px rgba(50, 161, 196, 0.22); }
.rate-btn-primary:hover:not(:disabled) { background: linear-gradient(135deg, var(--pf-accent-dark), #2788a8); transform: translateY(-2px); }
.rate-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.rate-btn-secondary { background: #ffffff; color: #334155; border: 1px solid var(--pf-border); border-radius: 10px; padding: 0.9rem 1.35rem; font-weight: 700; font-size: 0.95rem; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
.rate-btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; }
.rate-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 1rem 1.1rem; margin-bottom: 1rem; font-size: 0.95rem; font-weight: 700; font-family: inherit; }
.rate-success { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px; padding: 1rem 1.1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 12px; }
@media (max-width: 768px) {
    .rate-columns,
    .rate-info-grid { grid-template-columns: 1fr; }
    .rate-page { padding-top: 1rem !important; padding-bottom: 1.5rem !important; }
    .rate-wrap { padding: 0 0.75rem; }
    .rate-shell { border-radius: 16px; }
    .rate-shell-head {
        padding: 1rem;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: start;
        justify-content: stretch;
        column-gap: 0.75rem;
        row-gap: 0.35rem;
    }
    .rate-shell-copy {
        order: 1;
        width: auto;
        min-width: 0;
        align-self: start;
    }
    .rate-shell-title,
    .rate-shell-head .rate-shell-title,
    .rate-shell-head h1.rate-shell-title {
        font-size: 1.15rem;
        line-height: 1.2;
    }
    .rate-shell-sub {
        font-size: 0.84rem;
        line-height: 1.45;
        margin-top: 0.35rem;
        max-width: 18rem;
    }
    .rate-back-link {
        order: 2;
        width: auto;
        margin-left: auto;
        justify-content: flex-end;
        padding-top: 0;
        align-self: start;
        white-space: nowrap;
    }
    .rate-body { padding: 0.9rem; }
    .rate-card,
    .rate-panel { padding: 1rem; border-radius: 14px; }
    .rate-info-item {
        padding: 0.85rem 0.95rem;
        border-radius: 12px;
    }
    .rate-info-value {
        font-size: 0.96rem;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .rate-stars {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 0.55rem;
    }
    .rate-star-btn {
        width: 100%;
        min-width: 0;
        height: 3rem;
        font-size: 1.7rem;
        border-radius: 0.8rem;
    }
    .rate-textarea {
        min-height: 180px;
        padding: 0.95rem 0.95rem;
        font-size: 0.95rem;
    }
    .upload-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
    }
    #videoContainer {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    #addVideoBtn {
        width: 100% !important;
        max-width: 140px;
        height: 100px !important;
    }
    #videoPreviewArea {
        margin-top: 0 !important;
    }
    #videoPreviewArea > div {
        width: 100% !important;
        max-width: 100% !important;
    }
    .rate-actions {
        flex-direction: column-reverse;
        align-items: stretch;
        gap: 0.75rem;
    }
    .rate-btn-primary,
    .rate-btn-secondary {
        width: 100%;
        min-height: 48px;
        padding-left: 1rem;
        padding-right: 1rem;
    }
}
@media (max-width: 480px) {
    .rate-wrap { padding: 0 0.6rem; }
    .rate-body { padding: 0.75rem; }
    .rate-card,
    .rate-panel { padding: 0.9rem; }
    .rate-shell-title,
    .rate-shell-head .rate-shell-title,
    .rate-shell-head h1.rate-shell-title { font-size: 1.05rem; }
    .rate-label {
        font-size: 0.76rem;
        margin-bottom: 0.55rem;
    }
    .rate-stars {
        gap: 0.45rem;
    }
    .rate-star-btn {
        height: 2.8rem;
        font-size: 1.5rem;
    }
    .upload-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .rate-success {
        align-items: flex-start;
    }
}
</style>

<div class="rate-page min-h-screen py-8">
    <div class="rate-wrap">
        <div class="rate-shell">
            <div class="rate-shell-head">
                <div class="rate-shell-copy">
                    <h1 class="rate-shell-title">Rate Your Order</h1>
                    <p class="rate-shell-sub">Share your feedback for this completed order.</p>
                </div>
                <a class="rate-back-link" href="<?php echo $app_base; ?>/customer/orders.php?tab=completed">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </a>
            </div>

            <div class="rate-body">
                <div class="rate-card">
                    <div class="rate-info-grid">
                        <div class="rate-info-item">
                            <span class="rate-info-label">Order</span>
                            <div class="rate-info-value"><?php echo htmlspecialchars($order_code); ?></div>
                        </div>
                        <div class="rate-info-item">
                            <span class="rate-info-label">Service</span>
                            <div class="rate-info-value"><?php echo htmlspecialchars($service_type_label); ?></div>
                        </div>
                    </div>

                    <?php if ($already_rated && !$needs_message_update): ?>
                        <div class="rate-success">
                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                            <span style="font-weight: 700;">You already submitted a review for this order.</span>
                        </div>
                        <div class="rate-actions">
                            <a class="rate-btn-primary" href="<?php echo $app_base; ?>/customer/orders.php?tab=completed&highlight=<?php echo $order_id; ?>">View Your Review</a>
                            <a class="rate-btn-secondary" href="<?php echo $app_base; ?>/customer/orders.php?tab=completed">Back to Orders</a>
                        </div>
                    <?php else: ?>
                        <?php if ($error !== ''): ?><div class="rate-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" id="ratingForm" onsubmit="handleSubmit(this)">
                            <input type="hidden" name="order_id" value="<?php echo (int)$order_id; ?>">
                            <input type="hidden" id="ratingInput" name="rating" value="<?php echo $needs_message_update ? $existing_rating : ''; ?>">
                            <?php echo csrf_field(); ?>

                            <div class="rate-columns">
                                <div class="rate-panel">
                                    <label class="rate-label">Star Rating <span style="color:#ef4444">*</span></label>
                                    <div class="rate-stars" id="starButtons">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button type="button" class="rate-star-btn <?php echo ($needs_message_update && $i <= $existing_rating) ? 'active' : ''; ?>" data-value="<?php echo $i; ?>">&#9733;</button>
                                        <?php endfor; ?>
                                    </div>

                                    <div style="margin-top:1.5rem;">
                                        <label class="rate-label">Add Photos (Max 5)</label>
                                        <div class="upload-grid" id="imageGrid">
                                            <label class="upload-box" id="addImageBtn">
                                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                                <span style="font-size: 10px; margin-top:4px; font-family: inherit;">Add Photo</span>
                                                <input type="file" name="review_images[]" id="imageInput" multiple accept="image/*" style="display:none">
                                            </label>
                                        </div>
                                    </div>

                                    <div style="margin-top:2rem;">
                                        <label class="rate-label">Add Video (Max 1 MP4, 15MB)</label>
                                        <div id="videoContainer">
                                            <label class="upload-box" id="addVideoBtn" style="width: 100px; height: 100px;">
                                                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                                <span style="font-size: 10px; margin-top:4px; font-family: inherit;">Add Video</span>
                                                <input type="file" name="review_video" id="videoInput" accept="video/mp4" style="display:none">
                                            </label>
                                            <div id="videoPreviewArea" style="display:none; margin-top:10px;">
                                                <div style="position:relative; width:240px; aspect-ratio:16/9; border-radius:12px; overflow:hidden; border:1px solid #cbd5e1; background:#f8fafc;">
                                                    <video id="videoPreview" controls style="width:100%; height:100%; object-fit:cover;"></video>
                                                    <button type="button" class="remove-btn" onclick="removeVideo()" style="top:8px; right:8px; width:24px; height:24px; font-family: inherit;">&times;</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rate-panel">
                                    <label class="rate-label" for="messageInput">Write a Review <span style="color:#ef4444">*</span></label>
                                    <textarea id="messageInput" class="rate-textarea" name="message" required maxlength="500" placeholder="Tell us about the print quality, the service, or anything you liked... (5-500 characters)"><?php echo htmlspecialchars($needs_message_update ? $existing_message : ''); ?></textarea>
                                    <div id="charCount" style="text-align: right; font-size: 11.5px; color: #64748b; margin-top: 6px; font-family: inherit; font-weight: 600;"><?php echo strlen($needs_message_update ? $existing_message : ''); ?> / 500</div>

                                    <div class="rate-actions" style="justify-content: flex-end; margin-top: 2rem;">
                                        <a class="rate-btn-secondary" href="<?php echo $app_base; ?>/customer/orders.php?tab=completed">Skip for now</a>
                                        <button type="submit" id="submitBtn" class="rate-btn-primary"><?php echo $needs_message_update ? 'Update Review' : 'Submit Review'; ?></button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const stars = Array.from(document.querySelectorAll('.rate-star-btn'));
    const ratingInput = document.getElementById('ratingInput');
    const messageInput = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    const imageInput = document.getElementById('imageInput');
    const imageGrid = document.getElementById('imageGrid');
    const addImageBtn = document.getElementById('addImageBtn');
    const videoInput = document.getElementById('videoInput');
    const videoPreviewArea = document.getElementById('videoPreviewArea');
    const videoPreview = document.getElementById('videoPreview');
    const addVideoBtn = document.getElementById('addVideoBtn');

    let selectedFiles = [];

    stars.forEach((btn) => {
        btn.addEventListener('click', function () {
            const value = Number(this.dataset.value || 0);
            ratingInput.value = String(value);
            stars.forEach((s, idx) => s.classList.toggle('active', idx < value));
        });
    });

    if (messageInput) {
        messageInput.addEventListener('input', function () {
            if (this.value.length > 500) {
                this.value = this.value.slice(0, 500);
            }
            const len = this.value.length;
            charCount.textContent = `${len} / 500`;
            charCount.style.color = len >= 500 ? '#ef4444' : '#64748b';
        });
    }

    if (imageInput) {
        imageInput.addEventListener('change', function () {
            const newFiles = Array.from(this.files);
            if (selectedFiles.length + newFiles.length > 5) {
                showToast('Maximum 5 images allowed.');
                this.value = '';
                return;
            }

            newFiles.forEach((file) => {
                if (!file.type.startsWith('image/')) return;
                if (file.size > 5 * 1024 * 1024) {
                    showToast(`${file.name} is too large. Max 5MB.`);
                    return;
                }

                selectedFiles.push(file);
                const reader = new FileReader();
                reader.onload = (e) => {
                    const div = document.createElement('div');
                    div.className = 'upload-box';
                    div.innerHTML = `<img src="${e.target.result}"><button type="button" class="remove-btn">&times;</button>`;
                    div.querySelector('.remove-btn').onclick = () => {
                        const idx = selectedFiles.indexOf(file);
                        if (idx > -1) selectedFiles.splice(idx, 1);
                        div.remove();
                        addImageBtn.style.display = 'flex';
                        updateFileInput();
                    };
                    imageGrid.insertBefore(div, addImageBtn);
                    if (selectedFiles.length >= 5) addImageBtn.style.display = 'none';
                };
                reader.readAsDataURL(file);
            });
            updateFileInput();
        });
    }

    function updateFileInput() {
        if (!imageInput) return;
        const dt = new DataTransfer();
        selectedFiles.forEach((file) => dt.items.add(file));
        imageInput.files = dt.files;
    }

    if (videoInput) {
        videoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            if (file.type !== 'video/mp4') {
                showToast('Only MP4 videos are allowed.');
                this.value = '';
                return;
            }
            if (file.size > 15 * 1024 * 1024) {
                showToast('Video too large. Max 15MB.');
                this.value = '';
                return;
            }

            const url = URL.createObjectURL(file);
            videoPreview.src = url;
            videoPreviewArea.style.display = 'block';
            addVideoBtn.style.display = 'none';
        });
    }

    window.removeVideo = function () {
        videoInput.value = '';
        videoPreview.src = '';
        videoPreviewArea.style.display = 'none';
        addVideoBtn.style.display = 'flex';
    };

    window.handleSubmit = function () {
        const submitBtn = document.getElementById('submitBtn');
        const rating = Number(ratingInput.value || 0);

        if (rating < 1 || rating > 5) {
            showToast('Please select a star rating.');
            return false;
        }

        const msg = messageInput.value.trim();
        if (msg.length < 5) {
            showToast('Please share at least 5 characters of feedback.');
            return false;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span style="display:inline-block; animation:spin 1s linear infinite; margin-right:8px">&#8635;</span> Submitting...';
        return true;
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
