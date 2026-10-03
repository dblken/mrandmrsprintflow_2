<?php
/** Behavioral tests with a read-only fixture DB; no application bootstrap/migrations. */
define('BASE_PATH', '');
$source = file_get_contents(__DIR__ . '/../includes/functions.php');
preg_match_all('/^function ([a-zA-Z0-9_]+)\([^\n]*.*?^}/ms', $source, $definitions, PREG_SET_ORDER);
foreach ($definitions as $definition) {
    if ($definition[1] === 'printflow_review_notification_debug') continue;
    eval(str_replace('__DIR__', var_export(dirname(__DIR__) . '/includes', true), $definition[0]));
}
require_once __DIR__ . '/../includes/customization_normalizer.php';
require_once __DIR__ . '/../includes/notification_images.php';
function printflow_review_notification_debug(array $notification, array $context = []): void {}

$services = [
    101 => ['name' => 'Brochure', 'display_image' => '/uploads/services/brochure-primary.jpg,/uploads/services/brochure-second.jpg', 'hero_image' => '/uploads/services/brochure-hero.jpg'],
    102 => ['name' => 'Sintraboard Standees', 'display_image' => '/uploads/services/standees.mp4,/uploads/services/standees-primary.jpg'],
    103 => ['name' => 'No Photo', 'display_image' => ''],
    104 => ['name' => 'Duplicate', 'display_image' => '/uploads/services/duplicate-one.jpg'],
    105 => ['name' => 'Duplicate', 'display_image' => '/uploads/services/duplicate-two.jpg'],
];
$orders = [10 => ['order_type' => 'custom', 'reference_id' => 12], 11 => ['order_type' => 'custom', 'reference_id' => 12],
    12 => ['order_type' => 'product', 'reference_id' => 12], 13 => ['order_type' => 'custom', 'reference_id' => 103],
    14 => ['order_type' => 'custom', 'reference_id' => 101], 15 => ['order_type' => 'custom', 'reference_id' => 104]];
$makeLine = static fn($id, $pid, $custom) => ['order_item_id' => $id, 'product_id' => $pid,
    'product_name' => 'POS Service Item', 'product_sku' => 'POS-SERVICE', 'customization_data' => json_encode($custom)];
$lines = [10 => [$makeLine(100, 12, ['service_id' => 101])], 11 => [$makeLine(110, 12, ['service_type' => 'Sintraboard Standees'])],
    12 => [['order_item_id' => 120, 'product_id' => 12, 'product_name' => 'Mug', 'product_sku' => 'MUG', 'customization_data' => '{}']],
    13 => [$makeLine(130, 12, ['service_id' => 103])],
    14 => [$makeLine(140, 12, ['service_id' => 101]), $makeLine(141, 12, ['service_id' => 102])],
    15 => [$makeLine(150, 12, ['service_type' => 'Duplicate'])]];
$queries = [];
$customerProfile = null;
function db_query($sql, $types = '', $params = []): array {
    global $orders, $lines, $services, $queries, $customerProfile;
    $queries[] = [$sql, $types, $params];
    if (strlen($types) !== count($params) || substr_count($sql, '?') !== count($params)) throw new RuntimeException('SQL binding mismatch');
    $id = (int)($params[0] ?? 0);
    if ($sql === 'SHOW COLUMNS FROM order_items') return [['Field' => 'service_id'], ['Field' => 'item_type']];
    if (str_contains($sql, 'FROM order_messages')) {
        if (!str_contains($sql, 'created_at <= ?') || !str_contains($sql, 'DATE_SUB')) throw new RuntimeException('Unbounded sender lookup');
        if ($id === 99) return [];
        if ($id === 98) return [['sender_id' => 8, 'created_at' => $params[2]], ['sender_id' => 9, 'created_at' => $params[2]]];
        return [['sender_id' => $params[1] === 'Staff' ? 7 : 8, 'created_at' => $params[2]]];
    }
    if (str_contains($sql, 'profile_picture FROM users')) return [['profile_picture' => 'https://example.test/staff-avatar.jpg']];
    if (str_contains($sql, 'profile_picture FROM customers')) return [['profile_picture' => $customerProfile]];
    if (str_contains($sql, 'FROM payment_submissions')) return $id === 91 ? [['order_id' => 10, 'job_order_id' => 0]] : [];
    if (str_contains($sql, 'FROM reviews')) return $id === 31 ? [['order_id' => 14, 'order_item_id' => 141]] : [];
    if (str_contains($sql, 'FROM change_item_requests')) return [['order_item_id' => 141]];
    if (str_contains($sql, 'FROM services')) {
        if (str_contains($sql, 'LOWER(TRIM(name))')) {
            return array_values(array_map(static fn($key) => ['service_id' => $key], array_keys(array_filter($services,
                static fn($s) => strcasecmp($s['name'], $params[0]) === 0))));
        }
        return isset($services[$id]) ? [$services[$id]] : [];
    }
    if (str_contains($sql, 'FROM products')) return $id === 12 ? [['photo_path' => '/uploads/products/mug.jpg', 'product_image' => '/uploads/products/old-mug.jpg']] : [];
    if (str_contains($sql, 'FROM order_items oi')) return $lines[$id] ?? [];
    if (str_contains($sql, 'FROM customizations')) return [];
    if (str_contains($sql, 'FROM job_orders')) return $id === 81 ? [['order_id' => 0, 'service_type' => 'Brochure']] : [];
    if (str_contains($sql, 'FROM orders')) return isset($orders[$id]) ? [$orders[$id]] : [];
    throw new RuntimeException('Unexpected query: ' . $sql);
}

