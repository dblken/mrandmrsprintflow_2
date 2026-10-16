<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$flag = (string)file_get_contents($root . '/includes/admin_archive_ui.php');
$settings = (string)file_get_contents($root . '/admin/settings.php');
$archive = (string)file_get_contents($root . '/admin/archived_orders.php');

$failures = [];
$expect = static function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) {
        $failures[] = $msg;
    }
};

$expect(str_contains($flag, 'PRINTFLOW_ADMIN_ARCHIVE_UI_VISIBLE') && str_contains($flag, 'false'), 'archive admin UI flag defaults to hidden');
$expect(str_contains($settings, 'is_admin() && printflow_admin_archive_ui_visible()'), 'Settings omits Archived Orders link when flag is off');
$expect(str_contains($archive, 'pfArchiveAdminSurface') && str_contains($archive, '$archiveAdminUiVisible'), 'Archived Orders page wraps tools in a visibility-controlled surface');
$expect(str_contains($archive, 'if ($archiveAdminUiVisible):') && str_contains($archive, 'api_jenny_customer_exclusion.php'), 'Archive APIs remain in page source but scripts init only when UI visible');

if ($failures !== []) {
    fwrite(STDERR, "admin_archive_ui_visibility_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "admin_archive_ui_visibility_test passed.\n";
