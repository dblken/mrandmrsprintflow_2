<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pos_receipt_format.php';

function printflow_receipt_printer_ensure_schema(): void {
    static $ready = false;
    if ($ready) return;

    db_execute(
        "CREATE TABLE IF NOT EXISTS receipt_printers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NULL,
            name VARCHAR(120) NOT NULL DEFAULT 'XP-58H Receipt Printer',
            printer_model VARCHAR(80) NOT NULL DEFAULT 'XP-58H',
            paper_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 58,
            printable_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 50,
            columns_count TINYINT UNSIGNED NOT NULL DEFAULT 32,
            printer_driver_name VARCHAR(190) NOT NULL DEFAULT '',
            printing_mode VARCHAR(40) NOT NULL DEFAULT 'escpos_text',
            copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
            auto_print TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            api_key_hash CHAR(64) DEFAULT NULL,
            api_key_prefix VARCHAR(20) DEFAULT NULL,
            api_key_last4 VARCHAR(8) DEFAULT NULL,
            api_key_created_at DATETIME DEFAULT NULL,
            pushy_device_token VARCHAR(255) DEFAULT NULL,
            pushy_registered_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_receipt_printers_branch (branch_id, status, auto_print),
            KEY idx_receipt_printers_key_hash (api_key_hash),
            KEY idx_receipt_printers_default (is_default, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns = db_query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipt_printers'
           AND COLUMN_NAME IN ('pushy_device_token', 'pushy_registered_at')"
    ) ?: [];
    $existingColumns = array_column($columns, 'COLUMN_NAME');
    if (!in_array('pushy_device_token', $existingColumns, true)) {
        db_execute("ALTER TABLE receipt_printers ADD COLUMN pushy_device_token VARCHAR(255) DEFAULT NULL AFTER api_key_created_at");
    }
    if (!in_array('pushy_registered_at', $existingColumns, true)) {
        db_execute("ALTER TABLE receipt_printers ADD COLUMN pushy_registered_at DATETIME DEFAULT NULL AFTER pushy_device_token");
    }

    db_execute(
        "CREATE TABLE IF NOT EXISTS receipt_print_jobs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            job_uuid CHAR(36) NOT NULL,
            idempotency_key VARCHAR(190) NOT NULL,
            printer_id INT NOT NULL,
            branch_id INT NULL,
            order_id INT NULL,
            job_type VARCHAR(40) NOT NULL DEFAULT 'pos_receipt',
            receipt_number VARCHAR(60) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            copies TINYINT UNSIGNED NOT NULL DEFAULT 1,
            paper_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 58,
            columns_count TINYINT UNSIGNED NOT NULL DEFAULT 32,
            receipt_payload LONGTEXT NOT NULL,
            receipt_text LONGTEXT NOT NULL,
            escpos_base64 LONGTEXT NOT NULL,
            error_message TEXT NULL,
            claimed_at DATETIME NULL,
            printed_at DATETIME NULL,
            failed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_receipt_print_jobs_uuid (job_uuid),
            UNIQUE KEY uq_receipt_print_jobs_idempotency (idempotency_key),
            KEY idx_receipt_print_jobs_printer_status (printer_id, status, created_at),
            KEY idx_receipt_print_jobs_order (order_id),
            KEY idx_receipt_print_jobs_branch (branch_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    db_execute(
        "CREATE TABLE IF NOT EXISTS receipt_print_job_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            job_id BIGINT NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_receipt_print_job_events_job (job_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
}

function printflow_receipt_printer_generate_api_key(int $printerId): string {
    return 'pfpp_live_' . bin2hex(random_bytes(24)) . '|' . $printerId;
}

function printflow_receipt_printer_hash_key(string $apiKey): string {
    return hash('sha256', trim($apiKey));
}

function printflow_receipt_printer_request_api_key(): string {
    $headers = [];
    if (function_exists('getallheaders')) {
        $rawHeaders = getallheaders();
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                $headers[strtolower((string)$name)] = trim((string)$value);
            }
        }
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? $headers['authorization']
        ?? ''
    ));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim($matches[1]);
    }

    $headerKey = trim((string)(
        $_SERVER['HTTP_X_API_KEY']
        ?? $_SERVER['HTTP_X_PRINTFLOW_API_KEY']
        ?? $headers['x-api-key']
        ?? $headers['x-printflow-api-key']
        ?? ''
    ));
    if ($headerKey !== '') return $headerKey;

    foreach (['api_key', 'apiKey', 'key', 'token'] as $param) {
        $value = trim((string)($_GET[$param] ?? $_POST[$param] ?? ''));
        if ($value !== '') return $value;
    }

    return '';
}

