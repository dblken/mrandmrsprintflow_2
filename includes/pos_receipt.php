<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/runtime_config.php';

function printflow_pos_receipt_item_name(array $item): string {
    $customization = json_decode((string)($item['customization_data'] ?? ''), true);
    $customization = is_array($customization) ? $customization : [];
    $name = trim((string)($customization['service_type'] ?? $customization['product_type'] ?? $item['product_name'] ?? 'Item'));
    $size = trim((string)($customization['size'] ?? $customization['dimensions'] ?? ''));
    return $size !== '' ? $name . ' (' . $size . ')' : $name;
}

function printflow_pos_build_receipt(int $orderId, float $amountTendered = 0.0): array {
    $orders = db_query(
        "SELECT o.*, c.first_name, c.last_name, c.email, c.contact_number,
                b.branch_name, b.address AS branch_address, b.contact_number AS branch_contact,
                d.code AS discount_code, d.description AS discount_description, d.discount_percent
         FROM orders o
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         LEFT JOIN branches b ON b.id = o.branch_id
         LEFT JOIN discounts d ON d.discount_id = o.discount_id
         WHERE o.order_id = ? AND o.payment_status = 'Paid'
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    if (empty($orders)) {
        return [];
    }
    $order = $orders[0];
    $providerRows = db_query(
        "SELECT provider_payment_id, paid_at, amount_centavos, payment_method
         FROM provider_payments
         WHERE order_id = ? AND channel = 'pos' AND provider = 'paymongo'
           AND mode IN ('test', 'live') AND status = 'paid' AND fulfillment_applied_at IS NOT NULL
         ORDER BY id DESC LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    $providerPayment = $providerRows[0] ?? null;
    $rows = db_query(
        "SELECT oi.quantity, oi.unit_price, oi.customization_data, p.name AS product_name
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ? ORDER BY oi.order_item_id",
        'i',
        [$orderId]
    ) ?: [];

    $items = [];
    $subtotal = 0.0;
    foreach ($rows as $row) {
        $quantity = (int)$row['quantity'];
        $unitPrice = (float)$row['unit_price'];
        $lineTotal = $quantity * $unitPrice;
        $customization = json_decode((string)($row['customization_data'] ?? ''), true);
        $customization = printflow_customization_display_specs(is_array($customization) ? $customization : [], [
            'include_service' => false, 'include_design' => true, 'include_notes' => true, 'include_quantity' => false
        ]);
        $subtotal += $lineTotal;
        $items[] = [
            'name' => printflow_pos_receipt_item_name($row),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'customization' => $customization,
        ];
    }
    $total = (float)$order['total_amount'];
    $discountAmount = max(0, $subtotal - $total);
    $shop = printflow_load_runtime_config(
        'shop',
        dirname(__DIR__) . '/public/assets/uploads/shop_config.json'
    );
    $shopLogo = trim((string)($shop['logo'] ?? ''));
    $logoUrl = $shopLogo !== ''
        ? rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/printflow'), '/') . '/public/assets/uploads/' . rawurlencode(basename($shopLogo))
        : '';
    $isGuest = strtolower(trim((string)($order['email'] ?? ''))) === 'walkin@pos.local';

    $receiptDateTime = (string)($providerPayment['paid_at'] ?? $order['order_date']);
    $storedAmountPaid = isset($order['amount_paid']) ? (float)$order['amount_paid'] : 0.0;
    $amountPaid = $providerPayment
        ? round(((int)($providerPayment['amount_centavos'] ?? 0)) / 100, 2)
        : round($amountTendered > 0 ? $amountTendered : ($storedAmountPaid > 0 ? $storedAmountPaid : $total), 2);
    $paymentMethod = $providerPayment
        ? 'PayMongo - QRPh'
        : trim((string)($order['payment_method'] ?? 'Cash'));
    $cashier = trim((string)($_SESSION['user_name'] ?? ''));
    $cashierId = (int)($order['price_finalized_by'] ?? 0);
    if ($cashierId > 0) {
        $cashierRows = db_query(
            'SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1',
            'i',
            [$cashierId]
        ) ?: [];
        $originalCashier = trim((string)($cashierRows[0]['first_name'] ?? '') . ' ' . (string)($cashierRows[0]['last_name'] ?? ''));
        if ($originalCashier !== '') $cashier = $originalCashier;
    }
    return [
        'receipt_number' => 'POS-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
        'order_id' => $orderId,
        'qr_payload' => function_exists('printflow_receipt_qr_payload')
            ? printflow_receipt_qr_payload($orderId)
            : 'PF1:ORDER:' . $orderId,
        'date_time' => $receiptDateTime,
        'date_time_display' => date('M j, Y h:i A', strtotime($receiptDateTime) ?: time()),
        'cashier' => $cashier !== '' ? $cashier : 'Staff',
        'company' => [
            'name' => 'PrintFlow',
            'logo_url' => $logoUrl,
            'branch_name' => (string)($order['branch_name'] ?? 'Main Branch'),
            'address' => (string)($order['branch_address'] ?? ''),
            'contact' => (string)($order['branch_contact'] ?? ''),
        ],
        'customer' => [
            'name' => $isGuest ? 'Walk-in Guest' : trim((string)$order['first_name'] . ' ' . (string)$order['last_name']),
            'email' => $isGuest ? '' : (string)($order['email'] ?? ''),
            'phone' => $isGuest ? '' : (string)($order['contact_number'] ?? ''),
        ],
        'items' => $items,
        'subtotal' => round($subtotal, 2),
        'discount' => [
            'code' => (string)($order['discount_code'] ?? ''),
            'description' => (string)($order['discount_description'] ?? ''),
            'percent' => (float)($order['discount_percent'] ?? 0),
            'amount' => round($discountAmount, 2),
        ],
        'total' => round($total, 2),
        'payment' => [
            'reference' => (string)($providerPayment['provider_payment_id'] ?? $order['payment_reference'] ?? ''),
            'method' => $paymentMethod !== '' ? $paymentMethod : 'Cash',
            'amount_paid' => $amountPaid,
            'paid_at' => (string)($providerPayment['paid_at'] ?? ''),
            'change' => round(max(0, $amountPaid - $total), 2),
            'balance' => round(max(0, $total - $amountPaid), 2),
            'status' => (string)($order['payment_status'] ?? 'Paid'),
        ],
    ];
}

/** Load the immutable payload from the first physical copy, if one exists. */
function printflow_pos_load_original_printed_receipt(int $orderId): array {
    if ($orderId <= 0 || !function_exists('printflow_receipt_printer_ensure_schema')) {
        return [];
    }
    printflow_receipt_printer_ensure_schema();
    $rows = db_query(
        "SELECT receipt_payload FROM receipt_print_jobs
         WHERE order_id = ? AND job_type = 'pos_receipt'
         ORDER BY id ASC LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    $payload = json_decode((string)($rows[0]['receipt_payload'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}
