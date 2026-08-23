<?php
/**
 * PayMongo helpers. These functions never return or log secret values.
 */

require_once __DIR__ . '/env.php';

if (!function_exists('printflow_paymongo_env')) {
    function printflow_paymongo_env(string $name): string {
        $value = printflow_env($name);
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('printflow_paymongo_api_url')) {
    function printflow_paymongo_api_url(): string {
        $configured = rtrim(printflow_paymongo_env('PAYMONGO_API_URL'), '/');
        return $configured !== '' ? $configured : 'https://api.paymongo.com';
    }
}

if (!function_exists('printflow_paymongo_secret_key_for_mode')) {
    /**
     * Resolve a server-side key without ever returning the other environment's
     * credential. Legacy PAYMONGO_SECRET_KEY remains a safe alias when its
     * prefix matches the requested mode.
     */
    function printflow_paymongo_secret_key_for_mode(string $mode): string {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['test', 'live'], true)) {
            return '';
        }

        $specific = printflow_paymongo_env(
            $mode === 'live' ? 'PAYMONGO_LIVE_SECRET_KEY' : 'PAYMONGO_TEST_SECRET_KEY'
        );
        $expectedPrefix = $mode === 'live' ? 'sk_live_' : 'sk_test_';
        if ($specific !== '' && str_starts_with($specific, $expectedPrefix)) {
            return $specific;
        }

        $legacy = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        return $legacy !== '' && str_starts_with($legacy, $expectedPrefix) ? $legacy : '';
    }
}

if (!function_exists('printflow_paymongo_public_key_for_mode')) {
    function printflow_paymongo_public_key_for_mode(string $mode): string {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['test', 'live'], true)) {
            return '';
        }

        $specific = printflow_paymongo_env(
            $mode === 'live' ? 'PAYMONGO_LIVE_PUBLIC_KEY' : 'PAYMONGO_TEST_PUBLIC_KEY'
        );
        $expectedPrefix = $mode === 'live' ? 'pk_live_' : 'pk_test_';
        if ($specific !== '' && str_starts_with($specific, $expectedPrefix)) {
            return $specific;
        }

        $legacy = printflow_paymongo_env('PAYMONGO_PUBLIC_KEY');
        return $legacy !== '' && str_starts_with($legacy, $expectedPrefix) ? $legacy : '';
    }
}

