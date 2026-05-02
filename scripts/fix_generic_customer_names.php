<?php
/**
 * Replace placeholder customer display name "Customer" with realistic names + emails.
 *
 * Safety:
 * - Only updates rows in `customers` where TRIM(CONCAT(first, ' ', last)) = 'Customer' (case-sensitive).
 * - Does not touch `users` (staff/admin/manager).
 * - Does not change password_hash or any non-customer tables.
 * - Requires matching row count === count(REPLACEMENT_NAMES) or exits without changes.
 *
 * Usage:
 *   php scripts/fix_generic_customer_names.php --dry-run
 *   php scripts/fix_generic_customer_names.php --execute
 *
 * Rollback (after --execute, using the printed JSON path):
 *   php scripts/rollback_generic_customer_names.php path/to/backup.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

const REPLACEMENT_FULL_NAMES = [
    'Maria Santos',
    'Jose Reyes',
    'Ana Bautista',
    'Mark Villanueva',
    'Carla Mendoza',
    'Kevin Ramos',
    'Angelica Torres',
    'Bryan Castillo',
    'Liza Fernandez',
    'Daniel Garcia',
    'Patricia Lopez',
    'Christopher Aquino',
    'Michelle Navarro',
    'Jerome Flores',
    'Vanessa Cruz',
    'Allan Herrera',
    'Joy Mercado',
    'Patrick Chavez',
    'Kimberly Salazar',
    'Eric Padilla',
    'Rochelle Dominguez',
    'Vincent Ortega',
    'Hannah Molina',
    'Ryan Espino',
    'Kristine Valencia',
    'Carlo Pineda',
    'Janine Aguilar',
    'Dennis Soriano',
    'Aileen Zamora',
    'Joshua Cabrera',
    'Nicole Fuentes',
    'Marvin Del Rosario',
    'Grace Alvarado',
    'Leo Santiago',
    'Camille Soriano',
    'Arnold Mendoza',
    'Bea Ramos',
    'Noel Torres',
    'Katrina Fernandez',
    'Francis Garcia',
    'Sheila Lopez',
    'Arvin Aquino',
    'Denise Navarro',
    'Ruben Flores',
    'Maricel Cruz',
    'Joel Herrera',
    'Trisha Mercado',
    'Gilbert Chavez',
    'Alyssa Salazar',
];

/**
 * @return array{0:string,1:string}
 */
function pf_split_full_name(string $full): array
{
    $full = trim($full);
    if ($full === '') {
        throw new InvalidArgumentException('Empty full name');
    }
    $parts = preg_split('/\s+/u', $full) ?: [];
    if (count($parts) < 2) {
        throw new InvalidArgumentException('Full name needs at least two words: ' . $full);
    }
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    return [$first, $last];
}

$args = array_values(array_slice($argv, 1));
$dry = in_array('--dry-run', $args, true);
$execute = in_array('--execute', $args, true);

if (!$dry && !$execute) {
    fwrite(STDERR, "Specify --dry-run or --execute.\n");
    exit(1);
}
if ($dry && $execute) {
    fwrite(STDERR, "Use only one of --dry-run or --execute.\n");
    exit(1);
}

$sqlMatch = "
    SELECT customer_id, first_name, middle_name, last_name, email
    FROM customers
    WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer'
    ORDER BY customer_id ASC
";

$matches = db_query($sqlMatch);
$matchCount = is_array($matches) ? count($matches) : 0;

$nameCount = count(REPLACEMENT_FULL_NAMES);
echo "Generic-name match query: display name exactly \"Customer\" (trimmed).\n";
echo "Rows matched: {$matchCount}\n";
echo "Required for update: {$nameCount} (must equal name list length).\n\n";

if ($matchCount !== $nameCount) {
    fwrite(STDERR, "ABORT: Row count does not match. No changes applied.\n");
    fwrite(STDERR, "Adjust REPLACEMENT_FULL_NAMES or fix DB rows, then re-run.\n");
    exit(2);
}

// Email ownership: lowercase email -> owning customer_id (current DB + planned updates).
$emailOwner = [];
foreach (db_query("SELECT customer_id, LOWER(TRIM(email)) AS em FROM customers") as $erow) {
    $e = (string)($erow['em'] ?? '');
    if ($e !== '') {
        $emailOwner[$e] = (int)$erow['customer_id'];
    }
}

$assignments = [];
foreach ($matches as $idx => $row) {
    $cid = (int)$row['customer_id'];
    $full = REPLACEMENT_FULL_NAMES[$idx];
    [$first, $last] = pf_split_full_name($full);
    if (strlen($first) > 50 || strlen($last) > 50) {
        fwrite(STDERR, "ABORT: Name part exceeds varchar(50): {$full}\n");
        exit(3);
    }
    $collapsed = preg_replace('/\s+/u', '', mb_strtolower($full, 'UTF-8'));
    $primary = $collapsed . '@gmail.com';
    $lk = strtolower($primary);
    $owner = $emailOwner[$lk] ?? null;
    $usePrimary = ($owner === null || $owner === $cid);
    if ($usePrimary) {
        foreach ($assignments as $prev) {
            if (strtolower($prev['new']['email']) === $lk) {
                $usePrimary = false;
                break;
            }
        }
    }
    $candidate = $usePrimary ? $primary : ($collapsed . $cid . '@gmail.com');
    $emailOwner[strtolower($candidate)] = $cid;

    $assignments[] = [
        'customer_id' => $cid,
        'old' => [
            'first_name' => (string)$row['first_name'],
            'middle_name' => $row['middle_name'] ?? null,
            'last_name' => (string)$row['last_name'],
            'email' => (string)$row['email'],
        ],
        'new' => [
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'email' => $candidate,
        ],
    ];
}

