<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$revision = (string)file_get_contents($root . '/includes/revision_workflow.php');
$staffUi = (string)file_get_contents($root . '/staff/customizations.php');
$zip = (string)file_get_contents($root . '/public/download_revision_design_files_zip.php');

$expect(strpos($revision, 'printflow_revision_comparison_design_files_for_request') !== false, 'comparison grouping helper exists');
$expect(strpos($revision, "'original_files'") !== false, 'review payload includes original_files');
$expect(strpos($revision, "'current_response_files'") !== false, 'review payload includes current_response_files');
$expect(strpos($revision, 'printflow_revision_dedupe_design_files_physical') !== false, 'physical dedupe helper exists');
$expect(strpos($staffUi, 'pf-rev-design-compare') !== false, 'staff UI uses two-column comparison layout');
$expect(strpos($staffUi, 'revisionOriginalDesignFiles') !== false, 'staff UI binds original column to API array');
$expect(strpos($staffUi, 'revisionCurrentResponseDesignFiles') !== false, 'staff UI binds new upload column to API array');
$expect(strpos($staffUi, "revisionDesignFilesZipUrl('unified')") !== false, 'staff UI supports unified ZIP download');
$expect(strpos($zip, "scope === 'original'") !== false, 'ZIP endpoint supports original-only scope');

$dedupePhysical = static function (array $files): array {
    $byId = [];
    foreach ($files as $file) {
        if (!is_array($file)) {
            continue;
        }
        $fileId = (int)($file['file_id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }
        $byId[$fileId] = $file;
    }
    $deduped = [];
    $seenPhysical = [];
    foreach (array_values($byId) as $file) {
        $path = strtolower(str_replace('\\', '/', trim((string)($file['storage_path'] ?? ''))));
        $identity = $path !== '' ? ('path:' . $path) : ('id:' . (int)($file['file_id'] ?? 0));
        if (isset($seenPhysical[$identity])) {
            continue;
        }
        $seenPhysical[$identity] = true;
        $name = trim((string)($file['display_name'] ?? ''));
        if ($name !== '' && str_contains($name, ',')) {
            $parts = preg_split('/\s*,\s*/', $name) ?: [];
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $file['display_name'] = $part;
                    break;
                }
            }
        }
        $deduped[] = $file;
    }
    return $deduped;
};

$dupes = $dedupePhysical([
    [
        'file_id' => 10,
        'display_name' => 'a.jpg, b.jpg, c.jpg',
        'storage_path' => '/uploads/orders/a.jpg',
        'upload_sort' => 1,
    ],
    [
        'file_id' => 11,
        'display_name' => 'a.jpg',
        'storage_path' => '/uploads/orders/a.jpg',
        'upload_sort' => 2,
    ],
    [
        'file_id' => 12,
        'display_name' => 'other.jpg',
        'storage_path' => '/uploads/orders/other.jpg',
        'upload_sort' => 3,
    ],
]);
$expect(count($dupes) === 2, 'physical dedupe collapses duplicate storage paths with different file IDs');
$expect(($dupes[0]['display_name'] ?? '') === 'a.jpg', 'composite display_name is reduced to a single filename title');

if ($failures !== []) {
    fwrite(STDERR, "revision_comparison_design_files_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "revision_comparison_design_files_test passed.\n";
