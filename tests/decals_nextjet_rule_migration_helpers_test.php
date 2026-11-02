<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/decals_nextjet_rule_migration_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message): void {
    try { $callback(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
};
$original = [
    'id' => 41,
    'service_type' => 'Decals/Stickers (Print/Cut)',
    'item_id' => 18,
    'rule_type' => 'REQUIRED',
    'qty_multiplier' => '1.00',
    'qty_multiplier_approved' => 1,
    'qty_multiplier_approved_by' => 7,
    'qty_multiplier_approved_at' => '2026-10-30 10:20:30',
    'inventory_name' => 'Nextjet',
];

// Successful target/snapshot/migration state and exact rollback restoration.
$assert(pfpm_nextjet_is_target_row($original), 'canonical Decals/Nextjet target is recognized');
$snapshot = pfpm_nextjet_create_snapshot($original, '2026-11-02T01:02:03Z');
$validatedOriginal = pfpm_nextjet_validate_snapshot($snapshot);
$migrated = $original;
$migrated['rule_type'] = 'OPTIONAL';
$migrated['qty_multiplier_approved'] = 0;
$migrated['qty_multiplier_approved_by'] = null;
$migrated['qty_multiplier_approved_at'] = null;
$assert(pfpm_nextjet_matches_migrated_row($migrated, $validatedOriginal), 'expected post-migration row verifies');
$assert(!pfpm_nextjet_matches_original_row($migrated, $validatedOriginal), 'migrated row is not mistaken for restored state');
$assert(pfpm_nextjet_matches_original_row($original, $validatedOriginal), 'rollback state matches exact snapshot');

// Wrong target rows, failed post-update verification, and altered rows fail closed.
$wrongService = $original;
$wrongService['service_type'] = 'Poster Printing';
$assert(!pfpm_nextjet_is_target_row($wrongService), 'mismatched service row is rejected');
$wrongItem = $original;
$wrongItem['item_id'] = 22;
$assert(!pfpm_nextjet_is_target_row($wrongItem), 'mismatched item row is rejected');
$wrongName = $original;
$wrongName['inventory_name'] = 'Hologram';
$assert(!pfpm_nextjet_is_target_row($wrongName), 'mismatched inventory identity is rejected');
$notUpdated = $original;
$assert(!pfpm_nextjet_matches_migrated_row($notUpdated, $original), 'failed update verification is rejected');
$alteredPostState = $migrated;
$alteredPostState['qty_multiplier'] = '2.00';
$assert(!pfpm_nextjet_matches_migrated_row($alteredPostState, $original), 'changed multiplier blocks rollback');
$alteredPostState = $migrated;
$alteredPostState['qty_multiplier_approved_by'] = 7;
$assert(!pfpm_nextjet_matches_migrated_row($alteredPostState, $original), 'changed approval audit blocks rollback');

// Missing, malformed, and checksum-tampered snapshots cannot be restored.
$missingSnapshotPath = tempnam(sys_get_temp_dir(), 'pfpm-nextjet-missing-');
if ($missingSnapshotPath === false) throw new RuntimeException('Could not create local missing-snapshot test path.');
unlink($missingSnapshotPath);
$missingBytes = @file_get_contents($missingSnapshotPath);
$throws(static fn() => pfpm_nextjet_validate_snapshot(is_string($missingBytes) ? json_decode($missingBytes, true) : null),
    'missing snapshot file must fail');
$corruptSnapshotPath = tempnam(sys_get_temp_dir(), 'pfpm-nextjet-corrupt-');
if ($corruptSnapshotPath === false) throw new RuntimeException('Could not create local corrupt-snapshot test path.');
file_put_contents($corruptSnapshotPath, '{broken json');
$corruptBytes = file_get_contents($corruptSnapshotPath);
$throws(static fn() => pfpm_nextjet_validate_snapshot(json_decode((string)$corruptBytes, true)),
    'corrupted snapshot file must fail');
unlink($corruptSnapshotPath);
$corrupted = $snapshot;
$corrupted['row']['qty_multiplier'] = '9.99';
$throws(static fn() => pfpm_nextjet_validate_snapshot($corrupted), 'modified snapshot must fail checksum verification');
$throws(static fn() => pfpm_nextjet_validate_snapshot(['purpose' => pfpm_nextjet_snapshot_purpose()]),
    'incomplete snapshot must fail');

echo "Decals Nextjet migration guard helper tests passed.\n";
