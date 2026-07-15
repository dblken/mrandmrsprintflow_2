<?php
/**
 * OCR-assisted payment verification.
 *
 * OCR output is advisory only. This module never changes an order's paid state;
 * the existing staff approval workflows remain authoritative.
 */

if (!function_exists('payment_verification_env')) {
    function payment_verification_env(string $name, string $default = ''): string {
        if (function_exists('printflow_env')) {
            $value = printflow_env($name);
            return $value === false ? $default : trim((string)$value);
        }
        $value = getenv($name);
        return $value === false ? $default : trim((string)$value);
    }
}

if (!function_exists('payment_verification_log')) {
    function payment_verification_log(string $event, array $context = []): void {
        $safe = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[(string)$key] = $value;
            }
        }
        error_log('[payment_verification] ' . $event . ' ' . json_encode($safe, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('payment_verification_ensure_schema')) {
    function payment_verification_ensure_schema(): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $sql = "CREATE TABLE IF NOT EXISTS payment_submissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT DEFAULT NULL,
            job_order_id BIGINT DEFAULT NULL,
            customer_id INT NOT NULL,
            branch_id INT DEFAULT NULL,
            receipt_file VARCHAR(500) NOT NULL,
            receipt_storage_path VARCHAR(500) DEFAULT NULL,
            receipt_url VARCHAR(700) DEFAULT NULL,
            receipt_thumbnail VARCHAR(500) DEFAULT NULL,
            receipt_original_name VARCHAR(255) DEFAULT NULL,
            receipt_mime VARCHAR(100) DEFAULT NULL,
            receipt_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            receipt_sha256 CHAR(64) DEFAULT NULL,
            selected_payment_method VARCHAR(80) DEFAULT NULL,
            submission_token CHAR(64) DEFAULT NULL,
            expected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            submitted_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ocr_sender_name VARCHAR(190) DEFAULT NULL,
            sender_name VARCHAR(190) DEFAULT NULL,
            ocr_reference_number VARCHAR(190) DEFAULT NULL,
            reference_number VARCHAR(190) DEFAULT NULL,
            reference_normalized VARCHAR(190) DEFAULT NULL,
            ocr_amount_sent DECIMAL(12,2) DEFAULT NULL,
            amount_sent DECIMAL(12,2) DEFAULT NULL,
            ocr_detected_payment_method VARCHAR(80) DEFAULT NULL,
            detected_payment_method VARCHAR(80) DEFAULT NULL,
            ocr_transaction_date DATE DEFAULT NULL,
            transaction_date DATE DEFAULT NULL,
            ocr_transaction_time TIME DEFAULT NULL,
            transaction_time TIME DEFAULT NULL,
            ocr_receiver_name VARCHAR(190) DEFAULT NULL,
            receiver_name VARCHAR(190) DEFAULT NULL,
            ocr_receiver_account VARCHAR(190) DEFAULT NULL,
            receiver_account VARCHAR(190) DEFAULT NULL,
            raw_ocr_text MEDIUMTEXT,
            overall_confidence DECIMAL(5,2) DEFAULT NULL,
            sender_confidence DECIMAL(5,2) DEFAULT NULL,
            reference_confidence DECIMAL(5,2) DEFAULT NULL,
            amount_confidence DECIMAL(5,2) DEFAULT NULL,
            method_confidence DECIMAL(5,2) DEFAULT NULL,
            date_confidence DECIMAL(5,2) DEFAULT NULL,
            receiver_confidence DECIMAL(5,2) DEFAULT NULL,
            ocr_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            ocr_provider VARCHAR(50) DEFAULT NULL,
            ocr_error VARCHAR(500) DEFAULT NULL,
            ocr_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ocr_duration_ms INT UNSIGNED DEFAULT NULL,
            ocr_processed_at DATETIME DEFAULT NULL,
            amount_match_status VARCHAR(20) NOT NULL DEFAULT 'Unknown',
            method_match_status VARCHAR(20) NOT NULL DEFAULT 'Unknown',
            duplicate_submission_id BIGINT UNSIGNED DEFAULT NULL,
            verification_status VARCHAR(40) NOT NULL DEFAULT 'Pending Review',
            staff_notes TEXT,
            rejection_reason TEXT,
            corrected_by INT DEFAULT NULL,
            corrected_at DATETIME DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payment_submissions_order (order_id, created_at),
            KEY idx_payment_submissions_job (job_order_id, created_at),
            KEY idx_payment_submissions_customer (customer_id, created_at),
            KEY idx_payment_submissions_branch (branch_id, created_at),
            KEY idx_payment_submissions_queue (ocr_status, created_at),
            KEY idx_payment_submissions_review (verification_status, created_at),
            KEY idx_payment_submissions_reference (reference_normalized),
            KEY idx_payment_submissions_hash (receipt_sha256),
            UNIQUE KEY uq_payment_submissions_token (customer_id, submission_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ready = (bool)db_execute($sql);
        if ($ready) {
            $ready = !empty(db_query("SHOW TABLES LIKE 'payment_submissions'"));
        }
        if (!$ready) {
            return false;
        }

        // CREATE TABLE IF NOT EXISTS does not upgrade an existing installation.
        // Keep this additive and idempotent so deployments cannot lose old decisions.
        $columns = [
            'branch_id' => 'INT DEFAULT NULL AFTER customer_id',
            'receipt_storage_path' => 'VARCHAR(500) DEFAULT NULL AFTER receipt_file',
            'receipt_url' => 'VARCHAR(700) DEFAULT NULL AFTER receipt_storage_path',
            'submission_token' => 'CHAR(64) DEFAULT NULL AFTER selected_payment_method',
            'ocr_duration_ms' => 'INT UNSIGNED DEFAULT NULL AFTER ocr_attempts',
            'ocr_normalized_text' => 'MEDIUMTEXT DEFAULT NULL AFTER raw_ocr_text',
        ];
        foreach ($columns as $column => $definition) {
            if (!db_table_has_column('payment_submissions', $column)) {
                $altered = db_execute("ALTER TABLE payment_submissions ADD COLUMN `{$column}` {$definition}");
                db_table_has_column('payment_submissions', $column, true);
                if (!$altered || !db_table_has_column('payment_submissions', $column)) {
                    payment_verification_log('schema_upgrade_failed', ['column' => $column]);
                    $ready = false;
                    return false;
                }
            }
        }

        $indexes = db_query("SHOW INDEX FROM payment_submissions") ?: [];
        $indexNames = array_map(static fn(array $row): string => (string)($row['Key_name'] ?? ''), $indexes);
        if (!in_array('idx_payment_submissions_branch', $indexNames, true)) {
            if (!db_execute('ALTER TABLE payment_submissions ADD KEY idx_payment_submissions_branch (branch_id, created_at)')) {
                payment_verification_log('schema_upgrade_failed', ['index' => 'idx_payment_submissions_branch']);
                $ready = false;
                return false;
            }
        }
        if (!in_array('uq_payment_submissions_token', $indexNames, true)) {
            // NULL tokens remain allowed for all legacy rows.
            if (!db_execute('ALTER TABLE payment_submissions ADD UNIQUE KEY uq_payment_submissions_token (customer_id, submission_token)')) {
                payment_verification_log('schema_upgrade_failed', ['index' => 'uq_payment_submissions_token']);
                $ready = false;
                return false;
            }
        }
        return $ready;
    }
}

if (!function_exists('payment_verification_normalize_method')) {
    function payment_verification_normalize_method(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        $lower = strtolower($value);
        if (strpos($lower, 'gcash') !== false) return 'GCash';
        if (strpos($lower, 'maya') !== false || strpos($lower, 'paymaya') !== false) return 'Maya';
        if (strpos($lower, 'instapay') !== false) return 'Bank Transfer / InstaPay';
        if (strpos($lower, 'pesonet') !== false) return 'Bank Transfer / PESONet';

        $banks = [
            'bdo', 'bpi', 'metrobank', 'unionbank', 'union bank', 'security bank',
            'rcbc', 'landbank', 'land bank', 'chinabank', 'china bank', 'pnb',
            'eastwest', 'east west', 'cimb', 'seabank', 'sea bank', 'gotyme',
            'bank transfer', 'banking'
        ];
        foreach ($banks as $bank) {
            if (strpos($lower, $bank) !== false) return 'Bank Transfer';
        }
        return mb_substr($value, 0, 80);
    }
}

if (!function_exists('payment_verification_methods_match')) {
    function payment_verification_methods_match(?string $selected, ?string $detected): ?bool {
        $selected = payment_verification_normalize_method($selected);
        $detected = payment_verification_normalize_method($detected);
        if ($selected === '' || $detected === '') return null;
        if (strcasecmp($selected, $detected) === 0) return true;
        if ($selected === 'Bank Transfer' && str_starts_with($detected, 'Bank Transfer')) return true;
        if ($detected === 'Bank Transfer' && str_starts_with($selected, 'Bank Transfer')) return true;
        return false;
    }
}

if (!function_exists('payment_verification_normalize_reference')) {
    function payment_verification_normalize_reference(?string $value): string {
        $normalized = strtoupper((string)preg_replace('/[^A-Z0-9]+/i', '', trim((string)$value)));
        return strlen($normalized) >= 6 ? mb_substr($normalized, 0, 190) : '';
    }
}

if (!function_exists('payment_verification_mask_account')) {
    function payment_verification_mask_account(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        $plain = (string)preg_replace('/\s+/', '', $value);
        $length = mb_strlen($plain);
        if ($length <= 4) return str_repeat('*', $length);
        $visible = mb_substr($plain, -4);
        return str_repeat('*', min(8, $length - 4)) . $visible;
    }
}

if (!function_exists('payment_verification_expected_amount')) {
    function payment_verification_expected_amount(int $orderId = 0, int $jobOrderId = 0): float {
        if ($orderId <= 0 && $jobOrderId > 0) {
            $jobLink = db_query('SELECT order_id FROM job_orders WHERE id = ? LIMIT 1', 'i', [$jobOrderId]);
            $orderId = (int)($jobLink[0]['order_id'] ?? 0);
        }

        if ($orderId > 0 && function_exists('printflow_notification_latest_payable_amount')) {
            $amount = (float)printflow_notification_latest_payable_amount($orderId, $jobOrderId);
            if ($amount > 0) return round($amount, 2);
        }

        if ($orderId > 0) {
            $priority = ['final_price', 'approved_price', 'order_total', 'total_amount'];
            $available = [];
            foreach ($priority as $column) {
                if (function_exists('db_table_has_column') && db_table_has_column('orders', $column)) {
                    $available[] = "NULLIF(`{$column}`, 0)";
                }
            }
            if (!empty($available)) {
                $rows = db_query(
                    'SELECT COALESCE(' . implode(', ', $available) . ', 0) AS payable_amount FROM orders WHERE order_id = ? LIMIT 1',
                    'i',
                    [$orderId]
                );
                $amount = (float)($rows[0]['payable_amount'] ?? 0);
                if ($amount > 0) return round($amount, 2);
            }
        }

        if ($jobOrderId > 0) {
            $rows = db_query(
                'SELECT COALESCE(NULLIF(required_payment, 0), NULLIF(estimated_total, 0), 0) AS payable_amount FROM job_orders WHERE id = ? LIMIT 1',
                'i',
                [$jobOrderId]
            );
            return round(max(0, (float)($rows[0]['payable_amount'] ?? 0)), 2);
        }

        return 0.0;
    }
}

if (!function_exists('payment_verification_local_file')) {
    function payment_verification_local_file(?string $storedPath): ?string {
        $storedPath = str_replace('\\', '/', trim((string)$storedPath));
        $basename = basename($storedPath);
        if ($basename === '' || $basename === '.' || $basename === '..') return null;

        $root = dirname(__DIR__);
        $candidates = [
            $root . '/uploads/secure_payments/' . $basename,
            $root . '/uploads/payments/' . $basename,
            $root . '/public/uploads/payments/' . $basename,
            $root . '/public/assets/uploads/payments/' . $basename,
        ];
        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) continue;
            $real = realpath($candidate);
            if ($real === false) continue;
            $normalized = strtolower(str_replace('\\', '/', $real));
            if (strpos($normalized, '/uploads/') !== false) return $real;
        }
        return null;
    }
}

