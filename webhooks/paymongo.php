<?php

require_once __DIR__ . '/../includes/provider_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
if (!is_string($rawBody) || !printflow_paymongo_verify_webhook_signature($rawBody, $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid webhook signature.']);
    exit;
}

$payload = json_decode($rawBody, true);
$event = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
$attributes = isset($event['attributes']) && is_array($event['attributes']) ? $event['attributes'] : [];
$eventId = trim((string)($event['id'] ?? ''));
$eventType = trim((string)($attributes['type'] ?? ''));
$livemode = (bool)($attributes['livemode'] ?? true);

if (!preg_match('/^evt_[A-Za-z0-9_-]+$/', $eventId) || $livemode || $eventType !== 'link.payment.paid') {
    http_response_code(202);
    echo json_encode(['success' => true, 'processed' => false]);
    exit;
}

$inserted = db_execute(
    "INSERT INTO provider_webhook_events (event_id, event_type, status, raw_event_json)
     VALUES (?, ?, 'processing', ?)",
    'sss',
    [$eventId, $eventType, $rawBody]
);
if (!$inserted) {
    $existingEvents = db_query(
        'SELECT status FROM provider_webhook_events WHERE event_id = ? LIMIT 1',
        's',
        [$eventId]
    ) ?: [];
    $existingStatus = (string)($existingEvents[0]['status'] ?? '');
    if (in_array($existingStatus, ['processed', 'ignored', 'processing'], true)) {
        echo json_encode(['success' => true, 'processed' => $existingStatus === 'processed', 'duplicate' => true]);
        exit;
    }
    $reclaimed = db_execute_affected_rows(
        "UPDATE provider_webhook_events
         SET status = 'processing', processed_at = NULL
         WHERE event_id = ? AND status = 'failed'",
        's',
        [$eventId]
    );
    if ($reclaimed !== 1) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Webhook event could not be recorded.']);
        exit;
    }
}

$linkId = printflow_find_paymongo_link_id($attributes['data'] ?? []);
$paymentRows = $linkId !== '' ? (db_query(
    'SELECT * FROM provider_payments WHERE link_id = ? LIMIT 1',
    's',
    [$linkId]
) ?: []) : [];

if (empty($paymentRows)) {
    db_execute(
        "UPDATE provider_webhook_events SET status = 'ignored', processed_at = NOW() WHERE event_id = ?",
        's',
        [$eventId]
    );
    http_response_code(202);
    echo json_encode(['success' => true, 'processed' => false]);
    exit;
}

$payment = $paymentRows[0];
$verified = printflow_paymongo_get_paid_link_payment($linkId);
if (empty($verified['ok']) || empty($verified['paid']) || !empty($verified['livemode'])
    || (int)($verified['amount'] ?? 0) !== (int)$payment['amount_centavos']
    || strtoupper((string)($verified['currency'] ?? '')) !== 'PHP'
    || empty($verified['payment_id'])) {
    db_execute(
        "UPDATE provider_webhook_events
         SET status = 'failed', provider_payment_id = ?, processed_at = NOW()
         WHERE event_id = ?",
        'is',
        [(int)$payment['id'], $eventId]
    );
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Payment verification failed.']);
    exit;
}

$result = printflow_provider_payment_mark_paid((int)$payment['id'], (string)$verified['payment_id']);
db_execute(
    "UPDATE provider_webhook_events
     SET status = ?, provider_payment_id = ?, processed_at = NOW()
     WHERE event_id = ?",
    'sis',
    [!empty($result['ok']) ? 'processed' : 'failed', (int)$payment['id'], $eventId]
);

http_response_code(!empty($result['ok']) ? 200 : 500);
echo json_encode([
    'success' => !empty($result['ok']),
    'processed' => !empty($result['ok']),
]);