if (!function_exists('printflow_paymongo_live_enabled')) {
    function printflow_paymongo_live_enabled(): bool {
        $value = strtolower(printflow_paymongo_env('PAYMONGO_LIVE_ENABLED'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('printflow_paymongo_mode')) {
    /** Current link-creation mode. Existing ledger rows always retain their own mode. */
    function printflow_paymongo_mode(): string {
        $mode = strtolower(printflow_paymongo_env('PAYMONGO_MODE'));
        if (!in_array($mode, ['test', 'live'], true)) {
            return '';
        }
        if ($mode === 'live' && !printflow_paymongo_live_enabled()) {
            return '';
        }
        return printflow_paymongo_secret_key_for_mode($mode) !== '' ? $mode : '';
    }
}

if (!function_exists('printflow_paymongo_test_mode')) {
    function printflow_paymongo_test_mode(): bool {
        return printflow_paymongo_mode() === 'test';
    }
}

if (!function_exists('printflow_paymongo_live_mode')) {
    function printflow_paymongo_live_mode(): bool {
        return printflow_paymongo_mode() === 'live';
    }
}

if (!function_exists('printflow_paymongo_api_url_is_safe')) {
    function printflow_paymongo_api_url_is_safe(): bool {
        $parts = parse_url(printflow_paymongo_api_url());
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && strtolower((string)($parts['host'] ?? '')) === 'api.paymongo.com'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || (int)$parts['port'] === 443)
            && in_array((string)($parts['path'] ?? ''), ['', '/'], true);
    }
}

if (!function_exists('printflow_paymongo_safe_api_text')) {
    function printflow_paymongo_safe_api_text($value, string $fallback): string {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return $fallback;
        }

        foreach ([
            'PAYMONGO_PUBLIC_KEY', 'PAYMONGO_SECRET_KEY', 'PAYMONGO_WEBHOOK_SECRET',
            'PAYMONGO_TEST_PUBLIC_KEY', 'PAYMONGO_TEST_SECRET_KEY', 'PAYMONGO_TEST_WEBHOOK_SECRET',
            'PAYMONGO_LIVE_PUBLIC_KEY', 'PAYMONGO_LIVE_SECRET_KEY', 'PAYMONGO_LIVE_WEBHOOK_SECRET',
        ] as $name) {
            $credential = printflow_paymongo_env($name);
            if ($credential !== '') {
                $text = str_replace($credential, '[redacted]', $text);
            }
        }

        $text = (string) preg_replace(
            '/\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9_-]+\b/i',
            '[redacted]',
            $text
        );
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        return substr(trim($text), 0, 500);
    }
}

if (!function_exists('printflow_paymongo_failure')) {
    function printflow_paymongo_failure(
        string $message,
        int $httpStatus,
        string $errorCode,
        string $mode = ''
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        return [
            'ok' => false,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'message' => $message,
            'livemode' => $mode === 'live',
        ];
    }
}

if (!function_exists('printflow_paymongo_diagnostic_flags')) {
    function printflow_paymongo_diagnostic_flags(): array {
        return [
            'public_key_set' => printflow_paymongo_env('PAYMONGO_PUBLIC_KEY') !== '',
            'test_public_key_set' => printflow_paymongo_public_key_for_mode('test') !== '',
            'live_public_key_set' => printflow_paymongo_public_key_for_mode('live') !== '',
            'secret_key_set' => printflow_paymongo_env('PAYMONGO_SECRET_KEY') !== '',
            'test_secret_key_set' => printflow_paymongo_secret_key_for_mode('test') !== '',
            'live_secret_key_set' => printflow_paymongo_secret_key_for_mode('live') !== '',
            'api_url_set' => printflow_paymongo_env('PAYMONGO_API_URL') !== '',
            'mode' => printflow_paymongo_mode(),
            'test_mode' => printflow_paymongo_test_mode(),
            'live_mode' => printflow_paymongo_live_mode(),
            'live_enabled' => printflow_paymongo_live_enabled(),
            'curl_available' => function_exists('curl_init'),
        ];
    }
}

if (!function_exists('printflow_paymongo_safe_metadata')) {
    function printflow_paymongo_safe_metadata(array $metadata): array {
        $safeMetadata = [];
        foreach ($metadata as $key => $value) {
            $safeKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$key);
            if ($safeKey === '') {
                continue;
            }
            $safeMetadata[substr($safeKey, 0, 40)] = substr((string)$value, 0, 255);
        }
        return $safeMetadata;
    }
}

if (!function_exists('printflow_paymongo_normalize_payment_intent')) {
    function printflow_paymongo_normalize_payment_intent(array $data, string $mode, int $httpCode): array {
        $attributes = isset($data['attributes']) && is_array($data['attributes'])
            ? $data['attributes']
            : [];
        $candidateId = trim((string)($data['id'] ?? ''));
        $paymentIds = [];
        foreach (($attributes['payments'] ?? []) as $payment) {
            if (is_string($payment)) {
                $paymentId = trim($payment);
            } elseif (is_array($payment)) {
                $paymentId = trim((string)($payment['id'] ?? $payment['data']['id'] ?? ''));
            } else {
                continue;
            }
            if (preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId)) {
                $paymentIds[] = $paymentId;
            }
        }
        $nextAction = isset($attributes['next_action']) && is_array($attributes['next_action'])
            ? $attributes['next_action']
            : [];
        $code = isset($nextAction['code']) && is_array($nextAction['code'])
            ? $nextAction['code']
            : [];
        $imageUrl = trim((string)($code['image_url'] ?? ''));
        if (!preg_match('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=\r\n]+$#', $imageUrl)
            || strlen($imageUrl) > 2000000) {
            $imageUrl = '';
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpCode,
            'livemode' => (bool)($attributes['livemode'] ?? ($mode === 'live')),
            'id' => preg_match('/^pi_[A-Za-z0-9_-]+$/', $candidateId) ? $candidateId : '',
            'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
            'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
            'status' => strtolower(trim((string)($attributes['status'] ?? ''))),
            'client_key' => substr(trim((string)($attributes['client_key'] ?? '')), 0, 255),
            'payment_ids' => array_values(array_unique($paymentIds)),
            'payment_id' => (string)(end($paymentIds) ?: ''),
            'qr_image_url' => $imageUrl,
            'metadata' => isset($attributes['metadata']) && is_array($attributes['metadata'])
                ? $attributes['metadata']
                : [],
        ];
    }
}