function printflow_receipt_printer_key_last4(string $apiKey): string {
    return substr((string)(explode('|', $apiKey, 2)[0] ?? ''), -4);
}

function printflow_receipt_printer_create_or_update(array $data, int $userId): array {
    printflow_receipt_printer_ensure_schema();

    $printerId = (int)($data['printer_id'] ?? 0);
    $branchId = (int)($data['branch_id'] ?? 0);
    $name = trim((string)($data['name'] ?? 'XP-58H Receipt Printer'));
    $driverName = trim((string)($data['printer_driver_name'] ?? 'XP-58H'));
    $columns = (int)($data['columns_count'] ?? 32);
    $copies = (int)($data['copies'] ?? 1);
    $autoPrint = !empty($data['auto_print']) ? 1 : 0;
    $isDefault = !empty($data['is_default']) ? 1 : 0;
    $status = in_array((string)($data['status'] ?? 'active'), ['active', 'inactive'], true) ? (string)$data['status'] : 'active';

    if ($name === '') $name = 'XP-58H Receipt Printer';
    if ($driverName === '') $driverName = 'XP-58H';
    $columns = max(32, min(42, $columns));
    $copies = max(1, min(5, $copies));
    $branchParam = $branchId > 0 ? $branchId : null;

    if ($isDefault) {
        db_execute('UPDATE receipt_printers SET is_default = 0 WHERE id != ?', 'i', [$printerId]);
    }

    if ($printerId > 0) {
        $ok = db_execute(
            "UPDATE receipt_printers
             SET branch_id = ?, name = ?, printer_model = 'XP-58H', paper_width_mm = 58,
                 printable_width_mm = 50, columns_count = ?, printer_driver_name = ?,
                 printing_mode = 'escpos_text', copies = ?, auto_print = ?, is_default = ?, status = ?
             WHERE id = ?",
            'isisiiisi',
            [$branchParam, $name, $columns, $driverName, $copies, $autoPrint, $isDefault, $status, $printerId]
        );
        return ['ok' => (bool)$ok, 'printer_id' => $printerId, 'api_key' => null];
    }

    $newId = db_execute(
        "INSERT INTO receipt_printers
            (branch_id, name, printer_model, paper_width_mm, printable_width_mm, columns_count,
             printer_driver_name, printing_mode, copies, auto_print, is_default, status,
             created_by)
         VALUES (?, ?, 'XP-58H', 58, 50, ?, ?, 'escpos_text', ?, ?, ?, ?, ?)",
        'isisiiisi',
        [$branchParam, $name, $columns, $driverName, $copies, $autoPrint, $isDefault, $status, $userId]
    );

    if (!$newId) return ['ok' => false, 'printer_id' => 0, 'api_key' => null];
    $apiKey = printflow_receipt_printer_generate_api_key((int)$newId);
    db_execute(
        "UPDATE receipt_printers
         SET api_key_hash = ?, api_key_prefix = ?, api_key_last4 = ?, api_key_created_at = NOW()
         WHERE id = ?",
        'sssi',
        [printflow_receipt_printer_hash_key($apiKey), substr($apiKey, 0, 12), printflow_receipt_printer_key_last4($apiKey), (int)$newId]
    );

    return ['ok' => (bool)$newId, 'printer_id' => (int)$newId, 'api_key' => $apiKey];
}

