<?php

/** Customer-facing label for a paid order that has not reached fulfillment. */
function printflow_customer_paid_order_waiting_label(string $status, bool $paymentReceived, bool $readyMade): string {
    if (!$paymentReceived) {
        return '';
    }

    $normalized = strtolower(trim(str_replace(['–', '—'], '-', $status)));
    $downstream = [
        'in production', 'processing', 'printing', 'paid - in process',
        'ready for pickup', 'approved design', 'to receive', 'completed',
        'to rate', 'rated', 'cancelled', 'rejected',
    ];
    if (in_array($normalized, $downstream, true)) {
        return '';
    }

    return $readyMade ? 'Paid / Awaiting Fulfillment' : 'Payment Received / Awaiting Production';
}
