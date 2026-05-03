<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role('Admin');

const PF_DELETE_CUSTOMER_ID = 25;

function pf_dco_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pf_dco_table_exists(string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }

    $rows = db_query("SHOW TABLES LIKE ?", 's', [$safe]);
    $cache[$table] = !empty($rows);
    return $cache[$table];
}

function pf_dco_count_orders_child(string $table, string $orderColumn = 'order_id'): int
{
    if (!pf_dco_table_exists($table)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS c
            FROM `{$table}`
            WHERE `{$orderColumn}` IN (
                SELECT order_id FROM orders WHERE customer_id = ?
            )";
    $rows = db_query($sql, 'i', [PF_DELETE_CUSTOMER_ID]);
    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_count_job_orders(): int
{
    if (!pf_dco_table_exists('job_orders')) {
        return 0;
    }

    $rows = db_query(
        "SELECT COUNT(*) AS c
         FROM job_orders
         WHERE customer_id = ?
            OR order_id IN (SELECT order_id FROM orders WHERE customer_id = ?)",
        'ii',
        [PF_DELETE_CUSTOMER_ID, PF_DELETE_CUSTOMER_ID]
    );
    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_count_review_children(string $table): int
{
    if (!pf_dco_table_exists('reviews') || !pf_dco_table_exists($table)) {
        return 0;
    }

    $rows = db_query(
        "SELECT COUNT(*) AS c
         FROM `{$table}`
         WHERE review_id IN (
             SELECT id FROM reviews
             WHERE order_id IN (
                 SELECT order_id FROM orders WHERE customer_id = ?
             )
         )",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    );
    return (int)($rows[0]['c'] ?? 0);
}

function pf_dco_preview(): array
{
    $customer = db_query(
        "SELECT customer_id, first_name, last_name, email, contact_number
         FROM customers
         WHERE customer_id = ?
         LIMIT 1",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    );
    $customer = $customer[0] ?? null;

    $orders = db_query(
        "SELECT order_id, order_date, status, total_amount
         FROM orders
         WHERE customer_id = ?
         ORDER BY order_date DESC, order_id DESC",
        'i',
        [PF_DELETE_CUSTOMER_ID]
    ) ?: [];

    return [
        'customer' => $customer,
        'orders' => $orders,
        'counts' => [
            'orders' => count($orders),
            'order_items' => pf_dco_count_orders_child('order_items'),
            'order_designs' => pf_dco_count_orders_child('order_designs'),
            'order_messages' => pf_dco_count_orders_child('order_messages'),
            'order_notes' => pf_dco_count_orders_child('order_notes'),
            'order_status_history' => pf_dco_count_orders_child('order_status_history'),
            'reviews' => pf_dco_count_orders_child('reviews'),
            'review_images' => pf_dco_count_review_children('review_images'),
            'review_replies' => pf_dco_count_review_children('review_replies'),
            'job_orders' => pf_dco_count_job_orders(),
            'service_orders' => pf_dco_table_exists('service_orders')
                ? (int)((db_query("SELECT COUNT(*) AS c FROM service_orders WHERE customer_id = ?", 'i', [PF_DELETE_CUSTOMER_ID])[0]['c'] ?? 0))
                : 0,
        ],
    ];
}

function pf_dco_delete_customer_orders(): array
{
    global $conn;

    $deleted = [
        'review_images' => 0,
        'review_replies' => 0,
        'reviews' => 0,
        'order_status_history' => 0,
        'order_notes' => 0,
        'order_messages' => 0,
        'order_designs' => 0,
        'order_items' => 0,
        'job_orders' => 0,
        'orders' => 0,
    ];

    $orders = db_query("SELECT order_id FROM orders WHERE customer_id = ?", 'i', [PF_DELETE_CUSTOMER_ID]) ?: [];
    $orderIds = array_map(static fn(array $row): int => (int)$row['order_id'], $orders);

    if ($orderIds === []) {
        return [
            'success' => true,
            'message' => 'Customer 25 has no regular orders to delete.',
            'deleted' => $deleted,
        ];
    }

    try {
        $conn->begin_transaction();

        if (pf_dco_table_exists('review_images') && pf_dco_table_exists('reviews')) {
            $sql = "DELETE FROM review_images
                    WHERE review_id IN (
                        SELECT id FROM (
                            SELECT id
                            FROM reviews
                            WHERE order_id IN (
                                SELECT order_id FROM orders WHERE customer_id = ?
                            )
                        ) AS review_ids
                    )";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare review_images delete: ' . $conn->error);
            }
            $customerId = PF_DELETE_CUSTOMER_ID;
            $stmt->bind_param('i', $customerId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to delete review_images: ' . $stmt->error);
            }
            $deleted['review_images'] = $stmt->affected_rows;
            $stmt->close();
        }

        if (pf_dco_table_exists('review_replies') && pf_dco_table_exists('reviews')) {
            $sql = "DELETE FROM review_replies
                    WHERE review_id IN (
                        SELECT id FROM (
                            SELECT id
                            FROM reviews
                            WHERE order_id IN (
                                SELECT order_id FROM orders WHERE customer_id = ?
                            )
                        ) AS review_ids
                    )";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare review_replies delete: ' . $conn->error);
            }
            $customerId = PF_DELETE_CUSTOMER_ID;
            $stmt->bind_param('i', $customerId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to delete review_replies: ' . $stmt->error);
            }
            $deleted['review_replies'] = $stmt->affected_rows;
            $stmt->close();
        }

        foreach ([
            'reviews',
            'order_status_history',
            'order_notes',
            'order_messages',
            'order_designs',
            'order_items',
        ] as $table) {
            if (!pf_dco_table_exists($table)) {
                continue;
            }
            $sql = "DELETE FROM `{$table}`
                    WHERE order_id IN (
                        SELECT order_id FROM (
                            SELECT order_id FROM orders WHERE customer_id = ?
                        ) AS customer_orders
                    )";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException("Failed to prepare {$table} delete: " . $conn->error);
            }
            $customerId = PF_DELETE_CUSTOMER_ID;
            $stmt->bind_param('i', $customerId);
            if (!$stmt->execute()) {
                throw new RuntimeException("Failed to delete from {$table}: " . $stmt->error);
            }
            $deleted[$table] = $stmt->affected_rows;
            $stmt->close();
        }

        if (pf_dco_table_exists('job_orders')) {
            $sql = "DELETE FROM job_orders
                    WHERE customer_id = ?
                       OR order_id IN (
                            SELECT order_id FROM (
                                SELECT order_id FROM orders WHERE customer_id = ?
                            ) AS customer_orders
                       )";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare job_orders delete: ' . $conn->error);
            }
            $customerId = PF_DELETE_CUSTOMER_ID;
            $stmt->bind_param('ii', $customerId, $customerId);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to delete job_orders: ' . $stmt->error);
            }
            $deleted['job_orders'] = $stmt->affected_rows;
            $stmt->close();
        }

        $sql = "DELETE FROM orders WHERE customer_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare orders delete: ' . $conn->error);
        }
        $customerId = PF_DELETE_CUSTOMER_ID;
        $stmt->bind_param('i', $customerId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to delete orders: ' . $stmt->error);
        }
        $deleted['orders'] = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();

        log_activity(
            (int)get_user_id(),
            'Delete Customer Orders',
            'Deleted regular order transaction data for customer ID ' . PF_DELETE_CUSTOMER_ID . ' (' . count($orderIds) . ' orders).'
        );

        return [
            'success' => true,
            'message' => 'Deleted regular order transaction data for customer ID 25.',
            'deleted' => $deleted,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('delete_customer_25_orders_tool failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Delete failed: ' . $e->getMessage(),
            'deleted' => $deleted,
        ];
    }
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer_25_orders'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $result = [
            'success' => false,
            'message' => 'Invalid CSRF token.',
            'deleted' => [],
        ];
    } else {
        $result = pf_dco_delete_customer_orders();
    }
}