$checks = 0;
$assert = static function ($actual, $expected, $label) use (&$checks): void {
    $checks++;
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export($actual, true));
};
$fallback = '/neutral.png';
$base = ['type' => 'Order', 'data_id' => 10, 'created_at' => '2026-10-03 10:00:00',
    'image_url' => '/public/serve_design.php?type=order_item&id=100'];
foreach (['Order', 'Design', 'Status', 'Rating', 'Review', 'Payment'] as $type) {
    $notification = array_replace($base, ['type' => $type]);
    $assert(customer_notification_image_url($notification + ['customer_id' => 1], $fallback), '/uploads/services/brochure-primary.jpg', 'Customer ' . $type);
    $assert(staff_admin_notification_image_url($notification, $fallback), '/uploads/services/brochure-primary.jpg', 'Staff ' . $type);
}
$assert(staff_admin_notification_image_url(array_replace($base, ['data_id' => 11]), $fallback), '/uploads/services/standees-primary.jpg', 'Exact legacy standees and skip video');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 12]), $fallback), '/uploads/products/mug.jpg', 'Product PK stays in products namespace');
$assert(staff_admin_notification_image_url(array_replace($base, ['type' => 'Payment', 'data_id' => 91, 'message' => 'Customer submitted a payment proof for order ORD-10.']), $fallback), '/uploads/services/brochure-primary.jpg', 'Submission namespace');
$assert(staff_admin_notification_image_url(array_replace($base, ['type' => 'Payment', 'message' => 'PayMongo payment confirmed for ORD-10.']), $fallback), '/uploads/services/brochure-primary.jpg', 'Provider order namespace');
$assert(customer_notification_image_url(array_replace($base, ['type' => 'Job Order', 'data_id' => 81]), $fallback), '/uploads/services/brochure-primary.jpg', 'Standalone job exact relationship');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 13]), $fallback), $fallback, 'Missing official media stays neutral');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 14]), $fallback), $fallback, 'Multi-entity order does not choose arbitrary first image');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 15]), $fallback), $fallback, 'Duplicate catalog names do not guess');
$assert(customer_notification_image_url(array_replace($base, ['type' => 'Review', 'data_id' => 14, 'review_id' => 31]), $fallback), '/uploads/services/standees-primary.jpg', 'Review specific line');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 14, 'message' => 'Change Item request received.']), $fallback), '/uploads/services/standees-primary.jpg', 'Change item specific line');
$message = array_replace($base, ['type' => 'Message', 'customer_id' => 1]);
$assert(customer_notification_image_url($message, $fallback), 'https://example.test/staff-avatar.jpg', 'Incoming staff avatar');
$assert(staff_admin_notification_image_url(array_replace($message, ['customer_id' => null, 'user_id' => 7]), $fallback), pf_default_profile_image_url(), 'Incoming customer default avatar');
$assert(customer_notification_image_url(array_replace($message, ['data_id' => 99]), $fallback), pf_default_profile_image_url(), 'Missing historical sender');
$assert(customer_notification_image_url(array_replace($message, ['data_id' => 98]), $fallback), pf_default_profile_image_url(), 'Ambiguous sender');
$assert(customer_notification_image_url(array_replace($message, ['type' => 'System', 'message' => 'New support chat reply']), $fallback), pf_default_profile_image_url(), 'Support sender not retained');
$customerProfile = 'https://example.test/customer-avatar.jpg';
$assert(staff_admin_notification_image_url(array_replace($message, ['customer_id' => null]), $fallback), $customerProfile, 'Incoming customer avatar');
$lines[16] = [$makeLine(160, 12, ['source_page' => 'dynamic_form', 'form_type' => 'dynamic'])];
$orders[16] = ['order_type' => 'custom', 'reference_id' => 12];
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 16]), $fallback), '/uploads/products/mug.jpg', 'Custom product form stays in product namespace');
$lines[17] = [$makeLine(170, 12, []) + ['service_id' => 101, 'item_type' => 'service']];
$orders[17] = ['order_type' => 'custom', 'reference_id' => 12];
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 17]), $fallback), '/uploads/services/brochure-primary.jpg', 'Immutable line service ID');
$lines[18] = [$makeLine(180, 12, [])];
$orders[18] = ['order_type' => 'custom', 'reference_id' => 101];
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 18]), $fallback), $fallback, 'Ambiguous custom reference never probes overlapping service PK');
$assert(customer_notification_image_url(array_replace($base, ['data_id' => 0, 'message' => 'Order #10 completed.']), $fallback), '/uploads/services/brochure-primary.jpg', 'Legacy explicit order number');
$assert(customer_notification_image_url(array_replace($base, ['type' => 'Review', 'data_id' => 10, 'review_id' => 31]), $fallback), $fallback, 'Mismatched review relationship fails closed');
$assert(printflow_push_media_payload('Message', 10, 'New message', ['customer_id' => 1, 'created_at' => $message['created_at']])['image'], 'https://example.test/staff-avatar.jpg', 'Push uses same sender source');
foreach ($queries as [$sql]) {
    if (preg_match('/design_image|design_file|artwork_path|review_images|image_path FROM order_messages/i', $sql)) {
        throw new RuntimeException('Resolver queried customer upload media');
    }
}
echo "Notification image resolution: {$checks} behavioral checks passed; SQL bindings checked.\n";
