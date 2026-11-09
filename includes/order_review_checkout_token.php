<?php
/**
 * Customer order review checkout fingerprint + session token helpers.
 */

if (!function_exists('review_checkout_token_is_valid')) {
    function review_checkout_token_is_valid(string $token): bool {
        return (bool)preg_match('/^[a-f0-9]{64}$/', strtolower(trim($token)));
    }
}

if (!function_exists('review_checkout_fingerprint_line')) {
    function review_checkout_fingerprint_line(array $item): array {
        return [
            'product_id' => (int)($item['product_id'] ?? 0),
            'variant_id' => (int)($item['variant_id'] ?? 0),
            'quantity' => review_item_quantity($item),
            'branch_id' => (int)($item['branch_id'] ?? 0),
            'unit_price' => (int)round(review_item_unit_price($item) * 100),
            'design_fee' => (int)round((float)($item['design_fee'] ?? 0) * 100),
            'line_total' => (int)round(review_item_estimated_total($item) * 100),
            'customization' => review_item_customization($item),
        ];
    }
}

if (!function_exists('review_build_checkout_fingerprint')) {
    function review_build_checkout_fingerprint(int $customerId, array $payloadByKey): string {
        ksort($payloadByKey);
        return hash('sha256', json_encode([
            'customer_id' => $customerId,
            'items' => $payloadByKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('review_checkout_attempt_storage_key')) {
    function review_checkout_attempt_storage_key(array $itemKeys): string {
        $keys = array_values(array_filter(array_map('strval', $itemKeys), static fn(string $key): bool => $key !== ''));
        sort($keys);
        return hash('sha256', implode("\0", $keys));
    }
}

if (!function_exists('review_ensure_checkout_token')) {
    function review_ensure_checkout_token(int $customerId, string $fingerprint, string $attemptKey): string {
        if (!isset($_SESSION['order_review_checkout_tokens']) || !is_array($_SESSION['order_review_checkout_tokens'])) {
            $_SESSION['order_review_checkout_tokens'] = [];
        }
        if (!isset($_SESSION['order_review_checkout_attempts']) || !is_array($_SESSION['order_review_checkout_attempts'])) {
            $_SESSION['order_review_checkout_attempts'] = [];
        }
        $attempt = is_array($_SESSION['order_review_checkout_attempts'][$attemptKey] ?? null)
            ? $_SESSION['order_review_checkout_attempts'][$attemptKey]
            : null;
        $token = strtolower(trim((string)($attempt['token'] ?? ($_SESSION['order_review_checkout_tokens'][$fingerprint] ?? ''))));
        if (!review_checkout_token_is_valid($token)) {
            $token = bin2hex(random_bytes(32));
        }
        $_SESSION['order_review_checkout_tokens'][$fingerprint] = $token;
        $_SESSION['order_review_checkout_attempts'][$attemptKey] = [
            'fingerprint' => $fingerprint,
            'token' => $token,
            'updated_at' => time(),
        ];
        return $token;
    }
}

if (!function_exists('review_checkout_token_accept_for_attempt')) {
    function review_checkout_token_accept_for_attempt(
        string $postedToken,
        string $expectedToken,
        string $attemptKey,
        string $fingerprint
    ): bool {
        if (!review_checkout_token_is_valid($postedToken)) {
            return false;
        }
        $postedToken = strtolower(trim($postedToken));
        $expectedToken = strtolower(trim($expectedToken));
        if (hash_equals($expectedToken, $postedToken)) {
            return true;
        }
        if (!isset($_SESSION['order_review_checkout_attempts'][$attemptKey])
            || !is_array($_SESSION['order_review_checkout_attempts'][$attemptKey])) {
            return false;
        }
        $attempt = $_SESSION['order_review_checkout_attempts'][$attemptKey];
        $attemptToken = strtolower(trim((string)($attempt['token'] ?? '')));
        if (!hash_equals($attemptToken, $postedToken)) {
            return false;
        }
        $_SESSION['order_review_checkout_tokens'][$fingerprint] = $postedToken;
        $_SESSION['order_review_checkout_attempts'][$attemptKey] = [
            'fingerprint' => $fingerprint,
            'token' => $postedToken,
            'updated_at' => time(),
        ];
        return true;
    }
}
