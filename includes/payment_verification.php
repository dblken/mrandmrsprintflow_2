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
            receipt_file VARCHAR(500) NOT NULL,
            receipt_thumbnail VARCHAR(500) DEFAULT NULL,
            receipt_original_name VARCHAR(255) DEFAULT NULL,
            receipt_mime VARCHAR(100) DEFAULT NULL,
            receipt_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            receipt_sha256 CHAR(64) DEFAULT NULL,
            selected_payment_method VARCHAR(80) DEFAULT NULL,
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
            KEY idx_payment_submissions_queue (ocr_status, created_at),
            KEY idx_payment_submissions_review (verification_status, created_at),
            KEY idx_payment_submissions_reference (reference_normalized),
            KEY idx_payment_submissions_hash (receipt_sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ready = (bool)db_execute($sql);
        if ($ready) {
            $ready = !empty(db_query("SHOW TABLES LIKE 'payment_submissions'"));
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
        if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = $mime === 'image/png' ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
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
            return ['success' => false, 'error' => 'Please select a receipt image or PDF.'];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
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
            'application/pdf' => 'pdf',
        ];
        if (!isset($extensions[$mime])) {
            return ['success' => false, 'error' => 'Only JPG, JPEG, PNG, and PDF receipts are accepted.'];
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
            return ['success' => false, 'error' => 'The receipt could not be saved.'];
        }
        @chmod($target, 0640);

        $base = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
        $stored = $base . '/uploads/secure_payments/' . $filename;
        $thumbnail = payment_verification_make_thumbnail($target, $mime, $stem);
        return [
            'success' => true,
            'file_path' => $stored,
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
        $sql = "INSERT INTO payment_submissions
            (order_id, job_order_id, customer_id, receipt_file, receipt_thumbnail,
             receipt_original_name, receipt_mime, receipt_size, receipt_sha256,
             selected_payment_method, expected_amount, submitted_amount,
             verification_status, ocr_status)
            VALUES (NULLIF(?, 0), NULLIF(?, 0), ?, ?, NULLIF(?, ''), NULLIF(?, ''),
                    NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?)";
        $ok = db_execute($sql, 'iiissssissddss', [
            $orderId,
            $jobOrderId,
            $customerId,
            $receipt,
            trim((string)($data['receipt_thumbnail'] ?? '')),
            trim((string)($data['receipt_original_name'] ?? '')),
            trim((string)($data['receipt_mime'] ?? '')),
            max(0, (int)($data['receipt_size'] ?? 0)),
            trim((string)($data['receipt_sha256'] ?? '')),
            payment_verification_normalize_method((string)($data['selected_payment_method'] ?? '')),
            $expected,
            max(0, (float)($data['submitted_amount'] ?? 0)),
            (string)($data['verification_status'] ?? 'Pending Review'),
            (string)($data['ocr_status'] ?? 'Pending'),
        ]);
        if (!$ok) return 0;
        global $conn;
        return (int)($conn->insert_id ?? 0);
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

if (!function_exists('payment_ocr_with_tesseract')) {
    function payment_ocr_with_tesseract(string $filePath, string $mime): array {
        if ($mime === 'application/pdf') {
            return ['success' => false, 'unavailable' => true, 'error' => 'Local OCR cannot process PDF receipts without a PDF renderer.'];
        }
        if (!payment_ocr_tesseract_available()) {
            return ['success' => false, 'unavailable' => true, 'error' => 'Tesseract is not installed or configured.'];
        }
        $language = payment_verification_env('PAYMENT_OCR_LANGUAGE', 'eng');
        $language = preg_replace('/[^a-zA-Z0-9_+-]/', '', $language) ?: 'eng';
        $result = payment_ocr_run_process([
            payment_ocr_tesseract_binary(), $filePath, 'stdout', '-l', $language,
            '--psm', '6', 'tsv'
        ], 50);
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
        $amountMatch = ($amount === null || $expected <= 0)
            ? 'Unknown'
            : (abs($amount - $expected) <= 0.01 ? 'Matched' : 'Mismatch');
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

if (!function_exists('payment_ocr_process_submission')) {
    function payment_ocr_process_submission(int $submissionId, bool $force = false): array {
        if ($submissionId <= 0 || !payment_verification_ensure_schema()) {
            return ['success' => false, 'status' => 'Failed'];
        }
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
        if (!$submission) return ['success' => false, 'status' => 'Failed'];
        $filePath = payment_verification_local_file((string)$submission['receipt_file']);
        if ($filePath === null) {
            db_execute(
                "UPDATE payment_submissions SET ocr_status = 'Failed', ocr_error = ?, verification_status = 'Needs Review', ocr_processed_at = NOW() WHERE id = ?",
                'si',
                ['Receipt file is unavailable for OCR.', $submissionId]
            );
            return ['success' => false, 'status' => 'Failed'];
        }
        $mime = trim((string)($submission['receipt_mime'] ?? ''));
        if ($mime === '') $mime = (string)(@mime_content_type($filePath) ?: 'application/octet-stream');
        $ocr = payment_ocr_extract($filePath, $mime);
        if (empty($ocr['success'])) {
            $status = !empty($ocr['unavailable']) ? 'Unavailable' : 'Failed';
            $safeError = mb_substr(trim((string)($ocr['error'] ?? 'OCR could not process this receipt.')), 0, 500);
            db_execute(
                "UPDATE payment_submissions
                 SET ocr_status = ?, ocr_error = ?, verification_status = 'Needs Review', ocr_processed_at = NOW()
                 WHERE id = ?",
                'ssi',
                [$status, $safeError, $submissionId]
            );
            error_log("Payment OCR {$status} for submission #{$submissionId}: {$safeError}");
            return ['success' => false, 'status' => $status, 'verification_status' => 'Needs Review'];
        }

        $rawText = trim((string)$ocr['text']);
        $parsed = payment_ocr_parse_receipt_text(
            $rawText,
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

        $params = [
            $parsed['sender_name'], $parsed['reference_number'], $normalizedReference,
            $parsed['amount_sent'] === null ? null : number_format((float)$parsed['amount_sent'], 2, '.', ''),
            $parsed['detected_payment_method'], $parsed['transaction_date'], $parsed['transaction_time'],
            $parsed['receiver_name'], $parsed['receiver_account'], $rawText,
            $parsed['overall_confidence'], $parsed['sender_confidence'], $parsed['reference_confidence'],
            $parsed['amount_confidence'], $parsed['method_confidence'], $parsed['date_confidence'],
            $parsed['receiver_confidence'], (string)$ocr['provider'], $expected,
            $state['amount_match_status'], $state['method_match_status'], $duplicateId > 0 ? $duplicateId : null,
            $state['verification_status'], $submissionId,
        ];
        $ok = db_execute(
            "UPDATE payment_submissions SET
                ocr_sender_name = NULLIF(?, ''), ocr_reference_number = NULLIF(?, ''), reference_normalized = NULLIF(?, ''),
                ocr_amount_sent = ?, ocr_detected_payment_method = NULLIF(?, ''),
                ocr_transaction_date = ?, ocr_transaction_time = ?,
                ocr_receiver_name = NULLIF(?, ''), ocr_receiver_account = NULLIF(?, ''), raw_ocr_text = ?,
                overall_confidence = ?, sender_confidence = ?, reference_confidence = ?, amount_confidence = ?,
                method_confidence = ?, date_confidence = ?, receiver_confidence = ?,
                ocr_status = 'Completed', ocr_provider = ?, ocr_error = NULL, ocr_processed_at = NOW(),
                expected_amount = ?, amount_match_status = ?, method_match_status = ?,
                duplicate_submission_id = ?, verification_status = ?
             WHERE id = ?",
            str_repeat('s', 23) . 'i',
            $params
        );
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
            'ssssssssiii',
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

