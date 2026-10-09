ALTER TABLE `provider_payments`
    ADD COLUMN IF NOT EXISTS `provider_livemode` TINYINT(1) NULL DEFAULT NULL
        COMMENT 'PayMongo livemode flag from provider API (0=test, 1=live)' AFTER `mode`;
