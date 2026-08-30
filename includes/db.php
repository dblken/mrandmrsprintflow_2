<?php
/**
 * Database Connection
 * PrintFlow - Production Ready (Hostinger + Local Safe)
 */

require_once __DIR__ . '/env.php';

// Host-level PHP logging may be disabled. Keep server-side diagnostics enabled
// for web requests; display_errors remains controlled separately and stays off
// in production, so these details are never rendered to staff users.
if (PHP_SAPI !== 'cli') {
    @ini_set('log_errors', '1');
}

/**
 * Heuristic: determine if the current request expects JSON.
 * Used to avoid emitting HTML in API responses on DB failures.
 */
function printflow_expects_json(): bool {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '' && preg_match('~/(api_[^/]+\\.php)$~i', $uri)) return true;
    if (stripos($uri, '/api/') !== false) return true;
    if (stripos($uri, '/webhooks/') !== false) return true;
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
    // DB_* is canonical. PRINTFLOW_DB_* remains supported for existing hosts.
    'host' => printflow_db_env_value(['DB_HOST', 'PRINTFLOW_DB_HOST']),
    'user' => printflow_db_env_value(['DB_USER', 'PRINTFLOW_DB_USER']),
    'pass' => printflow_db_env_value(['DB_PASSWORD', 'PRINTFLOW_DB_PASSWORD', 'PRINTFLOW_DB_PASS']),
    'name' => printflow_db_env_value(['DB_NAME', 'PRINTFLOW_DB_NAME']),
    'port' => printflow_db_env_value(['DB_PORT', 'PRINTFLOW_DB_PORT']),
];

if ($db_config['port'] === '') {
    $db_config['port'] = '3306';
}

$db_config_status = [
    'env_loaded' => (bool)($GLOBALS['printflow_env_loaded'] ?? false),
    'db_host_set' => $db_config['host'] !== '',
    'db_port_set' => $db_config['port'] !== '',
    'db_name_set' => $db_config['name'] !== '',
    'db_user_set' => $db_config['user'] !== '',
    'db_password_set' => $db_config['pass'] !== '',
];

function printflow_db_error_reference(): string {
    try {
        return strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $e) {
        return strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
    }
}

function printflow_db_abort(string $stage, ?Throwable $exception = null, array $details = []): void {
    $reference = printflow_db_error_reference();
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $safePath = is_string($requestPath)
        ? (string)preg_replace('/[^a-zA-Z0-9_\/.\-]/', '', $requestPath)
        : '';

    $context = [
        'timestamp' => date(DATE_ATOM),
        'reference' => $reference,
        'stage' => preg_replace('/[^a-zA-Z0-9_\-]/', '', $stage),
        'request_path' => $safePath,
        'request_method' => preg_replace('/[^A-Z]/', '', strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''))),
        'role' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_SESSION['user_type'] ?? 'guest')),
        'user_id' => max(0, (int)($_SESSION['user_id'] ?? 0)),
        'branch_id' => max(0, (int)($_SESSION['selected_branch_id'] ?? $_SESSION['branch_id'] ?? 0)),
        'error_class' => $exception !== null ? get_class($exception) : (string)($details['error_class'] ?? 'database_bootstrap_error'),
    ];
    if ($exception !== null) {
        $context['error_code'] = (int)$exception->getCode();
    }

    foreach (['db_errno', 'retry_attempts'] as $integerKey) {
        if (array_key_exists($integerKey, $details)) {
            $context[$integerKey] = max(0, (int)$details[$integerKey]);
        }
    }
    if (!empty($details['db_sqlstate'])) {
        $context['db_sqlstate'] = substr(
            (string)preg_replace('/[^a-zA-Z0-9]/', '', (string)$details['db_sqlstate']),
            0,
            5
        );
    }
    if (!empty($details['missing_config']) && is_array($details['missing_config'])) {
        $context['missing_config'] = array_values(array_filter(array_map(
            static fn($key) => preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key),
            $details['missing_config']
        )));
    }

    error_log('[printflow_database_failure] ' . json_encode($context, JSON_UNESCAPED_SLASHES));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed. Review the server database configuration.\n");
        exit(1);
    }

    if (printflow_expects_json()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Database service is temporarily unavailable.',
            'error_reference' => $reference,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $safeReference = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Service temporarily unavailable</title></head><body style="font-family:system-ui,sans-serif;margin:3rem;line-height:1.5">'
        . '<main><h1>We cannot load this page right now.</h1><p>Please try again shortly. If the problem continues, contact support and provide reference '
        . '<code>' . $safeReference . '</code>.</p></main></body></html>';
    exit;
}

$required_config_status = $db_config_status;
unset($required_config_status['env_loaded']);
if (in_array(false, $required_config_status, true)) {
    $message = 'Database configuration is incomplete. Required environment variables are missing.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, json_encode($db_config_status, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }

    $GLOBALS['printflow_db_diagnostics'] = array_merge($db_config_status, [
        'pdo_connected' => false,
        'database_selected' => false,
        'resolved_env_path' => printflow_project_env_path(),
        'connection_driver' => extension_loaded('pdo_mysql') ? 'mysql' : '',
        'database_server_version' => '',
    ]);
    if (defined('PRINTFLOW_DB_DIAGNOSTIC_MODE') && PRINTFLOW_DB_DIAGNOSTIC_MODE) {
        return;
    }
    $missingConfig = [];
    foreach ($required_config_status as $configKey => $isSet) {
        if (!$isSet) $missingConfig[] = $configKey;
    }
    printflow_db_abort('configuration_incomplete', null, [
        'error_class' => 'database_configuration_error',
        'missing_config' => $missingConfig,
    ]);
}

