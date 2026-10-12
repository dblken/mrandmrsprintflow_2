<?php
declare(strict_types=1);

$GLOBALS['pos_test_walkin_lookup_count'] = 0;
$GLOBALS['conn'] = null;
function db_query(string $sql, string $types = '', array $params = []): array
{
    if (str_contains($sql, 'FROM customers WHERE customer_id = ?')) {
        return [['customer_id' => (int)($params[0] ?? 0), 'email' => 'registered@example.com']];
    }
    return [];
}
function pos_get_walkin_customer_id(): int
{
    $GLOBALS['pos_test_walkin_lookup_count']++;
    return 9001;
}
require_once __DIR__ . '/../includes/pos_customer_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};
$cases = [
    'blank name and email' => [['customer_id' => 'guest', 'guest_display_name' => '', 'customer_email' => ''], null, null],
    'name only' => [['customer_id' => 'guest', 'guest_display_name' => 'Walk-in Sample', 'customer_email' => ''], 'Walk-in Sample', null],
    'email only' => [['customer_id' => 'guest', 'guest_display_name' => '', 'customer_email' => 'walkin@example.com'], null, 'walkin@example.com'],
    'name and email' => [['customer_id' => 'guest', 'guest_display_name' => 'Sample Guest', 'customer_email' => 'sample@example.com'], 'Sample Guest', 'sample@example.com'],
];
foreach ($cases as $label => [$input, $expectedName, $expectedEmail]) {
    $resolved = printflow_pos_resolve_checkout_customer_context($input);
    $assert($resolved['customer_id'] === 9001, $label . ': stays on shared walk-in account');
    $assert($resolved['pos_guest_display_name'] === $expectedName, $label . ': name is optional and retained');
    $assert($resolved['pos_guest_email'] === $expectedEmail, $label . ': email is optional and retained');
}
$registered = printflow_pos_resolve_checkout_customer_context(['customer_id' => '42']);
$assert($registered['customer_id'] === 42, 'registered customer remains selected');
$assert($registered['pos_guest_display_name'] === null && $registered['pos_guest_email'] === null, 'registered customer ignores walk-in overrides');
$assert($GLOBALS['pos_test_walkin_lookup_count'] === 4, 'walk-in cases use placeholder, registered case does not');

try {
    printflow_pos_resolve_checkout_customer_context(['customer_id' => 'guest', 'customer_email' => 'not-an-email']);
    throw new RuntimeException('FAIL: invalid nonempty guest email should be rejected');
} catch (RuntimeException $error) {
    $assert($error->getCode() === 400, 'invalid optional email returns a client validation error');
}

echo "OK pos_walkin_optional_contact_test\n";
