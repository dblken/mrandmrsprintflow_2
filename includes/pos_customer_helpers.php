<?php

/**
 * POS walk-in vs registered customer resolution and display helpers.
 */

function printflow_pos_normalize_customer_email(?string $raw): string
{
    return strtolower(trim((string)$raw));
}

function printflow_pos_is_placeholder_customer_email(string $email): bool
{
    $email = printflow_pos_normalize_customer_email($email);
    if ($email === '' || $email === 'walkin@pos.local') {
        return true;
    }
    if (str_ends_with($email, '@pos.local')) {
        return true;
    }

    return false;
}

/** SQL fragment excluding shared POS placeholder customer rows from admin lists. */
function printflow_pos_sql_exclude_placeholder_customers(string $tableAlias = ''): string
{
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';
    $col = $prefix . 'email';

    return " AND LOWER(TRIM(COALESCE({$col}, ''))) <> 'walkin@pos.local'"
        . " AND LOWER(TRIM(COALESCE({$col}, ''))) NOT LIKE 'pos.guest.%@pos.local'"
        . " AND LOWER(TRIM(COALESCE({$col}, ''))) NOT LIKE '%@pos.local'";
}

function printflow_pos_ensure_orders_guest_display_name_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $rows = db_query("SHOW COLUMNS FROM orders LIKE 'pos_guest_display_name'") ?: [];
    if (!empty($rows)) {
        return;
    }

    global $conn;
    if (!($conn instanceof mysqli)) {
        return;
    }

    @$conn->query(
        'ALTER TABLE orders ADD COLUMN pos_guest_display_name VARCHAR(120) NULL DEFAULT NULL AFTER customer_id'
    );
}

function printflow_pos_sanitize_guest_display_name(?string $raw): string
{
    $name = preg_replace('/\s+/u', ' ', trim((string)$raw)) ?? trim((string)$raw);
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    } elseif (strlen($name) > 120) {
        $name = substr($name, 0, 120);
    }

    return $name;
}

/** @return array{first:string,last:string} */
function printflow_pos_parse_display_name(string $raw): array
{
    $name = printflow_pos_sanitize_guest_display_name($raw);
    if ($name === '') {
        return ['first' => '', 'last' => ''];
    }
    $parts = preg_split('/\s+/u', $name, 2) ?: [];

    return [
        'first' => trim((string)($parts[0] ?? '')),
        'last' => trim((string)($parts[1] ?? '')),
    ];
}

function printflow_pos_derive_name_parts_from_email(string $email): array
{
    $local = trim((string)explode('@', $email, 2)[0]);
    $local = str_replace(['.', '_', '-'], ' ', $local);
    $local = preg_replace('/\s+/u', ' ', $local) ?? $local;
    $local = trim($local);
    if ($local === '') {
        return ['first' => 'Customer', 'last' => '-'];
    }

    $parsed = printflow_pos_parse_display_name($local);
    $first = $parsed['first'] !== '' ? $parsed['first'] : 'Customer';
    $last = $parsed['last'] !== '' ? $parsed['last'] : '-';

    return ['first' => $first, 'last' => $last];
}

/**
 * Find an existing customer by email or create a registered account (never a pos.guest placeholder).
 */