if (!function_exists('payment_verification_make_thumbnail')) {
    function payment_verification_make_thumbnail(string $source, string $mime, string $stem): ?string {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        if ($mime === 'image/png') {
            $image = @imagecreatefrompng($source);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($source);
        } else {
            $image = @imagecreatefromjpeg($source);
        }
        if (!$image) return null;
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return null;
        }

        $max = 420;
        $ratio = min($max / $width, $max / $height, 1);
        $targetWidth = max(1, (int)round($width * $ratio));
        $targetHeight = max(1, (int)round($height * $ratio));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        $background = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $background);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $directory = dirname($source);
        $filename = 'thumb_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $stem) . '.jpg';
        $target = $directory . '/' . $filename;
        $saved = imagejpeg($thumb, $target, 82);
        imagedestroy($thumb);
        imagedestroy($image);
        if (!$saved) return null;
        @chmod($target, 0640);

        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        return $base . '/uploads/secure_payments/' . $filename;
    }
}

if (!function_exists('payment_verification_store_receipt')) {
    function payment_verification_store_receipt(array $file, int $maxBytes = 10485760): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The receipt exceeds the server upload limit.',
                UPLOAD_ERR_PARTIAL => 'The receipt upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Please select a receipt image or PDF.',
                default => 'The receipt upload could not be completed.',
            };
            payment_verification_log('upload_failed', ['upload_error' => $uploadError]);
            return ['success' => false, 'error' => $message];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            payment_verification_log('upload_failed', ['reason' => 'invalid_size', 'size' => $size]);
            return ['success' => false, 'error' => 'Receipt must be 10 MB or smaller.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => 'The uploaded receipt could not be validated.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($tmp));
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($extensions[$mime])) {
            payment_verification_log('upload_failed', ['reason' => 'invalid_mime', 'mime' => $mime]);
            return ['success' => false, 'error' => 'Only JPG, JPEG, PNG, WEBP, and PDF receipts are accepted.'];
        }

        if ($mime === 'application/pdf') {
            $head = (string)@file_get_contents($tmp, false, null, 0, 5);
            if ($head !== '%PDF-') {
                return ['success' => false, 'error' => 'The uploaded PDF is not valid.'];
            }
        } else {
            $info = @getimagesize($tmp);
            $pixels = !empty($info[0]) && !empty($info[1]) ? ((int)$info[0] * (int)$info[1]) : 0;
            if (!$info || $pixels <= 0 || $pixels > 40000000) {
                return ['success' => false, 'error' => 'The receipt image is invalid or too large to process safely.'];
            }
        }

        $directory = dirname(__DIR__) . '/uploads/secure_payments';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return ['success' => false, 'error' => 'Secure receipt storage is unavailable.'];
        }
        $stem = bin2hex(random_bytes(18));
        $filename = $stem . '.' . $extensions[$mime];
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            payment_verification_log('upload_failed', ['reason' => 'move_failed']);
            return ['success' => false, 'error' => 'The receipt could not be saved.'];
        }
        @chmod($target, 0640);

        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        $stored = $base . '/uploads/secure_payments/' . $filename;
        $thumbnail = payment_verification_make_thumbnail($target, $mime, $stem);
        $receiptUrl = $base . '/api_view_proof.php?file=' . rawurlencode($stored);
        payment_verification_log('upload_succeeded', ['mime' => $mime, 'size' => $size, 'filename' => $filename]);
        return [
            'success' => true,
            'file_path' => $stored,
            'storage_path' => 'secure_payments/' . $filename,
            'receipt_url' => $receiptUrl,
            'thumbnail_path' => $thumbnail,
            'local_path' => $target,
            'mime' => $mime,
            'size' => $size,
            'sha256' => hash_file('sha256', $target) ?: null,
            'original_name' => mb_substr(basename((string)($file['name'] ?? 'receipt')), 0, 255),
        ];
    }
}

