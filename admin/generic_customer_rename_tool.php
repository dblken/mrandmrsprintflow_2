<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Admin');

$current_user = get_logged_in_user();
$base_path = defined('BASE_PATH') ? BASE_PATH : '';

const PF_GCRT_BACKUP_TABLE = 'maintenance_customer_profile_tool_backup';

const PF_GENERIC_CUSTOMER_RENAME_MAP = [
    ['old_email' => 'lance@gmail.com',                     'full_name' => 'Maria Santos'],
    ['old_email' => 'direct_test_99@example.com',         'full_name' => 'Jose Reyes'],
    ['old_email' => 'admin@gmail.com',                    'full_name' => 'Ana Bautista'],
    ['old_email' => 'admin@printflow.com',                'full_name' => 'Mark Villanueva'],
    ['old_email' => 'tester@test.com',                    'full_name' => 'Carla Mendoza'],
    ['old_email' => 'admin@admin.com',                    'full_name' => 'Kevin Ramos'],
    ['old_email' => 'kentlloydvillanueva5@gmail.com',     'full_name' => 'Angelica Torres'],
    ['old_email' => 'kentlloydvillanueva6@gmail.com',     'full_name' => 'Bryan Castillo'],
    ['old_email' => 'gonk322@gmail.com',                  'full_name' => 'Liza Fernandez'],
    ['old_email' => 'kenvillanueva570@gmail.com',         'full_name' => 'Daniel Garcia'],
    ['old_email' => 'kakankk12312@gmail.com',             'full_name' => 'Patricia Lopez'],
    ['old_email' => 'kentll62738@gmail.com',              'full_name' => 'Christopher Aquino'],
    ['old_email' => 'tester@printflow.com',               'full_name' => 'Michelle Navarro'],
    ['old_email' => 'admin_test@test.com',                'full_name' => 'Jerome Flores'],
    ['old_email' => 'arrontuazon60892@gmail.com',         'full_name' => 'Vanessa Cruz'],
    ['old_email' => 'maja1211113@gmail.com',              'full_name' => 'Allan Herrera'],
    ['old_email' => 'maja12111123@gmail.com',             'full_name' => 'Joy Mercado'],
    ['old_email' => 'maja121111321212@gmail.com',         'full_name' => 'Patrick Chavez'],
    ['old_email' => 'test_maja_browser@gmail.com',        'full_name' => 'Kimberly Salazar'],
    ['old_email' => 'success_maja_v4@gmail.com',          'full_name' => 'Eric Padilla'],
    ['old_email' => 'success_maja_v10@gmail.com',         'full_name' => 'Rochelle Dominguez'],
    ['old_email' => 'success_maja_v11@gmail.com',         'full_name' => 'Vincent Ortega'],
    ['old_email' => 'arrontuazon60dsdfd892@gmail.com',    'full_name' => 'Hannah Molina'],
    ['old_email' => 'kentlloydvillanuev21212a@gmail.com', 'full_name' => 'Ryan Espino'],
    ['old_email' => 'rollback_test_maja@gmail.com',       'full_name' => 'Kristine Valencia'],
    ['old_email' => 'nav_test_maja@gmail.com',            'full_name' => 'Carlo Pineda'],
    ['old_email' => 'admin1212121@printflow.com',         'full_name' => 'Janine Aguilar'],
    ['old_email' => 'dfdsff1212121@printflow.com',        'full_name' => 'Dennis Soriano'],
    ['old_email' => 'glademernavarette@gmail.com',        'full_name' => 'Aileen Zamora'],
    ['old_email' => 'kentlloydvillanueva5@gmail.comw',    'full_name' => 'Joshua Cabrera'],
    ['old_email' => 'angela322116@gmail.com',             'full_name' => 'Nicole Fuentes'],
    ['old_email' => 'testadmin@gmail.com',                'full_name' => 'Marvin Del Rosario'],
    ['old_email' => 'admin_test@gmail.com',               'full_name' => 'Grace Alvarado'],
    ['old_email' => 'testadmin@example.com',              'full_name' => 'Leo Santiago'],
    ['old_email' => 'nav_test_v2@gmail.com',              'full_name' => 'Camille Soriano'],
    ['old_email' => 'fdfdgdgf@gmail.com',                 'full_name' => 'Arnold Mendoza'],
    ['old_email' => 'kentlloydvillanueva.edu@gmail.com',  'full_name' => 'Bea Ramos'],
    ['old_email' => '+639300610038@phone.local',          'full_name' => 'Noel Torres'],
    ['old_email' => 'lisa1211249022@gmail.com',           'full_name' => 'Katrina Fernandez'],
    ['old_email' => 'lisa121124902@gmail.com',            'full_name' => 'Francis Garcia'],
    ['old_email' => 'lisa12112490@gmail.com',             'full_name' => 'Sheila Lopez'],
    ['old_email' => 'dsffsdfdsf@gmail.com',               'full_name' => 'Arvin Aquino'],
    ['old_email' => 'lnzbarcenas@gmail.com',              'full_name' => 'Denise Navarro'],
    ['old_email' => 'bmildred218@gmail.com',              'full_name' => 'Ruben Flores'],
    ['old_email' => 'dumayasdenise9@gmail.com',           'full_name' => 'Maricel Cruz'],
];

