<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = (string)file_get_contents($root . '/includes/order_item_design_files.php');
$staffUi = (string)file_get_contents($root . '/staff/customizations.php');
$designJs = (string)file_get_contents($root . '/public/assets/js/pf-order-item-design-files.js');
$zip = (string)file_get_contents($root . '/public/download_order_item_design_files_zip.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(strpos($core, 'printflow_order_item_design_files_dedupe_for_display') !== false, 'server dedupes display list by file_id and physical identity');
$expect(strpos($core, 'if ($hasAny) {') !== false && strpos($core, 'printflow_order_item_design_files_backfill_legacy') !== false, 'legacy backfill skips when DB rows already exist');
$expect(strpos($core, 'list_for_items($ids, false)') !== false, 'staff attach loads files without legacy backfill');
$expect(strpos($core, 'database_file_count') !== false, 'design_files_meta exposes database_file_count');
$expect(strpos($staffUi, 'mergeDesignFilesLists') !== false, 'staff refresh merges design file lists instead of replacing with partial payloads');
$expect(strpos($staffUi, 'dedupePhysical') !== false, 'staff modal dedupes design files by physical identity');
$expect(strpos($designJs, 'dedupePhysical') !== false, 'shared JS supports physical dedupe');
$expect(strpos($zip, 'list_for_item($orderItemId, false)') !== false, 'item ZIP uses authoritative list without backfill');

$dedupe = static function (array $apiFiles): array {
    $byId = [];
    foreach ($apiFiles as $file) {
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
        $physicalKey = $path !== '' ? ('path:' . $path) : ('id:' . (int)($file['file_id'] ?? 0));
        if (isset($seenPhysical[$physicalKey])) {
            continue;
        }
        $seenPhysical[$physicalKey] = true;
        $deduped[] = $file;
    }
    return $deduped;
};

$customerSaved = [
    ['file_id' => 1, 'storage_path' => '/uploads/a.jpg', 'upload_sort' => 0],
    ['file_id' => 2, 'storage_path' => '/uploads/b.jpg', 'upload_sort' => 1],
    ['file_id' => 3, 'storage_path' => '/uploads/c.jpg', 'upload_sort' => 2],
];
$staffApi = $dedupe(array_merge($customerSaved, [
    ['file_id' => 4, 'storage_path' => '/uploads/a.jpg', 'upload_sort' => 3],
]));
$expect(count($staffApi) === 3, 'staff authoritative list keeps all unique files and drops physical duplicates');
$expect(array_column($staffApi, 'file_id') === [1, 2, 3], 'staff list preserves upload order by file_id sort');

if ($failures !== []) {
    fwrite(STDERR, "order_item_design_files_consistency_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order_item_design_files_consistency_test passed.\n";
