-- Destructive rollback for migrate_paymongo_payment_intents_20260821.php.
-- Back up provider_payments and provider_webhook_events before running this.
-- The existing provider/event_id unique protection is not changed.

ALTER TABLE `provider_webhook_events`
  DROP COLUMN `payment_method_id`,
  DROP COLUMN `payment_intent_id`;

ALTER TABLE `provider_payments`
  DROP INDEX `idx_provider_payment_flow_status`,
  DROP INDEX `uq_provider_payment_method`,
  DROP INDEX `uq_provider_payment_intent`,
  DROP COLUMN `client_key`,
  DROP COLUMN `qr_expires_at`,
  DROP COLUMN `qr_image_url`,
  DROP COLUMN `payment_method_id`,
  DROP COLUMN `payment_intent_id`,
  DROP COLUMN `payment_flow`;
