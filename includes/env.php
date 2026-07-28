<?php
/**
 * Small dotenv loader used by both the application bootstrap and database layer.
 * Real process/server variables always take precedence over values in .env.
 */

if (!function_exists('printflow_env')) {
    function printflow_env(string $name) {
        $value = getenv($name);
        if ($value !== false) return $value;
        if (array_key_exists($name, $_ENV)) return (string)$_ENV[$name];
        if (array_key_exists($name, $_SERVER)) return (string)$_SERVER[$name];
        return false;
    }
}

if (!function_exists('printflow_load_dotenv')) {
    function printflow_load_dotenv(string $path): array {
        if (!is_readable($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];

        $values = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_starts_with($line, 'export ')) $line = ltrim(substr($line, 7));
            if (strpos($line, '=') === false) continue;

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) continue;

            $value = trim($value);
            $length = strlen($value);
            if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"') || ($value[0] === "'" && $value[$length - 1] === "'"))) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                }
            } else {
                $value = (string)preg_replace('/\s+#.*$/', '', $value);
            }
            $values[$name] = $value;
        }
        return $values;
    }
}

if (!function_exists('printflow_import_dotenv')) {
    function printflow_import_dotenv(string $path): array {
        $values = printflow_load_dotenv($path);
        foreach ($values as $name => $value) {
            $processValue = getenv($name);
            $envValue = array_key_exists($name, $_ENV) ? (string)$_ENV[$name] : '';
            $serverValue = array_key_exists($name, $_SERVER) ? (string)$_SERVER[$name] : '';
            if (($processValue !== false && $processValue !== '') || $envValue !== '' || $serverValue !== '') {
                continue;
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        return $values;
    }
}