const PF_SPECIFIC_CUSTOMER_NAME_MAP = [
    ['customer_id' => 31,  'full_name' => 'Edward Navarro'],
    ['customer_id' => 287, 'full_name' => 'Hazel Lopez'],
    ['customer_id' => 281, 'full_name' => 'Oliver Castillo'],
    ['customer_id' => 293, 'full_name' => 'Michelle Torres'],
    ['customer_id' => 5,   'full_name' => 'Reynaldo Mercado', 'update_email' => true],
    ['customer_id' => 6,   'full_name' => 'Charlene Fernandez', 'update_email' => true],
];

const PF_DEMO_MOBILE_PREFIXES = [
    '0905',
    '0915',
    '0927',
    '0932',
    '0945',
    '0956',
    '0967',
    '0977',
    '0981',
    '0998',
];

function pf_gcrt_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pf_gcrt_split_full_name(string $full): array
{
    $full = trim($full);
    if ($full === '') {
        throw new InvalidArgumentException('Empty full name in plan.');
    }

    $parts = preg_split('/\s+/u', $full) ?: [];
    if (count($parts) < 2) {
        throw new InvalidArgumentException('Full name must contain at least two words: ' . $full);
    }

    $first = array_shift($parts);
    $last = implode(' ', $parts);

    return [$first, $last];
}

function pf_gcrt_expected_email(string $full): string
{
    $collapsed = preg_replace('/\s+/u', '', mb_strtolower(trim($full), 'UTF-8'));
    return $collapsed . '@gmail.com';
}

function pf_gcrt_exact_customer_label(array $row): string
{
    return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
}

function pf_gcrt_contact_is_missing($value): bool
{
    if ($value === null) {
        return true;
    }
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return true;
    }
    return strtoupper($trimmed) === 'N/A';
}

function pf_gcrt_contact_is_valid_demo(string $value): bool
{
    return (bool)preg_match('/^09\d{9}$/', trim($value));
}

function pf_gcrt_digits_are_step_sequence(string $digits, int $step): bool
{
    $length = strlen($digits);
    if ($length < 2) {
        return false;
    }

    for ($i = 1; $i < $length; $i++) {
        $current = (int)$digits[$i];
        $previous = (int)$digits[$i - 1];
        if (($current - $previous) !== $step) {
            return false;
        }
    }

    return true;
}

function pf_gcrt_contact_has_obvious_pattern(string $value): bool
{
    $digits = trim($value);
    if (!pf_gcrt_contact_is_valid_demo($digits)) {
        return true;
    }

    $suffix = substr($digits, 4);
    if ($suffix === false || strlen($suffix) !== 7) {
        return true;
    }

    if (substr($digits, -4) === '0000') {
        return true;
    }
    if (preg_match('/^(.)\1{6}$/', $suffix)) {
        return true;
    }
    if (preg_match('/(\d)\1{4,}/', $suffix)) {
        return true;
    }
    if (pf_gcrt_digits_are_step_sequence($suffix, 1) || pf_gcrt_digits_are_step_sequence($suffix, -1)) {
        return true;
    }

    return false;
}

function pf_gcrt_fetch_link_snapshot(array $ids): array
{
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $snapshot = [];

    foreach ($ids as $id) {
        $snapshot[(int)$id] = ['orders' => 0, 'job_orders' => 0];
    }

    $orderRows = db_query(
        "SELECT customer_id, COUNT(*) AS c
         FROM orders
         WHERE customer_id IN ($placeholders)
         GROUP BY customer_id",
        $types,
        $ids
    );
    foreach ($orderRows as $row) {
        $cid = (int)($row['customer_id'] ?? 0);
        if (isset($snapshot[$cid])) {
            $snapshot[$cid]['orders'] = (int)($row['c'] ?? 0);
        }
    }

    $jobOrderRows = db_query(
        "SELECT customer_id, COUNT(*) AS c
         FROM job_orders
         WHERE customer_id IN ($placeholders)
         GROUP BY customer_id",
        $types,
        $ids
    );
    foreach ($jobOrderRows as $row) {
        $cid = (int)($row['customer_id'] ?? 0);
        if (isset($snapshot[$cid])) {
            $snapshot[$cid]['job_orders'] = (int)($row['c'] ?? 0);
        }
    }

    return $snapshot;
}

function pf_gcrt_ensure_backup_table(): void
{
    $sql = "CREATE TABLE IF NOT EXISTS " . PF_GCRT_BACKUP_TABLE . " (
        batch_id VARCHAR(64) NOT NULL,
        scope VARCHAR(64) NOT NULL,
        customer_id INT NOT NULL,
        first_name VARCHAR(100) NULL,
        middle_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        email VARCHAR(255) NULL,
        contact_number VARCHAR(32) NULL,
        executed_by INT NULL,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (batch_id, customer_id),
        KEY idx_scope (scope),
        KEY idx_captured_at (captured_at),
        KEY idx_customer_id (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!db_execute($sql)) {
        throw new RuntimeException('Could not create or verify backup table.');
    }
}

function pf_gcrt_recent_batches(): array
{
    pf_gcrt_ensure_backup_table();

    return db_query(
        "SELECT batch_id, MAX(scope) AS scope, COUNT(*) AS row_count, MAX(captured_at) AS captured_at
         FROM " . PF_GCRT_BACKUP_TABLE . "
         GROUP BY batch_id
         ORDER BY MAX(captured_at) DESC
         LIMIT 12"
    );
}

function pf_gcrt_plan_shell(string $scope, string $label, string $confirmPhrase): array
{
    return [
        'scope' => $scope,
        'label' => $label,
        'confirm_phrase' => $confirmPhrase,
        'target_count' => 0,
        'assignments' => [],
        'errors' => [],
        'ready' => false,
    ];
}

