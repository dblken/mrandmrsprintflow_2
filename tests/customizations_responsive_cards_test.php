<?php
declare(strict_types=1);

$source = (string)file_get_contents(__DIR__ . '/../staff/customizations.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$twoColumnStart = strpos($source, '@container customization-list (max-width: 680px) {');
$assert($twoColumnStart !== false, 'responsive cards use the available list width breakpoint');
$mobileEnd = strpos($source, '/* Narrow customization records use native block cards.', $twoColumnStart);
$twoColumnCss = substr($source, $twoColumnStart, $mobileEnd - $twoColumnStart);
$assert(str_contains($twoColumnCss, 'grid-template-columns: repeat(2, minmax(0, 1fr))'), 'order-card fields use two columns when the list is wide enough');
foreach ([
    '.order-code-cell { grid-column: 1 !important; grid-row: 1 !important; }',
    '.customization-info-cell { grid-column: 2 !important; grid-row: 1 !important; }',
    '.needed-date-cell { grid-column: 1 !important; grid-row: 2 !important; }',
    '.status-col-cell { grid-column: 2 !important; grid-row: 2 !important; }',
    '.customer-cell { grid-column: 1 !important; grid-row: 3 !important; }',
    '.created-cell { grid-column: 2 !important; grid-row: 3 !important; }',
] as $selector) {
    $assert(str_contains($twoColumnCss, $selector), 'two-column card keeps the requested field placement: ' . $selector);
}
$assert(!str_contains($twoColumnCss, 'grid-template-columns: minmax(0, 1fr) !important;'), 'two-column card layout remains at narrow mobile widths');
$assert(str_contains($twoColumnCss, 'overflow-wrap: anywhere !important;'), 'long order values wrap without horizontal overflow');
$assert(str_contains($twoColumnCss, 'grid-column: 1 / -1 !important;'), 'View action spans both columns');
$assert(str_contains($twoColumnCss, 'max-width: 100% !important;') && str_contains($twoColumnCss, 'box-sizing: border-box !important;'), 'View control width is constrained to the inner card width');
$assert(str_contains($twoColumnCss, 'overflow-x: visible !important;'), 'mobile table wrapper does not mask overflowing card content');
$assert(!str_contains($twoColumnCss, 'overflow-x: hidden !important;'), 'mobile card overflow is not hidden at the table wrapper');
$assert(str_contains($source, 'data-label="Created"') && str_contains($source, 'x-text="formatOrderBusinessDate(item.jo)"'), 'created date and order data bindings remain intact');
$assert(str_contains($source, 'class="pf-urgent-request-badge"') && str_contains($source, 'x-text="getDisplayOrderCode(item.jo)"'), 'order number and urgent request badge markup remain intact');
