<?php

declare(strict_types=1);

require_once __DIR__ . '/provider_payments.php';

if (!function_exists('printflow_order_staff_completion_payment_gate')) {
    /**
     * @return array{allowed:bool, payment_status?:string, sync_payment_status?:string, reason?:string}
     */
    function printflow_order_staff_completion_payment_gate(int $orderId, array $order): array
    {
        $paymentStatusRaw = trim((string)($order['payment_status'] ?? ''));
        $paymentStatus = strtolower($paymentStatusRaw);
        if (in_array($paymentStatus, ['paid', 'fully paid'], true)) {
            return ['allowed' => true, 'payment_status' => $paymentStatusRaw];
        }

        $totalAmount = (float)($order['total_amount'] ?? 0);
        if ($totalAmount > 0 && db_table_has_column('orders', 'amount_paid')) {
            $amountPaid = (float)($order['amount_paid'] ?? 0);
            if ($amountPaid + 0.0001 >= $totalAmount) {
                return ['allowed' => true, 'sync_payment_status' => 'Paid'];
            }
        }

        $orderSource = strtolower(trim((string)($order['order_source'] ?? '')));
        if (in_array($orderSource, ['pos', 'walk-in'], true)) {
            $providerPayment = printflow_provider_payment_find('order', $orderId, 'pos', printflow_paymongo_mode());
            if (($providerPayment['status'] ?? '') === 'paid') {
                return ['allowed' => true, 'sync_payment_status' => 'Paid'];
            }
        }

        return [
            'allowed' => false,
            'payment_status' => $paymentStatusRaw !== '' ? $paymentStatusRaw : 'Unpaid',
            'reason' => 'Cannot mark as Completed: payment must be Paid.'
                . ' Current payment status: ' . ($paymentStatusRaw !== '' ? $paymentStatusRaw : 'Unpaid') . '.',
        ];
    }
}
