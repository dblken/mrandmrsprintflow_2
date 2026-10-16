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
$expect(!str_contains($settings, 'Archived Orders') && !str_contains($settings, '/admin/archived_orders.php'), 'Settings page has no Archived Orders navigation link');
// Scan shared components and scripts as well as pages so a second menu source
// cannot restore the entry while the Settings-only assertion still passes.
foreach (['admin', 'includes', 'assets', 'public/assets'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) continue;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'js', 'html', 'json'], true)) continue;
        $source = (string)file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($root) + 1);
        // The legacy redirect is a preserved direct route, not a rendered link.
        $source = preg_replace('/^header\([^\r\n]*archived_orders\.php[^\r\n]*\);$/m', '', $source);
        $expect(!preg_match('~(?:/admin/|[\x27\x22`])archived_orders\.php~i', $source), $relative . ' does not render or generate an archive hub link');
    }
}
$expect(str_contains($archive, 'pfArchiveAdminSurface') && str_contains($archive, '$archiveAdminUiVisible'), 'Archived Orders page wraps tools in a visibility-controlled surface');
$expect(str_contains($archive, 'if ($archiveAdminUiVisible):') && str_contains($archive, 'api_jenny_customer_exclusion.php'), 'Archive APIs remain in page source but scripts init only when UI visible');

if ($failures !== []) {
    fwrite(STDERR, "admin_archive_ui_visibility_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "admin_archive_ui_visibility_test passed.\n";
