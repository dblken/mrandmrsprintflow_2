-- Optional manual migration: product group description / notes (max 500 chars enforced in app)
-- Also applied automatically via printflow_ensure_product_catalog_groups_schema().

ALTER TABLE product_catalog_groups
    ADD COLUMN description TEXT NULL AFTER cover_image;
