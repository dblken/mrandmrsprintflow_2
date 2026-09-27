-- Optional: conditional_mode for service field visibility rules (show_when | hide_when).
-- Safe to run once; app also auto-adds column via printflow_ensure_service_field_conditional_mode_column().

ALTER TABLE service_field_configs
    ADD COLUMN IF NOT EXISTS conditional_mode VARCHAR(20) NULL DEFAULT NULL
    COMMENT 'show_when|hide_when with parent_field_key'
    AFTER parent_value;
