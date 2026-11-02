<?php
/** Set the new-rule default to OPTIONAL without changing existing rule rows. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/db.php';
global $conn;
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable; no schema change was made.\n");
    exit(1);
}

$result = @$conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE 'rule_type'");
$column = $result ? $result->fetch_assoc() : null;
if ($result) $result->free();
if (!$column || strtolower((string)($column['Type'] ?? '')) !== 'varchar(50)') {
    fwrite(STDERR, "service_material_rules.rule_type is missing or has an unexpected type; no schema change was made.\n");
    exit(1);
}

if ((string)($column['Default'] ?? '') !== 'OPTIONAL') {
    // ALTER COLUMN changes only the default; existing rule_type values are untouched.
    if (!@$conn->query("ALTER TABLE `service_material_rules` ALTER COLUMN `rule_type` SET DEFAULT 'OPTIONAL'")) {
        fwrite(STDERR, "Could not set the service material rule default; no rule rows were changed.\n");
        exit(1);
    }
}

$verify = @$conn->query("SHOW COLUMNS FROM `service_material_rules` LIKE 'rule_type'");
$verified = $verify ? $verify->fetch_assoc() : null;
if ($verify) $verify->free();
if (!$verified || (string)($verified['Default'] ?? '') !== 'OPTIONAL') {
    fwrite(STDERR, "Rule-type default verification failed.\n");
    exit(1);
}
fwrite(STDOUT, "RULE_TYPE_DEFAULT_RESULT=PASS default=OPTIONAL existing_rows=unchanged\n");