function pf_gcrt_build_generic_rename_plan(): array
{
    $plan = pf_gcrt_plan_shell('rename_generic', 'Generic Customer Rename', 'RENAME 45 CUSTOMERS');
    $mapping = PF_GENERIC_CUSTOMER_RENAME_MAP;
    $expectedCount = count($mapping);
    $plan['target_count'] = $expectedCount;

    $errors = [];
    $assignments = [];

    $exactMatches = db_query(
        "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
         FROM customers
         WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer'
         ORDER BY customer_id ASC"
    );
    if (count($exactMatches) !== $expectedCount) {
        $errors[] = 'Exact "Customer" row count is ' . count($exactMatches) . ', expected ' . $expectedCount . '.';
    }

    $oldEmails = [];
    $seenFullNames = [];
    $seenNewEmails = [];
    foreach ($mapping as $row) {
        $oldEmail = mb_strtolower(trim((string)$row['old_email']), 'UTF-8');
        $fullName = trim((string)$row['full_name']);
        $newEmail = pf_gcrt_expected_email($fullName);
        $fullKey = mb_strtolower($fullName, 'UTF-8');

        if (isset($seenFullNames[$fullKey])) {
            $errors[] = 'Duplicate replacement full name: ' . $fullName;
        }
        if (isset($seenNewEmails[$newEmail])) {
            $errors[] = 'Duplicate replacement email: ' . $newEmail;
        }

        $seenFullNames[$fullKey] = true;
        $seenNewEmails[$newEmail] = true;
        $oldEmails[] = $oldEmail;
    }

    $rows = db_query(
        "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
         FROM customers
         WHERE LOWER(TRIM(email)) IN (" . implode(',', array_fill(0, count($oldEmails), '?')) . ")",
        str_repeat('s', count($oldEmails)),
        $oldEmails
    );
    $byOldEmail = [];
    foreach ($rows as $row) {
        $byOldEmail[mb_strtolower(trim((string)$row['email']), 'UTF-8')] = $row;
    }

    $customerEmailOwner = [];
    foreach (db_query("SELECT customer_id, LOWER(TRIM(email)) AS em FROM customers") as $row) {
        $email = trim((string)($row['em'] ?? ''));
        if ($email !== '') {
            $customerEmailOwner[$email] = (int)$row['customer_id'];
        }
    }
    $userEmailOwner = [];
    foreach (db_query("SELECT user_id, role, LOWER(TRIM(email)) AS em FROM users") as $row) {
        $email = trim((string)($row['em'] ?? ''));
        if ($email !== '') {
            $userEmailOwner[$email] = ['user_id' => (int)$row['user_id'], 'role' => (string)$row['role']];
        }
    }

    foreach ($mapping as $index => $item) {
        $oldEmail = mb_strtolower(trim((string)$item['old_email']), 'UTF-8');
        $fullName = trim((string)$item['full_name']);
        [$first, $last] = pf_gcrt_split_full_name($fullName);
        $newEmail = pf_gcrt_expected_email($fullName);

        if (!isset($byOldEmail[$oldEmail])) {
            $errors[] = 'Missing target customer email in database: ' . $item['old_email'];
            continue;
        }

        $row = $byOldEmail[$oldEmail];
        if (pf_gcrt_exact_customer_label($row) !== 'Customer') {
            $errors[] = 'Target email is no longer an exact "Customer" row: ' . $item['old_email'];
            continue;
        }

        $cid = (int)$row['customer_id'];
        $owner = $customerEmailOwner[$newEmail] ?? null;
        if ($owner !== null && $owner !== $cid) {
            $errors[] = 'Planned Gmail address already belongs to another customer: ' . $newEmail;
        }
        if (isset($userEmailOwner[$newEmail])) {
            $errors[] = 'Planned Gmail address already belongs to a user account: ' . $newEmail;
        }

        $assignments[] = [
            'seq' => $index + 1,
            'customer_id' => $cid,
            'old' => [
                'first_name' => (string)$row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'],
                'contact_number' => $row['contact_number'] ?? null,
            ],
            'new' => [
                'first_name' => $first,
                'middle_name' => null,
                'last_name' => $last,
                'email' => $newEmail,
                'contact_number' => $row['contact_number'] ?? null,
                'full_name' => $fullName,
            ],
        ];
    }

    if (count($assignments) !== $expectedCount) {
        $errors[] = 'Prepared assignment count is ' . count($assignments) . ', expected ' . $expectedCount . '.';
    }

    $plan['assignments'] = $assignments;
    $plan['errors'] = array_values(array_unique($errors));
    $plan['ready'] = empty($plan['errors']);

    return $plan;
}

