<?php
/**
 * Staff service order detail payload for modal + API (JSON-safe).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/service_order_helper.php';
require_once __DIR__ . '/branch_context.php';

if (!function_exists('staff_service_order_placement_image_url')) {
    function staff_service_order_placement_image_url(string $placement_value): ?string {
        static $map = [
            'Front Center Print'     => 'Front Center Print.webp',
            'Back Upper Print'       => 'Back Upper Print.webp',
            'Left/Right Chest Print' => 'Left Right Chest Print.webp',
            'Bottom Hem Print'       => 'Buttom Hem Print.webp',
            'Sleeve Print'           => 'Sleeve Print.webp',
            'Long Sleeve Arm Print'  => 'Long Sleeve Arm Print.webp',
        ];
        $base = (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/images/tshirt_replacement/';
        $t = trim($placement_value);
        if ($t === '') {
            return null;
        }
        if (isset($map[$t])) {
            return $base . rawurlencode($map[$t]);
        }
        foreach ($map as $label => $file) {
            if (strcasecmp($label, $t) === 0) {
                return $base . rawurlencode($file);
            }
        }
        return null;
    }
}

if (!function_exists('service_order_staff_status_pill_class')) {
    function service_order_staff_status_pill_class(string $status): string {
        $s = strtoupper(trim($status));
        if (in_array($s, ['COMPLETED'], true)) {
            return 'badge-fulfilled';
        }
        if (in_array($s, ['REJECTED', 'CANCELLED'], true)) {
            return 'badge-cancelled';
        }
        if (in_array($s, ['PROCESSING', 'APPROVED', 'IN_PRODUCTION'], true)) {
            return 'badge-confirmed';
        }
        return 'badge-partial';
    }
}

if (!function_exists('so_first_detail')) {
    function so_first_detail(array $detail_by_key, array $keys): ?string {
        foreach ($keys as $key) {
            $k = strtolower((string)$key);
            if (!empty($detail_by_key[$k]['field_value'])) {
                $v = trim((string)$detail_by_key[$k]['field_value']);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }
}

/**
 * @return array<string,mixed>|null
 */
