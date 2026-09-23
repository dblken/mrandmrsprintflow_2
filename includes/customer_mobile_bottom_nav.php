<?php
/**
 * Customer mobile primary tab bar (Services, Products, Orders, Messages, Profile).
 * Shown only on small viewports via CSS; desktop uses header navigation.
 */
if (!function_exists('is_customer') || !function_exists('is_logged_in') || !is_logged_in() || !is_customer()) {
    return;
}

if (!isset($base_url)) {
    require_once __DIR__ . '/shop_config.php';
}

$pf_tab_script = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$pf_tab_path = strtolower(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

if (!isset($pf_customer_chat_unread)) {
    $pf_customer_chat_unread = 0;
    if (function_exists('get_user_id')) {
        require_once __DIR__ . '/chat_http.php';
        $pf_customer_chat_unread = printflow_chat_unread_count((int)get_user_id(), 'Customer');
    }
}

$pf_tab_match = static function (array $needles) use ($pf_tab_script, $pf_tab_path): bool {
    foreach ($needles as $needle) {
        $needle = strtolower((string)$needle);
        if ($needle === '') {
            continue;
        }
        if ($pf_tab_script === $needle || str_ends_with($pf_tab_path, '/' . $needle)) {
            return true;
        }
    }
    return false;
};

$pf_tab_services = $pf_tab_match(['services.php', 'order_service_dynamic.php']) || str_contains($pf_tab_path, '/customer/order/');
$pf_tab_products = $pf_tab_match(['products.php', 'order_create.php']);
$pf_tab_orders = $pf_tab_match([
    'orders.php',
    'order_review.php',
    'payment.php',
    'edit_order.php',
    'change_item_request.php',
    'checkout.php',
]);
$pf_tab_messages = $pf_tab_match(['messages.php', 'chat.php']);
$pf_tab_profile = $pf_tab_match(['profile.php']);

$pf_chat_badge_display = $pf_customer_chat_unread > 99 ? '99+' : (string)(int)$pf_customer_chat_unread;
$pf_chat_badge_visible = $pf_customer_chat_unread > 0;

$pf_tabs = [
    [
        'id' => 'services',
        'label' => 'Services',
        'href' => $base_url . '/customer/services.php',
        'active' => $pf_tab_services,
        'icon' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>',
    ],
    [
        'id' => 'products',
        'label' => 'Products',
        'href' => $base_url . '/customer/products.php',
        'active' => $pf_tab_products,
        'icon' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    ],
    [
        'id' => 'orders',
        'label' => 'Orders',
        'href' => $base_url . '/customer/orders.php',
        'active' => $pf_tab_orders,
        'icon' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    ],
    [
        'id' => 'messages',
        'label' => 'Messages',
        'href' => $base_url . '/customer/messages.php',
        'active' => $pf_tab_messages,
        'icon' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8m-8 4h5m-7 6h12a2 2 0 002-2V8a2 2 0 00-2-2H6a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
        'badge' => true,
    ],
    [
        'id' => 'profile',
        'label' => 'Profile',
        'href' => $base_url . '/customer/profile.php',
        'active' => $pf_tab_profile,
        'icon' => '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
    ],
];

// Prefer a single active tab when multiple rules match.
if ($pf_tab_services) {
    foreach ($pf_tabs as &$tab) {
        $tab['active'] = ($tab['id'] === 'services');
    }
    unset($tab);
} elseif ($pf_tab_orders) {
    foreach ($pf_tabs as &$tab) {
        $tab['active'] = ($tab['id'] === 'orders');
    }
    unset($tab);
} elseif ($pf_tab_messages) {
    foreach ($pf_tabs as &$tab) {
        $tab['active'] = ($tab['id'] === 'messages');
    }
    unset($tab);
} elseif ($pf_tab_profile) {
    foreach ($pf_tabs as &$tab) {
        $tab['active'] = ($tab['id'] === 'profile');
    }
    unset($tab);
} elseif ($pf_tab_products) {
    foreach ($pf_tabs as &$tab) {
        $tab['active'] = ($tab['id'] === 'products');
    }
    unset($tab);
}
?>
<nav class="pf-mobile-tab-bar" aria-label="Primary navigation" data-pf-mobile-tab-bar>
    <div class="pf-mobile-tab-bar__inner">
        <?php foreach ($pf_tabs as $tab): ?>
            <a href="<?php echo htmlspecialchars($tab['href'], ENT_QUOTES, 'UTF-8'); ?>"
               class="pf-mobile-tab-item<?php echo !empty($tab['active']) ? ' is-active' : ''; ?>"
               data-pf-mobile-tab="<?php echo htmlspecialchars($tab['id'], ENT_QUOTES, 'UTF-8'); ?>"
               <?php echo !empty($tab['active']) ? 'aria-current="page"' : ''; ?>>
                <span class="pf-mobile-tab-item__icon">
                    <?php echo $tab['icon']; ?>
                    <?php if (!empty($tab['badge'])): ?>
                        <span class="pf-mobile-tab-item__badge pf-chat-unread-badge" data-chat-unread-badge style="display:<?php echo $pf_chat_badge_visible ? 'inline-flex' : 'none'; ?>;"><?php echo htmlspecialchars($pf_chat_badge_display, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="pf-mobile-tab-item__label"><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
