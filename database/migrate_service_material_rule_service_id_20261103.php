<?php
declare(strict_types=1);
/**
 * Add stable service IDs to service material rules and remove the ENUM ceiling.
 * CLI only. No rule values, multipliers, approvals, or audit fields are changed.
 * Before execution, restore-test a full production backup and pass its reference
 * as --backup-restore-verified=<reference> plus --target-db=<database name>.
 * MySQL DDL implicitly commits: on an interrupted run, inspect the schema and
 * rerun this idempotent migration; restore the full backup only before any
 * post-migration application writes. Never shrink the VARCHAR back to ENUM.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/service_material_rule_display.php';
global $conn;
if (!$conn instanceof mysqli) { fwrite(STDERR, "Database connection unavailable; no schema changed.\n"); exit(1); }

$args = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.+)$/', (string)$arg, $m)) $args[$m[1]] = $m[2];
}
$backupReference = trim((string)($args['backup-restore-verified'] ?? ''));
$targetDb = trim((string)($args['target-db'] ?? ''));
if ($targetDb === '' || !preg_match('/^[A-Za-z0-9_]+$/', $targetDb)
    || $backupReference === '' || !preg_match('/^[A-Za-z0-9._-]{4,120}$/', $backupReference)) {
    fwrite(STDERR, "Required: --target-db=<database> --backup-restore-verified=<restore-tested-backup-reference>. No schema changed.\n"); exit(1);
}
$databaseRows = $conn->query('SELECT DATABASE() AS database_name');
$databaseRow = $databaseRows ? $databaseRows->fetch_assoc() : null;
if (!$databaseRow || (string)$databaseRow['database_name'] !== $targetDb) {
    fwrite(STDERR, "Connected database does not match --target-db. No schema changed.\n"); exit(1);
}

$engineResult = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_material_rules'");
$engineRow = $engineResult ? $engineResult->fetch_assoc() : null;
if (!$engineRow || strtoupper((string)$engineRow['ENGINE']) !== 'INNODB') { fwrite(STDERR, "service_material_rules must be InnoDB. No schema changed.\n"); exit(1); }

$getColumn = static function (mysqli $db, string $table, string $column): ?array {
    $stmt = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Could not prepare schema inspection.');
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) throw new RuntimeException('Could not inspect the required column definition.');
    $result = $stmt->get_result(); $row = $result ? $result->fetch_assoc() : null; $stmt->close();
    return $row ?: null;
};
$getRows = static function (mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException('A required read-only preflight query failed.');
    return $result->fetch_all(MYSQLI_ASSOC);
};

try {
    $serviceTypeColumn = $getColumn($conn, 'service_material_rules', 'service_type');
    $itemIdColumn = $getColumn($conn, 'service_material_rules', 'item_id');
    $serviceIdSource = $getColumn($conn, 'services', 'service_id');
    if (!$serviceTypeColumn || !$itemIdColumn || !$serviceIdSource
        || !in_array(strtolower((string)$serviceIdSource['DATA_TYPE']), ['int', 'bigint', 'mediumint', 'smallint'], true)
        || !in_array(strtolower((string)$itemIdColumn['DATA_TYPE']), ['int', 'bigint', 'mediumint', 'smallint'], true)) {
        throw new RuntimeException('The service/material identity columns have unsupported or missing definitions.');
    }
    if (str_contains(strtolower((string)$serviceTypeColumn['EXTRA']), 'generated')) throw new RuntimeException('service_type is generated; review the schema before migration.');

    $requiredColumns = ['id', 'item_id', 'rule_type', 'qty_multiplier', 'qty_multiplier_approved', 'qty_multiplier_approved_by', 'qty_multiplier_approved_at', 'qty_multiplier_basis', 'qty_multiplier_approved_basis'];
    foreach ($requiredColumns as $columnName) if (!$getColumn($conn, 'service_material_rules', $columnName)) throw new RuntimeException('Required rule column is missing: ' . $columnName . '.');

    $identity = $getRows($conn, "SELECT service_id, name, status FROM services WHERE service_id = 67");
    if (count($identity) !== 1 || (string)$identity[0]['name'] !== 'Brochure' || strtoupper(trim((string)$identity[0]['status'])) !== 'ACTIVATED') throw new RuntimeException('Verified Brochure service identity no longer matches service_id 67.');
    $item = $getRows($conn, "SELECT id, name, status, unit_of_measure FROM inv_items WHERE id = 212");
    if (count($item) !== 1 || (string)$item[0]['name'] !== 'C2S Board' || strtoupper(trim((string)$item[0]['status'])) !== 'ACTIVE' || strtolower(trim((string)$item[0]['unit_of_measure'])) !== 'sheet') throw new RuntimeException('Verified C2S Board identity no longer matches item_id 212.');

    // Rows 31 and 32 are explicitly known to be ambiguous. They must remain byte-for-byte untouched and unassigned.
    $ambiguous = $getRows($conn, "SELECT id, service_type, item_id, rule_type, qty_multiplier, qty_multiplier_approved, qty_multiplier_approved_by, qty_multiplier_approved_at FROM service_material_rules WHERE id IN (31, 32) ORDER BY id");
    if (count($ambiguous) !== 2) throw new RuntimeException('Ambiguous legacy rows 31 and 32 changed; stop for manual review.');
    foreach ($ambiguous as $index => $row) {
        $expectedId = 31 + $index;
        if ((int)$row['id'] !== $expectedId || (string)$row['service_type'] !== '' || (int)$row['item_id'] !== 212
            || strtoupper(trim((string)$row['rule_type'])) !== 'REQUIRED' || $row['qty_multiplier'] !== null
            || (int)$row['qty_multiplier_approved'] !== 0 || $row['qty_multiplier_approved_by'] !== null || $row['qty_multiplier_approved_at'] !== null) {
            throw new RuntimeException('Ambiguous legacy row ' . $expectedId . ' no longer matches the confirmed state; stop without changing it.');
        }
    }

    $mapping = [
        36 => ['Decals/Stickers (Print/Cut)'],
        39 => ['Plates'], 64 => ['Poster Printing'], 67 => ['Brochure'],
        71 => ['Sintraboard Standees'], 40 => ['Tarpaulin Printing'],
    ];
    $activeServices = $getRows($conn, "SELECT service_id, name, status FROM services WHERE service_id IN (36,39,40,64,67,71)");
    $activeById = [];
    foreach ($activeServices as $row) $activeById[(int)$row['service_id']] = $row;
    $canonicalNames = [36 => 'Stickers /Decals', 39 => 'Plates', 40 => 'Tarpaulin', 64 => 'Poster Printing', 67 => 'Brochure', 71 => 'Sintraboard Standees'];
    foreach ($canonicalNames as $serviceId => $name) {
        $row = $activeById[$serviceId] ?? null;
        if (!$row || strtoupper(trim((string)$row['status'])) !== 'ACTIVATED' || strcasecmp(trim((string)$row['name']), $name) !== 0) {
            throw new RuntimeException('A verified active service identity changed for service_id ' . $serviceId . '.');
        }
    }

    // Refuse ambiguous canonical/alias duplicates before any DDL or backfill.
    $preexistingServiceId = $getColumn($conn, 'service_material_rules', 'service_id');
    $allRuleRows = $getRows($conn, 'SELECT r.id, r.service_type, r.item_id, ' . ($preexistingServiceId ? 'r.service_id' : 'NULL AS service_id') . ', i.name AS item_name, i.status AS item_status FROM service_material_rules r LEFT JOIN inv_items i ON i.id = r.item_id ORDER BY r.id');
    $materialNamesByService = [
        36 => ['Nextjet', 'Gloss Laminate', 'Matte Laminate', 'Hologram'],
        39 => ['AC MC', 'AC PH', 'AC EURO', 'AC THAI', 'AC NMC', 'AC HOME', 'SP MC', 'SP PH', 'SP EURO', 'SP THAI', 'SP NMC'],
        40 => ['3ft Tarpaulin', '4ft Tarpaulin', '5ft Tarpaulin', '6ft Tarpaulin'],
        64 => ['C2S Board', 'C2S Special Paper'], 67 => ['C2S Board', 'C2S Special Paper'],
        71 => ['Sintra 5mm', 'Sintra 3mm 32', 'Pp Stkr Matte 98'],
    ];
    $materialIdsByService = [
        36 => [18 => 'Nextjet', 20 => 'Gloss Laminate', 21 => 'Matte Laminate', 22 => 'Hologram'],
        39 => [1 => 'AC MC', 2 => 'AC PH', 3 => 'AC EURO', 4 => 'AC THAI', 5 => 'AC NMC', 6 => 'AC HOME', 7 => 'SP MC', 8 => 'SP PH', 9 => 'SP EURO', 10 => 'SP THAI', 11 => 'SP NMC'],
        64 => [212 => 'C2S Board', 211 => 'C2S Special Paper'],
        67 => [212 => 'C2S Board', 211 => 'C2S Special Paper'],
        71 => [207 => 'Sintra 5mm', 206 => 'Sintra 3mm 32', 23 => 'Pp Stkr Matte 98'],
    ];
    $isVerifiedPair = static function (int $serviceId, int $itemId, string $itemName) use ($materialNamesByService, $materialIdsByService): bool {
        if (isset($materialIdsByService[$serviceId])) {
            return isset($materialIdsByService[$serviceId][$itemId])
                && strcasecmp($materialIdsByService[$serviceId][$itemId], $itemName) === 0;
        }
        return $serviceId === 40 && in_array($itemName, $materialNamesByService[40] ?? [], true);
    };
    $seen = [];
    foreach ($allRuleRows as $row) {
        $serviceIdValue = $row['service_id'] ?? null;
        $serviceId = (int)($serviceIdValue ?? 0);
        if ($serviceIdValue !== null && $serviceId <= 0) {
            throw new RuntimeException('Existing rule ID ' . (int)$row['id'] . ' has a non-null invalid service_id; stop for manual review.');
        }
        if ($serviceIdValue === null) {
            foreach ($mapping as $candidateId => $aliases) {
                if (in_array((string)$row['service_type'], $aliases, true)
                    && strtoupper(trim((string)($row['item_status'] ?? ''))) === 'ACTIVE'
                    && in_array((string)($row['item_name'] ?? ''), $materialNamesByService[$candidateId] ?? [], true)) {
                    $expectedItemName = printflow_pos_material_selection_item_names($candidateId)[(int)$row['item_id']] ?? null;
                    if ($expectedItemName !== null && $expectedItemName !== (string)$row['item_name']) continue;
                    $serviceId = $candidateId; break;
                }
            }
        } elseif (!in_array((string)($row['service_type'] ?? ''), $mapping[$serviceId] ?? [], true)
            || !$isVerifiedPair($serviceId, (int)$row['item_id'], (string)($row['item_name'] ?? ''))
            || strtoupper(trim((string)($row['item_status'] ?? ''))) !== 'ACTIVE') {
            throw new RuntimeException('Existing stable rule ID ' . (int)$row['id'] . ' has a conflicting service label or an inactive/unverified material; stop before adding the unique mapping key.');
        }
        if ($serviceId <= 0) continue;
        $key = $serviceId . ':' . (int)$row['item_id'];
        if (isset($seen[$key])) throw new RuntimeException('Duplicate mapped rows ' . $seen[$key] . ' and ' . (int)$row['id'] . ' exist for service_id/item_id ' . $key . '. Resolve explicitly before migration.');
        $seen[$key] = (int)$row['id'];
    }

    $home = realpath((string)getenv('HOME')); $root = realpath(dirname(__DIR__));
    if ($home === false || $root === false || $home === $root || str_starts_with($home, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) throw new RuntimeException('A private HOME directory outside the application root is required.');
    $backupDir = $home . DIRECTORY_SEPARATOR . '.printflow-service-material-schema-backups';
    if (is_link($backupDir)) throw new RuntimeException('Private schema-backup directory must not be a symlink.');
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) throw new RuntimeException('Could not create the private schema-backup directory.');
    @chmod($backupDir, 0700);
    if ((fileperms($backupDir) & 0777) !== 0700) throw new RuntimeException('Private schema-backup directory permissions are not 0700.');
    $createTable = $getRows($conn, 'SHOW CREATE TABLE service_material_rules');
    $fullRuleRows = $getRows($conn, 'SELECT * FROM service_material_rules ORDER BY id');
    $snapshot = ['database' => $targetDb, 'backup_reference' => $backupReference, 'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'show_create_table' => $createTable[0]['Create Table'] ?? null, 'rows' => $fullRuleRows];
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $snapshotPath = $backupDir . DIRECTORY_SEPARATOR . 'service-material-rules-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(5)) . '.json';
    $handle = @fopen($snapshotPath, 'x');
    if (!$handle) throw new RuntimeException('Could not create the private pre-migration snapshot.');
    $written = fwrite($handle, $json); $flushed = fflush($handle); fclose($handle); @chmod($snapshotPath, 0600);
    $readback = @file_get_contents($snapshotPath); $hash = is_string($readback) ? hash('sha256', $readback) : '';
    if ($written !== strlen($json) || !$flushed || (fileperms($snapshotPath) & 0777) !== 0600 || !is_string($readback) || !hash_equals(hash('sha256', $json), $hash)) throw new RuntimeException('Private pre-migration snapshot verification failed.');

    // Change only the column definition and add a nullable stable identity.
    $serviceType = $serviceTypeColumn;
    if (strtolower((string)$serviceType['DATA_TYPE']) === 'enum') {
        $charset = (string)$serviceType['CHARACTER_SET_NAME']; $collation = (string)$serviceType['COLLATION_NAME'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) || !preg_match('/^[A-Za-z0-9_]+$/', $collation)) throw new RuntimeException('service_type charset/collation cannot be safely preserved.');
        $newLength = 100;
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)$serviceType['COLUMN_TYPE'], $enumMatches);
        foreach ($enumMatches[1] ?? [] as $enumValue) {
            $charCount = preg_match_all('/./us', stripcslashes($enumValue), $unusedMatches);
            if ($charCount === false) throw new RuntimeException('An existing service_type enum value is not valid UTF-8.');
            $newLength = max($newLength, $charCount);
        }
        if ($newLength > 255) throw new RuntimeException('An existing service_type value exceeds the supported VARCHAR length.');
        $nullSql = strtoupper((string)$serviceType['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
        $defaultSql = $serviceType['COLUMN_DEFAULT'] === null
            ? (strtoupper((string)$serviceType['IS_NULLABLE']) === 'YES' ? ' DEFAULT NULL' : '')
            : " DEFAULT '" . $conn->real_escape_string((string)$serviceType['COLUMN_DEFAULT']) . "'";
        if (!$conn->query("ALTER TABLE service_material_rules MODIFY service_type VARCHAR({$newLength}) CHARACTER SET `{$charset}` COLLATE `{$collation}` {$nullSql}{$defaultSql}")) throw new RuntimeException('Could not widen service_type from ENUM to VARCHAR.');
    } elseif (strtolower((string)$serviceType['DATA_TYPE']) !== 'varchar' || (int)filter_var((string)$serviceType['COLUMN_TYPE'], FILTER_SANITIZE_NUMBER_INT) < 100) {
        throw new RuntimeException('service_type is neither the expected ENUM nor a compatible VARCHAR; no automatic alteration is safe.');
    }

    $stable = $getColumn($conn, 'service_material_rules', 'service_id');
    $serviceIdType = strtolower((string)$serviceIdSource['COLUMN_TYPE']);
    if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?$/', $serviceIdType)) throw new RuntimeException('services.service_id has an unsupported integer type.');
    if ($stable === null) {
        if (!$conn->query("ALTER TABLE service_material_rules ADD COLUMN service_id {$serviceIdType} NULL DEFAULT NULL AFTER id")) throw new RuntimeException('Could not add nullable service_id.');
    } elseif (strtolower((string)$stable['COLUMN_TYPE']) !== $serviceIdType || strtoupper((string)$stable['IS_NULLABLE']) !== 'YES') {
        throw new RuntimeException('Existing service_id column is incompatible; inspect it manually.');
    }

    $indexes = $getRows($conn, "SHOW INDEX FROM service_material_rules");
    $indexesByName = [];
    foreach ($indexes as $index) $indexesByName[(string)$index['Key_name']][(int)$index['Seq_in_index']] = [
        'column' => (string)$index['Column_name'], 'non_unique' => (int)$index['Non_unique'],
    ];
    $namedIndex = $indexesByName['uq_smr_service_item'] ?? [];
    if ($namedIndex !== [] && (array_column(array_values($namedIndex), 'column') !== ['service_id', 'item_id']
        || array_filter($namedIndex, static fn(array $entry): bool => $entry['non_unique'] !== 0) !== [])) {
        throw new RuntimeException('Index uq_smr_service_item exists with an unexpected definition.');
    }
    $hasEquivalentUnique = false;
    foreach ($indexesByName as $indexRows) {
        if ($indexRows !== [] && array_column(array_values($indexRows), 'column') === ['service_id', 'item_id']
            && array_filter($indexRows, static fn(array $entry): bool => $entry['non_unique'] !== 0) === []) {
            $hasEquivalentUnique = true;
            break;
        }
    }
    if (!$hasEquivalentUnique && !$conn->query('ALTER TABLE service_material_rules ADD UNIQUE KEY uq_smr_service_item (service_id, item_id)')) throw new RuntimeException('Could not add the stable service/material unique index.');

    // Backfill only exact, pre-verified service_type aliases. Blank legacy row values 31/32 are never changed.
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not begin the service ID backfill transaction.');
    $backfillTransactionOpen = true;
    $backfilled = [];
    foreach ($allRuleRows as $row) {
        if (($row['service_id'] ?? null) !== null) continue;
        $candidateId = null;
        foreach ($mapping as $mappedServiceId => $verifiedAliases) {
            if (in_array((string)($row['service_type'] ?? ''), $verifiedAliases, true)) {
                $candidateId = (int)$mappedServiceId;
                break;
            }
        }
        $itemId = (int)$row['item_id'];
        $itemName = (string)($row['item_name'] ?? '');
        if ($candidateId === null || !$isVerifiedPair($candidateId, $itemId, $itemName)
            || strtoupper(trim((string)($row['item_status'] ?? ''))) !== 'ACTIVE') continue;
        $stmt = $conn->prepare('UPDATE service_material_rules SET service_id = ? WHERE id = ? AND service_id IS NULL AND BINARY service_type = BINARY ? AND item_id = ?');
        if (!$stmt) throw new RuntimeException('Could not prepare exact row-ID service identity backfill.');
        $legacyType = (string)$row['service_type'];
        $ruleId = (int)$row['id'];
        $stmt->bind_param('iisi', $candidateId, $ruleId, $legacyType, $itemId);
        if (!$stmt->execute() || (int)$stmt->affected_rows !== 1) { $stmt->close(); throw new RuntimeException('Exact row-ID service identity backfill failed for rule ID ' . $ruleId . '.'); }
        $stmt->close();
        $backfilled[$ruleId] = [$candidateId, $itemId];
    }
    $stillAmbiguous = $getRows($conn, "SELECT id, service_type, item_id, service_id FROM service_material_rules WHERE id IN (31,32) ORDER BY id");
    foreach ($stillAmbiguous as $row) if ((int)$row['service_id'] !== 0 || (string)$row['service_type'] !== '' || (int)$row['item_id'] !== 212) throw new RuntimeException('Ambiguous rows 31/32 were unexpectedly changed.');
    $duplicates = $getRows($conn, 'SELECT service_id, item_id, COUNT(*) AS duplicate_count FROM service_material_rules WHERE service_id IS NOT NULL GROUP BY service_id, item_id HAVING COUNT(*) > 1');
    if ($duplicates !== []) throw new RuntimeException('Duplicate stable service/material mappings remain after backfill.');
    foreach ($backfilled as $ruleId => [$expectedServiceId, $expectedItemId]) {
        $verifyBackfill = $getRows($conn, 'SELECT id, service_id, item_id FROM service_material_rules WHERE id = ' . (int)$ruleId . ' LIMIT 1');
        if (count($verifyBackfill) !== 1 || (int)$verifyBackfill[0]['id'] !== (int)$ruleId
            || (int)$verifyBackfill[0]['service_id'] !== (int)$expectedServiceId || (int)$verifyBackfill[0]['item_id'] !== (int)$expectedItemId) {
            throw new RuntimeException('Exact backfill verification failed for rule ID ' . (int)$ruleId . '.');
        }
    }
    if (!$conn->commit()) throw new RuntimeException('Could not commit the verified service ID backfill.');
    $backfillTransactionOpen = false;
    $finalType = $getColumn($conn, 'service_material_rules', 'service_type');
    $finalId = $getColumn($conn, 'service_material_rules', 'service_id');
    if (!$finalType || strtolower((string)$finalType['DATA_TYPE']) !== 'varchar' || !$finalId || strtolower((string)$finalId['COLUMN_TYPE']) !== $serviceIdType || strtoupper((string)$finalId['IS_NULLABLE']) !== 'YES') throw new RuntimeException('Post-migration schema verification failed.');

    fwrite(STDOUT, "SERVICE_MATERIAL_SCHEMA_RESULT=PASS database={$targetDb} service_type=VARCHAR service_id=nullable stable_unique_key=present ambiguous_rows_31_32=untouched\n");
    fwrite(STDOUT, "SERVICE_MATERIAL_SCHEMA_SNAPSHOT={$snapshotPath}\nSERVICE_MATERIAL_SCHEMA_SNAPSHOT_SHA256={$hash}\nBACKUP_REFERENCE={$backupReference}\n");
} catch (Throwable $e) {
    if (!empty($backfillTransactionOpen)) @$conn->rollback();
    fwrite(STDERR, 'SERVICE_MATERIAL_SCHEMA_RESULT=FAIL reason=' . preg_replace('/[^A-Za-z0-9 .,;:_-]/', '', $e->getMessage()) . "\n");
    if (isset($snapshotPath)) fwrite(STDERR, "SERVICE_MATERIAL_SCHEMA_SNAPSHOT={$snapshotPath}\n");
    exit(1);
}