function service_order_staff_modal_data(int $order_id): ?array {
    if ($order_id < 1) {
        return null;
    }

    $branchFilter = printflow_branch_filter_for_user();
    $branchSql = '';
    $branchTypes = '';
    $branchParams = [];
    if ($branchFilter !== null) {
        $branchSql = ' AND (so.branch_id = ? OR so.branch_id IS NULL)';
        $branchTypes = 'i';
        $branchParams = [(int)$branchFilter];
    }

    $order = db_query(
        "SELECT so.*, c.first_name, c.last_name, c.email, c.contact_number, c.customer_type, c.transaction_count 
         FROM service_orders so 
         LEFT JOIN customers c ON so.customer_id = c.customer_id 
         WHERE so.id = ? AND " . printflow_customer_exclusion_customer_scope_sql('so.customer_id') . $branchSql,
        'i' . $branchTypes,
        array_merge([$order_id], $branchParams)
    );
    if (empty($order)) {
        return null;
    }
    $order = $order[0];

    $details = db_query(
        "SELECT field_name, field_value FROM service_order_details WHERE order_id = ?",
        'i',
        [$order_id]
    );
    $files = db_query(
        "SELECT id, file_data, mime_type, original_name, file_path FROM service_order_files WHERE order_id = ?",
        'i',
        [$order_id]
    );

    $svc_lower = strtolower((string)($order['service_name'] ?? ''));
    $is_tshirt_like = (strpos($svc_lower, 't-shirt') !== false
        || strpos($svc_lower, 'tshirt') !== false
        || strpos($svc_lower, 'shirt printing') !== false);

    $detail_by_key = [];
    foreach ($details as $d) {
        $detail_by_key[strtolower((string)$d['field_name'])] = $d;
    }

    $w_ft = so_first_detail($detail_by_key, ['width_ft', 'width']);
    $h_ft = so_first_detail($detail_by_key, ['height_ft', 'height']);
    $dim_raw = so_first_detail($detail_by_key, ['dimensions', 'size_dimensions']);
    if ($w_ft !== null && $h_ft !== null) {
        $dimensions_display = $w_ft . "' × " . $h_ft . "'";
    } elseif ($dim_raw !== null) {
        $dimensions_display = $dim_raw;
    } else {
        $dimensions_display = null;
    }

    $qty_tile = $detail_by_key['quantity']['field_value'] ?? null;
    $qty_display = null;
    if ($qty_tile !== null && trim((string)$qty_tile) !== '') {
        $qv = trim((string)$qty_tile);
        $qty_display = preg_match('/\b(pc|pcs|piece|pieces)\b/i', $qv) ? $qv : ($qv . ' pcs');
    }

    $notes_body = null;
    foreach (['notes', 'production_notes', 'additional_notes', 'special_instructions'] as $nk) {
        if (!empty($detail_by_key[$nk]['field_value'])) {
            $notes_body = (string)$detail_by_key[$nk]['field_value'];
            break;
        }
    }

    $priority_val = so_first_detail($detail_by_key, ['priority']);
    $due_val = so_first_detail($detail_by_key, ['due_date', 'needed_date', 'needed_by', 'date_needed']);
    $amount_paid_val = so_first_detail($detail_by_key, ['amount_paid', 'amount paid']);

    $pending_like = in_array($order['status'], ['Pending', 'Pending Review', 'Pending Approval', 'For Revision'], true);
    $priority_is_high = $priority_val !== null && stripos($priority_val, 'HIGH') !== false;
    $due_ts = ($due_val !== null && $due_val !== '') ? strtotime($due_val) : false;
    $due_overdue = (bool)($due_ts && $due_ts < strtotime('today'));

    $status_pill_class = service_order_staff_status_pill_class((string)($order['status'] ?? ''));
    $customer_type_label = $order['customer_type'] ?? 'REGULAR';
    $cust_badge_class = strtoupper((string)$customer_type_label) === 'NEW' ? 'badge-confirmed' : 'badge-fulfilled';

    $skip_spec_keys = ['notes', 'production_notes', 'additional_notes', 'special_instructions', 'branch_id'];
    $spec_rows = [];
    foreach ($details as $d) {
        $k = strtolower((string)$d['field_name']);
        if (in_array($k, $skip_spec_keys, true)) {
            continue;
        }
        if ($qty_display !== null && $k === 'quantity') {
            continue;
        }
        $placement_url = ($is_tshirt_like && stripos($d['field_name'], 'placement') !== false)
            ? staff_service_order_placement_image_url((string)$d['field_value'])
            : null;
        $spec_rows[] = [
            'field_name'    => (string)$d['field_name'],
            'field_value'   => (string)$d['field_value'],
            'label'         => ucwords(str_replace('_', ' ', (string)$d['field_name'])),
            'placement_url' => $placement_url,
        ];
    }

    $customer_full_name = trim((string)(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')));
    $customer_initial = $customer_full_name !== '' ? strtoupper(mb_substr($customer_full_name, 0, 1, 'UTF-8')) : '?';
    $customer_contact = trim((string)($order['contact_number'] ?? ''));
    if ($customer_contact === '' && !empty($order['email'])) {
        $customer_contact = (string)$order['email'];
    }
    $show_email_row = !empty($order['email']) && $customer_contact !== (string)$order['email'];

    $svc_line_title = (string)($order['service_name'] ?? '');
    if ($qty_display !== null) {
        $svc_line_title .= ' × ' . $qty_display;
    }

    $base = defined('BASE_URL') ? BASE_URL : '/printflow';
    $file_icon = $base . '/public/assets/images/services/default.png';

    $files_out = [];
    foreach ($files as $f) {
        $has_blob = !empty($f['file_data']);
        $has_legacy = !empty($f['file_path'] ?? '');
        $is_img_mime = in_array($f['mime_type'] ?? '', ['image/jpeg', 'image/jpg', 'image/png'], true);
        $display_name = $f['original_name'] ?: 'design file';
        $serve_file = $base . '/public/serve_design.php?type=service_file&id=' . (int)$f['id'];

        if ($has_blob) {
            $files_out[] = [
                'name'        => $display_name,
                'preview_url' => $is_img_mime ? $serve_file : '',
                'open_url'    => $serve_file,
                'is_image'    => $is_img_mime,
            ];
        } elseif ($has_legacy) {
            $ext = strtolower(pathinfo($f['file_path'], PATHINFO_EXTENSION));
            $is_img = in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
            $full_url = $base . '/' . $f['file_path'];
            $files_out[] = [
                'name'        => $display_name ?: basename($f['file_path']),
                'preview_url' => $is_img ? $full_url : '',
                'open_url'    => $full_url,
                'is_image'    => $is_img,
            ];
        } else {
            $files_out[] = [
                'name'        => $display_name,
                'preview_url' => '',
                'open_url'    => '',
                'is_image'    => false,
            ];
        }
    }

    $show_approve_block = in_array($order['status'], ['Pending', 'Pending Review', 'Pending Approval'], true);
    $show_cancel_only = !$show_approve_block && !in_array($order['status'], ['Completed', 'Rejected'], true);

    return [
        'id'                   => (int)$order['id'],
        'customer_id'          => (int)($order['customer_id'] ?? 0),
        'service_name'         => (string)($order['service_name'] ?? ''),
        'status'               => (string)($order['status'] ?? ''),
        'total_price'          => $order['total_price'] ?? 0,
        'formatted_total'      => format_currency($order['total_price'] ?? 0),
        'created_at'           => (string)($order['created_at'] ?? ''),
        'formatted_created'    => format_datetime($order['created_at'] ?? ''),
        'customer_full_name'   => $customer_full_name,
        'customer_initial'     => $customer_initial,
        'customer_type'        => (string)$customer_type_label,
        'customer_email'       => (string)($order['email'] ?? ''),
        'customer_contact'     => $customer_contact,
        'show_email_row'       => $show_email_row,
        'dimensions_display'   => $dimensions_display,
        'qty_display'          => $qty_display,
        'pending_like'         => $pending_like,
        'priority_val'         => $priority_val,
        'priority_is_high'     => $priority_is_high,
        'due_val'              => $due_val,
        'due_overdue'          => $due_overdue,
        'amount_paid_display'  => $amount_paid_val !== null ? (string)$amount_paid_val : '₱0.00',
        'status_pill_class'    => $status_pill_class,
        'cust_badge_class'     => $cust_badge_class,
        'svc_line_title'       => $svc_line_title,
        'notes_plain'          => $notes_body !== null ? $notes_body : '',
        'spec_rows'            => $spec_rows,
        'files'                => $files_out,
        'file_icon_fallback'   => $file_icon,
        'show_approve_block'   => $show_approve_block,
        'show_cancel_only'     => $show_cancel_only,
    ];
}
