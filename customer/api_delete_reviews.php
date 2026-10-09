<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

function review_cleanup_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'Customer' || get_user_id() <= 0) {
    review_cleanup_json(401, ['success' => false, 'error' => 'Please sign in as a customer.']);
}
ensure_ratings_table_exists();
$customerId = get_user_id();
$columns = array_flip(array_column(db_query('SHOW COLUMNS FROM reviews') ?: [], 'Field'));
$ownerCol = isset($columns['user_id']) ? 'user_id' : (isset($columns['customer_id']) ? 'customer_id' : '');
if ($ownerCol === '') {
    review_cleanup_json(500, ['success' => false, 'error' => 'Review ownership is unavailable.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db_query("SELECT COUNT(*) AS reviews, COALESCE(SUM((SELECT COUNT(*) FROM review_images ri WHERE ri.review_id = r.id)),0) AS images, COALESCE(SUM(CASE WHEN COALESCE(r.video_path,'') <> '' THEN 1 ELSE 0 END),0) AS videos FROM reviews r WHERE r.{$ownerCol} = ?", 'i', [$customerId]);
    review_cleanup_json(200, ['success' => true, 'counts' => ['reviews' => (int)($rows[0]['reviews'] ?? 0), 'images' => (int)($rows[0]['images'] ?? 0), 'videos' => (int)($rows[0]['videos'] ?? 0)]]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    review_cleanup_json(405, ['success' => false, 'error' => 'Invalid request method.']);
}
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    review_cleanup_json(403, ['success' => false, 'error' => 'Invalid security token. Refresh and try again.']);
}
$action = (string)($input['action'] ?? '');
if (!in_array($action, ['delete_one', 'delete_all'], true)) {
    review_cleanup_json(400, ['success' => false, 'error' => 'Invalid delete action.']);
}
$singleId = (int)($input['review_id'] ?? 0);
if ($action === 'delete_one' && $singleId <= 0) {
    review_cleanup_json(400, ['success' => false, 'error' => 'Review not found.']);
}

global $conn;
db_execute("CREATE TABLE IF NOT EXISTS review_cleanup_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, review_count INT NOT NULL, image_count INT NOT NULL, video_count INT NOT NULL, deletion_status VARCHAR(32) NOT NULL, deleted_at DATETIME NOT NULL, KEY idx_review_cleanup_customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mediaPaths = [];
$counts = ['reviews' => 0, 'images' => 0, 'videos' => 0];
$missing = 0;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not begin cleanup transaction.');
    $reviewRows = $action === 'delete_one'
        ? db_query("SELECT id, video_path FROM reviews WHERE id = ? AND {$ownerCol} = ? FOR UPDATE", 'ii', [$singleId, $customerId])
        : db_query("SELECT id, video_path FROM reviews WHERE {$ownerCol} = ? ORDER BY id FOR UPDATE", 'i', [$customerId]);
    if ($action === 'delete_one' && !$reviewRows) {
        $conn->rollback();
        review_cleanup_json(404, ['success' => false, 'error' => 'Review not found or not owned by your account.']);
    }
    $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $reviewRows)));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $images = db_query("SELECT image_path FROM review_images WHERE review_id IN ({$marks}) FOR UPDATE", $types, $ids) ?: [];
        foreach ($images as $row) $mediaPaths[] = (string)$row['image_path'];
        foreach ($reviewRows as $row) if (trim((string)($row['video_path'] ?? '')) !== '') $mediaPaths[] = (string)$row['video_path'];
        $counts = ['reviews' => count($ids), 'images' => count($images), 'videos' => count(array_filter($reviewRows, static fn($r) => trim((string)($r['video_path'] ?? '')) !== ''))];
        // Remove only directly linked review notifications and review-owned media/replies.
        foreach ([
            ["DELETE FROM notifications WHERE review_id IN ({$marks})", $types, $ids],
            ["DELETE FROM review_replies WHERE review_id IN ({$marks})", $types, $ids],
            ["DELETE FROM review_images WHERE review_id IN ({$marks})", $types, $ids],
            ["DELETE FROM reviews WHERE id IN ({$marks}) AND {$ownerCol} = ?", $types . 'i', array_merge($ids, [$customerId])],
        ] as [$sql, $bindTypes, $params]) {
            if (db_execute($sql, $bindTypes, $params) === false) throw new RuntimeException('Could not remove review records.');
        }
    }
    if (db_execute('INSERT INTO review_cleanup_audit (customer_id, review_count, image_count, video_count, deletion_status, deleted_at) VALUES (?, ?, ?, ?, ?, NOW())', 'iiiis', [$customerId, $counts['reviews'], $counts['images'], $counts['videos'], 'deleted']) === false) throw new RuntimeException('Could not write cleanup audit record.');
    if (!$conn->commit()) throw new RuntimeException('Could not commit cleanup transaction.');
} catch (Throwable $e) {
    if ($conn instanceof mysqli) $conn->rollback();
    error_log('Customer review cleanup failed: ' . $e->getMessage());
    review_cleanup_json(500, ['success' => false, 'error' => 'Review cleanup could not be completed. No files were removed.']);
}

// Only paths under the dedicated review upload directories can be removed.
$root = realpath(__DIR__ . '/..');
$removed = 0;
foreach (array_unique($mediaPaths) as $storedPath) {
    $path = trim(str_replace('\\', '/', $storedPath));
    $path = preg_replace('#^(?:https?://[^/]+)?/?(?:printflow/)?#i', '', $path);
    if (!preg_match('#^uploads/(reviews_images|reviews_videos)/[^/]+$#', $path)) continue;
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $parent = realpath(dirname($candidate));
    $allowed = realpath($root . '/uploads/' . (str_contains($path, 'reviews_images/') ? 'reviews_images' : 'reviews_videos'));
    if (!$parent || !$allowed || $parent !== $allowed) continue;
    if (!is_file($candidate)) { $missing++; continue; }
    // A path still referenced by another review is shared and must be retained.
    $sharedImage = db_query('SELECT 1 FROM review_images WHERE image_path = ? LIMIT 1', 's', [$storedPath]);
    $sharedVideo = db_query('SELECT 1 FROM reviews WHERE video_path = ? LIMIT 1', 's', [$storedPath]);
    if ($sharedImage || $sharedVideo) continue;
    if (@unlink($candidate)) $removed++;
}
review_cleanup_json(200, ['success' => true, 'message' => $counts['reviews'] ? 'Your review data was permanently deleted.' : 'You have no reviews to delete.', 'counts' => $counts, 'media_files_removed' => $removed, 'missing_media_files' => $missing]);
