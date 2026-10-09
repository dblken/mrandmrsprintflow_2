<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('Admin');
$rows = db_query("SELECT a.customer_id, u.first_name, u.last_name, u.email, a.review_count, a.image_count, a.video_count, a.deletion_status, a.deleted_at FROM review_cleanup_audit a LEFT JOIN users u ON u.user_id = a.customer_id ORDER BY a.deleted_at DESC, a.id DESC") ?: [];
$page_title = 'Review Cleanup Report - PrintFlow';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="container mx-auto px-4 py-8"><h1 class="text-2xl font-bold mb-4">Customer Review Cleanup Report</h1><div class="overflow-x-auto"><table class="w-full"><thead><tr><th>Customer account</th><th>Reviews</th><th>Images</th><th>Videos</th><th>Status</th><th>Deleted at</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) . ' (' . ($row['email'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$row['review_count']; ?></td><td><?php echo (int)$row['image_count']; ?></td><td><?php echo (int)$row['video_count']; ?></td><td><?php echo htmlspecialchars($row['deletion_status'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($row['deleted_at'], ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div></main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
