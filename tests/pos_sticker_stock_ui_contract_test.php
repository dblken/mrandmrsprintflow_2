<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pos = (string) file_get_contents($root . '/staff/pos.php');
$renderer = (string) file_get_contents($root . '/includes/service_field_renderer.php');
$apiFields = (string) file_get_contents($root . '/staff/api/pos_service_fields.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$qtyCaseStart = strpos($renderer, "case 'quantity':");
$qtyCaseEnd = strpos($renderer, "case 'textarea':", $qtyCaseStart === false ? 0 : $qtyCaseStart);
$qtyCase = $qtyCaseStart !== false && $qtyCaseEnd !== false
    ? substr($renderer, $qtyCaseStart, $qtyCaseEnd - $qtyCaseStart)
    : '';
$assert($qtyCase !== ''
    && str_contains($qtyCase, 'pf-service-quantity-stack')
    && str_contains($qtyCase, 'quantity-container')
    && preg_match('/quantity-container[\s\S]+pf-service-quantity-limit-hint/', $qtyCase) === 1,
    'Quantity limit hint is rendered below the quantity control inside pf-service-quantity-stack.');
$assert(str_contains($renderer, "input.closest('.pf-service-quantity-stack')"),
    'Quantity limit sync resolves the hint from the vertical stack wrapper.');

$assert(str_contains($pos, '#service-modal-overlay .pf-service-quantity-stack')
    && str_contains($pos, 'flex-direction: column'),
    'POS modal CSS stacks the quantity limit indicator under the control.');

$assert(str_contains($pos, 'posServiceStockAddAttempted')
    && str_contains($pos, 'function posStickerLaminateFormReadyForStock')
    && str_contains($pos, 'stickerLiveDeferred')
    && str_contains($pos, 'posServiceStockAddAttempted = true'),
    'Sticker laminate stock feedback waits for Add to Order unless the form is complete.');

$assert(!preg_match('/stickerCustomSizeChosen\s*&&\s*!stickerCustomReady[\s\S]{0,220}Enter width, height, laminate finish/s', $pos),
    'Live sticker stock check no longer auto-shows the laminate readiness paragraph.');

$assert(str_contains($pos, 'const validationResult = validateServiceOrderForm()')
    && preg_match('/validateServiceOrderForm\(\)[\s\S]{0,320}posServiceStockAddAttempted = true/s', $pos) === 1,
    'Add to Order validates required fields before the authoritative stock check.');

$assert(str_contains($pos, 'Insufficient') || str_contains($pos, 'sticker_laminate_v1'),
    'Sticker shortage and allocation handling remain wired in POS.');

$assert(str_contains($apiFields, 'pf-service-quantity-stack'),
    'Fallback service fields use the same quantity stack layout.');

echo "POS sticker stock UI contract tests passed.\n";
