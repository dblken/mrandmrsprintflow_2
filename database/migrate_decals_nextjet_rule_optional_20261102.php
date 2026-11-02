<?php
/**
 * Correct the confirmed unintended Decals/Sticker Nextjet rule.
 * CLI only. Preserves the original rule row in a private Hostinger home backup
 * before changing REQUIRED to OPTIONAL. The multiplier value is not changed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/decals_nextjet_rule_migration_helpers.php';

require_once __DIR__ . '/../includes/db.php';
global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable; no rule row was changed.\n");
    exit(1);
}
$home = realpath((string)getenv('HOME'));
$projectRoot = realpath(dirname(__DIR__));
if ($home === false || $projectRoot === false
    || ($home === $projectRoot)
    || str_starts_with($home, rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "A private Hostinger home directory outside the project root is required; no rule row was changed.\n");
    exit(1);
}
$backupDir = $home . DIRECTORY_SEPARATOR . '.printflow-service-material-rule-backups';
if (is_link($backupDir)) {
    fwrite(STDERR, "Private rule backup path must not be a symbolic link; no rule row was changed.\n");
    exit(1);
}
if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Could not create the private rule backup directory; no rule row was changed.\n");
    exit(1);
}
@chmod($backupDir, 0700);
if (realpath($backupDir) !== $backupDir || dirname((string)realpath($backupDir)) !== $home
    || (fileperms($backupDir) & 0777) !== 0700) {
    fwrite(STDERR, "Private rule backup directory permissions are not 0700; no rule row was changed.\n");
    exit(1);
}

foreach (['qty_multiplier', 'qty_multiplier_approved', 'qty_multiplier_approved_by', 'qty_multiplier_approved_at'] as $columnName) {
    $safe = $conn->real_escape_string($columnName);
    $columnResult = @$conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE '{$safe}'");
    $column = $columnResult ? $columnResult->fetch_assoc() : null;
    if ($columnResult) $columnResult->free();
    if (!$column) {
        fwrite(STDERR, "Required approval schema is missing; no rule row was changed.\n");
        exit(1);
    }
}
$engineResult = @$conn->query("SHOW TABLE STATUS LIKE 'service_material_rules'");
$engineRow = $engineResult ? $engineResult->fetch_assoc() : null;
if ($engineResult) $engineResult->free();
if (strtoupper((string)($engineRow['Engine'] ?? '')) !== 'INNODB') {
    fwrite(STDERR, "service_material_rules must use InnoDB for guarded rollback; no rule row was changed.\n");
    exit(1);
}

if (!$conn->begin_transaction()) {
    fwrite(STDERR, "Could not start a transaction; no rule row was changed.\n");
    exit(1);
}
try {
    $stmt = $conn->prepare(
        "SELECT r.id, r.service_type, r.item_id, r.rule_type, r.qty_multiplier,
                r.qty_multiplier_approved, r.qty_multiplier_approved_by, r.qty_multiplier_approved_at,
                i.name AS inventory_name
         FROM service_material_rules r
         JOIN inv_items i ON i.id = r.item_id
         WHERE r.service_type = ? AND r.item_id = ?
         FOR UPDATE"
    );
    if (!$stmt) throw new RuntimeException('Could not inspect the target service material rule.');
    $serviceType = 'Decals/Stickers (Print/Cut)';
    $itemId = 18;
    $stmt->bind_param('si', $serviceType, $itemId);
    if (!$stmt->execute()) throw new RuntimeException('Could not inspect the target service material rule.');
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    if (count($rows) !== 1) throw new RuntimeException('Expected exactly one matching service/item rule; found ' . count($rows) . '.');
    $row = $rows[0];
    if (!pfpm_nextjet_is_target_row($row)) {
        throw new RuntimeException('The target item ID no longer identifies Nextjet.');
    }
    if (strtoupper(trim((string)$row['rule_type'])) === 'OPTIONAL') {
        if ((int)$row['qty_multiplier_approved'] !== 0
            || $row['qty_multiplier_approved_by'] !== null || $row['qty_multiplier_approved_at'] !== null) {
            throw new RuntimeException('The OPTIONAL target has approval metadata; review it manually before proceeding.');
        }
        if (!$conn->commit()) throw new RuntimeException('Could not finish the no-op verification.');
        fwrite(STDOUT, "NEXTJET_RULE_RESULT=ALREADY_OPTIONAL item_id=18\n");
        exit(0);
    }
    if (strtoupper(trim((string)$row['rule_type'])) !== 'REQUIRED') {
        throw new RuntimeException('The target rule is neither REQUIRED nor OPTIONAL.');
    }

    $backupPath = $backupDir . DIRECTORY_SEPARATOR
        . 'decals-nextjet-rule-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.json';
    $snapshot = pfpm_nextjet_create_snapshot($row, gmdate('Y-m-d\TH:i:s\Z'));
    $backupJson = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = @fopen($backupPath, 'x');
    if (!$handle) throw new RuntimeException('Could not create the private rule snapshot; no rule row was changed.');
    $written = fwrite($handle, $backupJson);
    $flushed = fflush($handle);
    fclose($handle);
    @chmod($backupPath, 0600);
    $writtenSnapshot = @file_get_contents($backupPath);
    $decodedSnapshot = is_string($writtenSnapshot) ? json_decode($writtenSnapshot, true) : null;
    if ($written !== strlen($backupJson) || !$flushed || (fileperms($backupPath) & 0777) !== 0600
        || !is_string($writtenSnapshot) || !hash_equals(hash('sha256', $backupJson), hash('sha256', $writtenSnapshot))) {
        throw new RuntimeException('The private rule snapshot was incomplete or not mode 0600; no rule row was changed.');
    }
    pfpm_nextjet_validate_snapshot($decodedSnapshot);
    $backupFileSha256 = hash_file('sha256', $backupPath);
    if (!is_string($backupFileSha256)) throw new RuntimeException('Could not verify the private rule snapshot file hash.');

    $update = $conn->prepare(
        "UPDATE service_material_rules
         SET rule_type = 'OPTIONAL', qty_multiplier_approved = 0,
             qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL
         WHERE id = ? AND UPPER(TRIM(rule_type)) = 'REQUIRED'"
    );
    if (!$update) throw new RuntimeException('Could not prepare the rule-type correction.');
    $ruleId = (int)$row['id'];
    $update->bind_param('i', $ruleId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The guarded rule update did not affect exactly one row.');
    }
    $update->close();
    $verify = $conn->prepare(
        "SELECT r.id, r.service_type, r.item_id, r.rule_type, r.qty_multiplier,
                r.qty_multiplier_approved, r.qty_multiplier_approved_by, r.qty_multiplier_approved_at,
                i.name AS inventory_name
         FROM service_material_rules r JOIN inv_items i ON i.id = r.item_id
         WHERE r.id = ? AND r.service_type = ? AND r.item_id = ? FOR UPDATE"
    );
    if (!$verify) throw new RuntimeException('Could not verify the updated target rule.');
    $verify->bind_param('isi', $ruleId, $serviceType, $itemId);
    if (!$verify->execute()) throw new RuntimeException('Could not verify the updated target rule.');
    $verifyResult = $verify->get_result();
    $updatedRow = $verifyResult ? $verifyResult->fetch_assoc() : null;
    $verify->close();
    if (!$updatedRow || !pfpm_nextjet_matches_migrated_row($updatedRow, $row)) {
        throw new RuntimeException('Post-update verification failed; the transaction will be rolled back.');
    }
    if (!$conn->commit()) throw new RuntimeException('Could not commit the guarded rule correction.');
    fwrite(STDOUT, "NEXTJET_RULE_RESULT=PASS rule_id={$ruleId} item_id=18 rule_type=OPTIONAL multiplier_preserved=1 approval_cleared=1\n");
    fwrite(STDOUT, "RULE_BACKUP_PATH={$backupPath}\n");
    fwrite(STDOUT, "RULE_BACKUP_SHA256={$backupFileSha256}\n");
} catch (Throwable $error) {
    @$conn->rollback();
    fwrite(STDERR, "NEXTJET_RULE_RESULT=FAIL reason=" . $error->getMessage() . "\n");
    if (isset($backupPath) && is_file($backupPath)) fwrite(STDERR, "RULE_BACKUP_PATH={$backupPath}\n");
    exit(1);
}
