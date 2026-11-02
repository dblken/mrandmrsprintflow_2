<?php
/** Restore the exact captured Nextjet rule type and approval audit snapshot. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/decals_nextjet_rule_migration_helpers.php';
$home = realpath((string)getenv('HOME'));
$backupDir = $home === false ? false : $home . DIRECTORY_SEPARATOR . '.printflow-service-material-rule-backups';
$inputSnapshotPath = (string)($argv[1] ?? '');
$snapshotPath = realpath($inputSnapshotPath);
if ($backupDir === false || is_link($backupDir) || realpath($backupDir) !== $backupDir
    || is_link($inputSnapshotPath) || $snapshotPath === false || dirname($snapshotPath) !== $backupDir
    || !is_file($snapshotPath) || !is_readable($snapshotPath) || (fileperms($snapshotPath) & 0777) !== 0600) {
    fwrite(STDERR, "Provide a mode-0600 rollback snapshot from the private rule backup directory. No rule row was changed.\n");
    exit(1);
}
try {
    $snapshotJson = file_get_contents($snapshotPath);
    $snapshot = is_string($snapshotJson) ? json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR) : null;
    $row = pfpm_nextjet_validate_snapshot($snapshot);
} catch (Throwable $error) {
    fwrite(STDERR, "Rollback snapshot does not describe the approved target rule. No rule row was changed.\n");
    exit(1);
}
require_once __DIR__ . '/../includes/db.php';
global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable; no rule row was changed.\n");
    exit(1);
}
$engineResult = @$conn->query("SHOW TABLE STATUS LIKE 'service_material_rules'");
$engineRow = $engineResult ? $engineResult->fetch_assoc() : null;
if ($engineResult) $engineResult->free();
if (strtoupper((string)($engineRow['Engine'] ?? '')) !== 'INNODB') {
    fwrite(STDERR, "service_material_rules must use InnoDB for guarded rollback; no rule row was changed.\n");
    exit(1);
}

if (!$conn->begin_transaction()) {
    fwrite(STDERR, "Could not start rollback transaction; no rule row was changed.\n");
    exit(1);
}
try {
    $id = (int)$row['id'];
    $select = $conn->prepare(
        "SELECT r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                r.qty_multiplier_approved_by, r.qty_multiplier_approved_at, i.name AS inventory_name
         FROM service_material_rules r JOIN inv_items i ON i.id = r.item_id
         WHERE r.id = ? AND r.service_type = ? AND r.item_id = ? FOR UPDATE"
    );
    if (!$select) throw new RuntimeException('Could not lock the target rule for rollback.');
    $serviceType = 'Decals/Stickers (Print/Cut)';
    $itemId = 18;
    $select->bind_param('isi', $id, $serviceType, $itemId);
    if (!$select->execute()) throw new RuntimeException('Could not lock the target rule for rollback.');
    $result = $select->get_result();
    $current = $result ? $result->fetch_assoc() : null;
    $select->close();
    if (!$current) throw new RuntimeException('The target row is missing; refusing rollback.');
    $current['id'] = $id;
    $current['service_type'] = $serviceType;
    $current['item_id'] = $itemId;
    if (!pfpm_nextjet_matches_migrated_row($current, $row)) {
        throw new RuntimeException('The rule changed after migration or no longer matches the snapshot; refusing to overwrite it.');
    }
    $restore = $conn->prepare(
        "UPDATE service_material_rules
         SET rule_type = ?, qty_multiplier_approved = ?,
             qty_multiplier_approved_by = ?, qty_multiplier_approved_at = ?
         WHERE id = ? AND UPPER(TRIM(rule_type)) = 'OPTIONAL'"
    );
    if (!$restore) throw new RuntimeException('Could not prepare the guarded rollback.');
    $ruleType = (string)$row['rule_type'];
    $approved = (int)$row['qty_multiplier_approved'];
    $approvedBy = $row['qty_multiplier_approved_by'] === null ? null : (int)$row['qty_multiplier_approved_by'];
    $approvedAt = $row['qty_multiplier_approved_at'] === null ? null : (string)$row['qty_multiplier_approved_at'];
    $restore->bind_param('siisi', $ruleType, $approved, $approvedBy, $approvedAt, $id);
    if (!$restore->execute() || $restore->affected_rows !== 1) {
        $restore->close();
        throw new RuntimeException('The guarded rollback did not affect exactly one row.');
    }
    $restore->close();
    $verify = $conn->prepare(
        "SELECT r.id, r.service_type, r.item_id, r.rule_type, r.qty_multiplier,
                r.qty_multiplier_approved, r.qty_multiplier_approved_by, r.qty_multiplier_approved_at,
                i.name AS inventory_name
         FROM service_material_rules r JOIN inv_items i ON i.id = r.item_id
         WHERE r.id = ? AND r.service_type = ? AND r.item_id = ? FOR UPDATE"
    );
    if (!$verify) throw new RuntimeException('Could not verify the restored target rule.');
    $verify->bind_param('isi', $id, $serviceType, $itemId);
    if (!$verify->execute()) throw new RuntimeException('Could not verify the restored target rule.');
    $verifyResult = $verify->get_result();
    $restoredRow = $verifyResult ? $verifyResult->fetch_assoc() : null;
    $verify->close();
    if (!$restoredRow || !pfpm_nextjet_matches_original_row($restoredRow, $row)) {
        throw new RuntimeException('Rollback verification failed; the transaction will be rolled back.');
    }
    if (!$conn->commit()) throw new RuntimeException('Could not commit the guarded rollback.');
    fwrite(STDOUT, "NEXTJET_RULE_ROLLBACK=PASS rule_id={$id} original_type_restored=1 approval_audit_restored=1 multiplier_preserved=1\n");
} catch (Throwable $error) {
    @$conn->rollback();
    fwrite(STDERR, "NEXTJET_RULE_ROLLBACK=FAIL reason=" . $error->getMessage() . "\n");
    exit(1);
}
