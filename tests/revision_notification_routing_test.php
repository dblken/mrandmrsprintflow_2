<?php

function revision_notification_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/includes/functions.php');
$editOrder = file_get_contents($root . '/customer/edit_order.php');
$client = file_get_contents($root . '/public/assets/js/notifications.js');
$staffCustomizations = file_get_contents($root . '/staff/customizations.php');
$migration = file_get_contents($root . '/database/repair_revision_notification_targets_20260806.sql');

$revisionCheck = strpos($functions, 'if (printflow_notification_is_revision_submission($n))');
$ratingCheck = strpos($functions, '$is_rating = (', $revisionCheck === false ? 0 : $revisionCheck);

revision_notification_assert($revisionCheck !== false, 'staff routing recognizes revision submissions');
revision_notification_assert($ratingCheck !== false && $revisionCheck < $ratingCheck, 'revision routing runs before the broad review/rating classifier');
revision_notification_assert(substr_count($editOrder, "'Design'") >= 2, 'direct and fallback staff notifications store the Design type');
revision_notification_assert(strpos($editOrder, '$order_id') !== false, 'notification creation retains the internal order ID as data_id');
revision_notification_assert(strpos($client, "staff/customizations.php?order_id=' + did + '&status=PENDING'") !== false, 'client fallback deep-links the exact internal order ID');
revision_notification_assert(strpos($staffCustomizations, "Customer's Revised Design — Awaiting Staff Review") !== false, 'staff detail clearly labels the replacement design');
revision_notification_assert(strpos($migration, "SET type = 'Design'") !== false && strpos($migration, 'submitted revised details') !== false, 'existing revision notifications have an idempotent data repair');

echo "Revision notification routing regression test passed.\n";