if (!function_exists('payment_verification_create_submission')) {
    function payment_verification_create_submission(array $data): int {
        if (!payment_verification_ensure_schema()) return 0;
        $orderId = max(0, (int)($data['order_id'] ?? 0));
        $jobOrderId = max(0, (int)($data['job_order_id'] ?? 0));
        $customerId = max(0, (int)($data['customer_id'] ?? 0));
        $receipt = trim((string)($data['receipt_file'] ?? ''));
        if ($customerId <= 0 || $receipt === '') return 0;

        $expected = payment_verification_expected_amount($orderId, $jobOrderId);
        $branchId = max(0, (int)($data['branch_id'] ?? 0));
        if ($branchId <= 0 && $jobOrderId > 0) {
            $branch = db_query('SELECT branch_id FROM job_orders WHERE id = ? LIMIT 1', 'i', [$jobOrderId]);
            $branchId = max(0, (int)($branch[0]['branch_id'] ?? 0));
        }
        if ($branchId <= 0 && $orderId > 0) {
            $branch = db_query('SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
            $branchId = max(0, (int)($branch[0]['branch_id'] ?? 0));
        }

        $submissionToken = strtolower(trim((string)($data['submission_token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $submissionToken)) $submissionToken = null;
        $values = [
            $orderId > 0 ? $orderId : null,
            $jobOrderId > 0 ? $jobOrderId : null,
            $customerId,
            $branchId > 0 ? $branchId : null,
            $receipt,
            trim((string)($data['receipt_storage_path'] ?? '')) ?: null,
            trim((string)($data['receipt_url'] ?? '')) ?: null,
            trim((string)($data['receipt_thumbnail'] ?? '')) ?: null,
            trim((string)($data['receipt_original_name'] ?? '')) ?: null,
            trim((string)($data['receipt_mime'] ?? '')) ?: null,
            max(0, (int)($data['receipt_size'] ?? 0)),
            trim((string)($data['receipt_sha256'] ?? '')) ?: null,
            payment_verification_normalize_method((string)($data['selected_payment_method'] ?? '')) ?: null,
            $expected,
            max(0, (float)($data['submitted_amount'] ?? 0)),
            $submissionToken,
            (string)($data['verification_status'] ?? 'Pending Review'),
            (string)($data['ocr_status'] ?? 'Pending'),
        ];
        $ok = db_execute(
            "INSERT INTO payment_submissions
             (order_id, job_order_id, customer_id, branch_id, receipt_file,
              receipt_storage_path, receipt_url, receipt_thumbnail, receipt_original_name,
              receipt_mime, receipt_size, receipt_sha256, selected_payment_method,
              expected_amount, submitted_amount, submission_token, verification_status, ocr_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'iiiissssssissddsss',
            $values
        );
        $id = is_int($ok) ? $ok : 0;
        if ($id > 0) payment_verification_log('submission_created', ['submission_id' => $id, 'order_id' => $orderId, 'job_order_id' => $jobOrderId, 'branch_id' => $branchId]);
        else payment_verification_log('submission_insert_failed', ['order_id' => $orderId, 'job_order_id' => $jobOrderId]);
        return $id;
    }
}

if (!function_exists('payment_ocr_text_lines')) {
    function payment_ocr_text_lines(string $text): array {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $lines = preg_split('/\n+/', $text) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim((string)preg_replace('/[\t ]+/', ' ', $line));
            if ($line !== '') $result[] = mb_substr($line, 0, 500);
        }
        return $result;
    }
}

if (!function_exists('payment_ocr_labeled_value')) {
    function payment_ocr_labeled_value(array $lines, array $labels): string {
        foreach ($lines as $index => $line) {
            foreach ($labels as $label) {
                if (!preg_match('/^\s*(?:' . $label . ')\s*(?:name|no\.?|number|#|id)?\s*[:\-]?\s*(.*)$/i', $line, $match)) {
                    continue;
                }
                $value = trim((string)($match[1] ?? ''));
                if ($value === '' && isset($lines[$index + 1])) $value = trim((string)$lines[$index + 1]);
                $value = trim((string)preg_replace('/\s{2,}.*/', '', $value));
                return mb_substr($value, 0, 190);
            }
        }
        return '';
    }
}

if (!function_exists('payment_ocr_parse_gcash_receipt')) {
    function payment_ocr_parse_gcash_receipt(array $lines): array {
        $result = [
            'payment_method'   => '',
            'amount'           => null,
            'reference_number' => '',
            'transaction_date' => null,
            'transaction_time' => null,
            'receiver_name'    => '',
            'receiver_account' => '',
            'sender_name'      => '',
        ];

        $text      = implode("\n", $lines);

        // Detect GCash
        if (preg_match('/\bgcash\b/i', $text)) {
            $result['payment_method'] = 'GCash';
        }

        // ── Sender name ────────────────────────────────────────────────────────
        // Patterns: "Sent by: JUAN DELA CRUZ", "From: MARIA SANTOS"
        foreach ($lines as $line) {
            if (preg_match('/(?:sent\s+by|from)\s*:\s*(.+)/i', $line, $m)) {
                $name = trim($m[1]);
                if ($name !== '') {
                    $result['sender_name'] = mb_substr($name, 0, 190);
                    break;
                }
            }
        }

        // ── Amount ─────────────────────────────────────────────────────────────
        // Highest-priority: "Total Amount Sent [PHP/₱/P] 100.00"
        // Then: "Amount Sent [PHP/₱/P] 100.00" or "Amount [PHP/₱/P] 100.00"
        // The optional currency prefix handles receipts that embed "PHP" between
        // the label and the number (e.g. "Amount Sent PHP 100.00").
        $currencyPrefix = '(?:PHP|Php|₱|P\b)?\s*';
        $amountPatterns = [
            '/total\s+amount\s+sent\s*[:\s]*\s*' . $currencyPrefix . '([0-9][0-9,]*\.?\d*)/i',
            '/amount\s+sent\s*[:\s]*\s*'          . $currencyPrefix . '([0-9][0-9,]*\.?\d*)/i',
            '/amount\s+paid\s*[:\s]*\s*'          . $currencyPrefix . '([0-9][0-9,]*\.?\d*)/i',
            '/amount\s*[:\s]*\s*'                 . $currencyPrefix . '([0-9][0-9,]*\.?\d*)/i',
        ];
        foreach ($amountPatterns as $pattern) {
            if ($result['amount'] !== null) break;
            foreach ($lines as $line) {
                if (preg_match($pattern, $line, $match)) {
                    $amount = payment_ocr_parse_money($match[1]);
                    if ($amount !== null) {
                        $result['amount'] = $amount;
                        break;
                    }
                }
            }
        }

        // ── Reference number ────────────────────────────────────────────────────
        // GCash receipts sometimes format the ref as grouped digits with spaces:
        // "Reference No. 1234 5678 9012". Capture a broader set of chars and
        // then strip non-alphanumeric characters before storing.
        foreach ($lines as $line) {
            if (preg_match('/ref\s*(?:erence)?\s*no\.?\s*[:\s]*\s*([A-Z0-9][\s\-A-Z0-9]*)/i', $line, $match)) {
                $ref = preg_replace('/[^A-Z0-9]/i', '', $match[1]);
                if (strlen($ref) >= 6) {
                    $result['reference_number'] = mb_strtoupper(mb_substr($ref, 0, 190));
                    break;
                }
            }
        }

        // ── Date / time ─────────────────────────────────────────────────────────
        // Formats: "Apr 13, 2026 8:17 PM" or "July 11, 2026 11:30 AM"
        foreach ($lines as $line) {
            if (preg_match(
                '/((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+20\d{2})\s+(\d{1,2}:\d{2}\s*(?:AM|PM)?)/i',
                $line, $match
            )) {
                $date = payment_ocr_normalize_date($match[1]);
                $time = payment_ocr_normalize_time($match[2]);
                if ($date !== null) $result['transaction_date'] = $date;
                if ($time !== null) $result['transaction_time'] = $time;
                break;
            }
        }

        // ── Receiver name (masked) ──────────────────────────────────────────────
        // Looks for masked names like "KE••••••D V."
        foreach ($lines as $line) {
            if (preg_match('/([A-Z][A-Z•*]{2,}[A-Z\s.]+)/', $line, $match)) {
                $candidate = trim($match[1]);
                if (preg_match('/[•*]/', $candidate) && strlen($candidate) >= 3) {
                    $result['receiver_name'] = mb_substr($candidate, 0, 190);
                    break;
                }
            }
        }

        // ── Receiver account (mobile) ───────────────────────────────────────────
        foreach ($lines as $line) {
            if (preg_match('/(\+63\s*9\d{2}\s*\d{3}\s*\d{4}|09\d{2}\s*\d{3}\s*\d{4})/', $line, $match)) {
                $result['receiver_account'] = mb_substr(preg_replace('/\s+/', '', $match[1]), 0, 190);
                break;
            }
        }

        return $result;
    }
}


if (!function_exists('payment_ocr_parse_money')) {
    function payment_ocr_parse_money(string $value): ?float {
        $value = preg_replace('/[^0-9.,-]/', '', $value);
        if ($value === null || $value === '') return null;
        $value = str_replace(',', '', $value);
        if (!is_numeric($value)) return null;
        $amount = round((float)$value, 2);
        return $amount >= 0 && $amount <= 100000000 ? $amount : null;
    }
}

if (!function_exists('payment_ocr_extract_amount')) {
    function payment_ocr_extract_amount(array $lines): array {
        $candidates = [];
        foreach ($lines as $lineIndex => $line) {
            $lower = strtolower($line);
            $score = 0;
            if (preg_match('/\b(amount|amount sent|amount paid|total paid|total amount|sent)\b/i', $line)) $score += 45;
            if (preg_match('/\b(total|payment)\b/i', $line)) $score += 20;
            if (preg_match('/\b(fee|charge|balance|available|discount|change|cashback)\b/i', $line)) $score -= 55;
            if (preg_match('/(?:PHP|Php|\x{20B1}|\bP(?=\s*\d))\s*([0-9][0-9,]*(?:\.\d{1,2})?)/u', $line, $match)) {
                $amount = payment_ocr_parse_money($match[1]);
                if ($amount !== null) {
                    $candidates[] = ['amount' => $amount, 'score' => $score + 20, 'line' => $lineIndex];
                }
            } elseif ($score >= 40 && preg_match('/\b([0-9][0-9,]*(?:\.\d{1,2})?)\b/', $line, $match)) {
                $amount = payment_ocr_parse_money($match[1]);
                if ($amount !== null) {
                    $candidates[] = ['amount' => $amount, 'score' => $score, 'line' => $lineIndex];
                }
            }
            unset($lower);
        }
        if (empty($candidates)) return ['value' => null, 'confidence' => 0.0];
        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) return $b['amount'] <=> $a['amount'];
            return $b['score'] <=> $a['score'];
        });
        $best = $candidates[0];
        $confidence = $best['score'] >= 65 ? 92.0 : ($best['score'] >= 40 ? 80.0 : 68.0);
        return ['value' => (float)$best['amount'], 'confidence' => $confidence];
    }
}

if (!function_exists('payment_ocr_normalize_date')) {
    function payment_ocr_normalize_date(string $value): ?string {
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) return null;
        $year = (int)date('Y', $timestamp);
        if ($year < 2015 || $year > ((int)date('Y') + 1)) return null;
        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('payment_ocr_normalize_time')) {
    function payment_ocr_normalize_time(string $value): ?string {
        $value = trim($value);
        foreach (['g:i A', 'g:i:s A', 'H:i', 'H:i:s'] as $format) {
            $date = DateTime::createFromFormat('!' . $format, strtoupper($value));
            if ($date instanceof DateTime) return $date->format('H:i:s');
        }
        return null;
    }
}

if (!function_exists('payment_ocr_detect_method')) {
    function payment_ocr_detect_method(string $text): array {
        $checks = [
            ['pattern' => '/\bgcash\b/i', 'value' => 'GCash', 'confidence' => 96.0],
            ['pattern' => '/\b(?:paymaya|maya)\b/i', 'value' => 'Maya', 'confidence' => 94.0],
            ['pattern' => '/\binstapay\b/i', 'value' => 'Bank Transfer / InstaPay', 'confidence' => 94.0],
            ['pattern' => '/\bpesonet\b/i', 'value' => 'Bank Transfer / PESONet', 'confidence' => 94.0],
            ['pattern' => '/\b(?:bdo|bpi|metrobank|union\s?bank|security bank|rcbc|land\s?bank|china\s?bank|pnb|east\s?west|cimb|sea\s?bank|gotyme)\b/i', 'value' => 'Bank Transfer', 'confidence' => 86.0],
        ];
        foreach ($checks as $check) {
            if (preg_match($check['pattern'], $text)) {
                return ['value' => $check['value'], 'confidence' => $check['confidence']];
            }
        }
        return ['value' => '', 'confidence' => 0.0];
    }
}

if (!function_exists('payment_ocr_confidence_for_value')) {
    function payment_ocr_confidence_for_value(string $value, array $tokens, float $fallback): float {
        $needle = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $value));
        if ($needle === '' || empty($tokens)) return $fallback;
        $matches = [];
        foreach ($tokens as $token) {
            $word = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)($token['text'] ?? '')));
            if ($word !== '' && strpos($needle, $word) !== false) {
                $confidence = (float)($token['confidence'] ?? 0);
                if ($confidence >= 0) $matches[] = min(100, $confidence);
            }
        }
        return empty($matches) ? $fallback : round(array_sum($matches) / count($matches), 2);
    }
}

