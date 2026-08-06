<?php
declare(strict_types=1);

function paymongo_webhook_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$webhook = (string)file_get_contents($root . '/webhooks/paymongo.php');
$liveWebhook = (string)file_get_contents($root . '/webhooks/paymongo_live.php');
$paymongo = (string)file_get_contents($root . '/includes/paymongo.php');
$providerPayments = (string)file_get_contents($root . '/includes/provider_payments.php');
$migration = (string)file_get_contents(
    $root . '/database/migrate_paymongo_reconciliation_20260806.php'
);
$deployment = (string)file_get_contents($root . '/docs/PAYMONGO_TEST_MODE.md');

paymongo_webhook_assert(
    strpos($webhook, ": 'test';") !== false
        && strpos(
            $webhook,
            'printflow_paymongo_verify_webhook_signature($rawBody, $signature, $expectedMode)'
        ) !== false
        && strpos($webhook, '$livemode !== $expectedLivemode') !== false,
    'the shared endpoint defaults to test and verifies the exact expected environment'
);
paymongo_webhook_assert(
    strpos($liveWebhook, "define('PRINTFLOW_PAYMONGO_WEBHOOK_MODE', 'live');") !== false
        && strpos($liveWebhook, "require __DIR__ . '/paymongo.php';") !== false
        && strpos($webhook, 'printflow_paymongo_live_enabled()') !== false,
    'the live endpoint reuses the handler and remains explicitly feature-gated'
);
paymongo_webhook_assert(
    strpos($providerPayments, "\$signatureKey = \$expectedMode === 'live' ? 'li' : 'te';") !== false
        && strpos($providerPayments, '$timestamp . \'.\' . $rawBody') !== false
        && strpos($providerPayments, 'hash_equals($expected, $signature)') !== false,
    'mode-specific PayMongo signatures cover the timestamp and exact raw body'
);
paymongo_webhook_assert(
    strpos($webhook, "status IN ('failed', 'ignored')") !== false
        && strpos($webhook, "status = 'processing'") !== false
        && strpos($webhook, 'PRINTFLOW_PAYMONGO_WEBHOOK_STALE_MINUTES') !== false
        && strpos($webhook, "=== 'processed'") !== false,
    'processed events are idempotent while failed and stale attempts are reclaimable'
);
paymongo_webhook_assert(
    strpos($webhook, "'payload_version' => 2") !== false
        && strpos($webhook, "hash('sha256', \$rawBody)") !== false
        && strpos($webhook, '[$eventId, $eventType, $rawBody]') === false
        && strpos($webhook, '$safePayloadJson') !== false,
    'the inbox stores a normalized envelope and hash rather than the full provider payload'
);
paymongo_webhook_assert(
    strpos($webhook, "provider = 'paymongo' AND mode = ?") !== false
        && strpos($webhook, 'printflow_paymongo_get_paid_link_payment($linkId, $expectedMode)') !== false
        && strpos($webhook, "'ledger_not_found'") !== false
        && strpos($webhook, "printflow_paymongo_webhook_respond(503") !== false,
    'ledger lookup is mode-bound, provider-verified, and missing associations stay retryable'
);
paymongo_webhook_assert(
    strpos($webhook, "'provider_transaction_id' => (string)(\$verified['payment_id'] ?? '')") !== false
        && strpos($webhook, "'paid_amount_centavos' =>") !== false
        && strpos($webhook, "'reference_number' => \$referenceNumber") !== false
        && strpos($webhook, "'provider_paid_at' =>") !== false
        && strpos($webhook, 'printflow_provider_payment_mark_paid(') !== false,
    'only provider-verified payment metadata is passed into paid finalization and audit storage'
);
paymongo_webhook_assert(
    strpos($paymongo, 'function printflow_paymongo_get_paid_link_payment(string $linkId, string $mode = \'\'): array') !== false
        && strpos($providerPayments, 'string $expectedMode = \'test\'') !== false
        && strpos($providerPayments, '?int $paidAmountCentavos = null') !== false,
    'the webhook and shared payment helpers expose the same mode and paid-metadata contracts'
);

foreach ([
    "ENUM('test','live')",
    'idempotency_key',
    'paid_amount_centavos',
    'reference_number',
    'provider_paid_at',
    'payload_sha256',
    'attempt_count',
    'price_finalized_at',
    'price_finalized_by',
    'item_type',
    'service_id',
    'item_name_snapshot',
] as $requiredMigrationContract) {
    paymongo_webhook_assert(
        strpos($migration, $requiredMigrationContract) !== false,
        "the additive migration includes {$requiredMigrationContract}"
    );
}
paymongo_webhook_assert(
    strpos($migration, "item_type' => \"enum('product','service') DEFAULT NULL") !== false
        && strpos($migration, "service_id' => 'int DEFAULT NULL") !== false
        && strpos($migration, "price_finalized_at' => 'datetime DEFAULT NULL") !== false,
    'new identity and finalization fields remain nullable without guessing legacy values'
);
paymongo_webhook_assert(
    strpos($migration, "\$explicitServiceId = (int)(\$custom['service_id'] ?? 0);") !== false
        && strpos($migration, "\$explicitServiceName = trim((string)(\$custom['service_type'] ?? ''));") !== false
        && strpos($migration, "(int)(\$row['order_item_count'] ?? 0) === 1") !== false
        && strpos($migration, "strtolower(trim((string)(\$row['order_type'] ?? ''))) === 'custom'") === false,
    'legacy service identity requires explicit metadata and order-level fallback is single-item only'
);
paymongo_webhook_assert(
    strpos($migration, 'o.price_finalized_at = COALESCE(') !== false
        && strpos($migration, "pp.status IN ('generating','awaiting_payment','paid')") !== false
        && strpos($migration, 'pp.amount_centavos = ROUND(o.total_amount * 100)') !== false,
    'historical price finalization is inferred only from later workflow state or an exact payment ledger'
);
paymongo_webhook_assert(
    strpos($deployment, '/webhooks/paymongo.php') !== false
        && strpos($deployment, '/webhooks/paymongo_live.php') !== false
        && strpos($deployment, 'PAYMONGO_LIVE_ENABLED=false') !== false
        && strpos($deployment, 'the additive columns and webhook/payment history intact') !== false,
    'deployment guidance separates test/live registration and preserves audit evidence on rollback'
);

echo "PayMongo webhook reconciliation regression test passed.\n";