if (!function_exists('printflow_paymongo_normalize_payment_method')) {
    function printflow_paymongo_normalize_payment_method(array $data, string $mode, int $httpCode): array {
        $attributes = isset($data['attributes']) && is_array($data['attributes'])
            ? $data['attributes']
            : [];
        $candidateId = trim((string)($data['id'] ?? ''));
        $type = strtolower(trim((string)($attributes['type'] ?? '')));
        return [
            'ok' => true,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpCode,
            'livemode' => (bool)($attributes['livemode'] ?? ($mode === 'live')),
            'id' => preg_match('/^pm_[A-Za-z0-9_-]+$/', $candidateId) ? $candidateId : '',
            'type' => preg_match('/^[a-z0-9_-]{2,30}$/', $type) ? $type : '',
        ];
    }
}

if (!function_exists('printflow_paymongo_normalize_payment')) {
    function printflow_paymongo_normalize_payment(array $data, string $mode, int $httpCode): array {
        $attributes = isset($data['attributes']) && is_array($data['attributes'])
            ? $data['attributes']
            : [];
        $candidateId = trim((string)($data['id'] ?? ''));
        $source = isset($attributes['source']) && is_array($attributes['source'])
            ? $attributes['source']
            : [];
        $method = strtolower(trim((string)(
            $attributes['payment_method_used'] ?? $source['type'] ?? ''
        )));
        $paymentMethodId = trim((string)(
            $attributes['payment_method_id']
            ?? $attributes['payment_method']['id']
            ?? $attributes['payment_method']['data']['id']
            ?? ''
        ));
        $paidAt = $attributes['paid_at'] ?? null;
        if (is_numeric($paidAt)) {
            $paidAt = gmdate('Y-m-d H:i:s', (int)$paidAt);
        } elseif (is_string($paidAt) && $paidAt !== '') {
            $paidAt = substr(str_replace('T', ' ', (string)preg_replace('/(?:\.\d+)?Z$/', '', $paidAt)), 0, 19);
        } else {
            $paidAt = null;
        }
        $status = strtolower(trim((string)($attributes['status'] ?? '')));
        return [
            'ok' => true,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpCode,
            'livemode' => (bool)($attributes['livemode'] ?? ($mode === 'live')),
            'paid' => $status === 'paid',
            'payment_id' => preg_match('/^pay_[A-Za-z0-9_-]+$/', $candidateId) ? $candidateId : '',
            'payment_intent_id' => preg_match(
                '/^pi_[A-Za-z0-9_-]+$/',
                (string)($attributes['payment_intent_id'] ?? '')
            ) ? (string)$attributes['payment_intent_id'] : '',
            'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
            'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
            'status' => $status,
            'payment_method' => preg_match('/^[a-z0-9_-]{2,30}$/', $method) ? $method : '',
            'payment_method_id' => preg_match('/^pm_[A-Za-z0-9_-]+$/', $paymentMethodId)
                ? $paymentMethodId
                : '',
            'failure_code' => substr((string)preg_replace('/[^a-z0-9_-]/i', '', (string)(
                $attributes['failure_code']
                ?? $attributes['last_payment_error']['code']
                ?? $attributes['last_payment_error']['failed_code']
                ?? 'payment_failed'
            )), 0, 100),
            'reference_number' => substr(trim((string)(
                $attributes['external_reference_number'] ?? $attributes['reference_number'] ?? ''
            )), 0, 100),
            'provider_paid_at' => $paidAt,
            'metadata' => isset($attributes['metadata']) && is_array($attributes['metadata'])
                ? $attributes['metadata']
                : [],
        ];
    }
}

if (!function_exists('printflow_paymongo_checkout_url_is_safe')) {
    function printflow_paymongo_checkout_url_is_safe(string $candidateUrl): bool {
        $candidateUrl = trim($candidateUrl);
        $urlParts = $candidateUrl !== '' ? parse_url($candidateUrl) : false;
        return filter_var($candidateUrl, FILTER_VALIDATE_URL)
            && is_array($urlParts)
            && strtolower((string)($urlParts['scheme'] ?? '')) === 'https'
            && in_array(
                strtolower((string)($urlParts['host'] ?? '')),
                ['pm.link', 'checkout.paymongo.com'],
                true
            );
    }
}