if (!function_exists('payment_ocr_parse_receipt_text')) {
    function payment_ocr_parse_receipt_text(string $text, array $tokens = [], float $providerConfidence = 0.0): array {
        $text = trim(mb_substr(str_replace("\0", '', $text), 0, 100000));
        $lines = payment_ocr_text_lines($text);
        $method = payment_ocr_detect_method($text);
        
        // Use GCash-specific parser if GCash is detected
        if (preg_match('/\bgcash\b/i', $text)) {
            $gcashResult = payment_ocr_parse_gcash_receipt($lines);
            
            // Calculate confidence values for GCash-extracted fields
            $senderConfidence = $gcashResult['sender_name'] !== '' ? 80.0 : 0.0;
            $referenceConfidence = $gcashResult['reference_number'] !== '' ? 92.0 : 0.0;
            $amountConfidence = $gcashResult['amount'] !== null ? 94.0 : 0.0;
            $methodConfidence = $gcashResult['payment_method'] !== '' ? 96.0 : 0.0;
            $dateConfidence = $gcashResult['transaction_date'] !== null ? 85.0 : 0.0;
            $receiverConfidence = $gcashResult['receiver_name'] !== '' ? 75.0 : 0.0;
            
            $available = array_values(array_filter([
                $referenceConfidence, $amountConfidence, $methodConfidence, $dateConfidence
            ], static fn($value) => $value > 0));
            $overall = !empty($available) ? array_sum($available) / count($available) : $providerConfidence;
            if ($providerConfidence > 0 && $overall > 0) $overall = ($overall * 0.75) + ($providerConfidence * 0.25);
            
            return [
                'sender_name' => $gcashResult['sender_name'],
                'reference_number' => $gcashResult['reference_number'],
                'amount_sent' => $gcashResult['amount'],
                'detected_payment_method' => $gcashResult['payment_method'],
                'transaction_date' => $gcashResult['transaction_date'],
                'transaction_time' => $gcashResult['transaction_time'],
                'receiver_name' => $gcashResult['receiver_name'],
                'receiver_account' => $gcashResult['receiver_account'],
                'overall_confidence' => round(max(0, min(100, $overall)), 2),
                'sender_confidence' => round($senderConfidence, 2),
                'reference_confidence' => round($referenceConfidence, 2),
                'amount_confidence' => round($amountConfidence, 2),
                'method_confidence' => round($methodConfidence, 2),
                'date_confidence' => round($dateConfidence, 2),
                'receiver_confidence' => round($receiverConfidence, 2),
            ];
        }
        
        // Fall back to generic parser for non-GCash receipts
        $amount = payment_ocr_extract_amount($lines);

        $sender = payment_ocr_labeled_value($lines, ['sent\s+by', 'sender', 'from', 'account\s+name']);
        $receiver = payment_ocr_labeled_value($lines, ['sent\s+to', 'receiver', 'recipient', 'merchant']);
        $receiverAccount = payment_ocr_labeled_value($lines, ['receiver\s+account', 'account\s+number', 'mobile\s+number', 'gcash\s+number']);
        $reference = payment_ocr_labeled_value($lines, [
            'reference', 'ref(?:erence)?', 'transaction', 'transaction\s+id',
            'trace', 'trace\s+number', 'instapay\s+trace'
        ]);
        if ($reference !== '') {
            if (preg_match('/([A-Z0-9][A-Z0-9\- ]{5,40})/i', $reference, $match)) {
                $reference = trim((string)$match[1]);
            }
            if (payment_verification_normalize_reference($reference) === '') $reference = '';
        }

        $date = null;
        $time = null;
        foreach ($lines as $line) {
            if ($date === null && preg_match('/\b(?:20\d{2}[\/-]\d{1,2}[\/-]\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-](?:20)?\d{2}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+20\d{2})\b/i', $line, $match)) {
                $date = payment_ocr_normalize_date($match[0]);
            }
            if ($time === null && preg_match('/\b(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\s*(?:AM|PM)?\b/i', $line, $match)) {
                $time = payment_ocr_normalize_time($match[0]);
            }
        }

        $sender = trim((string)preg_replace('/(?:PHP|\x{20B1})\s*[0-9,.]+.*$/iu', '', $sender));
        $receiver = trim((string)preg_replace('/(?:PHP|\x{20B1})\s*[0-9,.]+.*$/iu', '', $receiver));
        $senderConfidence = $sender !== '' ? payment_ocr_confidence_for_value($sender, $tokens, 76.0) : 0.0;
        $referenceConfidence = $reference !== '' ? payment_ocr_confidence_for_value($reference, $tokens, 82.0) : 0.0;
        $amountConfidence = $amount['value'] !== null
            ? payment_ocr_confidence_for_value(number_format((float)$amount['value'], 2, '.', ''), $tokens, (float)$amount['confidence'])
            : 0.0;
        $methodConfidence = (float)$method['confidence'];
        $dateConfidence = $date !== null ? 78.0 : 0.0;
        $receiverConfidence = $receiver !== '' ? payment_ocr_confidence_for_value($receiver, $tokens, 74.0) : 0.0;

        $available = array_values(array_filter([
            $senderConfidence, $referenceConfidence, $amountConfidence,
            $methodConfidence, $dateConfidence, $receiverConfidence
        ], static fn($value) => $value > 0));
        $overall = !empty($available) ? array_sum($available) / count($available) : $providerConfidence;
        if ($providerConfidence > 0 && $overall > 0) $overall = ($overall * 0.75) + ($providerConfidence * 0.25);

        return [
            'sender_name' => mb_substr($sender, 0, 190),
            'reference_number' => mb_substr($reference, 0, 190),
            'amount_sent' => $amount['value'],
            'detected_payment_method' => (string)$method['value'],
            'transaction_date' => $date,
            'transaction_time' => $time,
            'receiver_name' => mb_substr($receiver, 0, 190),
            'receiver_account' => mb_substr($receiverAccount, 0, 190),
            'overall_confidence' => round(max(0, min(100, $overall)), 2),
            'sender_confidence' => round($senderConfidence, 2),
            'reference_confidence' => round($referenceConfidence, 2),
            'amount_confidence' => round($amountConfidence, 2),
            'method_confidence' => round($methodConfidence, 2),
            'date_confidence' => round($dateConfidence, 2),
            'receiver_confidence' => round($receiverConfidence, 2),
        ];
    }
}

if (!function_exists('payment_ocr_run_process')) {
    function payment_ocr_run_process(array $command, int $timeoutSeconds = 45): array {
        if (!function_exists('proc_open')) return ['success' => false, 'error' => 'Local OCR process execution is disabled.'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return ['success' => false, 'error' => 'Local OCR could not start.'];
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if ((microtime(true) - $started) >= $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(50000);
        } while (true);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($timedOut) return ['success' => false, 'error' => 'Local OCR timed out.'];
        if ($exitCode !== 0 && trim($stdout) === '') {
            return ['success' => false, 'error' => 'Local OCR failed: ' . mb_substr(trim($stderr), 0, 180)];
        }
        return ['success' => true, 'output' => $stdout];
    }
}

if (!function_exists('payment_ocr_tesseract_binary')) {
    function payment_ocr_tesseract_binary(): string {
        $configured = payment_verification_env('PAYMENT_OCR_TESSERACT_PATH');
        if ($configured !== '') return $configured;
        return 'tesseract';
    }
}

if (!function_exists('payment_ocr_tesseract_available')) {
    function payment_ocr_tesseract_available(): bool {
        static $available = null;
        if ($available !== null) return $available;
        $result = payment_ocr_run_process([payment_ocr_tesseract_binary(), '--version'], 5);
        return $available = !empty($result['success']);
    }
}

if (!function_exists('payment_ocr_preprocess_image')) {
    function payment_ocr_preprocess_image(string $sourcePath, string $mime): ?string {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        // Load original image
        if ($mime === 'image/png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($sourcePath);
        } else {
            $image = @imagecreatefromjpeg($sourcePath);
        }
        if (!$image) return null;

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return null;
        }

        // Auto-rotate based on EXIF
        if (function_exists('exif_read_data') && $mime === 'image/jpeg') {
            $exif = @exif_read_data($sourcePath);
            if ($exif && isset($exif['Orientation'])) {
                $orientation = (int)$exif['Orientation'];
                if ($orientation >= 2 && $orientation <= 8) {
                    $rotated = imagerotate($image, [0, 0, 0, 180, 270, 90, 270, 90, 0][$orientation], 0);
                    if ($rotated) {
                        imagedestroy($image);
                        $image = $rotated;
                        $width = imagesx($image);
                        $height = imagesy($image);
                    }
                }
            }
        }

        // Resize if too large (max 3000px for OCR)
        $maxDimension = 3000;
        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int)round($width * $ratio);
            $newHeight = (int)round($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        // Convert to grayscale
        $grayscale = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $grayColor = imagecolorallocate($grayscale, $gray, $gray, $gray);
                imagesetpixel($grayscale, $x, $y, $grayColor);
            }
        }
        imagedestroy($image);
        $image = $grayscale;

        // Increase contrast
        imagefilter($image, IMG_FILTER_CONTRAST, 20);

        // Mild sharpening
        imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
        imagefilter($image, IMG_FILTER_UNSHARP_MASK, 50, 1, 3);

        // Save to temporary file
        $directory = dirname($sourcePath);
        $stem = bin2hex(random_bytes(12));
        $tempPath = $directory . '/ocr_temp_' . $stem . '.png';
        imagepng($image, $tempPath, 9);
        imagedestroy($image);

        if (!is_file($tempPath)) {
            return null;
        }

        return $tempPath;
    }
}

if (!function_exists('payment_ocr_with_tesseract')) {
    function payment_ocr_with_tesseract(string $filePath, string $mime): array {
        if ($mime === 'application/pdf') {
            return ['success' => false, 'unavailable' => true, 'error' => 'Local OCR cannot process PDF receipts without a PDF renderer.'];
        }
        if (!payment_ocr_tesseract_available()) {
            return ['success' => false, 'unavailable' => true, 'error' => 'Tesseract is not installed or configured.'];
        }
        
        // Try with preprocessed image first
        $preprocessedPath = payment_ocr_preprocess_image($filePath, $mime);
        $usePreprocessed = ($preprocessedPath !== null && is_file($preprocessedPath));
        $ocrPath = $usePreprocessed ? $preprocessedPath : $filePath;
        
        $language = payment_verification_env('PAYMENT_OCR_LANGUAGE', 'eng');
        $language = preg_replace('/[^a-zA-Z0-9_+-]/', '', $language) ?: 'eng';
        $result = payment_ocr_run_process([
            payment_ocr_tesseract_binary(), $ocrPath, 'stdout', '-l', $language,
            '--psm', '6', 'tsv'
        ], 50);
        
        // Clean up preprocessed file
        if ($usePreprocessed && is_file($preprocessedPath)) {
            @unlink($preprocessedPath);
        }
        
        if (empty($result['success'])) return $result + ['unavailable' => false];

        $lines = preg_split('/\r?\n/', trim((string)$result['output'])) ?: [];
        $tokens = [];
        $grouped = [];
        foreach (array_slice($lines, 1) as $line) {
            $columns = explode("\t", $line);
            if (count($columns) < 12) continue;
            $word = trim((string)$columns[11]);
            $confidence = (float)$columns[10];
            if ($word === '') continue;
            $tokens[] = ['text' => $word, 'confidence' => max(0, $confidence)];
            $lineKey = implode(':', array_slice($columns, 1, 4));
            $grouped[$lineKey][] = $word;
        }
        $textLines = [];
        foreach ($grouped as $words) $textLines[] = implode(' ', $words);
        $confidences = array_column($tokens, 'confidence');
        $overall = empty($confidences) ? 0.0 : array_sum($confidences) / count($confidences);
        $text = trim(implode("\n", $textLines));
        if ($text === '') return ['success' => false, 'unavailable' => false, 'error' => 'No readable text was found in the receipt.'];
        return [
            'success' => true,
            'provider' => 'Tesseract',
            'text' => $text,
            'tokens' => $tokens,
            'confidence' => round($overall, 2),
        ];
    }
}

