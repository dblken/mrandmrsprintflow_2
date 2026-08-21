<?php
declare(strict_types=1);

/**
 * Pure PayMongo webhook normalization and validation helpers.
 * No helper in this file performs I/O or changes payment state.
 */

function printflow_paymongo_webhook_find_identifier($value, string $prefix): string {
    $pattern = '/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]+$/';
    if (is_string($value) && preg_match($pattern, $value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }
    foreach ($value as $item) {
        $found = printflow_paymongo_webhook_find_identifier($item, $prefix);
        if ($found !== '') {
            return $found;
        }
    }
    return '';
}

function printflow_paymongo_webhook_resource_attributes(array $resource): array {
    if (isset($resource['attributes']) && is_array($resource['attributes'])) {
        return $resource['attributes'];
    }
    if (isset($resource['data']['attributes']) && is_array($resource['data']['attributes'])) {
        return $resource['data']['attributes'];
    }
    return [];
}

function printflow_paymongo_webhook_provider_datetime($value): ?string {
    if (is_numeric($value) && (int)$value > 0) {
        return gmdate('Y-m-d H:i:s', (int)$value);
    }
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $normalized = substr(
        str_replace('T', ' ', (string)preg_replace('/(?:\.\d+)?Z$/', '', trim($value))),
        0,
        19
    );
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized)
        ? $normalized
        : null;
}

function printflow_paymongo_webhook_event_context(
    string $eventType,
    array $resource,
    string $mode
): array {
    $attributes = printflow_paymongo_webhook_resource_attributes($resource);
    $resourceId = trim((string)($resource['id'] ?? $resource['data']['id'] ?? ''));
    $source = isset($attributes['source']) && is_array($attributes['source'])
        ? $attributes['source']
        : [];
    $metadata = isset($attributes['metadata']) && is_array($attributes['metadata'])
        ? $attributes['metadata']
        : [];
    $paymentId = preg_match('/^pay_[A-Za-z0-9_-]+$/', $resourceId)
        ? $resourceId
        : printflow_paymongo_webhook_find_identifier($resource, 'pay');
    $paymentIntentId = trim((string)($attributes['payment_intent_id'] ?? ''));
    if (!preg_match('/^pi_[A-Za-z0-9_-]+$/', $paymentIntentId)) {
        $paymentIntentId = printflow_paymongo_webhook_find_identifier($resource, 'pi');
    }
    $paymentMethodId = trim((string)(
        $attributes['payment_method_id']
        ?? $attributes['payment_method']['id']
        ?? $attributes['payment_method']['data']['id']
        ?? ''
    ));
    if (!preg_match('/^pm_[A-Za-z0-9_-]+$/', $paymentMethodId)) {
        $paymentMethodId = preg_match('/^pm_[A-Za-z0-9_-]+$/', $resourceId)
            ? $resourceId
            : printflow_paymongo_webhook_find_identifier($resource, 'pm');
    }
    $method = strtolower(trim((string)(
        $attributes['payment_method_used'] ?? $source['type'] ?? $metadata['payment_method'] ?? ''
    )));
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $method)) {
        $method = '';
    }
    $failureCode = (string)preg_replace('/[^a-z0-9_-]/i', '', (string)(
        $attributes['failure_code']
        ?? $attributes['last_payment_error']['code']
        ?? $attributes['last_payment_error']['failed_code']
        ?? 'payment_failed'
    ));

    return [
        'event_type' => $eventType,
        'mode' => $mode,
        'payment_id' => $paymentId,
        'payment_intent_id' => $paymentIntentId,
        'payment_method_id' => $paymentMethodId,
        'ledger_id' => isset($metadata['printflow_payment_id'])
            && ctype_digit((string)$metadata['printflow_payment_id'])
            ? (int)$metadata['printflow_payment_id']
            : 0,
        'amount' => isset($attributes['amount']) ? (int)$attributes['amount'] : 0,
        'currency' => strtoupper(substr((string)($attributes['currency'] ?? ''), 0, 3)),
        'status' => strtolower(trim((string)($attributes['status'] ?? ''))),
        'payment_method' => $method,
        'reference_number' => substr(trim((string)(
            $attributes['external_reference_number']
            ?? $attributes['reference_number']
            ?? $metadata['pm_reference_number']
            ?? ''
        )), 0, 100),
        'provider_paid_at' => printflow_paymongo_webhook_provider_datetime($attributes['paid_at'] ?? null),
        'failure_code' => substr($failureCode !== '' ? $failureCode : 'payment_failed', 0, 100),
        'metadata' => $metadata,
    ];
}

function printflow_paymongo_webhook_metadata_errors(array $ledger, array $metadata): array {
    $errors = [];
    $expected = [
        'printflow_payment_id' => (string)((int)($ledger['id'] ?? 0)),
        'subject_type' => (string)($ledger['subject_type'] ?? ''),
        'subject_id' => (string)((int)($ledger['subject_id'] ?? 0)),
        'order_id' => (string)((int)($ledger['order_id'] ?? 0)),
        'job_order_id' => (string)((int)($ledger['job_order_id'] ?? 0)),
        'customer_id' => (string)((int)($ledger['customer_id'] ?? 0)),
        'channel' => (string)($ledger['channel'] ?? ''),
        'mode' => (string)($ledger['mode'] ?? ''),
        'payment_flow' => 'payment_intent',
    ];
    if (!isset($metadata['printflow_payment_id'])) {
        return ['metadata_missing'];
    }
    foreach ($expected as $key => $value) {
        if (array_key_exists($key, $metadata) && (string)$metadata[$key] !== $value) {
            $errors[] = 'metadata_' . $key;
        }
    }
    return $errors;
}

