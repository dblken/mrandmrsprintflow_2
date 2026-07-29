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
        return $mode === 'test' || strpos($secretKey, 'sk_test_') === 0;
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
            return ['ok' => false, 'test_mode' => printflow_paymongo_test_mode()];
        }

        $secretKey = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        if ($secretKey === '') {
            return ['ok' => false, 'test_mode' => printflow_paymongo_test_mode()];
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
        ];

        if ($payload !== null) {
            $body = json_encode($payload);
            if ($body === false) {
                return ['ok' => false, 'test_mode' => printflow_paymongo_test_mode()];
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

        $decoded = is_string($response) ? json_decode($response, true) : null;
        return [
            'ok' => $curlOk && $httpCode >= 200 && $httpCode < 300,
            'test_mode' => printflow_paymongo_test_mode(),
            'livemode' => is_array($decoded) ? (bool)($decoded['data']['livemode'] ?? true) : true,
        ];
    }
}

if (!function_exists('printflow_paymongo_test_api_request')) {
    function printflow_paymongo_test_api_request(): array {
        return printflow_paymongo_request('GET', '/v1/payment_links?limit=1');
    }
}

if (!function_exists('printflow_paymongo_create_test_payment_link')) {
    function printflow_paymongo_create_test_payment_link(): array {
        $secretKey = printflow_paymongo_env('PAYMONGO_SECRET_KEY');
        $safeTestKey = strpos($secretKey, 'sk_test_') === 0;
        if (!printflow_paymongo_test_mode() || !$safeTestKey) {
            return ['ok' => false, 'test_mode' => printflow_paymongo_test_mode(), 'livemode' => true];
        }

        return printflow_paymongo_request('POST', '/v1/payment_links', [
            'amount' => 100,
            'currency' => 'PHP',
            'description' => 'PrintFlow diagnostic test payment link',
            'remarks' => 'Diagnostic test only. Not tied to any live order.',
            'metadata' => [
                'source' => 'printflow_diagnostic',
                'live_order' => 'false',
            ],
            'restrictions' => [
                'completed_sessions' => [
                    'limit' => 1,
                ],
            ],
        ]);
    }
}