function pf_gcrt_build_specific_name_plan(): array
{
    $plan = pf_gcrt_plan_shell('rename_specific_ids', 'Specific Customer ID Rename', 'RENAME SPECIFIC IDS');
    $mapping = PF_SPECIFIC_CUSTOMER_NAME_MAP;
    $expectedCount = count($mapping);
    $plan['target_count'] = $expectedCount;

    $errors = [];
    $assignments = [];
    $ids = array_map(static fn($row) => (int)$row['customer_id'], $mapping);
    $rows = db_query(
        "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
         FROM customers
         WHERE customer_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
         ORDER BY customer_id ASC",
        str_repeat('i', count($ids)),
        $ids
    );
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['customer_id']] = $row;
    }

    $customerEmailOwner = [];
    foreach (db_query("SELECT customer_id, LOWER(TRIM(email)) AS em FROM customers") as $row) {
        $email = trim((string)($row['em'] ?? ''));
        if ($email !== '') {
            $customerEmailOwner[$email] = (int)$row['customer_id'];
        }
    }
    $userEmailOwner = [];
    foreach (db_query("SELECT user_id, role, LOWER(TRIM(email)) AS em FROM users") as $row) {
        $email = trim((string)($row['em'] ?? ''));
        if ($email !== '') {
            $userEmailOwner[$email] = ['user_id' => (int)$row['user_id'], 'role' => (string)$row['role']];
        }
    }

    $seenFullNames = [];
    $seenNewEmails = [];
    foreach ($mapping as $index => $item) {
        $cid = (int)$item['customer_id'];
        $fullName = trim((string)$item['full_name']);
        [$first, $last] = pf_gcrt_split_full_name($fullName);
        $fullKey = mb_strtolower($fullName, 'UTF-8');

        if (isset($seenFullNames[$fullKey])) {
            $errors[] = 'Duplicate replacement full name in specific-ID plan: ' . $fullName;
        }
        $seenFullNames[$fullKey] = true;

        if (!isset($byId[$cid])) {
            $errors[] = 'Customer ID not found: ' . $cid;
            continue;
        }

        $row = $byId[$cid];
        $shouldUpdateEmail = !empty($item['update_email']);
        $newEmail = $shouldUpdateEmail ? pf_gcrt_expected_email($fullName) : (string)$row['email'];
        $newEmailKey = mb_strtolower(trim($newEmail), 'UTF-8');

        if ($shouldUpdateEmail) {
            if (isset($seenNewEmails[$newEmailKey])) {
                $errors[] = 'Duplicate replacement email in specific-ID plan: ' . $newEmail;
            }
            $seenNewEmails[$newEmailKey] = true;

            $owner = $customerEmailOwner[$newEmailKey] ?? null;
            if ($owner !== null && $owner !== $cid) {
                $errors[] = 'Planned specific-ID email already belongs to another customer: ' . $newEmail;
            }
            if (isset($userEmailOwner[$newEmailKey])) {
                $errors[] = 'Planned specific-ID email already belongs to a user account: ' . $newEmail;
            }
        }

        $assignments[] = [
            'seq' => $index + 1,
            'customer_id' => $cid,
            'old' => [
                'first_name' => (string)$row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'],
                'contact_number' => $row['contact_number'] ?? null,
            ],
            'new' => [
                'first_name' => $first,
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => $last,
                'email' => $newEmail,
                'contact_number' => $row['contact_number'] ?? null,
                'full_name' => $fullName,
            ],
        ];
    }

    if (count($assignments) !== $expectedCount) {
        $errors[] = 'Prepared specific-ID assignment count is ' . count($assignments) . ', expected ' . $expectedCount . '.';
    }

    $plan['assignments'] = $assignments;
    $plan['errors'] = array_values(array_unique($errors));
    $plan['ready'] = empty($plan['errors']);

    return $plan;
}

function pf_gcrt_next_demo_mobile(array &$usedNumbers): string
{
    $prefixes = PF_DEMO_MOBILE_PREFIXES;
    $prefixCount = count($prefixes);

    for ($attempt = 0; $attempt < 5000; $attempt++) {
        $prefix = $prefixes[random_int(0, $prefixCount - 1)];
        $suffix = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $candidate = $prefix . $suffix;

        if (!pf_gcrt_contact_is_valid_demo($candidate)) {
            continue;
        }
        if (pf_gcrt_contact_has_obvious_pattern($candidate)) {
            continue;
        }
        if (isset($usedNumbers[$candidate])) {
            continue;
        }

        $usedNumbers[$candidate] = true;
        return $candidate;
    }

    throw new RuntimeException('Could not generate enough unique synthetic demo phone numbers.');
}

function pf_gcrt_build_missing_contact_plan(): array
{
    $plan = pf_gcrt_plan_shell('fill_missing_contacts', 'Missing Contact Number Fill', 'FILL MISSING CONTACTS');

    $errors = [];
    $assignments = [];
    $rows = db_query(
        "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
         FROM customers
         WHERE contact_number IS NULL
            OR TRIM(contact_number) = ''
            OR UPPER(TRIM(contact_number)) = 'N/A'
         ORDER BY customer_id ASC"
    );
    $plan['target_count'] = count($rows);

    $usedNumbers = [];
    foreach (db_query("SELECT LOWER(TRIM(contact_number)) AS phone FROM customers WHERE contact_number IS NOT NULL AND TRIM(contact_number) <> ''") as $row) {
        $phone = trim((string)($row['phone'] ?? ''));
        if ($phone !== '') {
            $usedNumbers[$phone] = true;
        }
    }
    foreach (db_query("SELECT LOWER(TRIM(contact_number)) AS phone FROM users WHERE contact_number IS NOT NULL AND TRIM(contact_number) <> ''") as $row) {
        $phone = trim((string)($row['phone'] ?? ''));
        if ($phone !== '') {
            $usedNumbers[$phone] = true;
        }
    }

    foreach ($rows as $index => $row) {
        $currentContact = $row['contact_number'] ?? null;
        if (!pf_gcrt_contact_is_missing($currentContact)) {
            $errors[] = 'Non-missing contact number slipped into target set for customer_id ' . (int)$row['customer_id'];
            continue;
        }

        $newContact = pf_gcrt_next_demo_mobile($usedNumbers);
        $assignments[] = [
            'seq' => $index + 1,
            'customer_id' => (int)$row['customer_id'],
            'old' => [
                'first_name' => (string)$row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'],
                'contact_number' => $currentContact,
            ],
            'new' => [
                'first_name' => (string)$row['first_name'],
                'middle_name' => $row['middle_name'] ?? null,
                'last_name' => (string)$row['last_name'],
                'email' => (string)$row['email'],
                'contact_number' => $newContact,
                'full_name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
            ],
        ];
    }

    $seen = [];
    foreach ($assignments as $assignment) {
        $phone = (string)$assignment['new']['contact_number'];
        if (!pf_gcrt_contact_is_valid_demo($phone)) {
            $errors[] = 'Generated phone number is invalid for customer_id ' . (int)$assignment['customer_id'];
        }
        if (pf_gcrt_contact_has_obvious_pattern($phone)) {
            $errors[] = 'Generated phone number has an obvious pattern for customer_id ' . (int)$assignment['customer_id'];
        }
        if (isset($seen[$phone])) {
            $errors[] = 'Generated phone number is duplicated: ' . $phone;
        }
        $seen[$phone] = true;
    }

    $plan['assignments'] = $assignments;
    $plan['errors'] = array_values(array_unique($errors));
    $plan['ready'] = empty($plan['errors']);

    return $plan;
}

