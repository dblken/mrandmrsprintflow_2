<?php
require_once __DIR__ . '/../includes/roll_width.php';

$checks = [];
$checks['24 inches normalize to 2 feet'] = abs(printflow_roll_width_to_feet('24', 'in') - 2.0) < 0.000001;
$checks['2 feet remain 2 feet'] = abs(printflow_roll_width_to_feet('2', 'ft') - 2.0) < 0.000001;
foreach ([3, 4, 5, 6] as $configuredWidth) {
    $checks[$configuredWidth . ' ft Tarpaulin width normalizes from inches'] = printflow_roll_width_to_feet((string)($configuredWidth * 12), 'in') === (float)$configuredWidth;
}
$checks['inch unit aliases convert consistently'] = abs(printflow_roll_width_to_feet('24.0', 'inches') - 2.0) < 0.000001;
$default = printflow_roll_width_default(['material_width' => '2.000', 'material_dimension_unit' => 'ft']);
$checks['receive form default uses configured item width'] = $default === ['value' => '2.000', 'unit' => 'ft'];
$defaultInches = printflow_roll_width_default(['material_width' => '24', 'material_dimension_unit' => 'in']);
$checks['receive form preserves configured inch value and unit'] = $defaultInches === ['value' => '24', 'unit' => 'in'];

foreach ([['', 'ft'], ['abc', 'ft'], ['0', 'ft'], ['-2', 'ft'], ['1', 'cm'], ['1201', 'in']] as [$value, $unit]) {
    try {
        printflow_roll_width_to_feet($value, $unit);
        $checks['reject invalid width ' . $value . ' ' . $unit] = false;
    } catch (InvalidArgumentException $e) {
        $checks['reject invalid width ' . $value . ' ' . $unit] = true;
    }
}

$api = file_get_contents(__DIR__ . '/../admin/inventory_transactions_api.php');
$rollApi = file_get_contents(__DIR__ . '/../admin/inventory_rolls_api.php');
$rollPage = file_get_contents(__DIR__ . '/../admin/inv_rolls_management.php');
$form = file_get_contents(__DIR__ . '/../admin/inv_items_management.php');
$rollService = file_get_contents(__DIR__ . '/../includes/RollService.php');
$checks['shared roll creation rejects missing width before any schema/database operation'] = strpos($rollService, "\$widthFt = printflow_roll_width_to_feet(\$widthFt, 'ft');") < strpos($rollService, 'InventoryManager::ensureBranchScopedSchema();', strpos($rollService, 'public static function createRoll'));
$checks['secondary roll intake submits and normalizes measured width'] = str_contains($rollPage, 'name="roll_width"') && str_contains($rollPage, 'name="roll_width_unit"') && str_contains($rollApi, 'printflow_roll_width_to_feet($_POST[\'roll_width\']') && str_contains($rollApi, 'createRoll($itemId, $len, $code, $supplier, $widthFt)');
$checks['API normalizes roll width server-side before receiveStock'] = strpos($api, 'printflow_roll_width_to_feet($widthRaw') !== false
    && strpos($api, '\'width_ft\'  => $widthFt') !== false;
$checks['receive form submits explicit width and unit without a 1-foot fallback'] = strpos($form, "fd.set('roll_width',") !== false
    && strpos($form, "fd.set('roll_width_unit',") !== false
    && strpos($form, "w || '1'") === false
    && strpos($form, 'default_roll_width_ft') === false;
$checks['configured physical width is prefilled and required for roll intake'] = strpos($form, 'printflowGetRollWidthDefault(item)') !== false
    && strpos($form, "style.display = isRoll ? 'block' : 'none'") !== false
    && strpos($form, "getElementById('addStockWidth').required = isRoll") !== false;
$checks['roll intake requires explicit physical-width confirmation in UI and API'] = strpos($form, 'addStockWidthConfirmed') !== false
    && strpos($form, 'physical_width_confirmed\', \'1\'') !== false
    && strpos($api, "physical_width_confirmed'] ?? '') !== '1'") !== false
    && strpos($api, 'Confirm the measured physical roll width before receiving this roll.') !== false;
$checks['RollService stores width separately from received and remaining length'] = strpos($rollService, 'width_ft, total_length_ft, remaining_length_ft') !== false
    && strpos($rollService, '$widthFt, $totalLength, $totalLength') !== false;
$correctionStart = strpos($rollService, 'public static function correctRollWidth');
$correctionEnd = strpos($rollService, 'public static function createRoll', $correctionStart ?: 0);
$correction = $correctionStart !== false && $correctionEnd !== false ? substr($rollService, $correctionStart, $correctionEnd - $correctionStart) : '';
$checks['existing-roll correction changes width only and preserves both length fields'] = $correction !== ''
    && strpos($correction, 'UPDATE inv_rolls SET width_ft = ? WHERE id = ? AND status = \'OPEN\'') !== false
    && !preg_match('/UPDATE\\s+inv_rolls\\s+SET[^;]*(?:remaining_length_ft|total_length_ft)/is', $correction)
    && strpos($correction, "'total_length_ft_preserved'") !== false
    && strpos($correction, "'remaining_length_ft_preserved'") !== false
    && strpos($correction, 'SELECT width_ft, total_length_ft, remaining_length_ft FROM inv_rolls') !== false
    && strpos($correction, 'Saved roll values failed verification') !== false;
$checks['existing-roll correction locks and validates the exact open branch roll'] = $correction !== ''
    && strpos($correction, 'FOR UPDATE') !== false
    && strpos($correction, 'strtoupper((string)$row[\'status\']) !== \'OPEN\'') !== false
    && strpos($correction, '$expectedBranchId > 0 && (int)$row[\'branch_id\'] !== $expectedBranchId') !== false;
$checks['existing-roll correction requires measured-width note and writes audit transactionally'] = $correction !== ''
    && strpos($correction, '$verificationNote === \'\'') !== false
    && strpos($correction, 'physical_width_verification_note') !== false
    && strpos($correction, 'INSERT INTO activity_logs') !== false
    && strpos($correction, '$conn->rollback()') !== false;
$checks['correction endpoint requires Admin, CSRF, explicit measurement confirmation, and unit conversion'] = strpos($rollApi, "case 'correct_roll_width':") !== false
    && strpos($rollApi, '($actor[\'role\'] ?? \'\') !== \'Admin\'') !== false
    && strpos($rollApi, 'printflow_inventory_rolls_require_csrf();') !== false
    && strpos($rollApi, "physical_width_verified'] ?? '') !== '1'") !== false
    && strpos($rollApi, 'printflow_roll_width_to_feet(') !== false;
$checks['Roll Tracking UI exposes correction only to Admin for open rolls and refreshes after save'] = strpos($rollPage, '$canCorrectRollWidth = (($current_user[\'role\'] ?? \'\') === \'Admin\')') !== false
    && strpos($rollPage, 'data-correct-width=') !== false
    && strpos($rollPage, 'physical_width_verified') !== false
    && strpos($rollPage, 'await loadRolls();') !== false;

$failed = [];
foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$pass) $failed[] = $label;
}
if ($failed) exit(1);