function printflow_receipt_printer_regenerate_key(int $printerId): ?string {
    printflow_receipt_printer_ensure_schema();
    if ($printerId <= 0) return null;
    $apiKey = printflow_receipt_printer_generate_api_key($printerId);
    $ok = db_execute(
        "UPDATE receipt_printers
         SET api_key_hash = ?, api_key_prefix = ?, api_key_last4 = ?, api_key_created_at = NOW()
         WHERE id = ?",
        'sssi',
        [printflow_receipt_printer_hash_key($apiKey), substr($apiKey, 0, 12), printflow_receipt_printer_key_last4($apiKey), $printerId]
    );
    return $ok ? $apiKey : null;
}

function printflow_receipt_printer_register_device(array $printer, string $deviceToken): bool {
    $deviceToken = trim($deviceToken);
    if ($deviceToken === '' || strlen($deviceToken) > 255) return false;
    return (bool)db_execute(
        'UPDATE receipt_printers SET pushy_device_token = ?, pushy_registered_at = NOW(), last_seen_at = NOW() WHERE id = ?',
        'si',
        [$deviceToken, (int)$printer['id']]
    );
}

function printflow_receipt_printer_list(): array {
    printflow_receipt_printer_ensure_schema();
    return db_query(
        "SELECT rp.*, b.branch_name
         FROM receipt_printers rp
         LEFT JOIN branches b ON b.id = rp.branch_id
         ORDER BY rp.is_default DESC, b.branch_name ASC, rp.id ASC"
    ) ?: [];
}

function printflow_receipt_printer_find_for_branch(?int $branchId): array {
    printflow_receipt_printer_ensure_schema();
    if ($branchId !== null && $branchId > 0) {
        $rows = db_query(
            "SELECT * FROM receipt_printers
             WHERE branch_id = ? AND status = 'active' AND auto_print = 1
             ORDER BY is_default DESC, id ASC LIMIT 1",
            'i',
            [$branchId]
        ) ?: [];
        if (!empty($rows)) return $rows[0];
    }
    $rows = db_query(
        "SELECT * FROM receipt_printers
         WHERE status = 'active' AND auto_print = 1
         ORDER BY is_default DESC, branch_id IS NULL DESC, id ASC LIMIT 1"
    ) ?: [];
    return $rows[0] ?? [];
}

function printflow_receipt_printer_authenticate(string $apiKey): array {
    printflow_receipt_printer_ensure_schema();
    $hash = printflow_receipt_printer_hash_key($apiKey);
    $rows = db_query(
        "SELECT * FROM receipt_printers WHERE api_key_hash = ? AND status = 'active' LIMIT 1",
        's',
        [$hash]
    ) ?: [];
    if (empty($rows)) return [];
    db_execute('UPDATE receipt_printers SET last_seen_at = NOW() WHERE id = ?', 'i', [(int)$rows[0]['id']]);
    return $rows[0];
}

function printflow_receipt_text_clean(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace(
        ["\xE2\x82\xB1", "\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\xA2", "\xC3\x97"],
        ['PHP ', '-', '-', '-', 'x'],
        $text
    );
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
    }
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
    return trim($text);
}

function printflow_receipt_money($value): string {
    return 'PHP ' . number_format((float)$value, 2);
}

function printflow_receipt_wrap(string $text, int $width): array {
    $text = printflow_receipt_text_clean($text);
    if ($text === '') return [''];
    return explode("\n", wordwrap($text, max(8, $width), "\n", true));
}

function printflow_receipt_center(string $text, int $width): string {
    $text = printflow_receipt_text_clean($text);
    $len = strlen($text);
    if ($len >= $width) return substr($text, 0, $width);
    $pad = intdiv($width - $len, 2);
    return str_repeat(' ', $pad) . $text;
}

function printflow_receipt_pair(string $left, string $right, int $width): string {
    $left = printflow_receipt_text_clean($left);
    $right = printflow_receipt_text_clean($right);
    $rightWidth = min(strlen($right), max(8, intdiv($width, 2)));
    $leftWidth = max(1, $width - $rightWidth - 1);
    $left = substr($left, 0, $leftWidth);
    $right = substr($right, 0, $rightWidth);
    return str_pad($left, $width - strlen($right), ' ') . $right;
}

