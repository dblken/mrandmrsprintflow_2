<?php
/**
 * Role-aware staff status and notification filter helpers.
 */

require_once __DIR__ . '/staff_access.php';
require_once __DIR__ . '/staff_order_status_buckets.php';

function printflow_staff_status_role(?string $role = null): string {
    if ($role !== null && $role !== '') {
        return printflow_normalize_staff_access_role($role) === 'pos' ? 'pos' : 'online';
    }
    return printflow_get_staff_access_role() === 'pos' ? 'pos' : 'online';
}

/**
 * @return array<string, string> code => label
 */
function printflow_staff_status_filter_options(?string $role = null): array {
    $role = printflow_staff_status_role($role);
    if ($role === 'pos') {
        return [
            '' => 'All Statuses',
            'PENDING' => 'Pending',
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
        ];
    }

    return [
        'ALL' => 'All Statuses',
        'INQUIRY' => 'Inquiry & Design',
        'PAYMENT' => 'Payment',
        'PRODUCTION' => 'Production',
        'TO_RECEIVE' => 'To Pickup',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
    ];
}

function printflow_staff_normalize_status_filter(string $raw, ?string $role = null): string {
    $role = printflow_staff_status_role($role);
    $raw = trim($raw);

    if ($role === 'pos') {
        if ($raw === '' || strtoupper($raw) === 'ALL') {
            return '';
        }
        $code = strtoupper($raw);
        return in_array($code, ['PENDING', 'COMPLETED', 'CANCELLED'], true) ? $code : '';
    }

    if ($raw === '' || strtoupper($raw) === 'ALL') {
        return 'ALL';
    }

    $code = strtoupper(str_replace([' ', '-'], '_', $raw));
    $aliases = [
        'INQUIRY_DESIGN' => 'INQUIRY',
        'INQUIRY_&_DESIGN' => 'INQUIRY',
        'TO_PICKUP' => 'TO_RECEIVE',
        'CLOSED' => 'CANCELLED',
        'CANCELLED_REJECTED' => 'CANCELLED',
        'PENDING' => 'INQUIRY',
        'PENDING_REVIEW' => 'INQUIRY',
        'APPROVED' => 'INQUIRY',
        'PROCESSING' => 'PRODUCTION',
        'READY_FOR_PICKUP' => 'TO_RECEIVE',
        'TO_PAY' => 'PAYMENT',
        'DOWNPAYMENT_SUBMITTED' => 'PAYMENT',
    ];
    if (isset($aliases[$code])) {
        $code = $aliases[$code];
    }

    $valid = ['INQUIRY', 'PAYMENT', 'PRODUCTION', 'TO_RECEIVE', 'COMPLETED', 'CANCELLED'];
    return in_array($code, $valid, true) ? $code : 'ALL';
}

/**
 * @return array{sql:string,types:string,params:array<int, mixed>}
 */
