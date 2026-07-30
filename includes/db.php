<?php
/**
 * Database Connection
 * PrintFlow - Production Ready (Hostinger + Local Safe)
 */

require_once __DIR__ . '/env.php';

/**
 * Heuristic: determine if the current request expects JSON.
 * Used to avoid emitting HTML in API responses on DB failures.
 */
function printflow_expects_json(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '' && preg_match('~/(api_[^/]+\\.php)$~i', $uri)) return true;
    if (stripos($uri, '/api/') !== false) return true;
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && stripos($accept, 'application/json') !== false) return true;
    $xrw = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw !== '' && strtolower($xrw) === 'xmlhttprequest') return true;
    return false;
}

/**
 * Collect DB errors for API debugging (kept in-memory for the request only).
 * Avoid exposing these in responses unless you explicitly include them (e.g. debug mode).
 */
if (!isset($GLOBALS['printflow_db_errors']) || !is_array($GLOBALS['printflow_db_errors'])) {
    $GLOBALS['printflow_db_errors'] = [];
}

function printflow_db_record_error(array $info): void {
    if (!isset($GLOBALS['printflow_db_errors']) || !is_array($GLOBALS['printflow_db_errors'])) {
        $GLOBALS['printflow_db_errors'] = [];
    }
    $GLOBALS['printflow_db_errors'][] = $info;
}

function printflow_db_errors(): array {
    return (isset($GLOBALS['printflow_db_errors']) && is_array($GLOBALS['printflow_db_errors']))
        ? $GLOBALS['printflow_db_errors']
        : [];
}

/**
 * ==========================
 * LOAD AND VALIDATE DATABASE CONFIGURATION
 * ==========================
 */
printflow_load_project_env();

function printflow_db_env_value(array $names) {
    foreach ($names as $name) {
        $value = printflow_env($name);
        if ($value !== false && trim((string)$value) !== '') {
            return (string)$value;
        }
    }
    return '';
}

$db_config = [
    // Keep the project's existing PRINTFLOW_DB_* names primary, while also
    // accepting the conventional DB_* aliases used by hosting platforms.
    'host' => printflow_db_env_value(['PRINTFLOW_DB_HOST', 'DB_HOST']),
    'user' => printflow_db_env_value(['PRINTFLOW_DB_USER', 'DB_USER']),
    'pass' => printflow_db_env_value(['PRINTFLOW_DB_PASS', 'PRINTFLOW_DB_PASSWORD', 'DB_PASSWORD']),
    'name' => printflow_db_env_value(['PRINTFLOW_DB_NAME', 'DB_NAME']),
    'port' => printflow_db_env_value(['PRINTFLOW_DB_PORT', 'DB_PORT']),
];

if ($db_config['port'] === '') {
    $db_config['port'] = '3306';
}

$db_config_status = [
    'db_host_set' => $db_config['host'] !== '',
    'db_name_set' => $db_config['name'] !== '',
    'db_user_set' => $db_config['user'] !== '',
    'db_password_set' => $db_config['pass'] !== '',
];

if (in_array(false, $db_config_status, true)) {
    $message = 'Database configuration is incomplete. Required environment variables are missing.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, json_encode($db_config_status, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }

    error_log($message);
    if (printflow_expects_json()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(500);
    die('Database configuration is unavailable.');
}

/**
 * ==========================
 * CONNECT DATABASE
 * ==========================
 */
if (function_exists('mysqli_report')) {
    // Some hosts enable STRICT reporting (SQL errors become exceptions -> 500).
    // Keep the app resilient and rely on error_log + printflow_db_errors() instead.
    mysqli_report(MYSQLI_REPORT_OFF);
}
$conn = @new mysqli(
    $db_config['host'],
    $db_config['user'],
    $db_config['pass'],
    $db_config['name'],
    (int)$db_config['port']
);

/**
 * ==========================
 * ERROR HANDLING
 * ==========================
 */
if ($conn->connect_error) {
    error_log('Database connection failed (errno ' . (int)$conn->connect_errno . ').');
    printflow_db_record_error([
        'stage' => 'connect',
        'errno' => $conn->connect_errno,
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed. Review the server database configuration.\n");
        exit(1);
    }

    if (printflow_expects_json()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed',
        ], $flags);
        exit;
    }

    http_response_code(500);
    die('Database connection is unavailable.');
}

/**
 * ==========================
 * SET CHARSET
 * ==========================
 */
$conn->set_charset("utf8mb4");

/**
 * Keep MySQL NOW()/CURRENT_TIMESTAMP aligned with the app timezone.
 * Without this, some hosts default the DB session to UTC, which makes new
 * notifications look about 8 hours old when PHP formats them in Manila time.
 */
$conn->query("SET time_zone = '+08:00'");

/**
 * ==========================
 * HELPER FUNCTIONS
 * ==========================
 */