if (!function_exists('payment_ocr_with_ocrspace')) {
    function payment_ocr_with_ocrspace(string $filePath, string $mime): array {
        $apiKey = payment_verification_env('PAYMENT_OCR_API_KEY');
        if ($apiKey === '') $apiKey = payment_verification_env('OCR_SPACE_API_KEY');
        if ($apiKey === '') return ['success' => false, 'unavailable' => true, 'error' => 'OCR API key is not configured.'];
        if (!function_exists('curl_init')) return ['success' => false, 'unavailable' => true, 'error' => 'PHP cURL is unavailable.'];

        $endpoint = payment_verification_env('PAYMENT_OCR_API_URL', 'https://api.ocr.space/parse/image');
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || stripos($endpoint, 'https://') !== 0) {
            return ['success' => false, 'unavailable' => true, 'error' => 'OCR API URL must use HTTPS.'];
        }
        $language = payment_verification_env('PAYMENT_OCR_LANGUAGE', 'eng');
        $language = preg_replace('/[^a-zA-Z0-9_-]/', '', $language) ?: 'eng';
        $curl = curl_init($endpoint);
        $post = [
            'file' => new CURLFile($filePath, $mime, basename($filePath)),
            'language' => $language,
            'isOverlayRequired' => 'true',
            'detectOrientation' => 'true',
            'scale' => 'true',
            'isTable' => 'true',
            'OCREngine' => '2',
        ];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($response === false || $status < 200 || $status >= 300) {
            return ['success' => false, 'unavailable' => false, 'error' => 'OCR API request failed' . ($curlError !== '' ? ': ' . mb_substr($curlError, 0, 150) : '.')];
        }
        if (strlen((string)$response) > 5242880) {
            return ['success' => false, 'unavailable' => false, 'error' => 'OCR API response was unexpectedly large.'];
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded) || !empty($decoded['IsErroredOnProcessing'])) {
            $message = $decoded['ErrorMessage'] ?? 'OCR API could not process the receipt.';
            if (is_array($message)) $message = implode(' ', array_map('strval', $message));
            return ['success' => false, 'unavailable' => false, 'error' => mb_substr((string)$message, 0, 180)];
        }

        $texts = [];
        $tokens = [];
        foreach (($decoded['ParsedResults'] ?? []) as $page) {
            $parsed = trim((string)($page['ParsedText'] ?? ''));
            if ($parsed !== '') $texts[] = $parsed;
            foreach (($page['TextOverlay']['Lines'] ?? []) as $line) {
                foreach (($line['Words'] ?? []) as $word) {
                    $value = trim((string)($word['WordText'] ?? ''));
                    if ($value !== '') $tokens[] = ['text' => $value, 'confidence' => 80.0];
                }
            }
        }
        $text = trim(implode("\n", $texts));
        if ($text === '') return ['success' => false, 'unavailable' => false, 'error' => 'No readable text was found in the receipt.'];
        return [
            'success' => true,
            'provider' => 'OCR.Space',
            'text' => $text,
            'tokens' => $tokens,
            'confidence' => 80.0,
        ];
    }
}

if (!function_exists('payment_ocr_extract')) {
    function payment_ocr_extract(string $filePath, string $mime): array {
        $provider = strtolower(payment_verification_env('PAYMENT_OCR_PROVIDER', 'auto'));
        if (in_array($provider, ['off', 'disabled', 'none'], true)) {
            return ['success' => false, 'unavailable' => true, 'error' => 'OCR processing is disabled.'];
        }
        if ($provider === 'tesseract') return payment_ocr_with_tesseract($filePath, $mime);
        if (in_array($provider, ['ocrspace', 'ocr.space', 'api'], true)) return payment_ocr_with_ocrspace($filePath, $mime);

        if ($mime !== 'application/pdf' && payment_ocr_tesseract_available()) {
            $local = payment_ocr_with_tesseract($filePath, $mime);
            if (!empty($local['success'])) return $local;
        }
        return payment_ocr_with_ocrspace($filePath, $mime);
    }
}

if (!function_exists('payment_verification_duplicate_id')) {
    function payment_verification_duplicate_id(int $submissionId, string $normalizedReference, string $sha256 = ''): int {
        if ($normalizedReference !== '') {
            $rows = db_query(
                "SELECT id FROM payment_submissions
                 WHERE id <> ? AND reference_normalized = ?
                   AND verification_status <> 'Rejected'
                 ORDER BY id ASC LIMIT 1",
                'is',
                [$submissionId, $normalizedReference]
            );
            if (!empty($rows[0]['id'])) return (int)$rows[0]['id'];
        }
        if ($sha256 !== '') {
            $rows = db_query(
                "SELECT id FROM payment_submissions
                 WHERE id <> ? AND receipt_sha256 = ?
                   AND verification_status <> 'Rejected'
                 ORDER BY id ASC LIMIT 1",
                'is',
                [$submissionId, $sha256]
            );
            if (!empty($rows[0]['id'])) return (int)$rows[0]['id'];
        }
        return 0;
    }
}

if (!function_exists('payment_verification_review_state')) {
    function payment_verification_review_state(
        ?float $amount,
        float $expected,
        ?bool $methodMatches,
        float $confidence,
        string $reference,
        int $duplicateId
    ): array {
        // Compare integer cent values; binary floating-point string comparisons are unsafe for money.
        $amountCents = $amount === null ? null : (int)round($amount * 100, 0, PHP_ROUND_HALF_UP);
        $expectedCents = (int)round($expected * 100, 0, PHP_ROUND_HALF_UP);
        $amountMatch = ($amountCents === null || $expectedCents <= 0)
            ? 'Unknown'
            : ($amountCents === $expectedCents ? 'Matched' : 'Mismatch');
        $methodMatch = $methodMatches === null ? 'Unknown' : ($methodMatches ? 'Matched' : 'Mismatch');
        if ($duplicateId > 0) {
            $status = 'Duplicate Suspected';
        } elseif ($amountMatch === 'Mismatch' || $methodMatch === 'Mismatch') {
            $status = 'Needs Review';
        } elseif ($amount === null || $reference === '' || $confidence < 85) {
            $status = 'Needs Review';
        } elseif ($amountMatch === 'Matched') {
            $status = 'Matched';
        } else {
            $status = 'Pending Review';
        }
        return ['amount_match_status' => $amountMatch, 'method_match_status' => $methodMatch, 'verification_status' => $status];
    }
}

if (!function_exists('payment_verification_sanitize_ocr_text')) {
    function payment_verification_sanitize_ocr_text(?string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string)$text));
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return mb_substr($text, 0, 200000);
    }
}

if (!function_exists('payment_ocr_normalize_text')) {
    function payment_ocr_normalize_text(string $text): string {
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Remove duplicate whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Correct common OCR substitutions when context is clear
        $text = preg_replace('/\bO\b(?=\d)/', '0', $text); // O -> 0 before digits
        $text = preg_replace('/\b0\b(?=[A-Za-z])/', 'O', $text); // 0 -> O before letters
        $text = preg_replace('/\bl\b(?=\d)/', '1', $text); // l -> 1 before digits
        $text = preg_replace('/\bI\b(?=\d)/', '1', $text); // I -> 1 before digits
        $text = preg_replace('/\bS\b(?=\d)/', '5', $text); // S -> 5 before digits (context-dependent)
        
        // Normalize Philippine peso symbols
        $text = preg_replace('/[\x{20B1}₱PHP]/u', 'PHP ', $text);
        
        // Clean up
        $text = trim($text);
        
        return $text;
    }
}