function printflow_pos_find_or_create_customer_by_email(string $email, string $displayName = ''): int
{
    global $conn;

    $email = printflow_pos_normalize_customer_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || printflow_pos_is_placeholder_customer_email($email)) {
        throw new RuntimeException('A valid customer email is required.', 400);
    }

    $existing = db_query(
        'SELECT customer_id, first_name, last_name FROM customers WHERE LOWER(TRIM(email)) = ? LIMIT 1',
        's',
        [$email]
    ) ?: [];
    if ($existing !== []) {
        return (int)$existing[0]['customer_id'];
    }

    $parsed = printflow_pos_parse_display_name($displayName);
    $first = $parsed['first'];
    $last = $parsed['last'];
    if ($first === '') {
        $derived = printflow_pos_derive_name_parts_from_email($email);
        $first = $derived['first'];
        $last = $derived['last'] !== '' ? $derived['last'] : '-';
    } elseif ($last === '') {
        $last = '-';
    }

    if (function_exists('printflow_ensure_customers_auth_provider_column')) {
        printflow_ensure_customers_auth_provider_column();
    }

    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $insertOk = printflow_run_guarded_account_insert(function () use ($first, $last, $email, $passwordHash) {
        return db_execute(
            "INSERT INTO customers (first_name, last_name, email, contact_number, password_hash, status, auth_provider, created_by_system, created_at)
             VALUES (?, ?, ?, '', ?, 'Activated', 'local', 1, NOW())",
            'ssss',
            [$first, $last, $email, $passwordHash]
        );
    });

    if (!$insertOk) {
        throw new RuntimeException('Could not create the customer account.', 500);
    }

    $customerId = is_numeric($insertOk) ? (int)$insertOk : (int)($conn->insert_id ?? 0);
    if ($customerId <= 0) {
        throw new RuntimeException('Could not create the customer account.', 500);
    }

    return $customerId;
}

/**
 * @return array{customer_id:int,pos_guest_display_name:?string}
 */
function printflow_pos_resolve_checkout_customer_context(array $data): array
{
    $rawCustomer = $data['customer_id'] ?? null;
    if ($rawCustomer !== null && $rawCustomer !== '' && $rawCustomer !== 'guest') {
        $customerId = (int)$rawCustomer;
        if ($customerId <= 0) {
            throw new RuntimeException('Please select a valid customer.', 400);
        }
        $rows = db_query(
            'SELECT customer_id, email FROM customers WHERE customer_id = ? LIMIT 1',
            'i',
            [$customerId]
        ) ?: [];
        if ($rows === []) {
            throw new RuntimeException('The selected customer could not be found.', 400);
        }
        $selectedEmail = printflow_pos_normalize_customer_email((string)($rows[0]['email'] ?? ''));
        if (printflow_pos_is_placeholder_customer_email($selectedEmail)) {
            throw new RuntimeException('Please select a valid customer.', 400);
        }

        return ['customer_id' => $customerId, 'pos_guest_display_name' => null];
    }

    $guestName = printflow_pos_sanitize_guest_display_name($data['guest_display_name'] ?? '');
    $checkoutEmail = printflow_pos_normalize_customer_email($data['customer_email'] ?? '');

    if ($checkoutEmail !== '') {
        if (!filter_var($checkoutEmail, FILTER_VALIDATE_EMAIL) || printflow_pos_is_placeholder_customer_email($checkoutEmail)) {
            $checkoutEmail = '';
        }
    }

    if ($checkoutEmail !== '') {
        $customerId = printflow_pos_find_or_create_customer_by_email($checkoutEmail, $guestName);

        return ['customer_id' => $customerId, 'pos_guest_display_name' => null];
    }

    if ($guestName === '') {
        throw new RuntimeException('Please enter the walk-in customer\'s name.', 400);
    }

    return [
        'customer_id' => pos_get_walkin_customer_id(),
        'pos_guest_display_name' => $guestName,
    ];
}

function printflow_pos_order_customer_display_name(array $order): string
{
    $guest = printflow_pos_sanitize_guest_display_name($order['pos_guest_display_name'] ?? '');
    if ($guest !== '') {
        return $guest;
    }

    $first = trim((string)($order['first_name'] ?? ''));
    $last = trim((string)($order['last_name'] ?? ''));
    if ($last === '-' || $last === '') {
        $full = $first;
    } else {
        $full = trim($first . ' ' . $last);
    }

    $email = printflow_pos_normalize_customer_email((string)($order['email'] ?? ''));
    if ($full === '' || ($full === 'Walk-in Guest' && printflow_pos_is_placeholder_customer_email($email))) {
        return 'Walk-in Customer (Guest)';
    }

    return $full;
}
