-- Optional manual migration: product group description / notes (max length enforced in app; see PRINTFLOW_CATALOG_GROUP_DESCRIPTION_MAX)
-- Also applied automatically via printflow_ensure_product_catalog_groups_schema().

ALTER TABLE product_catalog_groups
    ADD COLUMN description TEXT NULL AFTER cover_image;