function printflow_receipt_flatten_customization(array $customization): array {
    $specs = printflow_customization_display_specs($customization, [
        'include_service' => false,
        'include_design' => true,
        'include_notes' => true,
        'include_quantity' => false,
    ]);
    $lines = [];
    foreach ($specs as $label => $text) {
        $lines[] = $label . ': ' . $text;
        if (count($lines) >= 8) break;
    }
    return $lines;
}

function printflow_receipt_format_text(array $receipt, int $columns = 32): string {
    $columns = max(32, min(42, $columns));
    $line = str_repeat('-', $columns);
    $eq = str_repeat('=', $columns);
    $out = [];
    $company = $receipt['company'] ?? [];
    $payment = $receipt['payment'] ?? [];
    $discount = $receipt['discount'] ?? [];

    $out[] = printflow_receipt_center('PrintFlow', $columns);
    if (!empty($company['branch_name'])) $out[] = printflow_receipt_center((string)$company['branch_name'], $columns);
    foreach (printflow_receipt_wrap((string)($company['address'] ?? ''), $columns) as $addr) {
        if (trim($addr) !== '') $out[] = printflow_receipt_center($addr, $columns);
    }
    if (!empty($company['contact'])) $out[] = printflow_receipt_center((string)$company['contact'], $columns);
    $out[] = printflow_receipt_center('OFFICIAL POS RECEIPT', $columns);
    if (!empty($receipt['reprint'])) $out[] = printflow_receipt_center('REPRINT', $columns);
    $out[] = $eq;
    $out[] = printflow_receipt_center('RECEIPT INFO', $columns);
    $out[] = printflow_receipt_pair('Receipt No.', (string)($receipt['receipt_number'] ?? ''), $columns);
    foreach (printflow_receipt_labeled_value_lines('Date/Time', printflow_receipt_format_datetime($receipt['date_time'] ?? ''), $columns) as $dateLine) {
        $out[] = $dateLine;
    }
    if (!empty($receipt['cashier'])) $out[] = printflow_receipt_pair('Cashier', (string)$receipt['cashier'], $columns);
    $out[] = $line;
    $out[] = printflow_receipt_center('CUSTOMER', $columns);
    foreach (printflow_receipt_wrap((string)($receipt['customer']['name'] ?? 'Walk-in Guest'), $columns) as $row) $out[] = $row;
    if (!empty($receipt['customer']['phone'])) $out[] = (string)$receipt['customer']['phone'];
    $out[] = $line;
    $out[] = printflow_receipt_center('ITEMS', $columns);
    foreach (($receipt['items'] ?? []) as $item) {
        foreach (printflow_receipt_wrap((string)($item['name'] ?? 'Item'), $columns) as $row) $out[] = $row;
        $out[] = printflow_receipt_pair(
            '  ' . (int)($item['quantity'] ?? 0) . ' x ' . printflow_receipt_money($item['unit_price'] ?? 0),
            printflow_receipt_money($item['line_total'] ?? 0),
            $columns
        );
        $customLines = printflow_receipt_flatten_customization(is_array($item['customization'] ?? null) ? $item['customization'] : []);
        foreach ($customLines as $customLine) {
            foreach (printflow_receipt_wrap('  ' . $customLine, $columns) as $row) $out[] = $row;
        }
    }
    $out[] = $line;
    $out[] = printflow_receipt_pair('Subtotal', printflow_receipt_money($receipt['subtotal'] ?? 0), $columns);
    $out[] = printflow_receipt_pair('Discount', printflow_receipt_money($discount['amount'] ?? 0), $columns);
    $out[] = $line;
    $out[] = printflow_receipt_pair('TOTAL', printflow_receipt_money($receipt['total'] ?? 0), $columns);
    $out[] = $line;
    $out[] = printflow_receipt_pair('Payment', (string)($payment['method'] ?? 'Cash'), $columns);
    if (!empty($payment['reference'])) {
        foreach (printflow_receipt_wrap('Ref: ' . (string)$payment['reference'], $columns) as $row) $out[] = $row;
    }
    $out[] = printflow_receipt_pair('Amount Paid', printflow_receipt_money($payment['amount_paid'] ?? 0), $columns);
    $out[] = printflow_receipt_pair('Change', printflow_receipt_money($payment['change'] ?? 0), $columns);
    if ((float)($payment['balance'] ?? 0) > 0) {
        $out[] = printflow_receipt_pair('Balance', printflow_receipt_money($payment['balance']), $columns);
    }
    $out[] = $eq;
    $out[] = printflow_receipt_center('Thank you!', $columns);
    $out[] = printflow_receipt_center('Please keep this receipt.', $columns);
    $out[] = '';
    $out[] = '';

    return implode("\n", $out) . "\n";
}