$preview = pf_dco_preview();
$customer = $preview['customer'];
$orders = $preview['orders'];
$counts = $preview['counts'];

$page_title = 'Delete Customer 25 Orders Tool';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-5xl mx-auto">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Delete Customer 25 Orders Tool</h1>
                <p class="text-sm text-gray-600 mt-2">
                    Temporary admin maintenance page for removing the regular order transaction records of customer ID <strong>25</strong>.
                </p>
            </div>

            <div class="p-6 space-y-6">
                <?php if ($result !== null): ?>
                    <div class="<?php echo $result['success'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?> border rounded-xl px-4 py-3">
                        <div class="font-semibold"><?php echo pf_dco_h($result['message']); ?></div>
                        <?php if (!empty($result['deleted'])): ?>
                            <div class="text-sm mt-2">
                                <?php foreach ($result['deleted'] as $label => $count): ?>
                                    <div><?php echo pf_dco_h($label); ?>: <?php echo (int)$count; ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-4">
                    <div class="font-semibold text-amber-900">Warning</div>
                    <p class="text-sm text-amber-800 mt-1">
                        This permanently deletes regular `orders` rows for customer 25 plus linked records like order items, messages, notes, reviews, and job orders. It does not delete the customer account itself.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Customer Preview</h2>
                        <?php if ($customer): ?>
                            <div class="space-y-1 text-sm text-gray-700">
                                <div><strong>ID:</strong> <?php echo (int)$customer['customer_id']; ?></div>
                                <div><strong>Name:</strong> <?php echo pf_dco_h(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))); ?></div>
                                <div><strong>Email:</strong> <?php echo pf_dco_h($customer['email'] ?? ''); ?></div>
                                <div><strong>Contact:</strong> <?php echo pf_dco_h($customer['contact_number'] ?? ''); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-red-700">Customer ID 25 was not found.</div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Linked Row Counts</h2>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm text-gray-700">
                            <?php foreach ($counts as $label => $count): ?>
                                <div class="font-medium"><?php echo pf_dco_h($label); ?></div>
                                <div><?php echo (int)$count; ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (($counts['service_orders'] ?? 0) > 0): ?>
                            <p class="text-xs text-gray-500 mt-3">
                                `service_orders` are only shown for awareness here and are not deleted by this tool.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">Orders To Be Deleted</h2>
                    <?php if (empty($orders)): ?>
                        <div class="text-sm text-gray-600">No regular orders currently found for customer 25.</div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 border-b border-gray-200">
                                        <th class="py-2 pr-4">Order ID</th>
                                        <th class="py-2 pr-4">Date</th>
                                        <th class="py-2 pr-4">Status</th>
                                        <th class="py-2 pr-4">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="py-2 pr-4 font-medium text-gray-900">#<?php echo (int)$order['order_id']; ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h($order['order_date'] ?? ''); ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h($order['status'] ?? ''); ?></td>
                                            <td class="py-2 pr-4 text-gray-700"><?php echo pf_dco_h((string)($order['total_amount'] ?? '0.00')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" onsubmit="return confirm('Delete all regular order transaction data for customer ID 25? This cannot be undone.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="delete_customer_25_orders" value="1">
                    <button
                        type="submit"
                        class="inline-flex items-center px-5 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold transition"
                        <?php echo (!$customer || empty($orders)) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                    >
                        Delete Customer 25 Order Transactions
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