if (!function_exists('printflow_paymongo_normalize_payment_link')) {
    /**
     * Normalize both the current flat /v1/payment_links response and the
     * legacy nested Link shape. Checkout URLs remain restricted to PayMongo.
     */
    function printflow_paymongo_normalize_payment_link(array $data, string $mode, int $httpCode): array {
        $attributes = isset($data['attributes']) && is_array($data['attributes'])
            ? $data['attributes']
            : $data;
        $candidateUrl = trim((string)($attributes['url'] ?? $attributes['checkout_url'] ?? ''));
        $url = printflow_paymongo_checkout_url_is_safe($candidateUrl)
            ? $candidateUrl
            : '';
        $candidateId = trim((string)($data['id'] ?? ''));
        $candidateStatus = strtolower(trim((string)($attributes['status'] ?? '')));
        $status = in_array($candidateStatus, ['active', 'archived'], true)
            ? $candidateStatus
            : ($candidateStatus === 'unpaid' ? 'active' : '');

        return [
            'ok' => true,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpCode,
            'livemode' => (bool)($attributes['livemode'] ?? ($mode === 'live')),
            'id' => preg_match('/^link_[A-Za-z0-9_-]+$/', $candidateId) ? $candidateId : '',
            'url' => $url,
            'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
            'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
            'status' => $status,
            'reference_number' => substr(trim((string)($attributes['reference_number'] ?? '')), 0, 100),
        ];
    }
}

