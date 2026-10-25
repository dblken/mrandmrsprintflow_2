<?php
$source = file_get_contents(__DIR__ . '/../customer/checkout.php');
if (!preg_match('/\$order_sql\s*=\s*"(INSERT INTO orders.*?VALUES\s*\(.*?\))"\s*;/s', $source, $sqlMatch)) {
    fwrite(STDERR, "FAIL: could not locate customer order INSERT statement.\n");
    exit(1);
}
$sql = $sqlMatch[1];
if (!preg_match('/db_execute\(\$order_sql,\s*\x27([^\x27]+)\x27,\s*\[(.*?)\]\)/s', $source, $bindMatch)) {
    fwrite(STDERR, "FAIL: could not locate order bind call.\n");
    exit(1);
}
$types = $bindMatch[1];
$params = $bindMatch[2];
$placeholderCount = substr_count($sql, '?');
$typeCount = strlen($types);
$paramCount = preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*|\$[A-Za-z_][A-Za-z0-9_]*\[[^]]+\]/', $params);
if ($placeholderCount !== 10 || $typeCount !== $placeholderCount || $paramCount !== $placeholderCount) {
    fwrite(STDERR, "FAIL: customer order bind mismatch: placeholders=$placeholderCount types=$typeCount params=$paramCount.\n");
    exit(1);
}
if ($types !== 'iiiddsssss') {
    fwrite(STDERR, "FAIL: unexpected order parameter types: $types.\n");
    exit(1);
}
echo "Customer order bind contract passed: 10 placeholders, 10 types, 10 values.\n";
