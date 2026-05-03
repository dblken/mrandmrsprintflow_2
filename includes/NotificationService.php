<?php
/**
 * NotificationService
 * Sends notifications to customers on job order status changes.
 * PrintFlow v2
 */

require_once __DIR__ . '/functions.php';

class NotificationService {

    /** Values allowed by `notifications.type` ENUM (avoid "Data truncated for column 'type'"). */
    private static function normalizeNotificationType(string $type): string {
        $allowed = ['Order', 'Stock', 'System', 'Message', 'Job Order', 'Payment Issue', 'Design', 'Payment', 'Status', 'Rating', 'Review'];
        return in_array($type, $allowed, true) ? $type : 'System';
    }

    /**
     * Status → human-readable notification messages.
     */
    private static $statusMessages = [
        'APPROVED'     => 'Your customization order has been reviewed and approved.',
        'TO_PAY'       => 'Your order is ready. Please proceed to payment of ₱{amount}. {order_no}',
        'VERIFY_PAY'   => 'Your payment has been received and is under verification.',
        'IN_PRODUCTION'=> 'Your payment has been approved. We will now proceed with processing your order.',
        'TO_RECEIVE'   => 'Order Ready! Your order is now ready for pickup. Thank you for choosing Mr. and Mrs. Print!',
        'COMPLETED'    => 'Order Completed. Your order has been successfully picked up. We hope to see you again!',
        'CANCELLED'    => 'Your order has been cancelled. Please contact us for assistance.',
        'FOR REVISION' => 'Revision requested for your order. Reason: {reason}',
    ];

    /**
     * Send a notification to a customer about a job order status change.
     */
    public static function sendJobOrderNotification(int $customerId, int $jobOrderId, string $newStatus, ?string $overrideMessage = null, string $reason = ''): bool {
        if (!$customerId) return false;

        $newStatus = strtoupper($newStatus);
        $message = $overrideMessage ?? (self::$statusMessages[$newStatus] ?? null);
        if (!$message) return false;

        // Fetch order details for dynamic placeholders and linking
        $order = db_query("SELECT id, order_id, estimated_total FROM job_orders WHERE id = ?", 'i', [$jobOrderId]);
        if (!empty($order)) {
            $o = $order[0];
            $amount = number_format((float)($o['estimated_total'] ?? 0), 2);
            // One customer-facing reference (matches orders list / inventory labels), not mixed #JO + #ORD.
            $refLabel = printflow_get_job_inventory_reference((int)$o['id']);
            $orderNo = (string)($refLabel['label'] ?? '');

            $rev_reason = 'No reason provided';
            if (!empty($o['order_id'])) {
                $st = db_query("SELECT revision_reason FROM orders WHERE order_id = ?", 'i', [$o['order_id']]);
                if (!empty($st)) $rev_reason = $st[0]['revision_reason'] ?: 'No reason provided';
            }
            if (!empty($reason)) $rev_reason = $reason;

            // Use standard order ID for linking if it exists, otherwise use job order ID
            $linkId = $o['order_id'] ?: $o['id'];
            $type = $o['order_id'] ? 'Order' : 'Job Order';

            $message = str_replace(
                ['{amount}', '{order_no}', '{reason}'], 
                [$amount, $orderNo, $rev_reason], 
                $message
            );
        } else {
            $message = str_replace(['{amount}', '{order_no}', '{reason}'], ['0.00', '', 'No reason provided'], $message);
            $linkId = $jobOrderId;
            $type = 'Job Order';
        }

        return (bool) create_notification(
            $customerId,
            'Customer',
            $message,
            $type,
            false,
            false,
            $linkId
        );
    }

    /**
     * Send a generic custom notification to a customer.
     */
    public static function send(int $customerId, string $type, string $message, int $dataId = 0): bool {
        if (!$customerId) return false;

        $type = self::normalizeNotificationType($type);
        return (bool) create_notification(
            $customerId,
            'Customer',
            $message,
            $type,
            false,
            false,
            $dataId > 0 ? $dataId : null
        );
    }
}
