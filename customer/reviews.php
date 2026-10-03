<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');
ensure_ratings_table_exists();

if (!defined('BASE_URL')) define('BASE_URL', '/printflow');

$customer_id = get_user_id();
$order_id = (int)($_GET['order_id'] ?? 0);
$requested_review_id = (int)($_GET['review_id'] ?? 0);

$review_cols_raw = db_query("SHOW COLUMNS FROM reviews") ?: [];
$review_cols = array_map(static function ($col) {
	return (string)($col['Field'] ?? '');
}, $review_cols_raw);
$review_cols = array_filter($review_cols, static fn($v) => $v !== '');
$review_user_col = in_array('user_id', $review_cols, true) ? 'user_id' : (in_array('customer_id', $review_cols, true) ? 'customer_id' : 'user_id');
$review_message_col = in_array('comment', $review_cols, true) ? 'comment' : (in_array('message', $review_cols, true) ? 'message' : 'comment');
$review_has_service = in_array('service_type', $review_cols, true);

if ($order_id <= 0 && $requested_review_id > 0) {
	$review_owner_rows = db_query(
		"SELECT order_id FROM reviews WHERE id = ? AND {$review_user_col} = ? LIMIT 1",
		'ii', [$requested_review_id, $customer_id]
	);
	if (!empty($review_owner_rows[0]['order_id'])) {
		$order_id = (int)$review_owner_rows[0]['order_id'];
	}
}

if ($order_id <= 0) {
	redirect(BASE_URL . '/customer/orders.php?tab=completed');
}

$select_cols = "id, rating, {$review_message_col} AS review_message, created_at";
if ($review_has_service) {
	$select_cols .= ", service_type";
}

$review_rows = db_query(
	"SELECT {$select_cols} FROM reviews WHERE order_id = ? AND {$review_user_col} = ? " .
	($requested_review_id > 0 ? 'AND id = ? ' : '') .
	"ORDER BY id DESC LIMIT 1",
	$requested_review_id > 0 ? 'iii' : 'ii',
	$requested_review_id > 0 ? [$order_id, $customer_id, $requested_review_id] : [$order_id, $customer_id]
);

$review = !empty($review_rows) ? $review_rows[0] : null;
$images = [];
$replies = [];
$order_preview = printflow_order_notification_preview($order_id);
if ($review && !empty($review['id'])) {
	$images = db_query("SELECT image_path FROM review_images WHERE review_id = ?", 'i', [(int)$review['id']]) ?: [];
	$replies = db_query(
		"SELECT rr.reply_message, rr.created_at, u.first_name, u.last_name
		 FROM review_replies rr
		 LEFT JOIN users u ON u.user_id = rr.staff_id
		 WHERE rr.review_id = ?
		 ORDER BY rr.created_at ASC, rr.id ASC",
		'i', [(int)$review['id']]
	) ?: [];
}

$page_title = 'Review - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen py-10" style="background:#ffffff;">
	<div class="container mx-auto px-4" style="max-width: 900px;">
		<div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; margin-bottom: 1.5rem;">
			<a href="<?php echo BASE_URL; ?>/customer/orders.php?tab=completed" class="btn-secondary" style="padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
				<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
				Back
			</a>
			<h1 style="margin:0; font-size: 1.4rem; font-weight: 800; color: #0f172a;">Your Review</h1>
			<div style="font-size: 0.9rem; color:#64748b; font-weight:700;">Order <?php echo htmlspecialchars(printflow_format_order_code($order_id, '')); ?></div>
		</div>

		<?php if (!$review): ?>
			<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem;">
				<p style="margin:0; font-weight:700; color:#0f172a;">No review found for this order yet.</p>
				<p style="margin:0.5rem 0 0; color:#64748b;">You can leave a review from the order rating page.</p>
				<a href="<?php echo BASE_URL; ?>/customer/rate_order.php?order_id=<?php echo (int)$order_id; ?>" class="btn-primary" style="margin-top: 1rem; display:inline-block; text-decoration:none;">Rate Order</a>
			</div>
		<?php else: ?>
			<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius: 12px; padding: 1.5rem;">
				<div style="display:flex; gap:1rem; align-items:center; padding-bottom:1rem; margin-bottom:1rem; border-bottom:1px solid #e2e8f0;">
					<?php if (!empty($order_preview['image_url'])): ?>
						<img src="<?php echo htmlspecialchars((string)$order_preview['image_url']); ?>" alt="<?php echo htmlspecialchars((string)($order_preview['display_name'] ?? 'Order item')); ?>" style="width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;">
					<?php endif; ?>
					<div>
						<div style="font-size:1rem;font-weight:800;color:#0f172a;"><?php echo htmlspecialchars((string)($order_preview['display_name'] ?? 'Order item')); ?></div>
						<div style="font-size:.85rem;color:#64748b;margin-top:.25rem;">Order <?php echo htmlspecialchars(printflow_format_order_code($order_id, '')); ?></div>
					</div>
				</div>
				<div style="display:flex; align-items:center; gap: 0.75rem; margin-bottom: 1rem;">
					<div style="font-size: 1.1rem; font-weight: 800; color:#0f172a;">Rating:</div>
					<div style="font-size: 1.1rem; font-weight: 800; color:#f59e0b;">
						<?php echo str_repeat('★', (int)($review['rating'] ?? 0)); ?>
						<?php echo str_repeat('☆', max(0, 5 - (int)($review['rating'] ?? 0))); ?>
					</div>
				</div>

				<?php if (!empty($review['service_type'])): ?>
					<div style="font-size:0.9rem; color:#475569; font-weight:700; margin-bottom: 0.75rem;">
						Service: <?php echo htmlspecialchars($review['service_type']); ?>
					</div>
				<?php endif; ?>

				<div style="font-size:0.95rem; color:#0f172a; line-height:1.6; font-weight:600; white-space:pre-wrap;">
					<?php echo htmlspecialchars((string)($review['review_message'] ?? '')); ?>
				</div>
				<?php if (!empty($replies)): ?>
					<div style="margin-top:1.25rem;padding:1rem;border-left:4px solid #53c5e0;background:#f0f9ff;border-radius:8px;">
						<div style="font-size:.8rem;font-weight:800;color:#0369a1;text-transform:uppercase;margin-bottom:.5rem;">Staff reply</div>
						<?php foreach ($replies as $reply): ?>
							<div style="margin-top:.75rem;">
								<div style="font-size:.95rem;color:#0f172a;line-height:1.6;font-weight:600;white-space:pre-wrap;"><?php echo htmlspecialchars((string)($reply['reply_message'] ?? '')); ?></div>
								<div style="font-size:.78rem;color:#64748b;margin-top:.35rem;">Reply from <?php echo htmlspecialchars(trim((string)($reply['first_name'] ?? '') . ' ' . (string)($reply['last_name'] ?? '')) ?: 'PrintFlow Staff'); ?> · <?php echo htmlspecialchars((string)($reply['created_at'] ?? '')); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if (!empty($images)): ?>
					<div style="margin-top: 1.25rem;">
						<div style="font-size:0.8rem; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:0.5rem;">Photos</div>
						<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.75rem;">
							<?php foreach ($images as $img): ?>
								<div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; background:#f8fafc;">
									<img src="<?php echo htmlspecialchars($img['image_path']); ?>" alt="Review image" style="width:100%; height:100%; object-fit:cover; display:block;">
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
