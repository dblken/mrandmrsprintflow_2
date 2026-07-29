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

if (!function_exists('printflow_paymongo_test_mode')) {
    function printflow_paymongo_test_mode(): bool {
        $mode = strtolower(printflow_paymongo_env('PAYMONGO_MODE'));
        $secretKey = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        return $mode === 'test' && strpos($secretKey, 'sk_test_') === 0;
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

        foreach (['PAYMONGO_PUBLIC_KEY', 'PAYMONGO_SECRET_KEY'] as $name) {
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
        string $errorCode
    ): array {
        return [
            'ok' => false,
            'test_mode' => printflow_paymongo_test_mode(),
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'message' => $message,
            'livemode' => true,
        ];
    }
}

if (!function_exists('printflow_paymongo_diagnostic_flags')) {
    function printflow_paymongo_diagnostic_flags(): array {
        return [
            'public_key_set' => printflow_paymongo_env('PAYMONGO_PUBLIC_KEY') !== '',
            'secret_key_set' => printflow_paymongo_env('PAYMONGO_SECRET_KEY') !== '',
            'api_url_set' => printflow_paymongo_env('PAYMONGO_API_URL') !== '',
            'test_mode' => printflow_paymongo_test_mode(),
            'curl_available' => function_exists('curl_init'),
        ];
    }
}

if (!function_exists('printflow_paymongo_request')) {
    function printflow_paymongo_request(string $method, string $path, ?array $payload = null): array {
        if (!function_exists('curl_init')) {
            return printflow_paymongo_failure('cURL is not available.', 503, 'curl_unavailable');
        }

        if (!printflow_paymongo_api_url_is_safe()) {
            return printflow_paymongo_failure(
                'The PayMongo API URL is not configured safely.',
                400,
                'invalid_api_url'
            );
        }

        $secretKey = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        if ($secretKey === '') {
            return printflow_paymongo_failure(
                'The PayMongo secret key is not configured.',
                400,
                'secret_key_missing'
            );
        }

        $url = printflow_paymongo_api_url() . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
        ];
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
                    'request_encoding_failed'
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
                'request_failed'
            );
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (!is_array($decoded)) {
            return printflow_paymongo_failure(
                'PayMongo returned an invalid response.',
                $httpCode > 0 ? $httpCode : 502,
                'invalid_response'
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
                )
            );
        }

        $data = isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : [];
        if (preg_match('#^/v1/payment_links/link_[A-Za-z0-9_-]+/payments(?:\?.*)?$#', $path)) {
            $paidPayment = [];
            foreach ($data as $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $attributes = isset($payment['attributes']) && is_array($payment['attributes'])
                    ? $payment['attributes']
                    : $payment;
                if (strtolower(trim((string)($attributes['status'] ?? ''))) === 'paid') {
                    $paidPayment = $payment;
                    break;
                }
            }
            $attributes = isset($paidPayment['attributes']) && is_array($paidPayment['attributes'])
                ? $paidPayment['attributes']
                : $paidPayment;
            $paymentId = trim((string)($paidPayment['id'] ?? ''));

            return [
                'ok' => true,
                'test_mode' => printflow_paymongo_test_mode(),
                'http_status' => $httpCode,
                'livemode' => (bool)($attributes['livemode'] ?? true),
                'paid' => !empty($paidPayment),
                'payment_id' => preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId) ? $paymentId : '',
                'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
                'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
                'status' => strtolower(trim((string)($attributes['status'] ?? ''))),
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
            'test_mode' => printflow_paymongo_test_mode(),
            'http_status' => $httpCode,
            'livemode' => (bool)($data['livemode'] ?? true),
            'id' => $id,
            'url' => $url,
            'amount' => isset($data['amount']) ? (int)$data['amount'] : 0,
            'currency' => isset($data['currency']) && is_string($data['currency'])
                ? strtoupper(substr($data['currency'], 0, 3))
                : '',
            'status' => $status,
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
        return printflow_paymongo_request('GET', '/v1/payment_links?limit=1');
    }
}

if (!function_exists('printflow_paymongo_create_order_payment_link')) {
    function printflow_paymongo_create_order_payment_link(
        int $amountCentavos,
        string $description,
        string $remarks,
        array $metadata
    ): array {
        if (!printflow_paymongo_test_mode()) {
            return printflow_paymongo_failure(
                'PayMongo Test Mode and a test secret key are required.',
                400,
                'test_mode_required'
            );
        }
        if ($amountCentavos < 100 || $amountCentavos > 999999999) {
            return printflow_paymongo_failure(
                'The payment amount is outside PayMongo limits.',
                400,
                'invalid_amount'
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
        ]);
    }
}

if (!function_exists('printflow_paymongo_get_paid_link_payment')) {
    function printflow_paymongo_get_paid_link_payment(string $linkId): array {
        if (!printflow_paymongo_test_mode() || !preg_match('/^link_[A-Za-z0-9_-]+$/', $linkId)) {
            return printflow_paymongo_failure(
                'A valid Test Mode Payment Link is required.',
                400,
                'invalid_test_link'
            );
        }

        return printflow_paymongo_request(
            'GET',
            '/v1/payment_links/' . rawurlencode($linkId) . '/payments'
        );
    }
}

if (!function_exists('printflow_paymongo_create_test_payment_link')) {
    function printflow_paymongo_create_test_payment_link(): array {
        $mode = strtolower(printflow_paymongo_env('PAYMONGO_MODE'));
        $secretKey = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        $safeTestKey = strpos($secretKey, 'sk_test_') === 0;
        if ($mode !== 'test') {
            return printflow_paymongo_failure(
                'PAYMONGO_MODE must be set to test.',
                400,
                'test_mode_required'
            );
        }
        if (!$safeTestKey) {
            return printflow_paymongo_failure(
                'A PayMongo test secret key is required.',
                400,
                'test_key_required'
            );
        }
        if (!function_exists('curl_init')) {
            return printflow_paymongo_failure('cURL is not available.', 503, 'curl_unavailable');
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
        ]);
    }
}
