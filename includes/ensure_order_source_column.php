<?php
/**
 * Ensure orders.order_source exists for POS/customer source tracking.
 * Safe to include on every request: never throws; logs schema failures server-side.
 */

if (!function_exists('ensure_order_source_column')) {
    function ensure_order_source_column(): bool
    {
        static $completed = false;
        static $ready = false;

        if ($completed) {
            return $ready;
        }
        $completed = true;

        if (!function_exists('db_query')) {
            require_once __DIR__ . '/db.php';
        }

        global $conn;
        if (!($conn instanceof mysqli)) {
            error_log('[ensure_order_source_column] Database connection unavailable; skipping schema ensure.');
            return false;
        }

        try {
            $ordersTable = db_query("SHOW TABLES LIKE 'orders'");
            if ($ordersTable === [] || !is_array($ordersTable)) {
                if (function_exists('printflow_db_record_error')) {
                    printflow_db_record_error([
                        'stage' => 'schema_check',
                        'error' => 'orders table missing or SHOW TABLES failed',
                        'sql' => "SHOW TABLES LIKE 'orders'",
                    ]);
                }
                error_log('[ensure_order_source_column] orders table not found; skipping order_source ensure.');
                return false;
            }

            if (function_exists('db_table_has_column') && db_table_has_column('orders', 'order_source')) {
                $columns = db_query("SHOW COLUMNS FROM `orders` LIKE 'order_source'");
                $column = is_array($columns[0] ?? null) ? $columns[0] : null;
                if ($column !== null) {
                    $columnType = strtolower(trim((string)($column['Type'] ?? '')));
                    if (str_starts_with($columnType, 'enum(') && !str_contains($columnType, 'pos_draft')) {
                        $sql = "ALTER TABLE `orders`
                                MODIFY COLUMN `order_source` VARCHAR(32) NOT NULL DEFAULT 'customer'";
                        if (@$conn->query($sql)) {
                            error_log('[ensure_order_source_column] Expanded orders.order_source to VARCHAR for POS draft lifecycle values.');
                            if (function_exists('db_table_has_column')) {
                                db_table_has_column('orders', 'order_source', true);
                            }
                        } else {
                            $message = (string)$conn->error;
                            if (function_exists('printflow_db_record_error')) {
                                printflow_db_record_error([
                                    'stage' => 'schema_alter',
                                    'error' => $message,
                                    'errno' => $conn->errno,
                                    'sqlstate' => $conn->sqlstate,
                                    'sql' => $sql,
                                ]);
                            }
                            error_log('[ensure_order_source_column] Failed to expand orders.order_source: ' . $message);
                            $ready = true;
                            return true;
                        }
                    }
                }

                $ready = true;
                return true;
            }

            $afterClause = '';
            if (function_exists('db_table_has_column') && db_table_has_column('orders', 'order_type')) {
                $afterClause = ' AFTER `order_type`';
            }

            $sql = "ALTER TABLE `orders`
                    ADD COLUMN `order_source` VARCHAR(32) NOT NULL DEFAULT 'customer'{$afterClause}";
            if (@$conn->query($sql)) {
                error_log('[ensure_order_source_column] Added orders.order_source column.');
                if (function_exists('db_table_has_column')) {
                    db_table_has_column('orders', 'order_source', true);
                }
                $ready = true;
                return true;
            }

            $message = (string)$conn->error;
            if (function_exists('printflow_db_record_error')) {
                printflow_db_record_error([
                    'stage' => 'schema_alter',
                    'error' => $message,
                    'errno' => $conn->errno,
                    'sqlstate' => $conn->sqlstate,
                    'sql' => $sql,
                ]);
            }
            error_log('[ensure_order_source_column] Failed to add orders.order_source: ' . $message);
            return false;
        } catch (Throwable $e) {
            if (function_exists('printflow_db_record_error')) {
                printflow_db_record_error([
                    'stage' => 'exception',
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
            }
            error_log('[ensure_order_source_column] Schema ensure exception: ' . $e->getMessage());
            return false;
        }
    }
}

ensure_order_source_column();
