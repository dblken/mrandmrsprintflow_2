<?php
declare(strict_types=1);

$terms = (string) file_get_contents(__DIR__ . '/../public/terms.php');
$privacy = (string) file_get_contents(__DIR__ . '/../public/privacy.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$assert(str_contains($terms, 'Email Verification and Pending Registrations'), 'Terms include pending registration rules');
$assert(str_contains($terms, 'removed after 24 hours'), 'Terms disclose 24-hour pending registration cleanup');
$assert(str_contains($terms, 'Google Sign-In accounts') && str_contains($terms, 'orders or other protected business activity'), 'Terms preserve protected account and business records');
$assert(str_contains($privacy, 'retained for up to 24 hours'), 'Privacy Policy describes pending registration retention');
$assert(str_contains($privacy, 'security, legal, accounting, dispute-resolution, or operational purposes'), 'Privacy Policy preserves required record retention');
$assert(str_contains($privacy, 'PrintFlow does not sell customer personal information.'), 'Privacy Policy states that customer information is not sold');
$assert(str_contains($privacy, 'trusted service providers only when necessary'), 'Privacy Policy describes limited service-provider sharing');
$assert(!str_contains($privacy, 'For review:'), 'Privacy Policy has no internal review placeholder');

echo "Pending registration policy tests passed.\n";
