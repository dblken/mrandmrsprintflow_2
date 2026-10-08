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

$all_reviews_view = $order_id <= 0;
$all_reviews = [];
if ($all_reviews_view) {
	$all_reviews = db_query("SELECT id, order_id, rating, {$review_message_col} AS review_message, created_at FROM reviews WHERE {$review_user_col} = ? ORDER BY id DESC", 'i', [$customer_id]) ?: [];
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
		<?php if ($all_reviews_view): ?>
			<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;"><h1 style="margin:0;font-size:1.5rem;font-weight:800;color:#0f172a;">My Reviews</h1><?php if ($all_reviews): ?><button type="button" id="deleteAllMyReviews" class="btn-secondary" style="color:#b91c1c;border:1px solid #fecaca;">Delete All My Reviews</button><?php endif; ?></div>
			<?php if (!$all_reviews): ?><p>You have not submitted any reviews.</p><?php endif; ?>
			<?php foreach ($all_reviews as $row): $rid=(int)$row['id']; $rimgs=db_query('SELECT image_path FROM review_images WHERE review_id = ?', 'i', [$rid]) ?: []; ?>
				<article style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem;margin-bottom:1rem;">
					<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;"><div><a href="<?php echo BASE_URL; ?>/customer/reviews.php?review_id=<?php echo $rid; ?>" style="font-weight:800;color:#0f172a;text-decoration:none;">Order <?php echo htmlspecialchars(printflow_format_order_code((int)$row['order_id'], ''), ENT_QUOTES, 'UTF-8'); ?></a><div style="color:#f59e0b;margin:.35rem 0;"><?php echo str_repeat('★',(int)$row['rating']); ?><?php echo str_repeat('☆',max(0,5-(int)$row['rating'])); ?></div><div style="white-space:pre-wrap;color:#334155;"><?php echo htmlspecialchars((string)$row['review_message'], ENT_QUOTES, 'UTF-8'); ?></div><div style="font-size:.8rem;color:#64748b;margin-top:.5rem;"><?php echo count($rimgs); ?> image(s) · <?php echo !empty(db_query("SELECT id FROM reviews WHERE id = ? AND COALESCE(video_path,'') <> ''", 'i', [$rid])) ? 1 : 0; ?> video(s)</div></div><button type="button" class="deleteOneReview" data-review-id="<?php echo $rid; ?>" style="color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:.5rem .75rem;">Delete Review</button></div>
				</article>
			<?php endforeach; ?>
			<div id="reviewDeleteModal" aria-hidden="true" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;align-items:center;justify-content:center;padding:1rem;"><div role="dialog" aria-modal="true" aria-labelledby="reviewDeleteTitle" style="background:#fff;border-radius:14px;padding:1.5rem;max-width:460px;width:100%;"><h2 id="reviewDeleteTitle" style="margin:0 0 .5rem;font-size:1.2rem;font-weight:800;">Permanently delete review data?</h2><p id="reviewDeleteDetails" style="color:#475569;">This deletion is permanent. Your review, replies, and attached review media will be removed.</p><div style="display:flex;justify-content:flex-end;gap:.75rem;"><button type="button" id="cancelReviewDelete">Cancel</button><button type="button" id="confirmReviewDelete" style="color:white;background:#b91c1c;border-radius:8px;padding:.55rem .8rem;">Delete permanently</button></div></div></div>
			<input type="hidden" id="reviewDeleteCsrf" value="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
			<div id="reviewDeleteMessage" role="status" style="margin-top:1rem;"></div>
			<script>
			(function(){const api='<?php echo BASE_URL; ?>/customer/api_delete_reviews.php', modal=document.getElementById('reviewDeleteModal'), detail=document.getElementById('reviewDeleteDetails'), msg=document.getElementById('reviewDeleteMessage'), csrf=document.getElementById('reviewDeleteCsrf').value;let pending=null,busy=false;const close=()=>{modal.style.display='none';modal.setAttribute('aria-hidden','true');pending=null};async function counts(){const r=await fetch(api,{credentials:'same-origin'});const d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Could not load review counts.');return d.counts}function open(action,id){pending={action,review_id:id};(async()=>{try{const c=await counts();detail.textContent=(action==='delete_all'?'This will permanently delete '+c.reviews+' review(s), '+c.images+' image(s), and '+c.videos+' video(s) from your account. ':'This deletion is permanent. Your rating, comment, staff replies, and review media will be removed. ');detail.textContent+='Orders, payments, messages, and customer account data are not affected.';}catch(e){detail.textContent='This deletion is permanent. '+e.message}modal.style.display='flex';modal.setAttribute('aria-hidden','false')})()}document.querySelectorAll('.deleteOneReview').forEach(b=>b.addEventListener('click',()=>open('delete_one',Number(b.dataset.reviewId))));const all=document.getElementById('deleteAllMyReviews');if(all)all.addEventListener('click',()=>open('delete_all',0));document.getElementById('cancelReviewDelete').addEventListener('click',close);modal.addEventListener('click',e=>{if(e.target===modal)close()});document.getElementById('confirmReviewDelete').addEventListener('click',async()=>{if(busy||!pending)return;busy=true;const button=document.getElementById('confirmReviewDelete');button.disabled=true;try{const r=await fetch(api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({...pending,csrf_token:csrf})});const d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Delete failed.');msg.textContent=d.message+' Removed '+d.counts.reviews+' review(s), '+d.counts.images+' image(s), '+d.counts.videos+' video(s).'+(d.missing_media_files?' '+d.missing_media_files+' media file(s) were already missing.':'');close();setTimeout(()=>location.reload(),500)}catch(e){msg.textContent=e.message}finally{busy=false;button.disabled=false}})})();
			</script>
		<?php else: ?>
		<div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; margin-bottom: 1.5rem;">
			<a href="<?php echo BASE_URL; ?>/customer/orders.php?tab=completed" class="btn-secondary" style="padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
				<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
				Back
			</a>
			<h1 style="margin:0; font-size: 1.4rem; font-weight: 800; color: #0f172a;">Your Review</h1>
			<div style="display:flex;align-items:center;gap:1rem;"><a href="<?php echo BASE_URL; ?>/customer/reviews.php" style="font-size:.85rem;font-weight:700;color:#0f766e;">My Reviews</a><div style="font-size: 0.9rem; color:#64748b; font-weight:700;">Order <?php echo htmlspecialchars(printflow_format_order_code($order_id, '')); ?></div></div>
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
		<?php endif; ?>
	</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
