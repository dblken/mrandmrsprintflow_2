<?php
declare(strict_types=1);

if (!function_exists('printflow_json_error_reference')) {
    function printflow_json_error_reference(): string {
        try {
            return strtoupper(bin2hex(random_bytes(6)));
        } catch (Throwable $e) {
            return strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
        }
    }
}

if (!function_exists('printflow_json_fail_safely')) {
    function printflow_json_fail_safely(string $stage, ?Throwable $exception = null): void {
        $reference = printflow_json_error_reference();
        $log = '[json-api][' . $reference . '] ' . $stage;
        if ($exception !== null) {
            $log .= ' class=' . get_class($exception) . ' code=' . (string)$exception->getCode();
        }
        error_log($log);

        $baseLevel = (int)($GLOBALS['printflow_json_buffer_base_level'] ?? 0);
        while (ob_get_level() > $baseLevel) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'error_reference' => $reference,
        ], JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('printflow_json_endpoint_bootstrap')) {
    function printflow_json_endpoint_bootstrap(): void {
        ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!isset($GLOBALS['printflow_json_buffer_base_level'])) {
            $GLOBALS['printflow_json_buffer_base_level'] = ob_get_level();
            ob_start();
        }

        set_exception_handler(static function (Throwable $exception): void {
            printflow_json_fail_safely('uncaught_exception', $exception);
            exit;
        });
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                return;
            }
            printflow_json_fail_safely('fatal_error');
        });
    }
}

if (!function_exists('printflow_json_response')) {
    function printflow_json_response(array $payload, int $status = 200): never {
        $baseLevel = (int)($GLOBALS['printflow_json_buffer_base_level'] ?? 0);
        while (ob_get_level() > $baseLevel) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        echo $json === false ? '{"success":false,"error":"Response encoding failed."}' : $json;
        exit;
    }
}