function printflow_receipt_job_uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function printflow_receipt_pushy_secret(): string {
    $secret = printflow_env('PUSHY_API_SECRET');
    return $secret === false ? '' : trim((string)$secret);
}

function printflow_receipt_pushprinter_notify(array $printer, array $job, ?string &$failureReason = null): bool {
    $failureReason = null;
    $secret = printflow_receipt_pushy_secret();
    $deviceToken = trim((string)($printer['pushy_device_token'] ?? ''));
    $jobUuid = trim((string)($job['job_uuid'] ?? ''));
    $orderNumber = trim((string)($job['receipt_number'] ?? ''));
    if ($secret === '') {
        $failureReason = 'PUSHY_API_SECRET is not configured.';
        error_log('[receipt-pushy] Notification skipped: ' . $failureReason);
        return false;
    }
    if ($deviceToken === '') {
        $failureReason = 'No registered PushPrinter device token for printer #' . (int)($printer['id'] ?? 0) . '.';
        error_log('[receipt-pushy] Notification skipped: ' . $failureReason);
        return false;
    }
    if (!function_exists('curl_init')) {
        $failureReason = 'PHP cURL is unavailable.';
        error_log('[receipt-pushy] Notification skipped: ' . $failureReason);
        return false;
    }
    if ($jobUuid === '' || $orderNumber === '') {
        $failureReason = 'The print job UUID or order_number is missing.';
        error_log('[receipt-pushy] Notification skipped: ' . $failureReason);
        return false;
    }

    $payload = json_encode([
        'to' => $deviceToken,
        'data' => [
            'printer_id' => (string)$printer['id'],
            'printer_type' => 'escpos',
            'job_id' => $jobUuid,
            'order_id' => $jobUuid,
            // PushPrinter v3.1.0 rejects the entire notification when this is absent.
            'order_number' => $orderNumber,
        ],
        'notification' => [
            'title' => 'PrintFlow receipt',
            'body' => 'A receipt is ready to print.',
        ],
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return false;

    $curl = curl_init('https://api.pushy.me/push?api_key=' . rawurlencode($secret));
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        $providerMessage = '';
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $providerMessage = trim((string)($decoded['error'] ?? $decoded['message'] ?? ''));
            }
        }
        $failureReason = 'Pushy notification failed with HTTP ' . $httpCode
            . ($error !== '' ? ': ' . $error : ($providerMessage !== '' ? ': ' . $providerMessage : '.'));
        error_log('[receipt-pushy] ' . $failureReason);
        return false;
    }
    return true;
}