/**
 * ==========================
 * CONNECT DATABASE
 * ==========================
 *
 * The application query helpers use mysqli. PDO is initialized later as an
 * optional compatibility handle and must not prevent mysqli-backed pages from
 * loading on hosts where pdo_mysql is unavailable.
 */
$GLOBALS['printflow_db_diagnostics'] = array_merge($db_config_status, [
    'pdo_connected' => false,
    'mysqli_connected' => false,
    'database_selected' => false,
    'resolved_env_path' => printflow_project_env_path(),
    'connection_driver' => '',
    'database_server_version' => '',
]);

if (function_exists('mysqli_report')) {
    // Some hosts enable STRICT reporting (SQL errors become exceptions -> 500).
    // Keep the app resilient and rely on error_log + printflow_db_errors() instead.
    mysqli_report(MYSQLI_REPORT_OFF);
}
$mysqliException = null;
$conn = null;
$connectAttempts = 0;
$connectErrno = 0;
$connectSqlstate = '';
$transientConnectErrors = [1040, 1203, 2002, 2003, 2006, 2013];

do {
    $connectAttempts++;
    $mysqliException = null;
    try {
        $conn = @new mysqli(
            $db_config['host'],
            $db_config['user'],
            $db_config['pass'],
            $db_config['name'],
            (int)$db_config['port']
        );
    } catch (Throwable $e) {
        $mysqliException = $e;
        $conn = null;
    }

    $connectErrno = $conn instanceof mysqli
        ? (int)$conn->connect_errno
        : (int)($mysqliException?->getCode() ?? 0);
    $connectSqlstate = $mysqliException !== null && method_exists($mysqliException, 'getSqlState')
        ? (string)$mysqliException->getSqlState()
        : '';
    $connectionFailed = !$conn instanceof mysqli || $connectErrno !== 0;
    if (!$connectionFailed || $connectAttempts >= 2 || !in_array($connectErrno, $transientConnectErrors, true)) {
        break;
    }

    $conn = null;
    usleep(150000);
} while (true);
$mysqli = $conn;

/**
 * ==========================
 * ERROR HANDLING
 * ==========================
 */
if (!$conn instanceof mysqli || $connectErrno !== 0) {
    printflow_db_record_error([
        'stage' => 'connect',
        'errno' => $conn instanceof mysqli ? $conn->connect_errno : 0,
    ]);

    if (defined('PRINTFLOW_DB_DIAGNOSTIC_MODE') && PRINTFLOW_DB_DIAGNOSTIC_MODE) {
        $GLOBALS['printflow_db_diagnostics']['mysqli_connected'] = false;
        return;
    }
    printflow_db_abort('mysqli_compatibility_connect_failed', $mysqliException, [
        'error_class' => $mysqliException !== null ? get_class($mysqliException) : 'mysqli_connect_error',
        'db_errno' => $connectErrno,
        'db_sqlstate' => $connectSqlstate,
        'retry_attempts' => $connectAttempts,
    ]);
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

$databaseSelected = false;
$selectedResult = $conn->query('SELECT DATABASE() AS selected_database');
if ($selectedResult instanceof mysqli_result) {
    $selectedRow = $selectedResult->fetch_assoc();
    $selectedResult->free();
    $databaseSelected = hash_equals(
        $db_config['name'],
        (string)($selectedRow['selected_database'] ?? '')
    );
}
if (!$databaseSelected) {
    if (defined('PRINTFLOW_DB_DIAGNOSTIC_MODE') && PRINTFLOW_DB_DIAGNOSTIC_MODE) {
        $GLOBALS['printflow_db_diagnostics'] = array_merge($db_config_status, [
            'pdo_connected' => false,
            'mysqli_connected' => true,
            'database_selected' => false,
            'resolved_env_path' => printflow_project_env_path(),
            'connection_driver' => 'mysqli',
            'database_server_version' => (string)$conn->server_info,
        ]);
        return;
    }
    printflow_db_abort('database_not_selected');
}

$pdo = null;
$db = null;
$shouldInitializePdo = PHP_SAPI === 'cli'
    || (defined('PRINTFLOW_DB_DIAGNOSTIC_MODE') && PRINTFLOW_DB_DIAGNOSTIC_MODE);
if ($shouldInitializePdo && extension_loaded('pdo_mysql')) {
    try {
        $dsn = 'mysql:host=' . $db_config['host']
            . ';port=' . (int)$db_config['port']
            . ';dbname=' . $db_config['name']
            . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+08:00'");
        $db = $pdo;
    } catch (Throwable $e) {
        $pdo = null;
        $db = null;
        error_log('[database] optional_pdo_connect_failed class=' . get_class($e)
            . ' code=' . (string)$e->getCode());
    }
}

$GLOBALS['printflow_db_diagnostics'] = array_merge($db_config_status, [
    'pdo_connected' => $pdo instanceof PDO,
    'mysqli_connected' => true,
    'database_selected' => true,
    'resolved_env_path' => printflow_project_env_path(),
    'connection_driver' => $pdo instanceof PDO ? 'mysqli+pdo_mysql' : 'mysqli',
    'database_server_version' => (string)$conn->server_info,
]);

/**
 * ==========================
 * HELPER FUNCTIONS
 * ==========================
 */

if (!function_exists('printflow_db_in_transaction')) {
    /** mysqli has no portable inTransaction() API; ask the active SQL session. */
    function printflow_db_in_transaction($connection = null): bool {
        global $conn;
        $connection = $connection instanceof mysqli ? $connection : $conn;
        if (!($connection instanceof mysqli)) return false;

        $result = @$connection->query('SELECT @@session.in_transaction AS active_transaction');
        if (!($result instanceof mysqli_result)) return false;
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['active_transaction'] ?? 0) === 1;
    }
}

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
