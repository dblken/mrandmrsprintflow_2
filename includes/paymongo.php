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
            'test_public_key_set' => printflow_paymongo_env('PAYMONGO_TEST_PUBLIC_KEY') !== '',
            'live_public_key_set' => printflow_paymongo_env('PAYMONGO_LIVE_PUBLIC_KEY') !== '',
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

if (!function_exists('printflow_paymongo_request')) {
    function printflow_paymongo_request(
        string $method,
        string $path,
        ?array $payload = null,
        string $mode = '',
        string $idempotencyKey = ''
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

        $secretKey = printflow_paymongo_secret_key_for_mode($mode);
        if ($secretKey === '') {
            return printflow_paymongo_failure(
                'The PayMongo secret key is not configured for this environment.',
                400,
                'secret_key_missing',
                $mode
            );
        }

        $url = printflow_paymongo_api_url() . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
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
