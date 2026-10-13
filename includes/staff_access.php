<?php
/**
 * Staff access helpers for PrintFlow's shared staff system.
 *
 * This keeps the same staff area, but separates visibility and data scope
 * between walk-in POS staff and online / production staff.
 */

if (!function_exists('printflow_normalize_staff_access_role')) {
    function printflow_normalize_staff_access_role($value): string {
        $role = strtolower(trim((string)$value));
        return in_array($role, ['pos', 'online'], true) ? $role : 'online';
    }
}

if (!function_exists('printflow_staff_role_options')) {
    function printflow_staff_role_options(): array {
        return [
            'front_desk_staff' => [
                'role' => 'Staff',
                'position' => 'Front Desk / POS Staff',
                'access_role' => 'pos',
                'label' => 'Front Desk / POS Staff',
            ],
            'online_production_staff' => [
                'role' => 'Staff',
                'position' => 'Online / Production Staff',
                'access_role' => 'online',
                'label' => 'Online / Production Staff',
            ],
        ];
    }
}

if (!function_exists('printflow_resolve_staff_role_payload')) {
    function printflow_resolve_staff_role_payload($selectedRole): array {
        $selectedRole = trim((string)$selectedRole);
        $options = printflow_staff_role_options();
        if (isset($options[$selectedRole])) {
            return $options[$selectedRole];
        }
        return [
            'role' => $selectedRole !== '' ? $selectedRole : 'Staff',
            'position' => null,
            'access_role' => $selectedRole === 'Manager' ? 'online' : 'online',
            'label' => $selectedRole !== '' ? $selectedRole : 'Staff',
        ];
    }
}

if (!function_exists('printflow_detect_staff_access_role')) {
    function printflow_detect_staff_access_role($position = null): string {
        $positionText = strtolower(trim((string)$position));
        if ($positionText === '') {
            return 'online';
        }

        $posKeywords = [
            'cashier',
            'front desk',
            'frontdesk',
            'pos',
            'walk-in',
            'walk in',
            'counter',
            'sales clerk',
            'store staff',
        ];
        foreach ($posKeywords as $keyword) {
            if (strpos($positionText, $keyword) !== false) {
                return 'pos';
            }
        }

        return 'online';
    }
}

if (!function_exists('printflow_staff_role_display_name')) {
    function printflow_staff_role_display_name($role, $position = null): string {
        $role = trim((string)$role);
        $position = trim((string)$position);
        if ($role === 'Staff') {
            foreach (printflow_staff_role_options() as $option) {
                if ($position !== '' && strcasecmp($position, (string)$option['position']) === 0) {
                    return (string)$option['label'];
                }
            }

            return printflow_detect_staff_access_role($position) === 'pos'
                ? 'Front Desk / POS Staff'
                : 'Online / Production Staff';
        }

        return $role;
    }
}

if (!function_exists('printflow_resolve_staff_access_role_from_user')) {
    function printflow_resolve_staff_access_role_from_user(array $user): string {
        if (!empty($user['staff_access_role'])) {
            return printflow_normalize_staff_access_role($user['staff_access_role']);
        }
        return printflow_detect_staff_access_role($user['position'] ?? null);
    }
}

if (!function_exists('printflow_get_staff_access_role')) {
    function printflow_get_staff_access_role(): string {
        if (isset($_SESSION['staff_access_role'])) {
            return printflow_normalize_staff_access_role($_SESSION['staff_access_role']);
        }

        if (($_SESSION['user_type'] ?? null) !== 'Staff') {
            return 'online';
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 || !function_exists('db_query')) {
            return 'online';
        }

        $row = db_query(
            "SELECT position FROM users WHERE user_id = ? LIMIT 1",
            'i',
            [$userId]
        );
        $resolved = printflow_detect_staff_access_role($row[0]['position'] ?? null);
        $_SESSION['staff_access_role'] = $resolved;
        $_SESSION['staff_position'] = (string)($row[0]['position'] ?? '');
        return $resolved;
    }
}

