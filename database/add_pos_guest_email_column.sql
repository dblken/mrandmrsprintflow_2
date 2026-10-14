-- Optional receipt contact for POS walk-in orders. Existing customer data is unchanged.
ALTER TABLE orders
    ADD COLUMN pos_guest_email VARCHAR(254) NULL DEFAULT NULL AFTER pos_guest_display_name;
