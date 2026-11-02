<?php
declare(strict_types=1);

/** Pure guards shared by the targeted migration, rollback, and local tests. */
function pfpm_nextjet_snapshot_purpose(): string
{
    return 'Rollback snapshot for Decals/Stickers Nextjet rule-type correction';
}

function pfpm_nextjet_is_target_row(array $row): bool
{
    return (int)($row['id'] ?? 0) > 0
        && (string)($row['service_type'] ?? '') === 'Decals/Stickers (Print/Cut)'
        && (int)($row['item_id'] ?? 0) === 18
        && strtoupper(trim((string)($row['inventory_name'] ?? ''))) === 'NEXTJET';
}

function pfpm_nextjet_snapshot_payload(array $snapshot): array
{
    return [
        'purpose' => $snapshot['purpose'] ?? null,
        'captured_at_utc' => $snapshot['captured_at_utc'] ?? null,
        'row' => $snapshot['row'] ?? null,
    ];
}

function pfpm_nextjet_snapshot_checksum(array $payload): string
{
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function pfpm_nextjet_create_snapshot(array $row, string $capturedAtUtc): array
{
    $payload = [
        'purpose' => pfpm_nextjet_snapshot_purpose(),
        'captured_at_utc' => $capturedAtUtc,
        'row' => $row,
    ];
    $payload['sha256'] = pfpm_nextjet_snapshot_checksum($payload);
    return $payload;
}

function pfpm_nextjet_validate_snapshot($snapshot): array
{
    if (!is_array($snapshot)
        || ($snapshot['purpose'] ?? null) !== pfpm_nextjet_snapshot_purpose()
        || !is_string($snapshot['captured_at_utc'] ?? null)
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $snapshot['captured_at_utc'])
        || !is_array($snapshot['row'] ?? null)
        || !is_string($snapshot['sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/', $snapshot['sha256'])) {
        throw new RuntimeException('Rollback snapshot is missing required fields or has an invalid format.');
    }
    $payload = pfpm_nextjet_snapshot_payload($snapshot);
    $expected = pfpm_nextjet_snapshot_checksum($payload);
    if (!hash_equals($expected, $snapshot['sha256'])) {
        throw new RuntimeException('Rollback snapshot checksum verification failed.');
    }
    $row = $snapshot['row'];
    if (!pfpm_nextjet_is_target_row($row)
        || strtoupper(trim((string)($row['rule_type'] ?? ''))) !== 'REQUIRED'
        || !array_key_exists('qty_multiplier', $row)
        || !array_key_exists('qty_multiplier_approved', $row)
        || !array_key_exists('qty_multiplier_approved_by', $row)
        || !array_key_exists('qty_multiplier_approved_at', $row)) {
        throw new RuntimeException('Rollback snapshot does not describe a complete original target rule.');
    }
    return $row;
}

function pfpm_nextjet_nullable_string($value): ?string
{
    return $value === null ? null : (string)$value;
}

function pfpm_nextjet_matches_migrated_row(array $current, array $original): bool
{
    return pfpm_nextjet_is_target_row($current)
        && (int)($current['id'] ?? 0) === (int)($original['id'] ?? -1)
        && strtoupper(trim((string)($current['rule_type'] ?? ''))) === 'OPTIONAL'
        && pfpm_nextjet_nullable_string($current['qty_multiplier'] ?? null) === pfpm_nextjet_nullable_string($original['qty_multiplier'] ?? null)
        && (int)($current['qty_multiplier_approved'] ?? -1) === 0
        && ($current['qty_multiplier_approved_by'] ?? null) === null
        && ($current['qty_multiplier_approved_at'] ?? null) === null;
}

function pfpm_nextjet_matches_original_row(array $current, array $original): bool
{
    return pfpm_nextjet_is_target_row($current)
        && (int)($current['id'] ?? 0) === (int)($original['id'] ?? -1)
        && (string)($current['rule_type'] ?? '') === (string)($original['rule_type'] ?? '')
        && pfpm_nextjet_nullable_string($current['qty_multiplier'] ?? null) === pfpm_nextjet_nullable_string($original['qty_multiplier'] ?? null)
        && (int)($current['qty_multiplier_approved'] ?? -1) === (int)($original['qty_multiplier_approved'] ?? -2)
        && ($current['qty_multiplier_approved_by'] ?? null) === ($original['qty_multiplier_approved_by'] ?? null)
        && ($current['qty_multiplier_approved_at'] ?? null) === ($original['qty_multiplier_approved_at'] ?? null);
}