function printflow_staff_orders_status_clause(string $filter, string $alias = 'o', ?string $role = null): array {
    $role = printflow_staff_status_role($role);
    $filter = printflow_staff_normalize_status_filter($filter, $role);

    if ($role === 'pos') {
        if ($filter === '') {
            return ['sql' => '1=1', 'types' => '', 'params' => []];
        }
        if ($filter === 'PENDING') {
            return ['sql' => "{$alias}.status NOT IN ('Completed', 'Cancelled', 'Rejected')", 'types' => '', 'params' => []];
        }
        if ($filter === 'COMPLETED') {
            return ['sql' => "{$alias}.status = 'Completed'", 'types' => '', 'params' => []];
        }
        if ($filter === 'CANCELLED') {
            return ['sql' => "{$alias}.status IN ('Cancelled', 'Rejected')", 'types' => '', 'params' => []];
        }
        return ['sql' => '1=1', 'types' => '', 'params' => []];
    }

    if ($filter === 'ALL' || $filter === '') {
        return ['sql' => '1=1', 'types' => '', 'params' => []];
    }

    switch ($filter) {
        case 'INQUIRY':
            return ['sql' => "{$alias}.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'Approved', 'Design Approved', 'Approved Design')", 'types' => '', 'params' => []];
        case 'PAYMENT':
            return ['sql' => staff_orders_sql_payment_bucket($alias), 'types' => '', 'params' => []];
        case 'PRODUCTION':
            return ['sql' => "{$alias}.status IN ('Processing', 'In Production', 'Printing')", 'types' => '', 'params' => []];
        case 'TO_RECEIVE':
            return ['sql' => "{$alias}.status IN ('Ready for Pickup', 'To Pickup', 'To Pick Up')", 'types' => '', 'params' => []];
        case 'COMPLETED':
            return ['sql' => "{$alias}.status = 'Completed'", 'types' => '', 'params' => []];
        case 'CANCELLED':
            return ['sql' => "{$alias}.status IN ('Cancelled', 'Rejected')", 'types' => '', 'params' => []];
        default:
            return ['sql' => '1=1', 'types' => '', 'params' => []];
    }
}

function printflow_staff_job_orders_status_sql(string $filter, string $alias = 'jo', ?string $role = null): string {
    $role = printflow_staff_status_role($role);
    $filter = printflow_staff_normalize_status_filter($filter, $role);

    if ($role === 'pos') {
        if ($filter === '') {
            return " AND {$alias}.status NOT IN ('CANCELLED', 'REJECTED')";
        }
        if ($filter === 'PENDING') {
            return " AND {$alias}.status NOT IN ('COMPLETED', 'CANCELLED', 'REJECTED')";
        }
        if ($filter === 'COMPLETED') {
            return " AND {$alias}.status = 'COMPLETED'";
        }
        if ($filter === 'CANCELLED') {
            return " AND {$alias}.status IN ('CANCELLED', 'REJECTED')";
        }
        return '';
    }

    if ($filter === 'ALL' || $filter === '') {
        return " AND {$alias}.status NOT IN ('CANCELLED', 'REJECTED')";
    }

    switch ($filter) {
        case 'INQUIRY':
            return " AND {$alias}.status IN ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'FOR_REVISION', 'APPROVED')";
        case 'PAYMENT':
            return " AND {$alias}.status IN ('TO_PAY', 'PAYMENT_CONFIRMED', 'TO_VERIFY', 'VERIFY_PAY', 'PENDING_VERIFICATION', 'DOWNPAYMENT_SUBMITTED')";
        case 'PRODUCTION':
            return " AND {$alias}.status IN ('IN_PRODUCTION', 'PROCESSING', 'PRINTING')";
        case 'TO_RECEIVE':
            return " AND {$alias}.status IN ('TO_RECEIVE', 'READY_TO_COLLECT')";
        case 'COMPLETED':
            return " AND {$alias}.status = 'COMPLETED'";
        case 'CANCELLED':
            return " AND {$alias}.status IN ('CANCELLED', 'REJECTED')";
        default:
            return '';
    }
}

