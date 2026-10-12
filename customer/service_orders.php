<?php
/**
 * Customer - My Service Orders List
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_role('Customer');

service_order_ensure_tables();
$customer_id = get_user_id();

$orders = db_query("SELECT * FROM service_orders so WHERE so.customer_id = ? AND " . printflow_customer_exclusion_customer_scope_sql('so.customer_id') . " ORDER BY so.created_at DESC", 'i', [$customer_id]);

$page_title = 'My Service Orders - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4" style="max-width: 900px;">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">My Service Orders</h1>
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 bg-gray-50">
                            <th class="text-left py-3 px-4">ID</th>
                            <th class="text-left py-3 px-4">Service</th>
                            <th class="text-left py-3 px-4">Status</th>
                            <th class="text-left py-3 px-4">Date</th>
                            <th class="text-right py-3 px-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr><td colspan="5" class="py-8 text-center text-gray-500">No service orders yet. <a href="<?php echo BASE_URL; ?>/customer/services.php" class="text-indigo-600">Browse services</a> to place an order.</td></tr>
                        <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="py-3 px-4 font-mono">#<?php echo $o['id']; ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars($o['service_name']); ?></td>
                            <td class="py-3 px-4"><?php echo status_badge($o['status'], 'order'); ?></td>
                            <td class="py-3 px-4"><?php echo format_datetime($o['created_at']); ?></td>
                            <td class="py-3 px-4 text-right"><a href="<?php echo BASE_URL; ?>/customer/service_order_view.php?id=<?php echo $o['id']; ?>" class="text-indigo-600 hover:underline">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="mt-4"><a href="<?php echo BASE_URL; ?>/customer/services.php" class="text-indigo-600 hover:underline">Back to Services</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