if (!function_exists('printflow_paymongo_request')) {
    function printflow_paymongo_request(
        string $method,
        string $path,
        ?array $payload = null,
        string $mode = '',
        string $idempotencyKey = '',
        string $keyType = 'secret'
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if ($mode === '' || ($mode === 'live' && !printflow_paymongo_live_enabled())) {
            return printflow_paymongo_failure(
                'PayMongo is not configured for the requested environment.',
                400,
                'payment_mode_unavailable',
                $mode
            );
        }
        if (!function_exists('curl_init')) {
            return printflow_paymongo_failure('cURL is not available.', 503, 'curl_unavailable', $mode);
        }

        if (!printflow_paymongo_api_url_is_safe()) {
            return printflow_paymongo_failure(
                'The PayMongo API URL is not configured safely.',
                400,
                'invalid_api_url',
                $mode
            );
        }

        $keyType = strtolower(trim($keyType));
        $apiKey = $keyType === 'public'
            ? printflow_paymongo_public_key_for_mode($mode)
            : printflow_paymongo_secret_key_for_mode($mode);
        if ($apiKey === '') {
            return printflow_paymongo_failure(
                'The required PayMongo API key is not configured for this environment.',
                400,
                $keyType === 'public' ? 'public_key_missing' : 'secret_key_missing',
                $mode
            );
        }

        $url = printflow_paymongo_api_url() . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($apiKey . ':'),
        ];
        $idempotencyKey = trim($idempotencyKey);
        if (strtoupper($method) === 'POST' && $idempotencyKey !== '') {
            $idempotencyKey = substr((string)preg_replace('/[^A-Za-z0-9_.:-]/', '-', $idempotencyKey), 0, 255);
            if ($idempotencyKey !== '') {
                $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
            }
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($payload !== null) {
            $body = json_encode($payload);
            if ($body === false) {
                return printflow_paymongo_failure(
                    'The PayMongo request could not be encoded.',
                    500,
                    'request_encoding_failed',
                    $mode
                );
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlOk = $response !== false;
        curl_close($ch);

        if (!$curlOk) {
            return printflow_paymongo_failure(
                'The PayMongo API request could not be completed.',
                502,
                'request_failed',
                $mode
            );
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            return printflow_paymongo_failure(
                'PayMongo returned an invalid response.',
                $httpCode > 0 ? $httpCode : 502,
                'invalid_response',
                $mode
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $error = isset($decoded['errors'][0]) && is_array($decoded['errors'][0])
                ? $decoded['errors'][0]
                : [];
            return printflow_paymongo_failure(
                printflow_paymongo_safe_api_text(
                    $error['detail'] ?? '',
                    'PayMongo rejected the request.'
                ),
                $httpCode > 0 ? $httpCode : 502,
                printflow_paymongo_safe_api_text(
                    $error['code'] ?? '',
                    'paymongo_request_failed'
                ),
                $mode
            );
        }

        $data = isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : [];
        if (preg_match('#^/v1/payment_intents(?:/pi_[A-Za-z0-9_-]+(?:/(?:attach|cancel))?)?$#', $path)) {
            return printflow_paymongo_normalize_payment_intent($data, $mode, $httpCode);
        }
        if (preg_match('#^/v1/payment_methods(?:/pm_[A-Za-z0-9_-]+)?$#', $path)) {
            return printflow_paymongo_normalize_payment_method($data, $mode, $httpCode);
        }
        if (preg_match('#^/v1/payments/pay_[A-Za-z0-9_-]+$#', $path)) {
            return printflow_paymongo_normalize_payment($data, $mode, $httpCode);
        }
        if ($path === '/v1/payment_links'
            || preg_match('#^/v1/payment_links/link_[A-Za-z0-9_-]+$#', $path)) {
            return printflow_paymongo_normalize_payment_link($data, $mode, $httpCode);
        }
        if (preg_match('#^/v1/payment_links/link_[A-Za-z0-9_-]+/payments(?:\?.*)?$#', $path)) {
            $paidPayment = [];
            $paidPaymentData = [];
            foreach ($data as $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $paymentData = isset($payment['data']) && is_array($payment['data'])
                    ? $payment['data']
                    : $payment;
                $attributes = isset($paymentData['attributes']) && is_array($paymentData['attributes'])
                    ? $paymentData['attributes']
                    : (isset($payment['attributes']) && is_array($payment['attributes'])
                        ? $payment['attributes']
                        : $paymentData);
                if (strtolower(trim((string)($attributes['status'] ?? ''))) === 'paid') {
                    $paidPayment = $payment;
                    $paidPaymentData = $paymentData;
                    break;
                }
            }
            $paymentData = $paidPaymentData !== []
                ? $paidPaymentData
                : (isset($paidPayment['data']) && is_array($paidPayment['data'])
                    ? $paidPayment['data']
                    : $paidPayment);
            $attributes = isset($paymentData['attributes']) && is_array($paymentData['attributes'])
                ? $paymentData['attributes']
                : (isset($paidPayment['attributes']) && is_array($paidPayment['attributes'])
                    ? $paidPayment['attributes']
                    : $paymentData);
            $paymentId = trim((string)($paymentData['id'] ?? $paidPayment['id'] ?? ''));
            $source = isset($attributes['source']) && is_array($attributes['source'])
                ? $attributes['source']
                : [];
            $sourceAttributes = isset($source['attributes']) && is_array($source['attributes'])
                ? $source['attributes']
                : (isset($source['data']['attributes']) && is_array($source['data']['attributes'])
                    ? $source['data']['attributes']
                    : $source);
            $paymentMethod = strtolower(trim((string)(
                $attributes['payment_method_used']
                ?? $sourceAttributes['type']
                ?? $source['type']
                ?? ''
            )));
            if (!preg_match('/^[a-z0-9_-]{2,30}$/', $paymentMethod)) {
                $paymentMethod = '';
            }

            return [
                'ok' => true,
                'mode' => $mode,
                'test_mode' => $mode === 'test',
                'http_status' => $httpCode,
                'livemode' => (bool)($attributes['livemode'] ?? ($mode === 'live')),
                'paid' => !empty($paidPayment),
                'payment_id' => preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId) ? $paymentId : '',
                'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
                'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
                'status' => strtolower(trim((string)($attributes['status'] ?? ''))),
                'payment_method' => $paymentMethod,
                'reference_number' => substr(trim((string)(
                    $attributes['external_reference_number']
                    ?? $attributes['reference_number']
                    ?? ''
                )), 0, 100),
                'provider_paid_at' => isset($attributes['paid_at']) && is_numeric($attributes['paid_at'])
                    ? gmdate('Y-m-d H:i:s', (int)$attributes['paid_at'])
                    : (isset($attributes['paid_at']) && is_string($attributes['paid_at'])
                        ? substr(str_replace('T', ' ', preg_replace('/(?:\.\d+)?Z$/', '', $attributes['paid_at'])), 0, 19)
                        : null),
            ];
        }
        $candidateUrl = isset($data['url']) && is_string($data['url'])
            ? trim($data['url'])
            : '';
        $urlParts = $candidateUrl !== '' ? parse_url($candidateUrl) : false;
        $url = filter_var($candidateUrl, FILTER_VALIDATE_URL)
            && is_array($urlParts)
            && strtolower((string)($urlParts['scheme'] ?? '')) === 'https'
            && strtolower((string)($urlParts['host'] ?? '')) === 'pm.link'
            ? $candidateUrl
            : '';
        $candidateId = isset($data['id']) && is_string($data['id'])
            ? trim($data['id'])
            : '';
        $id = preg_match('/^link_[A-Za-z0-9_-]+$/', $candidateId)
            ? $candidateId
            : '';
        $candidateStatus = isset($data['status']) && is_string($data['status'])
            ? strtolower(trim($data['status']))
            : '';
        $status = in_array($candidateStatus, ['active', 'archived'], true)
            ? $candidateStatus
            : '';

        return [
            'ok' => true,
            'mode' => $mode,
            'test_mode' => $mode === 'test',
            'http_status' => $httpCode,
            'livemode' => (bool)($data['livemode'] ?? true),
            'id' => $id,
            'url' => $url,
            'amount' => isset($data['amount']) ? (int)$data['amount'] : 0,
            'currency' => isset($data['currency']) && is_string($data['currency'])
                ? strtoupper(substr($data['currency'], 0, 3))
                : '',
            'status' => $status,
            'reference_number' => substr(trim((string)($data['reference_number'] ?? '')), 0, 100),
        ];
    }
}

if (!function_exists('printflow_paymongo_test_api_request')) {
    function printflow_paymongo_test_api_request(): array {
        if (!printflow_paymongo_test_mode()) {
            return printflow_paymongo_failure(
                'PayMongo test mode and a test secret key are required.',
                400,
                'test_mode_required'
            );
        }
        return printflow_paymongo_request('GET', '/v1/payment_links?limit=1', null, 'test');
    }
}

if (!function_exists('printflow_paymongo_create_order_payment_link')) {
    function printflow_paymongo_create_order_payment_link(
        int $amountCentavos,
        string $description,
        string $remarks,
        array $metadata,
        string $mode = '',
        string $idempotencyKey = ''
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if ($mode === '' || printflow_paymongo_secret_key_for_mode($mode) === ''
            || ($mode === 'live' && !printflow_paymongo_live_enabled())) {
            return printflow_paymongo_failure(
                'PayMongo is not configured for the requested environment.',
                400,
                'payment_mode_unavailable',
                $mode
            );
        }
        if ($amountCentavos < 100 || $amountCentavos > 999999999) {
            return printflow_paymongo_failure(
                'The payment amount is outside PayMongo limits.',
                400,
                'invalid_amount',
                $mode
            );
        }

        $safeMetadata = [];
        foreach ($metadata as $key => $value) {
            $safeKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$key);
            if ($safeKey === '') {
                continue;
            }
            $safeMetadata[substr($safeKey, 0, 40)] = substr((string)$value, 0, 255);
        }

        return printflow_paymongo_request('POST', '/v1/payment_links', [
            'amount' => $amountCentavos,
            'currency' => 'PHP',
            'description' => substr(trim($description), 0, 1000),
            'remarks' => substr(trim($remarks), 0, 1000),
            'metadata' => $safeMetadata,
            'restriction' => [
                'completed_sessions' => [
                    'limit' => 1,
                ],
            ],
        ], $mode, $idempotencyKey);
    }
}

if (!function_exists('printflow_paymongo_archive_payment_link')) {
    function printflow_paymongo_archive_payment_link(string $linkId, string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if (!preg_match('/^link_[A-Za-z0-9_-]+$/', $linkId)) {
            return printflow_paymongo_failure(
                'A valid PayMongo Payment Link is required.',
                400,
                'invalid_payment_link',
                $mode
            );
        }
        return printflow_paymongo_request(
            'PATCH',
            '/v1/payment_links/' . rawurlencode($linkId),
            ['archive' => true],
            $mode,
            '',
            'secret'
        );
    }
}

if (!function_exists('printflow_paymongo_enabled_methods')) {
    /**
     * Direct methods are deliberately allowlisted per environment. Test mode
     * defaults to QRPh; live mode must be explicitly enabled after merchant
     * capability confirmation.
     */
    function printflow_paymongo_enabled_methods(string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if ($mode === '' || ($mode === 'live' && !printflow_paymongo_live_enabled())
            || printflow_paymongo_secret_key_for_mode($mode) === ''
            || printflow_paymongo_public_key_for_mode($mode) === ''
            || printflow_paymongo_env(
                $mode === 'live' ? 'PAYMONGO_LIVE_WEBHOOK_SECRET' : 'PAYMONGO_TEST_WEBHOOK_SECRET'
            ) === '') {
            return [];
        }

        $configured = printflow_paymongo_env(
            $mode === 'live' ? 'PAYMONGO_LIVE_DIRECT_METHODS' : 'PAYMONGO_TEST_DIRECT_METHODS'
        );
        if ($configured === '') {
            $configured = printflow_paymongo_env('PAYMONGO_DIRECT_METHODS');
        }
        if ($configured === '' && $mode === 'test') {
            $configured = 'qrph';
        }
        $requested = preg_split('/[\s,]+/', strtolower($configured), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_intersect(['qrph'], array_unique($requested)));
    }
}

if (!function_exists('printflow_paymongo_create_payment_intent')) {
    function printflow_paymongo_create_payment_intent(
        int $amountCentavos,
        string $description,
        array $metadata,
        array $allowedMethods = ['qrph'],
        string $mode = '',
        string $idempotencyKey = ''
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if ($amountCentavos < 100 || $amountCentavos > 999999999) {
            return printflow_paymongo_failure(
                'The payment amount is outside PayMongo limits.',
                400,
                'invalid_amount',
                $mode
            );
        }
        $allowedMethods = array_values(array_unique(array_map(
            static fn($method): string => strtolower(trim((string)$method)),
            $allowedMethods
        )));
        $enabledMethods = printflow_paymongo_enabled_methods($mode);
        if ($allowedMethods === [] || array_diff($allowedMethods, $enabledMethods) !== []) {
            return printflow_paymongo_failure(
                'The requested direct payment method is not enabled for this environment.',
                400,
                'payment_method_unavailable',
                $mode
            );
        }

        return printflow_paymongo_request('POST', '/v1/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                    'currency' => 'PHP',
                    'payment_method_allowed' => $allowedMethods,
                    'description' => substr(trim($description), 0, 255),
                    'metadata' => printflow_paymongo_safe_metadata($metadata),
                ],
            ],
        ], $mode, $idempotencyKey, 'secret');
    }
}