if (!function_exists('payment_ocr_process_submission')) {
    function payment_ocr_process_submission(int $submissionId, bool $force = false): array {
        $startedAt = microtime(true);
        if ($submissionId <= 0 || !payment_verification_ensure_schema()) {
            return ['success' => false, 'status' => 'Failed'];
        }
        payment_verification_log('ocr_started', ['submission_id' => $submissionId, 'force' => $force]);
        if ($force) {
            db_execute(
                "UPDATE payment_submissions SET ocr_status = 'Pending', ocr_error = NULL
                 WHERE id = ? AND verification_status NOT IN ('Approved', 'Rejected')",
                'i',
                [$submissionId]
            );
        }
        $claimed = db_execute_affected_rows(
            "UPDATE payment_submissions
             SET ocr_status = 'Processing', ocr_attempts = ocr_attempts + 1, ocr_error = NULL
             WHERE id = ? AND ocr_status IN ('Pending', 'Failed', 'Unavailable')
               AND verification_status NOT IN ('Approved', 'Rejected')",
            'i',
            [$submissionId]
        );
        if ($claimed !== 1) {
            $existing = db_query('SELECT ocr_status, verification_status FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$submissionId]);
            return [
                'success' => !empty($existing),
                'status' => (string)($existing[0]['ocr_status'] ?? 'Failed'),
                'verification_status' => (string)($existing[0]['verification_status'] ?? ''),
            ];
        }

        $rows = db_query('SELECT * FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$submissionId]);
        $submission = $rows[0] ?? null;
        if (!$submission) return ['success' => false, 'status' => 'Failed', 'error' => 'Payment submission record not found.'];
        $filePath = payment_verification_local_file((string)$submission['receipt_file']);
        if ($filePath === null) {
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            db_execute(
                "UPDATE payment_submissions SET ocr_status = 'Failed', ocr_error = ?, verification_status = 'Needs Review', ocr_duration_ms = ?, ocr_processed_at = NOW() WHERE id = ?",
                'sii',
                ['Receipt file is unavailable for OCR.', $durationMs, $submissionId]
            );
            payment_verification_log('ocr_failed', ['submission_id' => $submissionId, 'reason' => 'file_unavailable', 'duration_ms' => $durationMs]);
            return ['success' => false, 'status' => 'Failed', 'error' => 'Receipt file is unavailable for OCR.'];
        }
        $mime = trim((string)($submission['receipt_mime'] ?? ''));
        if ($mime === '') $mime = (string)(@mime_content_type($filePath) ?: 'application/octet-stream');
        $ocr = payment_ocr_extract($filePath, $mime);
        if (empty($ocr['success'])) {
            $status = !empty($ocr['unavailable']) ? 'Unavailable' : 'Failed';
            $safeError = mb_substr(trim((string)($ocr['error'] ?? 'OCR could not process this receipt.')), 0, 500);
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            db_execute(
                "UPDATE payment_submissions
                 SET ocr_status = ?, ocr_error = ?, verification_status = 'Needs Review', ocr_duration_ms = ?, ocr_processed_at = NOW()
                 WHERE id = ?",
                'ssii',
                [$status, $safeError, $durationMs, $submissionId]
            );
            payment_verification_log('ocr_failed', ['submission_id' => $submissionId, 'status' => $status, 'reason' => $safeError, 'duration_ms' => $durationMs]);
            return ['success' => false, 'status' => $status, 'verification_status' => 'Needs Review', 'error' => $safeError];
        }

        $rawText = payment_verification_sanitize_ocr_text((string)$ocr['text']);
        $normalizedText = payment_ocr_normalize_text($rawText);
        $parsed = payment_ocr_parse_receipt_text(
            $normalizedText,
            is_array($ocr['tokens'] ?? null) ? $ocr['tokens'] : [],
            (float)($ocr['confidence'] ?? 0)
        );
        $expected = payment_verification_expected_amount((int)($submission['order_id'] ?? 0), (int)($submission['job_order_id'] ?? 0));
        $reference = trim((string)($submission['reference_number'] ?: $parsed['reference_number']));
        $normalizedReference = payment_verification_normalize_reference($reference);
        $amount = $submission['amount_sent'] !== null ? (float)$submission['amount_sent'] : $parsed['amount_sent'];
        $detectedMethod = trim((string)($submission['detected_payment_method'] ?: $parsed['detected_payment_method']));
        $duplicateId = payment_verification_duplicate_id(
            $submissionId,
            $normalizedReference,
            trim((string)($submission['receipt_sha256'] ?? ''))
        );
        $state = payment_verification_review_state(
            $amount === null ? null : (float)$amount,
            $expected,
            payment_verification_methods_match((string)$submission['selected_payment_method'], $detectedMethod),
            (float)$parsed['overall_confidence'],
            $normalizedReference,
            $duplicateId
        );

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $params = [
            $parsed['sender_name'], $parsed['reference_number'], $normalizedReference,
            $parsed['amount_sent'] === null ? null : number_format((float)$parsed['amount_sent'], 2, '.', ''),
            $parsed['detected_payment_method'], $parsed['transaction_date'], $parsed['transaction_time'],
            $parsed['receiver_name'], $parsed['receiver_account'], $rawText, $normalizedText,
            $parsed['overall_confidence'], $parsed['sender_confidence'], $parsed['reference_confidence'],
            $parsed['amount_confidence'], $parsed['method_confidence'], $parsed['date_confidence'],
            $parsed['receiver_confidence'], (string)$ocr['provider'], $durationMs, $expected,
            $state['amount_match_status'], $state['method_match_status'], $duplicateId > 0 ? $duplicateId : null,
            $state['verification_status'], $submissionId,
        ];
        $ok = db_execute(
            "UPDATE payment_submissions SET
                ocr_sender_name = NULLIF(?, ''), ocr_reference_number = NULLIF(?, ''), reference_normalized = NULLIF(?, ''),
                ocr_amount_sent = ?, ocr_detected_payment_method = NULLIF(?, ''),
                ocr_transaction_date = ?, ocr_transaction_time = ?,
                ocr_receiver_name = NULLIF(?, ''), ocr_receiver_account = NULLIF(?, ''), raw_ocr_text = ?, ocr_normalized_text = ?,
                overall_confidence = ?, sender_confidence = ?, reference_confidence = ?, amount_confidence = ?,
                method_confidence = ?, date_confidence = ?, receiver_confidence = ?,
                ocr_status = 'Completed', ocr_provider = ?, ocr_error = NULL, ocr_duration_ms = ?, ocr_processed_at = NOW(),
                expected_amount = ?, amount_match_status = ?, method_match_status = ?,
                duplicate_submission_id = ?, verification_status = ?
             WHERE id = ?",
            str_repeat('s', 25) . 'i',
            $params
        );
        payment_verification_log($ok ? 'ocr_succeeded' : 'ocr_save_failed', [
            'submission_id' => $submissionId,
            'provider' => (string)($ocr['provider'] ?? ''),
            'duration_ms' => $durationMs,
            'verification_status' => $state['verification_status'],
        ]);
        return [
            'success' => (bool)$ok,
            'status' => $ok ? 'Completed' : 'Failed',
            'verification_status' => $state['verification_status'],
        ];
    }
}

if (!function_exists('payment_ocr_process_queue')) {
    function payment_ocr_process_queue(int $limit = 10): array {
        if (!payment_verification_ensure_schema()) return ['processed' => 0, 'completed' => 0, 'needs_review' => 0, 'failed' => 0];
        $limit = max(1, min(50, $limit));
        $rows = db_query(
            "SELECT id FROM payment_submissions
             WHERE ocr_status = 'Pending' AND verification_status NOT IN ('Approved', 'Rejected')
             ORDER BY created_at ASC LIMIT ?",
            'i',
            [$limit]
        ) ?: [];
        $summary = ['processed' => 0, 'completed' => 0, 'needs_review' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result = payment_ocr_process_submission((int)$row['id']);
            $summary['processed']++;
            if (($result['status'] ?? '') === 'Completed') $summary['completed']++;
            else $summary['failed']++;
            if (($result['verification_status'] ?? '') === 'Needs Review') $summary['needs_review']++;
        }
        return $summary;
    }
}

if (!function_exists('payment_verification_effective_value')) {
    function payment_verification_effective_value(array $row, string $correctedColumn, string $ocrColumn) {
        $corrected = $row[$correctedColumn] ?? null;
        return ($corrected !== null && trim((string)$corrected) !== '') ? $corrected : ($row[$ocrColumn] ?? null);
    }
}

if (!function_exists('payment_verification_amount_result')) {
    function payment_verification_amount_result(array $row): string {
        $ocrStatus = strtolower(trim((string)($row['ocr_status'] ?? '')));
        if (in_array($ocrStatus, ['pending', 'processing'], true)) return 'pending_ocr';

        $amountRaw = payment_verification_effective_value($row, 'amount_sent', 'ocr_amount_sent');
        $expectedRaw = $row['expected_amount'] ?? null;
        if ($amountRaw === null || $amountRaw === '' || $expectedRaw === null || $expectedRaw === '') return 'unreadable';

        $amountCents = (int)round((float)$amountRaw * 100, 0, PHP_ROUND_HALF_UP);
        $expectedCents = (int)round((float)$expectedRaw * 100, 0, PHP_ROUND_HALF_UP);
        if ($expectedCents <= 0) return 'unreadable';
        if ($amountCents === $expectedCents) return 'exact_match';
        return $amountCents < $expectedCents ? 'underpaid' : 'overpaid';
    }
}

if (!function_exists('payment_verification_recalculate')) {
    function payment_verification_recalculate(int $submissionId): bool {
        $rows = db_query('SELECT * FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$submissionId]);
        $row = $rows[0] ?? null;
        if (!$row) return false;
        if (in_array((string)$row['verification_status'], ['Approved', 'Rejected'], true)) return true;

        $reference = trim((string)payment_verification_effective_value($row, 'reference_number', 'ocr_reference_number'));
        $normalizedReference = payment_verification_normalize_reference($reference);
        $amountRaw = payment_verification_effective_value($row, 'amount_sent', 'ocr_amount_sent');
        $amount = ($amountRaw === null || $amountRaw === '') ? null : (float)$amountRaw;
        $method = trim((string)payment_verification_effective_value($row, 'detected_payment_method', 'ocr_detected_payment_method'));
        $expected = payment_verification_expected_amount((int)($row['order_id'] ?? 0), (int)($row['job_order_id'] ?? 0));
        $duplicateId = payment_verification_duplicate_id(
            $submissionId,
            $normalizedReference,
            trim((string)($row['receipt_sha256'] ?? ''))
        );
        $hasCorrection = !empty($row['corrected_at']);
        $confidence = $hasCorrection ? 100.0 : (float)($row['overall_confidence'] ?? 0);
        $state = payment_verification_review_state(
            $amount,
            $expected,
            payment_verification_methods_match((string)$row['selected_payment_method'], $method),
            $confidence,
            $normalizedReference,
            $duplicateId
        );
        return (bool)db_execute(
            "UPDATE payment_submissions
             SET reference_normalized = NULLIF(?, ''), expected_amount = ?,
                 amount_match_status = ?, method_match_status = ?,
                 duplicate_submission_id = ?, verification_status = ?
             WHERE id = ?",
            'sdssisi',
            [
                $normalizedReference, $expected, $state['amount_match_status'],
                $state['method_match_status'], $duplicateId > 0 ? $duplicateId : null,
                $state['verification_status'], $submissionId,
            ]
        );
    }
}

if (!function_exists('payment_verification_save_corrections')) {
    function payment_verification_save_corrections(int $submissionId, array $input, int $staffId): array {
        if ($submissionId <= 0 || $staffId <= 0 || !payment_verification_ensure_schema()) {
            return ['success' => false, 'error' => 'Invalid payment submission.'];
        }
        $rows = db_query('SELECT verification_status FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$submissionId]);
        if (empty($rows)) return ['success' => false, 'error' => 'Payment submission not found.'];
        if (in_array((string)$rows[0]['verification_status'], ['Approved', 'Rejected'], true)) {
            return ['success' => false, 'error' => 'This payment submission has already been finalized.'];
        }

        $sender = mb_substr(trim((string)($input['sender_name'] ?? '')), 0, 190);
        $reference = mb_substr(trim((string)($input['reference_number'] ?? '')), 0, 190);
        $amountInput = trim((string)($input['amount_sent'] ?? ''));
        $amount = $amountInput === '' ? null : payment_ocr_parse_money($amountInput);
        if ($amountInput !== '' && $amount === null) return ['success' => false, 'error' => 'Enter a valid detected amount.'];
        $method = payment_verification_normalize_method((string)($input['detected_payment_method'] ?? ''));
        $dateInput = trim((string)($input['transaction_date'] ?? ''));
        $date = $dateInput === '' ? null : payment_ocr_normalize_date($dateInput);
        if ($dateInput !== '' && $date === null) return ['success' => false, 'error' => 'Enter a valid transaction date.'];
        $timeInput = trim((string)($input['transaction_time'] ?? ''));
        $time = $timeInput === '' ? null : payment_ocr_normalize_time($timeInput);
        if ($timeInput !== '' && $time === null) return ['success' => false, 'error' => 'Enter a valid transaction time.'];
        $receiver = mb_substr(trim((string)($input['receiver_name'] ?? '')), 0, 190);
        $account = mb_substr(trim((string)($input['receiver_account'] ?? '')), 0, 190);
        $notes = mb_substr(trim((string)($input['staff_notes'] ?? '')), 0, 5000);

        $ok = db_execute(
            "UPDATE payment_submissions SET
                sender_name = NULLIF(?, ''), reference_number = NULLIF(?, ''), amount_sent = ?,
                detected_payment_method = NULLIF(?, ''), transaction_date = ?, transaction_time = ?,
                receiver_name = NULLIF(?, ''), receiver_account = NULLIF(?, ''),
                staff_notes = NULLIF(?, ''), corrected_by = ?, corrected_at = NOW()
             WHERE id = ? AND verification_status NOT IN ('Approved', 'Rejected')",
            'sssssssssii',
            [
                $sender, $reference, $amount === null ? null : number_format($amount, 2, '.', ''),
                $method, $date, $time, $receiver, $account, $notes, $staffId, $submissionId,
            ]
        );
        if (!$ok) return ['success' => false, 'error' => 'Could not save the corrected OCR details.'];
        payment_verification_recalculate($submissionId);
        return ['success' => true];
    }
}

if (!function_exists('payment_verification_mark_decision')) {
    function payment_verification_mark_decision(
        int $submissionId,
        string $status,
        int $staffId,
        string $reason = '',
        string $notes = ''
    ): bool {
        if ($submissionId <= 0 || $staffId <= 0 || !payment_verification_ensure_schema()) return false;
        $allowed = ['Approved', 'Rejected', 'Duplicate Suspected', 'Needs Review'];
        if (!in_array($status, $allowed, true)) return false;
        $existing = db_query('SELECT verification_status FROM payment_submissions WHERE id = ? LIMIT 1', 'i', [$submissionId]);
        if (empty($existing)) return false;
        $current = (string)$existing[0]['verification_status'];
        if ($current === $status && in_array($status, ['Approved', 'Rejected'], true)) return true;
        if (in_array($current, ['Approved', 'Rejected'], true)) return false;

        return (bool)db_execute(
            "UPDATE payment_submissions
             SET verification_status = ?, rejection_reason = NULLIF(?, ''),
                 staff_notes = CASE WHEN ? <> '' THEN ? ELSE staff_notes END,
                 verified_by = ?, verified_at = NOW()
             WHERE id = ? AND verification_status NOT IN ('Approved', 'Rejected')",
            'ssssii',
            [
                $status, mb_substr(trim($reason), 0, 5000),
                mb_substr(trim($notes), 0, 5000), mb_substr(trim($notes), 0, 5000),
                $staffId, $submissionId,
            ]
        );
    }
}

if (!function_exists('payment_verification_latest_submission_id')) {
    function payment_verification_latest_submission_id(int $orderId = 0, int $jobOrderId = 0): int {
        if (!payment_verification_ensure_schema()) return 0;
        if ($orderId > 0) {
            $rows = db_query(
                "SELECT id FROM payment_submissions WHERE order_id = ?
                 ORDER BY (verification_status IN ('Approved','Rejected')) ASC, created_at DESC, id DESC LIMIT 1",
                'i',
                [$orderId]
            );
            if (!empty($rows[0]['id'])) return (int)$rows[0]['id'];
        }
        if ($jobOrderId > 0) {
            $rows = db_query(
                "SELECT id FROM payment_submissions WHERE job_order_id = ?
                 ORDER BY (verification_status IN ('Approved','Rejected')) ASC, created_at DESC, id DESC LIMIT 1",
                'i',
                [$jobOrderId]
            );
            if (!empty($rows[0]['id'])) return (int)$rows[0]['id'];
        }
        return 0;
    }
}

if (!function_exists('payment_verification_mark_order_decision')) {
    function payment_verification_mark_order_decision(
        int $submissionId,
        int $orderId,
        int $jobOrderId,
        string $status,
        int $staffId,
        string $reason = '',
        string $notes = ''
    ): bool {
        if ($submissionId <= 0) $submissionId = payment_verification_latest_submission_id($orderId, $jobOrderId);
        if ($submissionId <= 0) return true;
        return payment_verification_mark_decision($submissionId, $status, $staffId, $reason, $notes);
    }
}

if (!function_exists('payment_verification_customer_summary')) {
    function payment_verification_customer_summary(int $customerId, int $orderId = 0, int $jobOrderId = 0): ?array {
        if ($customerId <= 0 || !payment_verification_ensure_schema()) return null;
        $where = $orderId > 0 ? 'order_id = ?' : 'job_order_id = ?';
        $id = $orderId > 0 ? $orderId : $jobOrderId;
        if ($id <= 0) return null;
        $rows = db_query(
            "SELECT id, verification_status, ocr_status, rejection_reason, created_at
             FROM payment_submissions WHERE customer_id = ? AND {$where}
             ORDER BY created_at DESC, id DESC LIMIT 1",
            'ii',
            [$customerId, $id]
        );
        if (empty($rows)) return null;
        $row = $rows[0];
        $status = (string)$row['verification_status'];
        if ($status === 'Approved') {
            $label = 'Payment Approved';
            $message = 'Your payment has been verified and approved.';
        } elseif ($status === 'Rejected') {
            $label = 'Please Upload a New Receipt';
            $message = 'Your payment proof could not be verified. Please upload a new receipt.';
        } else {
            $label = ($row['ocr_status'] ?? '') === 'Processing' ? 'Under Review' : 'Receipt Submitted';
            $message = 'Payment proof submitted. Your payment is pending staff verification.';
        }
        return $row + ['customer_label' => $label, 'customer_message' => $message];
    }
}

if (!function_exists('payment_verification_proof_url')) {
    function payment_verification_proof_url(?string $path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        return $base . '/api_view_proof.php?file=' . rawurlencode($path);
    }
}

if (!function_exists('payment_verification_order_label')) {
    function payment_verification_order_label(array $row): string {
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId > 0 && function_exists('printflow_format_order_code')) {
            return printflow_format_order_code($orderId, (string)($row['order_sku'] ?? ''));
        }
        $jobId = (int)($row['job_order_id'] ?? 0);
        if ($jobId > 0 && function_exists('printflow_format_job_code')) {
            return 'JO-' . printflow_format_job_code($jobId);
        }
        return $orderId > 0 ? 'ORD-' . $orderId : 'Payment #' . (int)($row['id'] ?? 0);
    }
}

if (!function_exists('payment_verification_get_submission')) {
    function payment_verification_get_submission(int $submissionId): ?array {
        if ($submissionId <= 0 || !payment_verification_ensure_schema()) return null;
        $rows = db_query(
            "SELECT ps.*,
                    (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-')
                     FROM order_items oi LEFT JOIN products p ON p.product_id = oi.product_id
                     WHERE oi.order_id = ps.order_id) AS order_sku,
                    o.status AS order_status, o.payment_status AS order_payment_status,
                    o.branch_id AS order_branch_id,
                    jo.status AS job_status, jo.payment_status AS job_payment_status,
                    jo.payment_proof_status AS job_proof_status, jo.branch_id AS job_branch_id,
                    jo.job_title, jo.service_type, jo.estimated_total, jo.required_payment,
                    CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
                    c.email AS customer_email,
                    CONCAT_WS(' ', verifier.first_name, verifier.last_name) AS verifier_name,
                    CONCAT_WS(' ', corrector.first_name, corrector.last_name) AS corrector_name
             FROM payment_submissions ps
             LEFT JOIN orders o ON o.order_id = ps.order_id
             LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
             LEFT JOIN customers c ON c.customer_id = ps.customer_id
             LEFT JOIN users verifier ON verifier.user_id = ps.verified_by
             LEFT JOIN users corrector ON corrector.user_id = ps.corrected_by
             WHERE ps.id = ? LIMIT 1",
            'i',
            [$submissionId]
        );
        return $rows[0] ?? null;
    }
}

if (!function_exists('payment_verification_can_access')) {
    function payment_verification_can_access(array $submission, ?int $branchId = null): bool {
        $userType = (string)($_SESSION['user_type'] ?? '');
        if ($userType === 'Admin') return true;
        if ($userType !== 'Staff') return false;
        if (function_exists('printflow_get_staff_access_role') && printflow_get_staff_access_role() !== 'online') {
            return false;
        }
        if ($branchId === null && function_exists('printflow_branch_filter_for_user')) {
            $branchId = printflow_branch_filter_for_user();
        }
        if ($branchId === null || $branchId <= 0) {
            $branchId = (int)($_SESSION['branch_id'] ?? 0);
        }
        if ($branchId <= 0) return false;
        $submissionBranch = (int)($submission['branch_id'] ?? 0);
        if ($submissionBranch <= 0) $submissionBranch = (int)($submission['order_branch_id'] ?? 0);
        if ($submissionBranch <= 0) $submissionBranch = (int)($submission['job_branch_id'] ?? 0);
        return $submissionBranch > 0 && $submissionBranch === $branchId;
    }
}

if (!function_exists('payment_verification_import_legacy_submissions')) {
    function payment_verification_import_legacy_submissions(int $limit = 100): int {
        if (!payment_verification_ensure_schema()) return 0;
        $limit = max(1, min(500, $limit));
        $imported = 0;

        $orders = db_query(
            "SELECT o.order_id, o.customer_id, o.branch_id, o.payment_proof, o.total_amount,
                    o.payment_status, o.status, o.payment_submitted_at, pm.name AS payment_method_name
             FROM orders o
             LEFT JOIN payment_methods pm ON pm.payment_method_id = o.payment_method_id
             WHERE NULLIF(TRIM(o.payment_proof), '') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM payment_submissions ps
                    WHERE ps.order_id = o.order_id AND ps.receipt_file = o.payment_proof
               )
             ORDER BY o.payment_submitted_at DESC, o.order_id DESC LIMIT ?",
            'i',
            [$limit]
        ) ?: [];
        foreach ($orders as $order) {
            $local = payment_verification_local_file((string)$order['payment_proof']);
            $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
            $orderStatus = strtolower(trim((string)($order['status'] ?? '')));
            $verificationStatus = $paymentStatus === 'paid'
                ? 'Approved'
                : ($orderStatus === 'rejected' ? 'Rejected' : 'Pending Review');
            $ocrStatus = in_array($verificationStatus, ['Approved', 'Rejected'], true) ? 'Skipped' : 'Pending';
            $id = payment_verification_create_submission([
                'order_id' => (int)$order['order_id'],
                'customer_id' => (int)$order['customer_id'],
                'branch_id' => (int)($order['branch_id'] ?? 0),
                'receipt_file' => (string)$order['payment_proof'],
                'receipt_storage_path' => ltrim((string)$order['payment_proof'], '/'),
                'receipt_url' => payment_verification_proof_url((string)$order['payment_proof']),
                'receipt_mime' => $local ? (string)(@mime_content_type($local) ?: '') : '',
                'receipt_size' => $local ? (int)@filesize($local) : 0,
                'receipt_sha256' => $local ? (string)(@hash_file('sha256', $local) ?: '') : '',
                'selected_payment_method' => (string)($order['payment_method_name'] ?: 'GCash'),
                'submitted_amount' => (float)($order['total_amount'] ?? 0),
                'verification_status' => $verificationStatus,
                'ocr_status' => $ocrStatus,
            ]);
            if ($id > 0) $imported++;
        }

        $remaining = max(1, $limit - $imported);
        $jobs = db_query(
            "SELECT jo.id, jo.order_id, jo.customer_id, jo.branch_id, jo.payment_proof_path,
                    jo.payment_method, jo.payment_submitted_amount,
                    jo.payment_proof_status, jo.payment_status
             FROM job_orders jo
             WHERE NULLIF(TRIM(jo.payment_proof_path), '') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1 FROM payment_submissions ps
                    WHERE (ps.job_order_id = jo.id OR (jo.order_id IS NOT NULL AND ps.order_id = jo.order_id))
                      AND ps.receipt_file = jo.payment_proof_path
               )
             ORDER BY jo.payment_proof_uploaded_at DESC, jo.id DESC LIMIT ?",
            'i',
            [$remaining]
        ) ?: [];
        foreach ($jobs as $job) {
            $local = payment_verification_local_file((string)$job['payment_proof_path']);
            $proofStatus = strtoupper(trim((string)($job['payment_proof_status'] ?? '')));
            $verificationStatus = $proofStatus === 'VERIFIED'
                ? 'Approved'
                : ($proofStatus === 'REJECTED' ? 'Rejected' : 'Pending Review');
            $ocrStatus = in_array($verificationStatus, ['Approved', 'Rejected'], true) ? 'Skipped' : 'Pending';
            $id = payment_verification_create_submission([
                'order_id' => (int)($job['order_id'] ?? 0),
                'job_order_id' => (int)$job['id'],
                'customer_id' => (int)$job['customer_id'],
                'branch_id' => (int)($job['branch_id'] ?? 0),
                'receipt_file' => (string)$job['payment_proof_path'],
                'receipt_storage_path' => ltrim((string)$job['payment_proof_path'], '/'),
                'receipt_url' => payment_verification_proof_url((string)$job['payment_proof_path']),
                'receipt_mime' => $local ? (string)(@mime_content_type($local) ?: '') : '',
                'receipt_size' => $local ? (int)@filesize($local) : 0,
                'receipt_sha256' => $local ? (string)(@hash_file('sha256', $local) ?: '') : '',
                'selected_payment_method' => (string)($job['payment_method'] ?: 'GCash'),
                'submitted_amount' => (float)($job['payment_submitted_amount'] ?? 0),
                'verification_status' => $verificationStatus,
                'ocr_status' => $ocrStatus,
            ]);
            if ($id > 0) $imported++;
        }
        return $imported;
    }
}

if (!function_exists('payment_verification_notify_reviewers')) {
    function payment_verification_notify_reviewers(int $orderId = 0, int $jobOrderId = 0, int $submissionId = 0): void {
        if ($submissionId <= 0) {
            $submissionId = payment_verification_latest_submission_id($orderId, $jobOrderId);
        }
        $submission = $submissionId > 0 ? payment_verification_get_submission($submissionId) : null;
        if ($submission) {
            $orderId = (int)($submission['order_id'] ?? $orderId);
            $jobOrderId = (int)($submission['job_order_id'] ?? $jobOrderId);
        }

        $branchId = (int)($submission['branch_id'] ?? 0);
        if ($branchId <= 0) $branchId = (int)($submission['order_branch_id'] ?? 0);
        if ($branchId <= 0) $branchId = (int)($submission['job_branch_id'] ?? 0);
        if ($branchId <= 0 && $jobOrderId > 0) {
            $branch = db_query('SELECT branch_id FROM job_orders WHERE id = ? LIMIT 1', 'i', [$jobOrderId]);
            $branchId = (int)($branch[0]['branch_id'] ?? 0);
        }
        if ($branchId <= 0 && $orderId > 0) {
            $branch = db_query('SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
            $branchId = (int)($branch[0]['branch_id'] ?? 0);
        }

        $customerName = trim((string)($submission['customer_name'] ?? ''));
        $customerLabel = $customerName !== '' ? $customerName : 'A customer';
        $itemLabel = '';
        if ($orderId > 0 && function_exists('printflow_order_notification_preview')) {
            $preview = printflow_order_notification_preview($orderId);
            $itemLabel = trim((string)($preview['display_name'] ?? ''));
        }
        if ($itemLabel === '' && $jobOrderId > 0 && function_exists('printflow_job_notification_preview')) {
            $preview = printflow_job_notification_preview($jobOrderId);
            $itemLabel = trim((string)($preview['display_name'] ?? ''));
        }
        if ($itemLabel === '') {
            $itemLabel = payment_verification_order_label($submission ?: ['order_id' => $orderId, 'job_order_id' => $jobOrderId]);
        }
        $message = $customerLabel . ' submitted payment proof for ' . $itemLabel . '. Needs verification.';

        $usersSql = "SELECT user_id, user_type, position, branch_id FROM users
                     WHERE user_type = 'Staff'
                       AND COALESCE(status, 'Activated') <> 'Archived'";
        $types = '';
        $params = [];
        if ($branchId > 0) {
            $usersSql .= ' AND branch_id = ?';
            $types = 'i';
            $params[] = $branchId;
        }
        $users = db_query($usersSql, $types, $params) ?: [];
        $created = 0;
        foreach ($users as $user) {
            if (function_exists('printflow_detect_staff_access_role')) {
                if (printflow_detect_staff_access_role((string)($user['position'] ?? '')) !== 'online') continue;
            }
            $notificationId = create_notification(
                (int)$user['user_id'],
                'Staff',
                $message,
                'Payment',
                false,
                false,
                $submissionId > 0 ? $submissionId : ($orderId > 0 ? $orderId : $jobOrderId)
            );
            if ((int)$notificationId > 0) $created++;
        }
        payment_verification_log('notification_inserted', [
            'submission_id' => $submissionId,
            'order_id' => $orderId,
            'job_order_id' => $jobOrderId,
            'branch_id' => $branchId,
            'staff_notifications' => $created,
        ]);
    }
}

if (!function_exists('payment_verification_resolve_proof')) {
    function payment_verification_resolve_proof(int $orderId, int $jobOrderId = 0): ?array {
        if (!payment_verification_ensure_schema()) return null;

        // 1. Try to find the latest payment submission record
        $submission = null;
        if ($jobOrderId > 0) {
            $rows = db_query(
                "SELECT * FROM payment_submissions
                 WHERE (job_order_id = ? OR (order_id IS NOT NULL AND order_id = ?))
                   AND verification_status <> 'Rejected'
                 ORDER BY id DESC LIMIT 1",
                'ii',
                [$jobOrderId, $orderId]
            );
            if (!empty($rows)) {
                $submission = $rows[0];
            }
        }
        
        if (!$submission && $orderId > 0) {
            $rows = db_query(
                "SELECT * FROM payment_submissions
                 WHERE order_id = ? AND verification_status <> 'Rejected'
                 ORDER BY id DESC LIMIT 1",
                'i',
                [$orderId]
            );
            if (!empty($rows)) {
                $submission = $rows[0];
            }
        }

        if ($submission) {
            $proofPath = (string)$submission['receipt_file'];
            $submittedAmount = $submission['amount_sent'] !== null 
                ? (float)$submission['amount_sent'] 
                : ($submission['ocr_amount_sent'] !== null ? (float)$submission['ocr_amount_sent'] : null);
            
            // If the amount is still null, default to the submitted amount or expected amount if it is matched,
            // but we must check if ocr_status is completed.
            if ($submittedAmount === null && ($submission['ocr_status'] === 'Completed' || $submission['ocr_status'] === 'Failed' || $submission['ocr_status'] === 'Unavailable')) {
                // If OCR ran but couldn't detect amount, return null (which translates to 'Not detected' in UI)
                // but if they entered a manual correction or we have submitted_amount, return that.
                if ((float)$submission['submitted_amount'] > 0) {
                    $submittedAmount = (float)$submission['submitted_amount'];
                }
            } elseif ($submittedAmount === null) {
                // OCR still pending/processing
                $submittedAmount = null;
            }

            return [
                'payment_proof_path'        => $proofPath,
                'payment_submitted_amount'  => $submittedAmount,
                'payment_proof_uploaded_at' => $submission['created_at'],
                'submission_id'             => (int)$submission['id'],
                'ocr_status'                => $submission['ocr_status'],
                'ocr_error'                 => $submission['ocr_error'],
                'verification_status'       => $submission['verification_status'],
                'source'                    => 'payment_submissions',
            ];
        }

        // 2. Fall back to legacy fields in orders table
        if ($orderId > 0) {
            $rows = db_query(
                "SELECT payment_proof_path, payment_submitted_amount, payment_proof_uploaded_at, total_amount, downpayment_amount
                 FROM orders WHERE order_id = ? LIMIT 1",
                'i',
                [$orderId]
            );
            if (!empty($rows) && trim((string)($rows[0]['payment_proof_path'] ?? '')) !== '') {
                $row = $rows[0];
                $amt = $row['payment_submitted_amount'] !== null ? (float)$row['payment_submitted_amount'] : null;
                if ($amt === null || $amt <= 0) {
                    $amt = (float)($row['downpayment_amount'] ?? $row['total_amount'] ?? 0);
                }
                return [
                    'payment_proof_path'        => (string)$row['payment_proof_path'],
                    'payment_submitted_amount'  => $amt > 0 ? $amt : null,
                    'payment_proof_uploaded_at' => $row['payment_proof_uploaded_at'],
                    'submission_id'             => null,
                    'ocr_status'                => 'Completed',
                    'ocr_error'                 => null,
                    'verification_status'       => 'Pending Review',
                    'source'                    => 'orders',
                ];
            }
        }

        // 3. Fall back to legacy fields in job_orders table
        if ($jobOrderId > 0) {
            $rows = db_query(
                "SELECT payment_proof_path, payment_submitted_amount, payment_proof_uploaded_at, estimated_total
                 FROM job_orders WHERE id = ? LIMIT 1",
                'i',
                [$jobOrderId]
            );
            if (!empty($rows) && trim((string)($rows[0]['payment_proof_path'] ?? '')) !== '') {
                $row = $rows[0];
                $amt = $row['payment_submitted_amount'] !== null ? (float)$row['payment_submitted_amount'] : null;
                if ($amt === null || $amt <= 0) {
                    $amt = (float)($row['estimated_total'] ?? 0);
                }
                return [
                    'payment_proof_path'        => (string)$row['payment_proof_path'],
                    'payment_submitted_amount'  => $amt > 0 ? $amt : null,
                    'payment_proof_uploaded_at' => $row['payment_proof_uploaded_at'],
                    'submission_id'             => null,
                    'ocr_status'                => 'Completed',
                    'ocr_error'                 => null,
                    'verification_status'       => 'Pending Review',
                    'source'                    => 'job_orders',
                ];
            }
        }

        return null;
    }
}
