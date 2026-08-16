<?php

/** Final customer receipts are available only after confirmed payment and production progress. */
function printflow_customer_receipt_is_available(string $orderStatus, string $paymentStatus): bool {
    if (strcasecmp(trim($paymentStatus), 'Paid') !== 0) return false;
    $status = str_replace(['–', '—', '_'], ['-', '-', ' '], $orderStatus);
    $status = strtolower(trim((string)preg_replace('/\s+/', ' ', $status)));
    return in_array($status, [
        'processing', 'in production', 'printing', 'paid - in process', 'paid-in-process',
        'approved design', 'ready for pickup', 'to receive', 'completed', 'to rate', 'rated'
    ], true);
}
