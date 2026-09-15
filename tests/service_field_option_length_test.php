<?php

require_once __DIR__ . '/../includes/service_field_config_helper.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$assert(printflow_service_field_option_max_length() === 64, 'option max length is 64');

$short = printflow_normalize_service_field_options([
    ['value' => 'Sticker Cut Out (0.5ft X 0.5ft)', 'price' => 80],
]);
$assert(($short[0]['value'] ?? '') === 'Sticker Cut Out (0.5ft X 0.5ft)', 'short option preserved');

$exact64 = str_repeat('A', 64);
$normalized64 = printflow_normalize_service_field_options([['value' => $exact64, 'price' => 1]]);
$assert(($normalized64[0]['value'] ?? '') === $exact64, '64-character option preserved');

$over64 = str_repeat('B', 80);
$normalizedOver = printflow_normalize_service_field_options([['value' => $over64, 'price' => 1]]);
$assert(strlen($normalizedOver[0]['value'] ?? '') === 64, 'overlong option capped at 64 on save');

$adminPage = (string)file_get_contents(__DIR__ . '/../admin/service_field_config.php');
$assert(str_contains($adminPage, 'printflow_service_field_option_max_length()'), 'admin page uses shared option max length');
$assert(!preg_match('/class="option-input"[^>]*maxlength="32"/', $adminPage), 'option inputs no longer use maxlength 32');
$assert(str_contains($adminPage, 'PF_SERVICE_OPTION_MAX_LEN'), 'dynamic option rows use shared JS max length');

echo PHP_EOL . 'All service field option length tests passed.' . PHP_EOL;
