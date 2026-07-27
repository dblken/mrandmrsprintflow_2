-- Remove the historic fake service-card price.
--
-- Customer catalog pricing is derived from positive enabled choices in
-- service_field_configs.field_options. Order quotations remain in orders,
-- order_items, job_orders, customizations, and service_orders.

ALTER TABLE `services`
    MODIFY COLUMN `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00;

UPDATE `services`
SET `price` = 0.00
WHERE `price` = 1.00;