echo "--- Planned updates (passwords unchanged) ---\n";
foreach ($assignments as $a) {
    echo sprintf(
        "ID %d: \"%s %s\" <%s>  =>  \"%s %s\" <%s>\n",
        $a['customer_id'],
        $a['old']['first_name'],
        $a['old']['last_name'],
        $a['old']['email'],
        $a['new']['first_name'],
        $a['new']['last_name'],
        $a['new']['email']
    );
}

if ($dry) {
    echo "\nDry run only. Re-run with --execute to write + backup.\n";
    exit(0);
}

global $conn;

$backup = [
    'created_at' => date('c'),
    'criteria' => "TRIM(CONCAT(first_name,' ',last_name)) = 'Customer'",
    'match_count' => $matchCount,
    'rows' => array_map(static function (array $a): array {
        return [
            'customer_id' => $a['customer_id'],
            'first_name' => $a['old']['first_name'],
            'middle_name' => $a['old']['middle_name'],
            'last_name' => $a['old']['last_name'],
            'email' => $a['old']['email'],
        ];
    }, $assignments),
];

$backupDir = __DIR__ . '/data';
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Could not create backup directory: {$backupDir}\n");
    exit(4);
}

$backupPath = $backupDir . '/generic_customer_rename_backup_' . date('Ymd_His') . '.json';
file_put_contents(
    $backupPath,
    json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
echo "\nBackup written: {$backupPath}\n";
echo "Rollback: php scripts/rollback_generic_customer_names.php " . escapeshellarg($backupPath) . "\n\n";

$conn->begin_transaction();
try {
    foreach ($assignments as $a) {
        $ok = db_execute(
            "UPDATE customers SET first_name = ?, middle_name = ?, last_name = ?, email = ? WHERE customer_id = ?",
            'ssssi',
            [
                $a['new']['first_name'],
                $a['new']['middle_name'],
                $a['new']['last_name'],
                $a['new']['email'],
                $a['customer_id'],
            ]
        );
        if (!$ok) {
            throw new RuntimeException('UPDATE failed for customer_id ' . $a['customer_id']);
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "Rolled back: " . $e->getMessage() . "\n");
    exit(5);
}

echo "Update committed.\n\n--- Verification ---\n";

$stillGeneric = db_query(
    "SELECT COUNT(*) AS c FROM customers
     WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer'"
);
echo 'Rows still named "Customer": ' . (int)($stillGeneric[0]['c'] ?? -1) . "\n";

$ids = array_map(static fn($x) => (int)$x['customer_id'], $assignments);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$typesVerify = str_repeat('i', count($ids));
$names = db_query(
    "SELECT customer_id, first_name, last_name, email FROM customers WHERE customer_id IN ($placeholders) ORDER BY customer_id ASC",
    $typesVerify,
    $ids
);

$expectedById = [];
foreach ($assignments as $a) {
    $expectedById[(int)$a['customer_id']] = $a['new'];
}

$seen = [];
$ok = true;
foreach ($names as $r) {
    $cid = (int)$r['customer_id'];
    $fn = trim((string)$r['first_name']);
    $ln = trim((string)$r['last_name']);
    $em = strtolower(trim((string)$r['email']));
    $exp = $expectedById[$cid] ?? null;
    if ($exp === null) {
        echo "Missing expected row for customer_id {$cid}\n";
        $ok = false;
        continue;
    }
    if ($fn !== $exp['first_name'] || $ln !== $exp['last_name'] || $em !== strtolower($exp['email'])) {
        echo "Mismatch customer_id {$cid}: DB \"{$fn} {$ln}\" <{$em}> vs planned \"{$exp['first_name']} {$exp['last_name']}\" <{$exp['email']}>\n";
        $ok = false;
    }
    $fullKey = mb_strtolower($fn . ' ' . $ln, 'UTF-8');
    if (isset($seen[$fullKey])) {
        echo "DUPLICATE full name in updated set: {$fn} {$ln}\n";
        $ok = false;
    }
    $seen[$fullKey] = true;
    if (strlen($em) < 10 || substr($em, -10) !== '@gmail.com') {
        echo "Email must end with @gmail.com for {$fn} {$ln}: {$em}\n";
        $ok = false;
    }
}
echo $ok ? "Updated rows verified against plan (names + emails).\n" : "Verification reported issues (see above).\n";

echo "\nDone.\n";