function pf_gcrt_execute_plan(array $plan, int $executedBy): array
{
    if (empty($plan['ready'])) {
        throw new RuntimeException('This plan is not safe to execute.');
    }

    pf_gcrt_ensure_backup_table();

    global $conn;
    $assignments = $plan['assignments'];
    $ids = array_map(static fn($a) => (int)$a['customer_id'], $assignments);
    $preLinks = pf_gcrt_fetch_link_snapshot($ids);
    $preUserRoleSnapshot = db_query("SELECT role, COUNT(*) AS c FROM users GROUP BY role ORDER BY role ASC");
    $batchId = $plan['scope'] . '_' . date('Ymd_His');

    $conn->begin_transaction();
    try {
        foreach ($assignments as $assignment) {
            $inserted = db_execute(
                "INSERT INTO " . PF_GCRT_BACKUP_TABLE . "
                 (batch_id, scope, customer_id, first_name, middle_name, last_name, email, contact_number, executed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                'ssisssssi',
                [
                    $batchId,
                    $plan['scope'],
                    (int)$assignment['customer_id'],
                    $assignment['old']['first_name'],
                    $assignment['old']['middle_name'],
                    $assignment['old']['last_name'],
                    $assignment['old']['email'],
                    $assignment['old']['contact_number'],
                    $executedBy,
                ]
            );
            if (!$inserted) {
                throw new RuntimeException('Backup insert failed for customer_id ' . (int)$assignment['customer_id']);
            }
        }

        foreach ($assignments as $assignment) {
            $updated = db_execute(
                "UPDATE customers
                 SET first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ?
                 WHERE customer_id = ?",
                'sssssi',
                [
                    $assignment['new']['first_name'],
                    $assignment['new']['middle_name'],
                    $assignment['new']['last_name'],
                    $assignment['new']['email'],
                    $assignment['new']['contact_number'],
                    (int)$assignment['customer_id'],
                ]
            );
            if (!$updated) {
                throw new RuntimeException('Customer update failed for customer_id ' . (int)$assignment['customer_id']);
            }
        }

        $updatedRows = db_query(
            "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
             FROM customers
             WHERE customer_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
             ORDER BY customer_id ASC",
            str_repeat('i', count($ids)),
            $ids
        );
        $expectedById = [];
        foreach ($assignments as $assignment) {
            $expectedById[(int)$assignment['customer_id']] = $assignment['new'];
        }
        foreach ($updatedRows as $row) {
            $cid = (int)$row['customer_id'];
            $expected = $expectedById[$cid] ?? null;
            if ($expected === null) {
                throw new RuntimeException('Verification could not find expected data for customer_id ' . $cid);
            }

            if (
                trim((string)$row['first_name']) !== (string)$expected['first_name'] ||
                trim((string)($row['middle_name'] ?? '')) !== trim((string)($expected['middle_name'] ?? '')) ||
                trim((string)$row['last_name']) !== (string)$expected['last_name'] ||
                mb_strtolower(trim((string)$row['email']), 'UTF-8') !== mb_strtolower(trim((string)$expected['email']), 'UTF-8') ||
                trim((string)($row['contact_number'] ?? '')) !== trim((string)($expected['contact_number'] ?? ''))
            ) {
                throw new RuntimeException('Verification mismatch for customer_id ' . $cid);
            }
        }

        if ($plan['scope'] === 'rename_generic') {
            $remaining = db_query(
                "SELECT COUNT(*) AS c
                 FROM customers
                 WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer'"
            );
            if ((int)($remaining[0]['c'] ?? -1) !== 0) {
                throw new RuntimeException('Some exact "Customer" rows still remain after generic rename.');
            }
        }

        if ($plan['scope'] === 'fill_missing_contacts') {
            $seenPhones = [];
            foreach ($updatedRows as $row) {
                $phone = trim((string)($row['contact_number'] ?? ''));
                if (pf_gcrt_contact_is_missing($phone)) {
                    throw new RuntimeException('A targeted contact number is still blank, NULL, empty, or N/A.');
                }
                if (!pf_gcrt_contact_is_valid_demo($phone)) {
                    throw new RuntimeException('A generated contact number is not in 09XXXXXXXXX format: ' . $phone);
                }
                if (pf_gcrt_contact_has_obvious_pattern($phone)) {
                    throw new RuntimeException('A generated contact number still has an obvious pattern: ' . $phone);
                }
                if (isset($seenPhones[$phone])) {
                    throw new RuntimeException('A generated contact number is duplicated: ' . $phone);
                }
                $seenPhones[$phone] = true;
            }
        }

        $postLinks = pf_gcrt_fetch_link_snapshot($ids);
        foreach ($ids as $id) {
            $before = $preLinks[$id] ?? ['orders' => 0, 'job_orders' => 0];
            $after = $postLinks[$id] ?? ['orders' => 0, 'job_orders' => 0];
            if ($before['orders'] !== $after['orders'] || $before['job_orders'] !== $after['job_orders']) {
                throw new RuntimeException('Linked order counts changed for customer_id ' . $id);
            }
        }

        $postUserRoleSnapshot = db_query("SELECT role, COUNT(*) AS c FROM users GROUP BY role ORDER BY role ASC");
        if (json_encode($preUserRoleSnapshot) !== json_encode($postUserRoleSnapshot)) {
            throw new RuntimeException('User role counts changed unexpectedly.');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'batch_id' => $batchId,
        'updated_count' => count($assignments),
        'label' => $plan['label'],
    ];
}

function pf_gcrt_rollback_batch(string $batchId): array
{
    $batchId = trim($batchId);
    if ($batchId === '') {
        throw new InvalidArgumentException('Batch ID is required for rollback.');
    }

    pf_gcrt_ensure_backup_table();
    $rows = db_query(
        "SELECT customer_id, first_name, middle_name, last_name, email, contact_number
         FROM " . PF_GCRT_BACKUP_TABLE . "
         WHERE batch_id = ?
         ORDER BY customer_id ASC",
        's',
        [$batchId]
    );
    if (!$rows) {
        throw new RuntimeException('No backup rows found for batch ' . $batchId);
    }

    global $conn;
    $conn->begin_transaction();
    try {
        foreach ($rows as $row) {
            $restored = db_execute(
                "UPDATE customers
                 SET first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ?
                 WHERE customer_id = ?",
                'sssssi',
                [
                    (string)$row['first_name'],
                    $row['middle_name'] ?? null,
                    (string)$row['last_name'],
                    (string)$row['email'],
                    $row['contact_number'] ?? null,
                    (int)$row['customer_id'],
                ]
            );
            if (!$restored) {
                throw new RuntimeException('Rollback failed for customer_id ' . (int)$row['customer_id']);
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'batch_id' => $batchId,
        'restored_count' => count($rows),
    ];
}

$messages = [];
$errors = [];
$lastBatchId = '';
$rollbackBatchId = '';

try {
    pf_gcrt_ensure_backup_table();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF validation failed. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        try {
            if ($action === 'execute_generic') {
                $plan = pf_gcrt_build_generic_rename_plan();
                $confirm = trim((string)($_POST['confirm_phrase_generic'] ?? ''));
                if ($confirm !== $plan['confirm_phrase']) {
                    throw new RuntimeException('Type "' . $plan['confirm_phrase'] . '" exactly before executing the generic rename.');
                }
                $result = pf_gcrt_execute_plan($plan, (int)($current_user['user_id'] ?? 0));
                $lastBatchId = (string)$result['batch_id'];
                $messages[] = $result['label'] . ' completed successfully. Batch ID: ' . $lastBatchId . '. Updated rows: ' . (int)$result['updated_count'] . '.';
            } elseif ($action === 'execute_specific') {
                $plan = pf_gcrt_build_specific_name_plan();
                $confirm = trim((string)($_POST['confirm_phrase_specific'] ?? ''));
                if ($confirm !== $plan['confirm_phrase']) {
                    throw new RuntimeException('Type "' . $plan['confirm_phrase'] . '" exactly before executing the specific-ID rename.');
                }
                $result = pf_gcrt_execute_plan($plan, (int)($current_user['user_id'] ?? 0));
                $lastBatchId = (string)$result['batch_id'];
                $messages[] = $result['label'] . ' completed successfully. Batch ID: ' . $lastBatchId . '. Updated rows: ' . (int)$result['updated_count'] . '.';
            } elseif ($action === 'execute_contacts') {
                $plan = pf_gcrt_build_missing_contact_plan();
                $confirm = trim((string)($_POST['confirm_phrase_contacts'] ?? ''));
                if ($confirm !== $plan['confirm_phrase']) {
                    throw new RuntimeException('Type "' . $plan['confirm_phrase'] . '" exactly before filling contact numbers.');
                }
                $result = pf_gcrt_execute_plan($plan, (int)($current_user['user_id'] ?? 0));
                $lastBatchId = (string)$result['batch_id'];
                $messages[] = $result['label'] . ' completed successfully. Batch ID: ' . $lastBatchId . '. Updated rows: ' . (int)$result['updated_count'] . '.';
            } elseif ($action === 'rollback') {
                $rollbackBatchId = trim((string)($_POST['batch_id'] ?? ''));
                $confirm = trim((string)($_POST['rollback_phrase'] ?? ''));
                if ($confirm !== 'ROLLBACK') {
                    throw new RuntimeException('Type "ROLLBACK" exactly before restoring a batch.');
                }
                $result = pf_gcrt_rollback_batch($rollbackBatchId);
                $messages[] = 'Rollback completed for batch ' . $result['batch_id'] . '. Restored rows: ' . (int)$result['restored_count'] . '.';
            } elseif ($action === 'preview') {
                $messages[] = 'Preview refreshed.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$genericPlan = pf_gcrt_plan_shell('rename_generic', 'Generic Customer Rename', 'RENAME 45 CUSTOMERS');
$specificPlan = pf_gcrt_plan_shell('rename_specific_ids', 'Specific Customer ID Rename', 'RENAME SPECIFIC IDS');
$contactPlan = pf_gcrt_plan_shell('fill_missing_contacts', 'Missing Contact Number Fill', 'FILL MISSING CONTACTS');
$recentBatches = [];

try {
    $genericPlan = pf_gcrt_build_generic_rename_plan();
    $specificPlan = pf_gcrt_build_specific_name_plan();
    $contactPlan = pf_gcrt_build_missing_contact_plan();
    $recentBatches = pf_gcrt_recent_batches();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$page_title = 'Temporary Customer Maintenance Tool';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pf_gcrt_h($page_title); ?></title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f5f7fb; color: #18212f; }
        .wrap { max-width: 1240px; margin: 0 auto; padding: 24px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid #dde4ee; border-radius: 14px; padding: 18px; margin-bottom: 18px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        h1, h2, h3 { margin: 0 0 12px; }
        .muted { color: #5f6f86; }
        .pill { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .pill.ok { background: #dcfce7; color: #166534; }
        .pill.warn { background: #fef3c7; color: #92400e; }
        .pill.bad { background: #fee2e2; color: #991b1b; }
        .msg { border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
        .msg.ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #166534; }
        .msg.bad { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .btn { appearance: none; border: 0; border-radius: 10px; padding: 12px 16px; font-weight: 700; cursor: pointer; }
        .btn.primary { background: #0f4c5c; color: #fff; }
        .btn.secondary { background: #e2e8f0; color: #1e293b; }
        .btn.danger { background: #991b1b; color: #fff; }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .kpi { padding: 14px; border: 1px solid #e5eaf1; border-radius: 12px; background: #fbfcfe; }
        .kpi .label { font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 8px; }
        .kpi .value { font-size: 28px; font-weight: 800; }
        .two-col { display: grid; grid-template-columns: 1.4fr 1fr; gap: 18px; }
        .small { font-size: 13px; }
        .list { margin: 0; padding-left: 18px; }
        .right-link { color: #0f4c5c; font-weight: 700; text-decoration: none; }
        .mono { font-family: Consolas, Monaco, monospace; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #edf2f7; vertical-align: top; font-size: 14px; }
        th { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; }
        input[type="text"] { width: 100%; max-width: 360px; padding: 11px 12px; border-radius: 10px; border: 1px solid #cbd5e1; }
        .plan-table { overflow: auto; margin-top: 14px; }
        .section-gap { margin-top: 4px; }
        @media (max-width: 960px) {
            .grid3, .two-col { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <h1><?php echo pf_gcrt_h($page_title); ?></h1>
                <div class="muted small">Admin-only temporary page for safe, reversible customer maintenance on live data.</div>
            </div>
            <a class="right-link" href="<?php echo pf_gcrt_h($base_path . '/admin/dashboard.php'); ?>">Back to Dashboard</a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="msg ok"><?php echo pf_gcrt_h($message); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="msg bad"><?php echo pf_gcrt_h($error); ?></div>
        <?php endforeach; ?>

        <div class="card">
            <h2>Refresh All Previews</h2>
            <form method="post" class="actions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="preview">
                <button type="submit" class="btn secondary">Refresh Preview</button>
            </form>
        </div>

        <?php
        $plans = [$genericPlan, $specificPlan, $contactPlan];
        foreach ($plans as $plan):
        ?>
            <?php foreach ($plan['errors'] as $error): ?>
                <div class="msg bad"><?php echo pf_gcrt_h($plan['label'] . ': ' . $error); ?></div>
            <?php endforeach; ?>
            <div class="card">
                <h2><?php echo pf_gcrt_h($plan['label']); ?></h2>
                <div class="grid3">
                    <div class="kpi">
                        <div class="label">Target Rows</div>
                        <div class="value"><?php echo (int)$plan['target_count']; ?></div>
                    </div>
                    <div class="kpi">
                        <div class="label">Prepared Assignments</div>
                        <div class="value"><?php echo count($plan['assignments']); ?></div>
                    </div>
                    <div class="kpi">
                        <div class="label">Readiness</div>
                        <div class="value" style="font-size:18px;padding-top:6px;">
                            <?php if ($plan['ready']): ?>
                                <span class="pill ok">Safe To Execute</span>
                            <?php elseif ($plan['target_count'] > 0): ?>
                                <span class="pill warn">Blocked Until Fixed</span>
                            <?php else: ?>
                                <span class="pill bad">No Targets Right Now</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="two-col section-gap">
                    <div>
                        <p class="muted small">
                            <?php if ($plan['scope'] === 'rename_generic'): ?>
                                Changes only exact live <span class="mono">Customer</span> rows from the earlier email-based plan, including Gmail updates.
                            <?php elseif ($plan['scope'] === 'rename_specific_ids'): ?>
                                Changes the names for customer IDs <span class="mono">31, 287, 281, 293, 5, 6</span>, and also updates the emails for IDs <span class="mono">5</span> and <span class="mono">6</span> to their matching Gmail format.
                            <?php else: ?>
                                Fills only blank, NULL, empty, or <span class="mono">N/A</span> customer contact numbers with unique synthetic random-looking numbers in <span class="mono">09XXXXXXXXX</span> format.
                            <?php endif; ?>
                        </p>

                        <div class="plan-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer ID</th>
                                        <th>Current</th>
                                        <th>Planned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($plan['assignments'])): ?>
                                        <tr><td colspan="4" class="muted">No rows currently targeted.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($plan['assignments'] as $assignment): ?>
                                            <tr>
                                                <td><?php echo (int)$assignment['seq']; ?></td>
                                                <td><?php echo (int)$assignment['customer_id']; ?></td>
                                                <td>
                                                    <div><strong><?php echo pf_gcrt_h(trim($assignment['old']['first_name'] . ' ' . $assignment['old']['last_name'])); ?></strong></div>
                                                    <div class="mono"><?php echo pf_gcrt_h($assignment['old']['email']); ?></div>
                                                    <div class="mono"><?php echo pf_gcrt_h((string)($assignment['old']['contact_number'] ?? 'NULL')); ?></div>
                                                </td>
                                                <td>
                                                    <div><strong><?php echo pf_gcrt_h(trim($assignment['new']['first_name'] . ' ' . $assignment['new']['last_name'])); ?></strong></div>
                                                    <div class="mono"><?php echo pf_gcrt_h($assignment['new']['email']); ?></div>
                                                    <div class="mono"><?php echo pf_gcrt_h((string)($assignment['new']['contact_number'] ?? 'NULL')); ?></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <div class="card" style="margin-bottom:0;">
                            <h3>Execute</h3>
                            <p class="muted small">A full row backup is saved first to <span class="mono"><?php echo pf_gcrt_h(PF_GCRT_BACKUP_TABLE); ?></span>, then the update runs inside a transaction and verifies linked orders and user roles are unchanged.</p>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <?php if ($plan['scope'] === 'rename_generic'): ?>
                                    <input type="hidden" name="action" value="execute_generic">
                                    <label for="confirm_phrase_generic" class="small"><strong>Type exactly:</strong> <span class="mono"><?php echo pf_gcrt_h($plan['confirm_phrase']); ?></span></label>
                                    <div style="margin:8px 0 14px;">
                                        <input id="confirm_phrase_generic" name="confirm_phrase_generic" type="text" autocomplete="off" placeholder="<?php echo pf_gcrt_h($plan['confirm_phrase']); ?>">
                                    </div>
                                <?php elseif ($plan['scope'] === 'rename_specific_ids'): ?>
                                    <input type="hidden" name="action" value="execute_specific">
                                    <label for="confirm_phrase_specific" class="small"><strong>Type exactly:</strong> <span class="mono"><?php echo pf_gcrt_h($plan['confirm_phrase']); ?></span></label>
                                    <div style="margin:8px 0 14px;">
                                        <input id="confirm_phrase_specific" name="confirm_phrase_specific" type="text" autocomplete="off" placeholder="<?php echo pf_gcrt_h($plan['confirm_phrase']); ?>">
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="execute_contacts">
                                    <label for="confirm_phrase_contacts" class="small"><strong>Type exactly:</strong> <span class="mono"><?php echo pf_gcrt_h($plan['confirm_phrase']); ?></span></label>
                                    <div style="margin:8px 0 14px;">
                                        <input id="confirm_phrase_contacts" name="confirm_phrase_contacts" type="text" autocomplete="off" placeholder="<?php echo pf_gcrt_h($plan['confirm_phrase']); ?>">
                                    </div>
                                <?php endif; ?>
                                <button
                                    type="submit"
                                    class="btn primary"
                                    <?php echo $plan['ready'] ? '' : 'disabled'; ?>
                                    onclick="return confirm('Run <?php echo pf_gcrt_h($plan['label']); ?> now? A reversible backup batch will be created first.')"
                                >Execute</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <h2>Rollback</h2>
            <p class="muted small">Restore a previous batch from the backup table. This puts back the saved name, email, and contact number for each customer in that batch.</p>
            <form method="post" class="actions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="rollback">
                <input name="batch_id" type="text" autocomplete="off" value="<?php echo pf_gcrt_h($lastBatchId !== '' ? $lastBatchId : $rollbackBatchId); ?>" placeholder="batch_id">
                <input name="rollback_phrase" type="text" autocomplete="off" placeholder="ROLLBACK">
                <button type="submit" class="btn danger" onclick="return confirm('Rollback the selected batch?')">Rollback Batch</button>
            </form>
        </div>

        <div class="card">
            <h2>Recent Backup Batches</h2>
            <?php if (empty($recentBatches)): ?>
                <div class="muted">No backup batches found yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Scope</th>
                            <th>Rows</th>
                            <th>Captured At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBatches as $batch): ?>
                            <tr>
                                <td class="mono"><?php echo pf_gcrt_h((string)$batch['batch_id']); ?></td>
                                <td class="mono"><?php echo pf_gcrt_h((string)$batch['scope']); ?></td>
                                <td><?php echo (int)($batch['row_count'] ?? 0); ?></td>
                                <td><?php echo pf_gcrt_h((string)($batch['captured_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>After You Finish</h2>
            <ul class="list small">
                <li>Spot-check customers, linked orders, and a few contact numbers in admin.</li>
                <li>Delete this temporary page from production: <span class="mono">admin/generic_customer_rename_tool.php</span>.</li>
                <li>Remove exposed debug files from production such as <span class="mono">public/tmp_check_customers.php</span>, <span class="mono">public/tmp_check_users.php</span>, and <span class="mono">public/setup_admin.php</span>.</li>
            </ul>
        </div>
    </div>
</body>
</html>
