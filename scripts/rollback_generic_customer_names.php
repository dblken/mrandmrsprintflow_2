<?php
/**
 * Restore customers from JSON backup produced by fix_generic_customer_names.php
 *
 * Usage:
 *   php scripts/rollback_generic_customer_names.php scripts/data/generic_customer_rename_backup_YYYYMMDD_HHMMSS.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/rollback_generic_customer_names.php <backup.json>\n");
    exit(1);
}

$raw = file_get_contents($path);
$data = json_decode($raw ?: '', true);
if (!is_array($data) || empty($data['rows']) || !is_array($data['rows'])) {
    fwrite(STDERR, "Invalid backup JSON.\n");
    exit(2);
}

global $conn;
$conn->begin_transaction();
try {
    foreach ($data['rows'] as $row) {
        $cid = (int)($row['customer_id'] ?? 0);
        if ($cid <= 0) {
            throw new InvalidArgumentException('Invalid customer_id in backup');
        }
        $mn = $row['middle_name'] ?? null;
        $ok = db_execute(
            "UPDATE customers SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE customer_id = ?",
            'ssssi',
            [
                (string)($row['first_name'] ?? ''),
                $mn,
                (string)($row['last_name'] ?? ''),
                (string)($row['email'] ?? ''),
                $cid,
            ]
        );
        if (!$ok) {
            throw new RuntimeException('Rollback UPDATE failed for customer_id ' . $cid);
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Rolled back transaction: " . $e->getMessage() . "\n");
    exit(3);
}

echo "Restored " . count($data['rows']) . " customer row(s) from backup.\n";
