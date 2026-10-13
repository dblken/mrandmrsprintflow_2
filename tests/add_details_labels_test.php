<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/add_details_labels.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(
    printflow_add_details_status_label('For Revision') === 'Request Add Details',
    'For Revision maps to Request Add Details'
);
$expect(
    printflow_add_details_status_label('Revision Requested') === 'Request Add Details',
    'Revision Requested maps to Request Add Details'
);
$expect(
    str_contains(printflow_add_details_replace_phrases('Revise Order #12'), 'Request Add Details'),
    'Revise Order phrase is replaced'
);
$expect(
    str_contains(printflow_add_details_order_page_title(99), 'Request Add Details'),
    'order page title uses Request Add Details'
);

if ($failures !== []) {
    fwrite(STDERR, "add_details_labels_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "add_details_labels_test passed.\n";
