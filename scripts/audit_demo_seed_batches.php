<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
printflow_load_project_env();
if (in_array('--check-config', $argv, true)) {
    $groups = ['host' => ['DB_HOST', 'PRINTFLOW_DB_HOST'], 'name' => ['DB_NAME', 'PRINTFLOW_DB_NAME'], 'user' => ['DB_USER', 'PRINTFLOW_DB_USER'], 'password' => ['DB_PASSWORD', 'PRINTFLOW_DB_PASSWORD', 'PRINTFLOW_DB_PASS']];
    $set = [];
    foreach ($groups as $group => $keys) { $set[$group . '_set'] = false; foreach ($keys as $key) if (printflow_env($key)) $set[$group . '_set'] = true; }
    echo json_encode($set, JSON_PRETTY_PRINT), PHP_EOL; exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/demo_seed_audit.php';
try {
    $connection = $GLOBALS['conn'] ?? null;
    if (!$connection instanceof mysqli) throw new RuntimeException('Database connection unavailable.');
    echo json_encode((new DemoSeedReadOnlyAudit(new DemoSeedMysqliDeletionDatabase($connection)))->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, 'Read-only audit failed: ' . $error->getMessage() . PHP_EOL); exit(1); }
