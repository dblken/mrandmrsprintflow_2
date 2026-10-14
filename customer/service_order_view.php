<?php
/**
 * Customer - View Single Service Order (read-only)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_role('Customer');

$order_id = (int)($_GET['id'] ?? 0);
$customer_id = get_user_id();

$order = db_query("SELECT * FROM service_orders so WHERE so.id = ? AND so.customer_id = ? AND " . printflow_customer_exclusion_customer_scope_sql('so.customer_id'), 'ii', [$order_id, $customer_id]);
if (empty($order)) redirect(BASE_URL . '/customer/service_orders.php');
$order = $order[0];

$details = db_query("SELECT field_name, field_value FROM service_order_details WHERE order_id = ?", 'i', [$order_id]);
$files = db_query("SELECT file_path, original_name FROM service_order_files WHERE order_id = ?", 'i', [$order_id]);

$page_title = 'Service Order #' . $order_id . ' - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4" style="max-width: 720px;">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Service Order #<?php echo $order_id; ?></h1>
        <div class="card mb-4">
            <div class="flex justify-between items-center mb-4">
                <span class="font-medium"><?php echo htmlspecialchars($order['service_name']); ?></span>
                <?php echo status_badge($order['status'], 'order'); ?>
            </div>
            <div class="text-sm text-gray-600">Submitted: <?php echo format_datetime($order['created_at']); ?></div>
        </div>
        <div class="card mb-4">
            <h2 class="text-lg font-semibold mb-3">Order Details</h2>
            <dl class="space-y-2">
                <?php foreach ($details as $d): ?>
                <div class="flex justify-between"><dt class="text-gray-600"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $d['field_name']))); ?></dt><dd><?php echo htmlspecialchars($d['field_value']); ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php if (!empty($files)): ?>
        <div class="card mb-4">
            <h2 class="text-lg font-semibold mb-3">Uploaded Files</h2>
            <ul class="space-y-2">
                <?php foreach ($files as $f): ?>
                <li>
                    <?php 
                    $ext = strtolower(pathinfo($f['file_path'], PATHINFO_EXTENSION));
                    $is_img = in_array($ext, ['jpg','jpeg','png','gif']);
                    ?>
                    <?php if ($is_img): ?>
                    <a href="<?php echo BASE_URL . '/' . htmlspecialchars($f['file_path']); ?>" target="_blank" class="block">
                        <img src="<?php echo BASE_URL . '/' . htmlspecialchars($f['file_path']); ?>" alt="" class="max-w-xs rounded border">
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL . '/' . htmlspecialchars($f['file_path']); ?>" target="_blank" class="text-indigo-600 hover:underline"><?php echo htmlspecialchars($f['original_name'] ?: basename($f['file_path'])); ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <p><a href="<?php echo BASE_URL; ?>/customer/service_orders.php" class="text-indigo-600 hover:underline">← Back to My Service Orders</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