if (!function_exists('printflow_get_staff_access_meta')) {
    function printflow_get_staff_access_meta(?string $role = null): array {
        $role = printflow_normalize_staff_access_role($role ?? printflow_get_staff_access_role());
        if ($role === 'pos') {
            return [
                'key' => 'pos',
                'label' => 'Front Desk / POS Staff',
                'short_label' => 'Counter Staff',
                'focus_label' => 'Walk-in Focus',
                'theme_class' => 'printflow-staff-pos',
                'accent' => '#2563eb',
                'soft' => '#bfdbfe',
                'modules' => ['dashboard', 'pos', 'orders', 'customizations', 'products', 'reports', 'notifications', 'profile'],
            ];
        }

        return [
            'key' => 'online',
            'label' => 'Online / Production Staff',
            'short_label' => 'Online Operations Staff',
            'focus_label' => 'Online Focus',
            'theme_class' => 'printflow-staff-online',
            'accent' => '#7c3aed',
            'soft' => '#ddd6fe',
            'modules' => ['dashboard', 'orders', 'payment_verification', 'customizations', 'products', 'chats', 'reviews', 'reports', 'notifications', 'profile'],
        ];
    }
}

if (!function_exists('printflow_staff_can_access_module')) {
    function printflow_staff_can_access_module(string $module): bool {
        $userType = (string)($_SESSION['user_type'] ?? '');
        if ($userType !== 'Staff') {
            return true;
        }
        $meta = printflow_get_staff_access_meta();
        return in_array($module, $meta['modules'], true);
    }
}

if (!function_exists('printflow_staff_home_url')) {
    function printflow_staff_home_url(): string {
        $role = printflow_get_staff_access_role();
        if ($role === 'pos') {
            return AUTH_REDIRECT_BASE . '/staff/pos/dashboard.php';
        }
        return AUTH_REDIRECT_BASE . '/staff/online/dashboard.php';
    }
}

if (!function_exists('printflow_require_staff_module')) {
    function printflow_require_staff_module(string $module): void {
        if (($_SESSION['user_type'] ?? '') !== 'Staff') {
            return;
        }

        if (printflow_staff_can_access_module($module)) {
            return;
        }

        header('Location: ' . printflow_staff_home_url());
        exit;
    }
}

if (!function_exists('printflow_order_channel_is_pos')) {
    function printflow_order_channel_is_pos(?string $orderSource): bool
    {
        return in_array(strtolower(trim((string)$orderSource)), ['pos', 'walk-in', 'pos_merged', 'pos_draft'], true);
    }
}

if (!function_exists('printflow_resolve_staff_order_source')) {
    /**
     * Resolve the staff channel for an order (POS vs online), including Set Price drafts.
     */
    function printflow_resolve_staff_order_source(?int $orderId, ?string $currentSource = null): string
    {
        $source = strtolower(trim((string)$currentSource));
        if (in_array($source, ['pos', 'walk-in', 'pos_merged', 'pos_draft'], true)) {
            return $source;
        }

        if ($orderId === null || $orderId <= 0 || !function_exists('db_query')) {
            return $source !== '' ? $source : 'customer';
        }

        $sourceCheckRows = db_query(
            "SELECT
                LOWER(TRIM(COALESCE(o.order_source, ''))) AS db_order_source,
                LOWER(TRIM(COALESCE(c.email, ''))) AS customer_email,
                EXISTS(
                    SELECT 1
                    FROM customizations cust
                    WHERE cust.order_id = o.order_id
                      AND (
                          cust.customization_details LIKE '%\"source\":\"POS\"%'
                          OR cust.customization_details LIKE '%\"source\": \"POS\"%'
                      )
                ) AS has_pos_customization,
                EXISTS(
                    SELECT 1
                    FROM order_items oi
                    WHERE oi.order_id = o.order_id
                      AND (
                          oi.customization_data LIKE '%\"source\":\"POS\"%'
                          OR oi.customization_data LIKE '%\"source\": \"POS\"%'
                      )
                ) AS has_pos_order_item
             FROM orders o
             LEFT JOIN customers c ON c.customer_id = o.customer_id
             WHERE o.order_id = ?
             LIMIT 1",
            'i',
            [$orderId]
        );
        $sourceCheck = $sourceCheckRows[0] ?? [];
        $dbOrderSource = strtolower(trim((string)($sourceCheck['db_order_source'] ?? '')));
        if (in_array($dbOrderSource, ['pos', 'walk-in', 'pos_merged', 'pos_draft'], true)) {
            return $dbOrderSource;
        }

        $customerEmail = strtolower(trim((string)($sourceCheck['customer_email'] ?? '')));
        $isWalkInGuest = $customerEmail === 'walkin@pos.local';
        $hasPosCustomization = !empty($sourceCheck['has_pos_customization']);
        $hasPosOrderItem = !empty($sourceCheck['has_pos_order_item']);
        if ($isWalkInGuest || $hasPosCustomization || $hasPosOrderItem) {
            if (function_exists('db_execute')) {
                db_execute(
                    "UPDATE orders
                     SET order_source = 'pos'
                     WHERE order_id = ?
                       AND LOWER(TRIM(COALESCE(order_source, ''))) NOT IN ('pos', 'walk-in', 'pos_merged', 'pos_draft')",
                    'i',
                    [$orderId]
                );
            }
            return 'pos';
        }

        return $source !== '' ? $source : 'customer';
    }
}