if (!function_exists('printflow_paymongo_create_payment_method')) {
    function printflow_paymongo_create_payment_method(
        string $type,
        array $details = [],
        int $expirySeconds = 1800,
        string $mode = '',
        string $idempotencyKey = ''
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        $type = strtolower(trim($type));
        if ($type !== 'qrph' || !in_array($type, printflow_paymongo_enabled_methods($mode), true)) {
            return printflow_paymongo_failure(
                'The requested direct payment method is not enabled for this environment.',
                400,
                'payment_method_unavailable',
                $mode
            );
        }
        if ($details !== []) {
            return printflow_paymongo_failure(
                'QRPh does not accept customer payment details on the server.',
                400,
                'payment_method_details_not_allowed',
                $mode
            );
        }
        $expirySeconds = max(60, min(9000, $expirySeconds));
        return printflow_paymongo_request('POST', '/v1/payment_methods', [
            'data' => [
                'attributes' => [
                    'type' => 'qrph',
                    'expiry_seconds' => $expirySeconds,
                ],
            ],
        ], $mode, $idempotencyKey, 'public');
    }
}

if (!function_exists('printflow_paymongo_attach_payment_method')) {
    function printflow_paymongo_attach_payment_method(
        string $paymentIntentId,
        string $paymentMethodId,
        string $clientKey,
        string $mode = '',
        string $idempotencyKey = ''
    ): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if (!preg_match('/^pi_[A-Za-z0-9_-]+$/', $paymentIntentId)
            || !preg_match('/^pm_[A-Za-z0-9_-]+$/', $paymentMethodId)
            || !str_starts_with($clientKey, $paymentIntentId . '_client_')
            || strlen($clientKey) > 255) {
            return printflow_paymongo_failure(
                'Valid Payment Intent attachment details are required.',
                400,
                'invalid_payment_attachment',
                $mode
            );
        }
        return printflow_paymongo_request(
            'POST',
            '/v1/payment_intents/' . rawurlencode($paymentIntentId) . '/attach',
            [
                'data' => [
                    'attributes' => [
                        'payment_method' => $paymentMethodId,
                        'client_key' => $clientKey,
                    ],
                ],
            ],
            $mode,
            $idempotencyKey,
            'public'
        );
    }
}