function printflow_receipt_order_branch_id(int $orderId): ?int {
    $rows = db_query('SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]) ?: [];
    if (empty($rows)) return null;
    $branchId = (int)($rows[0]['branch_id'] ?? 0);
    return $branchId > 0 ? $branchId : null;
}

function printflow_receipt_enqueue_order_print(
    int $orderId,
    array $receipt,
    ?int $branchId = null,
    string $jobType = 'pos_receipt',
    string $deliveryKey = ''
): array {
    printflow_receipt_printer_ensure_schema();
    if ($orderId <= 0 || empty($receipt)) {
        return ['ok' => false, 'code' => 'missing_receipt', 'message' => 'Receipt data is unavailable.'];
    }
    if (empty($receipt['qr_payload'])) {
        $receipt['qr_payload'] = printflow_receipt_qr_payload($orderId);
    }
    if ($branchId === null) $branchId = printflow_receipt_order_branch_id($orderId);
    $printer = printflow_receipt_printer_find_for_branch($branchId);
    if (empty($printer)) {
        return ['ok' => false, 'code' => 'no_printer', 'message' => 'No active receipt printer is configured for this branch.'];
    }

    $printerId = (int)$printer['id'];
    $idempotencyKey = $jobType . ':order:' . $orderId . ':printer:' . $printerId;
    if ($deliveryKey !== '') {
        $idempotencyKey .= ':delivery:' . substr(hash('sha256', $deliveryKey), 0, 24);
    }
    $existing = db_query(
        'SELECT id, job_uuid, status FROM receipt_print_jobs WHERE idempotency_key = ? LIMIT 1',
        's',
        [$idempotencyKey]
    ) ?: [];
    if (!empty($existing)) {
        return [
            'ok' => true,
            'queued' => false,
            'duplicate' => true,
            'job_id' => (int)$existing[0]['id'],
            'job_uuid' => (string)$existing[0]['job_uuid'],
            'status' => (string)$existing[0]['status'],
        ];
    }

    $columns = (int)($printer['columns_count'] ?? 32);
    $text = printflow_receipt_format_text($receipt, $columns);
    $uuid = printflow_receipt_job_uuid();
    $payloadJson = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jobId = db_execute(
        "INSERT INTO receipt_print_jobs
            (job_uuid, idempotency_key, printer_id, branch_id, order_id, job_type, receipt_number,
             status, copies, paper_width_mm, columns_count, receipt_payload, receipt_text, escpos_base64)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 58, ?, ?, ?, ?)",
        'ssiiissiisss',
        [
            $uuid,
            $idempotencyKey,
            $printerId,
            $branchId,
            $orderId,
            $jobType,
            (string)($receipt['receipt_number'] ?? ''),
            (int)($printer['copies'] ?? 1),
            $columns,
            (string)$payloadJson,
            $text,
            printflow_receipt_escpos_base64($text, (string)($receipt['qr_payload'] ?? ''))
        ]
    );

    if (!$jobId) {
        return ['ok' => false, 'code' => 'enqueue_failed', 'message' => 'Receipt print job could not be queued.'];
    }
    db_execute(
        'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
        'iss',
        [(int)$jobId, 'pending', 'Receipt print job queued.']
    );
    $job = [
        'job_uuid' => $uuid,
        'receipt_number' => (string)($receipt['receipt_number'] ?? ''),
    ];
    $notificationError = null;
    $notified = printflow_receipt_pushprinter_notify($printer, $job, $notificationError);
    if (!$notified) {
        db_execute(
            'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
            'iss',
            [(int)$jobId, 'pending', 'Push notification unavailable: ' . ($notificationError ?: 'unknown provider error') . ' The job remains available to the polling fallback.']
        );
    } else {
        db_execute(
            'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
            'iss',
            [(int)$jobId, 'notified', 'Pushy accepted the PushPrinter notification.']
        );
    }
    return ['ok' => true, 'queued' => true, 'job_id' => (int)$jobId, 'job_uuid' => $uuid, 'status' => 'pending'];
}

function printflow_receipt_enqueue_order_print_safe(
    int $orderId,
    array $receipt,
    ?int $branchId = null,
    string $jobType = 'pos_receipt',
    string $deliveryKey = ''
): array {
    try {
        return printflow_receipt_enqueue_order_print($orderId, $receipt, $branchId, $jobType, $deliveryKey);
    } catch (Throwable $e) {
        error_log('[receipt-print-queue] Order #' . $orderId . ': ' . $e->getMessage());
        return [
            'ok' => false,
            'code' => 'enqueue_error',
            'message' => 'The transaction completed, but the receipt could not be queued for printing.',
        ];
    }
}

