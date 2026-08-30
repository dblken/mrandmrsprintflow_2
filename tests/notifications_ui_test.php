<?php

$root = dirname(__DIR__);
$customer = file_get_contents($root . '/customer/notifications.php');
$staff = file_get_contents($root . '/staff/notifications.php');
$sidebar = file_get_contents($root . '/includes/staff_sidebar.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($customer, 'grid-template-columns: 56px minmax(0, 1fr)') !== false, 'Customer mobile notifications must use a compact thumbnail/text grid.');
$assert(strpos($customer, 'width: 56px;') !== false && strpos($customer, 'height: 56px;') !== false, 'Customer mobile notification thumbnails must remain 56px square.');
$assert(strpos($customer, 'overflow-wrap: anywhere;') !== false, 'Customer notification messages must safely wrap long order codes.');
$assert(strpos($customer, 'loading="lazy"') !== false && strpos($customer, 'decoding="async"') !== false, 'Customer notification images must use deferred decoding/loading.');
$assert(strpos($customer, 'id="pf-push-toggle"') !== false, 'Customer Enable Notifications control must remain available.');
$assert(strpos($customer, 'Mark all as read') !== false, 'Customer Mark All As Read control must remain available.');

$assert(strpos($staff, 'id="pf-push-toggle"') === false, 'Staff notification UI must not expose the push enable/disable control.');
$assert(strpos($staff, 'onclick="refreshNotifications()"') !== false, 'Staff Refresh control must remain available.');
$assert(strpos($staff, 'action=mark_all_read') !== false, 'Staff Mark All Read action must remain available.');
$assert(strpos($staff, 'notifFilterPanel()') !== false, 'Staff Filter control must remain available.');
$assert(strpos($sidebar, '/staff/notifications.php') !== false, 'The shared staff notification route must remain linked for staff roles.');

if ($failures) {
    fwrite(STDERR, "Notification UI regression test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Notification UI regression test passed.\n";