function printflow_staff_service_orders_status_sql(string $filter, string $alias = 'so', ?string $role = null): string {
    $role = printflow_staff_status_role($role);
    $filter = printflow_staff_normalize_status_filter($filter, $role);

    if ($role === 'pos') {
        if ($filter === '') {
            return " AND {$alias}.status NOT IN ('Cancelled', 'Rejected')";
        }
        if ($filter === 'PENDING') {
            return " AND {$alias}.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'Processing', 'In Production', 'Printing', 'Approved', 'To Pay')";
        }
        if ($filter === 'COMPLETED') {
            return " AND {$alias}.status = 'Completed'";
        }
        if ($filter === 'CANCELLED') {
            return " AND {$alias}.status IN ('Cancelled', 'Rejected')";
        }
        return '';
    }

    if ($filter === 'ALL' || $filter === '') {
        return " AND {$alias}.status NOT IN ('Cancelled', 'Rejected')";
    }

    switch ($filter) {
        case 'INQUIRY':
            return " AND {$alias}.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'Approved')";
        case 'PAYMENT':
            return " AND {$alias}.status IN ('To Pay', 'Payment Confirmed', 'To Verify', 'Verify Pay', 'Pending Verification', 'Downpayment Submitted')";
        case 'PRODUCTION':
            return " AND {$alias}.status IN ('Processing', 'In Production', 'Printing')";
        case 'TO_RECEIVE':
            return " AND {$alias}.status IN ('Ready for Pickup', 'Ready For Pickup')";
        case 'COMPLETED':
            return " AND {$alias}.status = 'Completed'";
        case 'CANCELLED':
            return " AND {$alias}.status IN ('Cancelled', 'Rejected')";
        default:
            return '';
    }
}

/**
 * @return array<string, string>
 */
function printflow_staff_dashboard_status_labels(?string $role = null): array {
    $role = printflow_staff_status_role($role);
    if ($role === 'pos') {
        return [
            'PENDING' => 'Pending',
            'COMPLETED' => 'Completed',
            'CANCELLED' => 'Cancelled',
        ];
    }

    return [
        'INQUIRY' => 'Inquiry & Design',
        'PAYMENT' => 'Payment',
        'PRODUCTION' => 'Production',
        'TO_RECEIVE' => 'To Pickup',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
    ];
}

function printflow_staff_dashboard_normalize_status_filter(string $raw, ?string $role = null): string {
    return printflow_staff_normalize_status_filter($raw, $role);
}

/**
 * @return array{sql:string,types:string,params:array<int, mixed>}
 */
function printflow_staff_dashboard_status_sql(string $alias, string $filter, ?string $role = null): array {
    return printflow_staff_orders_status_clause($filter, $alias, $role);
}

/**
 * @return array<int, string>
 */
function printflow_staff_notification_type_options(?string $role = null): array {
    $role = printflow_staff_status_role($role);
    $types = ['Order', 'Payment', 'Design', 'Job Order', 'Stock', 'System', 'Status', 'Message', 'Payment Issue'];
    if ($role === 'online') {
        $types[] = 'Rating';
        $types[] = 'Review';
    }
    return $types;
}

function printflow_staff_notification_type_allowed(string $type, ?string $role = null): bool {
    return in_array($type, printflow_staff_notification_type_options($role), true);
}

function printflow_staff_map_orders_status_to_bucket(string $status, ?string $role = null): string {
    $status = trim($status);
    $role = printflow_staff_status_role($role);

    if ($role === 'pos') {
        if ($status === 'Completed') {
            return 'Completed';
        }
        if (in_array($status, ['Cancelled', 'Rejected'], true)) {
            return 'Cancelled';
        }
        return 'Pending';
    }

    if ($status === 'Completed') {
        return 'Completed';
    }
    if (in_array($status, ['Cancelled', 'Rejected'], true)) {
        return 'Cancelled';
    }
    if (in_array($status, ['Ready for Pickup', 'To Pickup', 'To Pick Up'], true)) {
        return 'To Pickup';
    }
    if (in_array($status, ['Processing', 'In Production', 'Printing'], true)) {
        return 'Production';
    }
    if (in_array($status, ['To Pay', 'Payment Confirmed', 'To Verify', 'Pending Verification', 'Verify Pay', 'Downpayment Submitted', 'Payment Rejected'], true)) {
        return 'Payment';
    }
    return 'Inquiry & Design';
}

/**
 * @return array<int, string>
 */
function printflow_staff_report_status_chart_labels(?string $role = null): array {
    $options = printflow_staff_status_filter_options($role);
    $labels = array_values($options);
    if (($labels[0] ?? '') === 'All Statuses') {
        array_shift($labels);
    }
    return $labels;
}