if (!function_exists('printflow_staff_order_source_sql')) {
    function printflow_staff_order_source_sql(string $orderAlias = 'o', ?string $role = null): string {
        $role = printflow_normalize_staff_access_role($role ?? printflow_get_staff_access_role());
        $posPredicate = "(
            LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos', 'walk-in', 'pos_merged', 'pos_draft')
            OR EXISTS (
                SELECT 1
                FROM customizations pos_scope
                WHERE pos_scope.order_id = {$orderAlias}.order_id
                  AND pos_scope.customization_details LIKE '%\"source\":\"POS\"%'
                LIMIT 1
            )
        )";

        if ($role === 'pos') {
            return $posPredicate;
        }

        return "(NOT {$posPredicate} AND LOWER(TRIM(COALESCE({$orderAlias}.order_source, 'customer'))) <> 'pos_merged')";
    }
}

if (!function_exists('printflow_resolve_order_source_for_staff_scope')) {
    function printflow_resolve_order_source_for_staff_scope(int $dataId, ?string $notificationType = null): string {
        $dataId = (int)$dataId;
        if ($dataId <= 0 || !function_exists('db_query')) {
            return 'customer';
        }

        $notificationType = strtolower(trim((string)$notificationType));

        if ($notificationType === 'job order') {
            $jobRows = db_query(
                "SELECT COALESCE(o.order_source, 'customer') AS order_source
                 FROM job_orders jo
                 LEFT JOIN orders o ON o.order_id = jo.order_id
                 WHERE jo.id = ? LIMIT 1",
                'i',
                [$dataId]
            );
            if (!empty($jobRows[0]['order_source'])) {
                return strtolower(trim((string)$jobRows[0]['order_source']));
            }
        }

        $orderRows = db_query(
            "SELECT COALESCE(order_source, 'customer') AS order_source
             FROM orders
             WHERE order_id = ? LIMIT 1",
            'i',
            [$dataId]
        );
        if (!empty($orderRows[0]['order_source'])) {
            $rawSource = (string)$orderRows[0]['order_source'];
            if (function_exists('printflow_resolve_staff_order_source')) {
                return printflow_resolve_staff_order_source($dataId, $rawSource);
            }
            return strtolower(trim($rawSource));
        }

        $jobRows = db_query(
            "SELECT COALESCE(o.order_source, 'customer') AS order_source
             FROM job_orders jo
             LEFT JOIN orders o ON o.order_id = jo.order_id
             WHERE jo.id = ? LIMIT 1",
            'i',
            [$dataId]
        );
        if (!empty($jobRows[0]['order_source'])) {
            return strtolower(trim((string)$jobRows[0]['order_source']));
        }

        return 'customer';
    }
}

if (!function_exists('printflow_staff_role_can_access_order_source')) {
    function printflow_staff_role_can_access_order_source(?string $staffRole, ?string $orderSource): bool {
        $staffRole = printflow_normalize_staff_access_role($staffRole ?? printflow_get_staff_access_role());
        $isPos = printflow_order_channel_is_pos($orderSource);

        if ($staffRole === 'pos') {
            return $isPos;
        }

        return !$isPos;
    }
}
