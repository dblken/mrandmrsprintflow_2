<?php
// Exercise stock notifications without bootstrapping the application database.
define('BASE_PATH', $argv[1] ?? '');
$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/functions.php');
preg_match_all('/^function ([a-zA-Z0-9_]+)\([^\n]*.*?^}/ms', $source, $definitions, PREG_SET_ORDER);
foreach ($definitions as $definition) {
    eval(str_replace('__DIR__', var_export($root . '/includes', true), $definition[0]));
}
require_once $root . '/includes/notification_images.php';
$fallback = printflow_notification_normalize_media_url('/public/assets/images/services/catalog-placeholder.svg');
if ($fallback !== BASE_PATH . '/public/assets/images/services/catalog-placeholder.svg'
    || !printflow_notification_local_media_exists($fallback)) {
    throw new RuntimeException('Notification fallback must resolve to an existing image under the app base path.');
}
$stock = ['type' => 'Stock', 'data_id' => 42, 'message' => 'Sp Mc is out of stock.'];
if (staff_admin_notification_image_url($stock, $fallback) !== $fallback) {
    throw new RuntimeException('Stock notifications must use the valid placeholder.');
}
foreach (['admin/notifications.php', 'public/api/notifications/list.php', 'public/api/push/poll.php'] as $view) {
    $contents = file_get_contents($root . '/' . $view);
    if (str_contains($contents, '/public/assets/images/services/default.png')
        || !str_contains($contents, "printflow_notification_normalize_media_url('/public/assets/images/services/catalog-placeholder.svg')")) {
        throw new RuntimeException($view . ' must use the existing normalized placeholder.');
    }
}
echo "Manager notification image fallback checks passed for base path '" . BASE_PATH . "'.\n";