function printflow_receipt_enqueue_test_print(int $printerId, int $userId): array {
    printflow_receipt_printer_ensure_schema();
    $rows = db_query('SELECT * FROM receipt_printers WHERE id = ? LIMIT 1', 'i', [$printerId]) ?: [];
    if (empty($rows)) return ['ok' => false, 'message' => 'Printer not found.'];
    $printer = $rows[0];
    $receipt = [
        'receipt_number' => 'TEST-' . date('His'),
        'order_id' => 0,
        'date_time' => date('Y-m-d H:i:s'),
        'company' => [
            'name' => 'PrintFlow',
            'branch_name' => (string)($printer['name'] ?? 'XP-58H'),
            'address' => '58mm thermal printer test',
            'contact' => '',
        ],
        'customer' => ['name' => 'Printer Test', 'email' => '', 'phone' => ''],
        'items' => [[
            'name' => 'XP-58H 58mm alignment test',
            'quantity' => 1,
            'unit_price' => 1,
            'line_total' => 1,
            'customization' => ['columns' => (int)($printer['columns_count'] ?? 32)],
        ]],
        'subtotal' => 1,
        'discount' => ['amount' => 0],
        'total' => 1,
        'payment' => ['method' => 'Test', 'amount_paid' => 1, 'change' => 0, 'balance' => 0],
    ];
    $columns = (int)($printer['columns_count'] ?? 32);
    $text = printflow_receipt_format_text($receipt, $columns);
    $uuid = printflow_receipt_job_uuid();
    $jobId = db_execute(
        "INSERT INTO receipt_print_jobs
            (job_uuid, idempotency_key, printer_id, branch_id, order_id, job_type, receipt_number,
             status, copies, paper_width_mm, columns_count, receipt_payload, receipt_text, escpos_base64)
         VALUES (?, ?, ?, ?, NULL, 'test_print', ?, 'pending', ?, 58, ?, ?, ?, ?)",
        'ssiisiisss',
        [
            $uuid,
            'test:' . $printerId . ':' . $userId . ':' . bin2hex(random_bytes(8)),
            $printerId,
            (int)($printer['branch_id'] ?? 0) ?: null,
            $receipt['receipt_number'],
            (int)($printer['copies'] ?? 1),
            $columns,
            json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $text,
            printflow_receipt_escpos_base64($text)
        ]
    );
    if (!$jobId) return ['ok' => false, 'message' => 'Test print could not be queued.'];
    db_execute(
        'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
        'iss',
        [(int)$jobId, 'pending', '58mm test receipt queued.']
    );
    printflow_receipt_pushprinter_notify($printer, [
        'job_uuid' => $uuid,
        'receipt_number' => (string)$receipt['receipt_number'],
    ]);
    return ['ok' => true, 'job_id' => (int)$jobId, 'job_uuid' => $uuid];
}

function printflow_receipt_claim_next_job(array $printer): array {
    printflow_receipt_printer_ensure_schema();
    $printerId = (int)$printer['id'];
    db_execute(
        "UPDATE receipt_print_jobs
         SET status = 'pending', claimed_at = NULL,
             error_message = 'Printer agent did not acknowledge the previous claim.'
         WHERE printer_id = ? AND status = 'claimed' AND claimed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
           AND attempts < max_attempts",
        'i',
        [$printerId]
    );
    db_execute(
        "UPDATE receipt_print_jobs
         SET status = 'failed', failed_at = NOW(),
             error_message = 'Maximum print attempts reached without acknowledgement.'
         WHERE printer_id = ? AND status = 'claimed' AND claimed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
           AND attempts >= max_attempts",
        'i',
        [$printerId]
    );
    $rows = db_query(
        "SELECT * FROM receipt_print_jobs
         WHERE printer_id = ? AND status = 'pending' AND attempts < max_attempts
         ORDER BY created_at ASC, id ASC LIMIT 1",
        'i',
        [$printerId]
    ) ?: [];
    if (empty($rows)) return [];
    $job = $rows[0];
    $affected = db_execute_affected_rows(
        "UPDATE receipt_print_jobs
         SET status = 'claimed', attempts = attempts + 1, claimed_at = NOW(), error_message = NULL
         WHERE id = ? AND status = 'pending'",
        'i',
        [(int)$job['id']]
    );
    if ($affected !== 1) return [];
    $job['attempts'] = (int)$job['attempts'] + 1;
    $job['status'] = 'claimed';
    db_execute(
        'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
        'iss',
        [(int)$job['id'], 'claimed', 'Print job claimed by printer agent.']
    );
    return $job;
}