function printflow_paymongo_webhook_intent_errors(
    array $ledger,
    array $verifiedPayment,
    array $verifiedIntent,
    array $subject,
    string $eventType,
    string $expectedMode,
    string $eventPaymentMethodId = ''
): array {
    $errors = [];
    $expectedLive = $expectedMode === 'live';
    $intentId = (string)($ledger['payment_intent_id'] ?? '');
    if ((string)($ledger['provider'] ?? '') !== 'paymongo') {
        $errors[] = 'provider';
    }
    if ((string)($ledger['mode'] ?? '') !== $expectedMode) {
        $errors[] = 'mode';
    }
    if ((string)($ledger['payment_flow'] ?? '') !== 'payment_intent') {
        $errors[] = 'payment_flow';
    }
    if (!preg_match('/^pi_[A-Za-z0-9_-]+$/', $intentId)
        || (string)($verifiedIntent['id'] ?? '') !== $intentId) {
        $errors[] = 'payment_intent';
    }
    if (empty($verifiedIntent['ok'])
        || (string)($verifiedIntent['mode'] ?? '') !== $expectedMode
        || (bool)($verifiedIntent['livemode'] ?? !$expectedLive) !== $expectedLive) {
        $errors[] = 'intent_livemode';
    }
    if ((int)($verifiedIntent['amount'] ?? 0) !== (int)($ledger['amount_centavos'] ?? 0)) {
        $errors[] = 'intent_amount';
    }
    if (strtoupper((string)($verifiedIntent['currency'] ?? '')) !== 'PHP') {
        $errors[] = 'intent_currency';
    }
    $errors = array_merge(
        $errors,
        printflow_paymongo_webhook_metadata_errors(
            $ledger,
            isset($verifiedIntent['metadata']) && is_array($verifiedIntent['metadata'])
                ? $verifiedIntent['metadata']
                : []
        )
    );
    if (empty($subject)) {
        $errors[] = 'subject';
    } else {
        if ((int)($subject['customer_id'] ?? 0) !== (int)($ledger['customer_id'] ?? 0)) {
            $errors[] = 'customer';
        }
        if ((int)($ledger['order_id'] ?? 0) > 0
            && (int)($subject['order_id'] ?? 0) !== (int)$ledger['order_id']) {
            $errors[] = 'order';
        }
        if (function_exists('printflow_money_to_centavos')
            && printflow_money_to_centavos($subject['total_amount'] ?? '')
                !== (int)($ledger['amount_centavos'] ?? 0)) {
            $errors[] = 'subject_amount';
        }
        $subjectStatus = strtoupper(str_replace(' ', '_', trim((string)($subject['order_status'] ?? ''))));
        if ($eventType === 'payment.paid'
            && in_array($subjectStatus, ['CANCELLED', 'REJECTED'], true)) {
            $errors[] = 'subject_status';
        }
    }
    $storedMethodId = (string)($ledger['payment_method_id'] ?? '');
    if ($eventPaymentMethodId !== '' && $storedMethodId !== ''
        && $eventPaymentMethodId !== $storedMethodId) {
        $errors[] = 'payment_method';
    }

    $intentStatus = strtolower((string)($verifiedIntent['status'] ?? ''));
    if ($eventType === 'payment.paid' && $intentStatus !== 'succeeded') {
        $errors[] = 'intent_status';
    }
    if (in_array($eventType, ['payment.failed', 'qrph.expired'], true)
        && $intentStatus !== 'awaiting_payment_method') {
        $errors[] = 'intent_status';
    }
    if (in_array($eventType, ['payment.paid', 'payment.failed'], true)) {
        $expectedPaymentStatus = $eventType === 'payment.paid' ? 'paid' : 'failed';
        if (empty($verifiedPayment['ok'])
            || !preg_match('/^pay_[A-Za-z0-9_-]+$/', (string)($verifiedPayment['payment_id'] ?? ''))
            || (string)($verifiedPayment['mode'] ?? '') !== $expectedMode
            || (bool)($verifiedPayment['livemode'] ?? !$expectedLive) !== $expectedLive) {
            $errors[] = 'payment';
        }
        if ((string)($verifiedPayment['payment_intent_id'] ?? '') !== $intentId) {
            $errors[] = 'payment_intent_ownership';
        }
        if (strtolower((string)($verifiedPayment['status'] ?? '')) !== $expectedPaymentStatus) {
            $errors[] = 'payment_status';
        }
        if ((int)($verifiedPayment['amount'] ?? 0) !== (int)($ledger['amount_centavos'] ?? 0)) {
            $errors[] = 'amount';
        }
        if (strtoupper((string)($verifiedPayment['currency'] ?? '')) !== 'PHP') {
            $errors[] = 'currency';
        }
    }
    return array_values(array_unique($errors));
}

function printflow_paymongo_webhook_transition_action(
    string $ledgerStatus,
    string $eventType,
    string $storedProviderPaymentId = '',
    string $incomingProviderPaymentId = ''
): string {
    if ($ledgerStatus === 'paid') {
        if ($eventType !== 'payment.paid') {
            return 'already_paid';
        }
        return $storedProviderPaymentId === '' || $storedProviderPaymentId === $incomingProviderPaymentId
            ? 'already_paid'
            : 'provider_payment_conflict';
    }
    return match ($eventType) {
        'payment.paid' => 'mark_paid',
        'payment.failed' => 'mark_failed',
        'qrph.expired' => 'mark_expired',
        default => 'ignore',
    };
}
