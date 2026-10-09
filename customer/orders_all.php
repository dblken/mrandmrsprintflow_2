<?php
/**
 * Customer Orders (All) - Fallback page
 * Simple, stable page that always shows all orders.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';

require_role('Customer');

$customer_id = get_user_id();

$orders = db_query(
    "SELECT
        o.order_id,
        o.order_date,
        o.status,
        o.payment_status,
        o.total_amount,
        (
            SELECT COALESCE(p.name, 'Service Order')
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = o.order_id
            ORDER BY oi.order_item_id ASC
            LIMIT 1
        ) AS first_item_name,
        (
            SELECT p.product_image
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = o.order_id
            ORDER BY oi.order_item_id ASC
            LIMIT 1
        ) AS first_item_image,
        (
            SELECT oi.customization_data
            FROM order_items oi
            WHERE oi.order_id = o.order_id
            ORDER BY oi.order_item_id ASC
            LIMIT 1
        ) AS first_item_customization,
        (
            SELECT COALESCE(SUM(oi.quantity), 0)
            FROM order_items oi
            WHERE oi.order_id = o.order_id
        ) AS total_quantity
     FROM orders o
     WHERE o.customer_id = ? AND " . printflow_order_archive_scope_sql('o') . "
     ORDER BY o.order_date DESC",
    'i',
    [$customer_id]
);

if (!is_array($orders)) {
    $orders = [];
}

$hidden_price_statuses = ['Pending', 'Pending Approval', 'Pending Review', 'For Revision', 'Approved'];

function orders_all_product_image_url(string $raw, string $display_name): string
{
    $raw = trim($raw);
    $base = rtrim((defined('BASE_PATH') ? BASE_PATH : ''), '/');
    $root = dirname(__DIR__);

    $resolve_existing = static function (string $relative) use ($root, $base): ?string {
        $relative = '/' . ltrim($relative, '/');
        if (is_file($root . $relative)) {
            return $base . $relative;
        }
        return null;
    };

    if ($raw !== '') {
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        $clean = '/' . ltrim($raw, '/');
        if ($base !== '' && strncmp($clean, $base . '/', strlen($base) + 1) === 0) {
            $clean = substr($clean, strlen($base));
            $clean = '/' . ltrim((string)$clean, '/');
        }

        foreach ([
            $clean,
            '/uploads/products/' . basename($clean),
            '/public/assets/uploads/products/' . basename($clean),
            '/public/images/products/' . basename($clean),
        ] as $candidate) {
            $resolved = $resolve_existing($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    return get_service_image_url($display_name);
}

$page_title = 'All Orders - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .orders-all-page {
        color: #d9e6ef;
    }
    .orders-all-page .card {
        background: rgba(10, 37, 48, 0.55) !important;
        border: 1px solid rgba(83, 197, 224, 0.22) !important;
        border-radius: 1.25rem !important;
        box-shadow: 0 12px 40px rgba(2, 12, 18, 0.35) !important;
        overflow: hidden;
    }
    .orders-all-page .head-row {
        border-bottom: 1px solid rgba(83, 197, 224, 0.18);
        padding: 1rem 1.2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .orders-all-page .row {
        padding: 1rem 1.2rem;
        border-bottom: 1px solid rgba(83, 197, 224, 0.14);
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
    }
    .orders-all-page .row:last-child { border-bottom: 0; }
    .orders-all-page .thumb {
        width: 64px;
        height: 64px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid rgba(83, 197, 224, 0.28);
        background: rgba(255, 255, 255, 0.05);
    }
    .orders-all-page .meta {
        flex: 1;
        min-width: 0;
    }
    .orders-all-page .name {
        font-weight: 800;
        color: #eaf6fb;
        line-height: 1.25;
    }
    .orders-all-page .sub {
        font-size: 0.8rem;
        color: #9fc6d9;
        margin-top: 0.2rem;
    }
    .orders-all-page .right {
        text-align: right;
        flex-shrink: 0;
    }
</style>

<div class="min-h-screen py-8 orders-all-page">
    <div class="container mx-auto px-4" style="max-width:1100px;">
        <div class="card">
            <div class="head-row">
                <div style="font-size:1rem; font-weight:800; color:#eaf6fb;">All Customer Orders</div>
                <div style="font-size:0.82rem; color:#9fc6d9;">Total: <?php echo count($orders); ?></div>
            </div>

            <?php if (empty($orders)): ?>
                <div style="padding:1.5rem 1.2rem; color:#9fc6d9;">No orders found.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                        $c = !empty($order['first_item_customization']) ? json_decode((string)$order['first_item_customization'], true) : [];
                        $service_type = trim((string)($c['service_type'] ?? ''));
                        $display_name = $service_type !== '' ? $service_type : (string)($order['first_item_name'] ?? 'Order Item');
                        $qty = max(1, (int)($order['total_quantity'] ?? 0));
                        $img = orders_all_product_image_url((string)($order['first_item_image'] ?? ''), $display_name);
                    ?>
                    <div class="row">
                        <img class="thumb" src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($display_name); ?>" onerror="this.src='<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/images/services/default.png';">
                        <div class="meta">
                            <div class="name"><?php echo htmlspecialchars($display_name); ?></div>
                            <div class="sub"><?php echo $qty; ?>x</div>
                            <div class="sub"><?php echo format_datetime($order['order_date']); ?></div>
                            <div class="sub">Order #<?php echo (int)$order['order_id']; ?></div>
                        </div>
                        <div class="right">
                            <?php if (in_array((string)$order['status'], $hidden_price_statuses, true)): ?>
                                <div class="sub" style="font-style:italic;">Price will be confirmed by the shop</div>
                            <?php else: ?>
                                <div style="font-size:1rem; font-weight:800; color:#eaf6fb;"><?php echo format_currency((float)$order['total_amount']); ?></div>
                            <?php endif; ?>
                            <div style="margin-top:0.35rem;"><?php echo status_badge((string)$order['status'], 'order'); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

