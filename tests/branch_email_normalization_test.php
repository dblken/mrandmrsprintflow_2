<?php
declare(strict_types=1);

$api = (string) file_get_contents(__DIR__ . '/../admin/api_branch.php');
$page = (string) file_get_contents(__DIR__ . '/../admin/branches_management.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$assert(substr_count($api, 'strtolower(trim(sanitize(') >= 3, 'branch API lowercases email on check, create, and update');
$assert(str_contains($api, 'LOWER(TRIM(email)) = ?'), 'branch email duplicate checks are case-insensitive');
$assert(str_contains($page, "form.email.replace(/\\s/g, '').toLowerCase()"), 'branch email input lowercases values and blocks spaces');
$assert(substr_count($page, 'strtolower(trim((string)') >= 4, 'branch email displays are lowercase in all table views');
$assert(str_contains($page, '.branches-table th:nth-child(3), .branches-table td:nth-child(3) { text-transform: lowercase; }'), 'branch email table column enforces lowercase presentation');

echo "Branch email normalization tests passed.\n";