function db_query($sql, $types = '', $params = []) {
    global $conn;

    $stmt = null;
    $result = null;

    try {
        if (empty($types) || empty($params)) {
            $result = $conn->query($sql);
        } else {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                printflow_db_record_error([
                    'stage' => 'prepare',
                    'error' => $conn->error,
                    'errno' => $conn->errno,
                    'sqlstate' => $conn->sqlstate,
                    'sql' => $sql,
                ]);
                error_log("DB Prepare Error: " . $conn->error);
                return [];
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                printflow_db_record_error([
                    'stage' => 'execute',
                    'error' => $stmt->error,
                    'errno' => $stmt->errno,
                    'sqlstate' => $stmt->sqlstate,
                    'sql' => $sql,
                ]);
                error_log("DB Execute Error: " . $stmt->error);
                $stmt->close();
                return [];
            }

            // Prefer mysqlnd-powered get_result(), but fall back if unavailable.
            $result = null;
            if (method_exists($stmt, 'get_result')) {
                $result = $stmt->get_result();
                if ($result === false) {
                    printflow_db_record_error([
                        'stage' => 'get_result',
                        'error' => $stmt->error,
                        'errno' => $stmt->errno,
                        'sqlstate' => $stmt->sqlstate,
                        'sql' => $sql,
                    ]);
                    error_log("DB get_result Error: " . $stmt->error);
                    $stmt->close();
                    return [];
                }
            } else {
                $meta = $stmt->result_metadata();
                if (!$meta) {
                    $stmt->close();
                    return [];
                }

                $fields = $meta->fetch_fields();
                $row = [];
                $bind = [];
                foreach ($fields as $field) {
                    $row[$field->name] = null;
                    $bind[] = &$row[$field->name];
                }

                // bind_result requires references.
                call_user_func_array([$stmt, 'bind_result'], $bind);

                $data = [];
                while ($stmt->fetch()) {
                    // Copy since $row values are reused by reference each fetch.
                    $data[] = array_map(static fn($v) => $v, $row);
                }

                $stmt->close();
                return $data;
            }
        }
    } catch (Throwable $e) {
        printflow_db_record_error([
            'stage' => 'exception',
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'errno' => $stmt instanceof mysqli_stmt ? $stmt->errno : $conn->errno,
            'sqlstate' => $stmt instanceof mysqli_stmt ? $stmt->sqlstate : $conn->sqlstate,
            'sql' => $sql,
        ]);
        error_log("DB Exception: " . $e->getMessage());
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        return [];
    }

    if (!$result) {
        printflow_db_record_error([
            'stage' => 'query',
            'error' => $conn->error,
            'errno' => $conn->errno,
            'sqlstate' => $conn->sqlstate,
            'sql' => $sql,
        ]);
        error_log("DB Query Error: " . $conn->error);
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        return [];
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    return $data;
}

/**
 * Schema helper: check if a table has a specific column.
 */
function db_table_has_column(string $table, string $column, bool $refresh = false): bool {
    static $cache = [];

    $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $c = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($t === '' || $c === '') return false;

    $key = $t . '.' . $c;
    if ($refresh) {
        unset($cache[$key]);
    }
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];

    // SHOW statements do not work reliably with prepared LIKE placeholders on all MySQL builds.
    $rows = db_query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    $cache[$key] = !empty($rows);
    return (bool)$cache[$key];
}

function db_execute_affected_rows($sql, $types = '', $params = []) {
    global $conn;

    $stmt = null;

    try {
        if (empty($types) || empty($params)) {
            if (!$conn->query($sql)) {
                return -1;
            }
            return (int)$conn->affected_rows;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return -1;
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $stmt->close();
            return -1;
        }

        $affected = (int)$stmt->affected_rows;
        $stmt->close();
        return $affected;
    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        return -1;
    }
}

function db_execute($sql, $types = '', $params = []) {
    global $conn;

    $stmt = null;

    try {
        if (empty($types) || empty($params)) {
            if (!$conn->query($sql)) {
                printflow_db_record_error([
                    'stage' => 'execute_query',
                    'error' => $conn->error,
                    'errno' => $conn->errno,
                    'sqlstate' => $conn->sqlstate,
                    'sql' => $sql,
                ]);
                error_log("DB Execute Error: " . $conn->error);
                return false;
            }
            return $conn->insert_id ?: true;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            printflow_db_record_error([
                'stage' => 'prepare',
                'error' => $conn->error,
                'errno' => $conn->errno,
                'sqlstate' => $conn->sqlstate,
                'sql' => $sql,
            ]);
            error_log("DB Prepare Error: " . $conn->error);
            return false;
        }

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            printflow_db_record_error([
                'stage' => 'execute',
                'error' => $stmt->error,
                'errno' => $stmt->errno,
                'sqlstate' => $stmt->sqlstate,
                'sql' => $sql,
            ]);
            error_log("DB Execute Error: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $insertId = $stmt->insert_id ?: $conn->insert_id;
        $stmt->close();
        return $insertId ?: true;
    } catch (Throwable $e) {
        printflow_db_record_error([
            'stage' => 'exception',
            'error' => $e->getMessage(),
            'class' => get_class($e),
            'errno' => $stmt instanceof mysqli_stmt ? $stmt->errno : $conn->errno,
            'sqlstate' => $stmt instanceof mysqli_stmt ? $stmt->sqlstate : $conn->sqlstate,
            'sql' => $sql,
        ]);
        error_log("DB Exception: " . $e->getMessage());
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        return false;
    }
}

function db_escape($str) {
    global $conn;
    return $conn->real_escape_string($str);
}

function db_close() {
    global $conn;
    if ($conn) $conn->close();
}
