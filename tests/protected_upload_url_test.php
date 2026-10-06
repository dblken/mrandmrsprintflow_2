<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/order_items_persistence.php';

$serve = 'https://example.com/public/serve_design.php?type=order_item&id=13719';
$direct = 'https://example.com/uploads/orders/revision_12120_8_13719_test.jpg';

$display = printflow_order_design_display_url($serve, $direct);
if ($display !== $serve) {
    fwrite(STDERR, "Expected serve URL to win\n");
    exit(1);
}

$fallback = printflow_order_design_display_url(null, $direct);
if ($fallback !== $direct) {
    fwrite(STDERR, "Expected direct fallback\n");
    exit(1);
}

echo "protected_upload_url_test: OK\n";
