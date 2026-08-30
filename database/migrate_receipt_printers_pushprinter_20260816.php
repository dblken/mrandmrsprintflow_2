<?php

require_once __DIR__ . '/../includes/pos_receipt_printer.php';

printflow_receipt_printer_ensure_schema();
echo "Receipt printer and print queue tables are ready.\n";

