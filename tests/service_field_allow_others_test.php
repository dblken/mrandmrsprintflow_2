<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/service_field_renderer.php';

function assert_contains_text(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_not_contains_text(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$baseConfig = [
    'label' => 'Sizes',
    'type' => 'select',
    'options' => [
        ['value' => '18x24(inch)', 'price' => 0],
        ['value' => '24x30(inch)', 'price' => 0],
        ['value' => '24x36(inch)', 'price' => 0],
    ],
    'visible' => true,
    'required' => true,
    'default' => null,
    'unit' => 'ft',
    'order' => 1,
];

$withoutOthers = render_service_field('sizes', $baseConfig + ['allow_others' => false]);
assert_not_contains_text($withoutOthers, '<option value="Others"', 'allow_others=false must not append Others.');
assert_not_contains_text($withoutOthers, 'name="sizes_other"', 'allow_others=false must not render a custom input.');

$withOthers = render_service_field('sizes', $baseConfig + ['allow_others' => true]);
assert_contains_text($withOthers, '<option value="Others" data-price="0">Others</option>', 'allow_others=true appends a zero-price Others option.');
assert_contains_text($withOthers, 'name="sizes_other"', 'allow_others=true renders the custom input.');
assert_contains_text($withOthers, 'placeholder="Enter custom sizes"', 'select custom input uses the field label in its placeholder.');

$editExisting = render_service_field('sizes', $baseConfig + ['allow_others' => true], [], [
    'customization' => ['Sizes' => '20x28 inches'],
]);
assert_contains_text($editExisting, '<option value="Others" data-price="0" selected>Others</option>', 'custom saved values reopen as Others.');
assert_contains_text($editExisting, 'value="20x28 inches"', 'custom saved values populate the custom input.');

$radio = render_service_field('material', [
    'label' => 'Material',
    'type' => 'radio',
    'options' => [
        ['value' => 'Sintra', 'price' => 0],
        ['value' => 'Acrylic', 'price' => 0],
    ],
    'visible' => true,
    'required' => true,
    'allow_others' => true,
    'default' => null,
    'unit' => 'ft',
    'order' => 2,
]);
assert_contains_text($radio, 'name="material_other"', 'radio allow_others still renders its custom input.');

echo "service_field_allow_others_test: PASS\n";