if (!function_exists('printflow_paymongo_get_payment_intent')) {
    function printflow_paymongo_get_payment_intent(string $paymentIntentId, string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if (!preg_match('/^pi_[A-Za-z0-9_-]+$/', $paymentIntentId)) {
            return printflow_paymongo_failure(
                'A valid Payment Intent is required.',
                400,
                'invalid_payment_intent',
                $mode
            );
        }
        return printflow_paymongo_request(
            'GET',
            '/v1/payment_intents/' . rawurlencode($paymentIntentId),
            null,
            $mode,
            '',
            'secret'
        );
    }
}

if (!function_exists('printflow_paymongo_cancel_payment_intent')) {
    function printflow_paymongo_cancel_payment_intent(string $paymentIntentId, string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if (!preg_match('/^pi_[A-Za-z0-9_-]+$/', $paymentIntentId)) {
            return printflow_paymongo_failure(
                'A valid Payment Intent is required.',
                400,
                'invalid_payment_intent',
                $mode
            );
        }
        return printflow_paymongo_request(
            'POST',
            '/v1/payment_intents/' . rawurlencode($paymentIntentId) . '/cancel',
            null,
            $mode,
            '',
            'secret'
        );
    }
}

if (!function_exists('printflow_paymongo_get_payment')) {
    function printflow_paymongo_get_payment(string $paymentId, string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId)) {
            return printflow_paymongo_failure(
                'A valid PayMongo Payment is required.',
                400,
                'invalid_payment',
                $mode
            );
        }
        return printflow_paymongo_request(
            'GET',
            '/v1/payments/' . rawurlencode($paymentId),
            null,
            $mode,
            '',
            'secret'
        );
    }
}

