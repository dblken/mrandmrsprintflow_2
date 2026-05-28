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
                'short_label' => 'POS Staff',
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
            'short_label' => 'Online Staff',
            'focus_label' => 'Online Focus',
            'theme_class' => 'printflow-staff-online',
            'accent' => '#7c3aed',
            'soft' => '#ddd6fe',
            'modules' => ['dashboard', 'orders', 'customizations', 'chats', 'reviews', 'reports', 'notifications', 'profile'],
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

if (!function_exists('printflow_staff_order_source_sql')) {
    function printflow_staff_order_source_sql(string $orderAlias = 'o', ?string $role = null): string {
        $role = printflow_normalize_staff_access_role($role ?? printflow_get_staff_access_role());
        $posPredicate = "(
            LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos', 'walk-in')
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
            return strtolower(trim((string)$orderRows[0]['order_source']));
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
        $orderSource = strtolower(trim((string)$orderSource));
        $isPos = in_array($orderSource, ['pos', 'walk-in'], true);

        if ($staffRole === 'pos') {
            return $isPos;
        }

        return !$isPos && $orderSource !== 'pos_merged';
    }
}
