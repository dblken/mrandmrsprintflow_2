-- PrintFlow customer Terms/Privacy acceptance fields
-- Review and run once before deploying the registration agreement requirement.
-- Existing customer rows remain valid because both columns are nullable.

ALTER TABLE `customers`
  ADD COLUMN `terms_accepted_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN `terms_version` VARCHAR(20) NULL DEFAULT NULL;