if (!function_exists('printflow_paymongo_get_paid_link_payment')) {
    function printflow_paymongo_get_paid_link_payment(string $linkId, string $mode = ''): array {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : printflow_paymongo_mode();
        if ($mode === '' || printflow_paymongo_secret_key_for_mode($mode) === ''
            || ($mode === 'live' && !printflow_paymongo_live_enabled())
            || !preg_match('/^link_[A-Za-z0-9_-]+$/', $linkId)) {
            return printflow_paymongo_failure(
                'A valid Payment Link and environment are required.',
                400,
                'invalid_payment_link',
                $mode
            );
        }

        return printflow_paymongo_request(
            'GET',
            '/v1/payment_links/' . rawurlencode($linkId) . '/payments',
            null,
            $mode
        );
    }
}

if (!function_exists('printflow_paymongo_create_test_payment_link')) {
    function printflow_paymongo_create_test_payment_link(): array {
        if (!printflow_paymongo_test_mode()) {
            return printflow_paymongo_failure(
                'PAYMONGO_MODE must be set to test with a test secret key.',
                400,
                'test_mode_required',
                'test'
            );
        }
        if (!function_exists('curl_init')) {
            return printflow_paymongo_failure('cURL is not available.', 503, 'curl_unavailable', 'test');
        }

        return printflow_paymongo_request('POST', '/v1/payment_links', [
            'amount' => 10000,
            'currency' => 'PHP',
            'description' => 'PrintFlow isolated test - PHP 100.00',
            'remarks' => 'Diagnostic test only. Not attached to any PrintFlow order.',
            'metadata' => [
                'source' => 'printflow_diagnostic',
                'live_order' => 'false',
            ],
        ], 'test', 'printflow-isolated-diagnostic-test');
    }
}
