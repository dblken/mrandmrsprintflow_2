-- Optional admin help text shown to customers as an info tooltip on dynamic order fields.
ALTER TABLE service_field_configs
  ADD COLUMN help_text TEXT NULL
  COMMENT 'Optional customer-facing info tooltip for this field'
  AFTER field_label;
