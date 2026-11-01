<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
if ($source === false) throw new RuntimeException('Could not read POS checkout API.');
$start = strpos($source, "if (isset(\$data['action']) && \$data['action'] === 'create_pending_customization') {");
$end = strpos($source, "if (empty(\$data['items']))", $start ?: 0);
if ($start === false || $end === false) throw new RuntimeException('Could not isolate the POS Set Price draft action.');
$action = substr($source, $start, $end - $start);
$csrf = strpos($action, "pos_checkout_verify_csrf((string)(\$data['csrf_token'] ?? ''), \$sessionContext)");
$session = strpos($action, 'pos_checkout_reopen_session();');
if ($csrf === false || $session === false || $csrf > $session) {
    throw new RuntimeException('The mutating Set Price draft action must verify CSRF before reopening/writing session state.');
}
if (!str_contains($action, 'create_pending_customization_initialization_failed')
    || !str_contains($action, 'catch (Throwable $e)')
    || !str_contains($action, 'http_response_code(500)')
    || !str_contains($action, "'stage' => 'draft_creation'")) {
    throw new RuntimeException('Set Price initialization and transaction failures must be logged and returned as structured JSON errors.');
}

echo "POS Set Price API contract tests passed.\n";