function printflow_receipt_public_job_payload(array $job, array $printer): array {
    return [
        'id' => (int)$job['id'],
        'job_id' => (int)$job['id'],
        'job_uuid' => (string)$job['job_uuid'],
        'type' => (string)$job['job_type'],
        'format' => 'escpos_text',
        'printer_name' => (string)($printer['name'] ?? ''),
        'printer_driver_name' => (string)($printer['printer_driver_name'] ?? ''),
        'paper_width_mm' => 58,
        'printable_width_mm' => 50,
        'columns' => (int)$job['columns_count'],
        'copies' => (int)$job['copies'],
        'content' => (string)$job['receipt_text'],
        'receipt_text' => (string)$job['receipt_text'],
        'escpos_base64' => (string)$job['escpos_base64'],
        'receipt' => json_decode((string)$job['receipt_payload'], true) ?: null,
    ];
}

function printflow_receipt_ack_job(array $printer, int $jobId, string $status, string $message = ''): bool {
    printflow_receipt_printer_ensure_schema();
    if ($jobId <= 0) return false;
    $status = strtolower(trim($status));
    if (!in_array($status, ['printed', 'failed'], true)) return false;
    $rows = db_query(
        'SELECT status, attempts, max_attempts FROM receipt_print_jobs WHERE id = ? AND printer_id = ? LIMIT 1',
        'ii',
        [$jobId, (int)$printer['id']]
    ) ?: [];
    if (empty($rows)) return false;
    if ((string)$rows[0]['status'] === 'printed' && $status === 'printed') return true;

    if ($status === 'printed') {
        $ok = db_execute(
            "UPDATE receipt_print_jobs
             SET status = 'printed', printed_at = NOW(), failed_at = NULL, error_message = NULL
             WHERE id = ? AND printer_id = ?",
            'ii',
            [$jobId, (int)$printer['id']]
        );
        $eventStatus = 'printed';
    } else {
        $willRetry = (int)$rows[0]['attempts'] < (int)$rows[0]['max_attempts'];
        $nextStatus = $willRetry ? 'pending' : 'failed';
        $ok = db_execute(
            "UPDATE receipt_print_jobs
             SET status = ?, claimed_at = NULL,
                 failed_at = CASE WHEN ? = 'failed' THEN NOW() ELSE NULL END,
                 error_message = ?
             WHERE id = ? AND printer_id = ?",
            'sssii',
            [$nextStatus, $nextStatus, substr($message, 0, 1000), $jobId, (int)$printer['id']]
        );
        $eventStatus = $nextStatus;
        if ($willRetry && $message === '') $message = 'Printer agent reported a failure; job queued for retry.';
    }
    if ($ok) {
        db_execute(
            'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
            'iss',
            [$jobId, $eventStatus, $message]
        );
    }
    return (bool)$ok;
}

function printflow_receipt_retry_job(int $jobId): bool {
    printflow_receipt_printer_ensure_schema();
    $newUuid = printflow_receipt_job_uuid();
    $affected = db_execute_affected_rows(
        "UPDATE receipt_print_jobs
         SET job_uuid = ?, status = 'pending', claimed_at = NULL, failed_at = NULL,
             error_message = NULL, attempts = 0
         WHERE id = ? AND (
             status IN ('pending', 'failed')
             OR (status = 'claimed' AND claimed_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
         )",
        'si',
        [$newUuid, $jobId]
    );
    if ($affected === 1) {
        db_execute(
            'INSERT INTO receipt_print_job_events (job_id, status, message) VALUES (?, ?, ?)',
            'iss',
            [$jobId, 'pending', 'Receipt print job manually retried.']
        );
        $rows = db_query(
            'SELECT j.job_uuid, j.receipt_number, p.* FROM receipt_print_jobs j INNER JOIN receipt_printers p ON p.id = j.printer_id WHERE j.id = ? LIMIT 1',
            'i',
            [$jobId]
        ) ?: [];
        if (!empty($rows)) printflow_receipt_pushprinter_notify($rows[0], $rows[0]);
    }
    return $affected === 1;
}
