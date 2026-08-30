-- Idempotent repair for active legacy revision rows whose field authorization
-- was never persisted. Only the explicitly approved, unambiguous rules below
-- are eligible; every change is copied to an immutable repair audit row first.

CREATE TABLE IF NOT EXISTS order_revision_permission_repairs (
    repair_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_request_id BIGINT UNSIGNED NOT NULL,
    order_id INT NOT NULL,
    previous_permitted_fields LONGTEXT NULL,
    repaired_permitted_fields LONGTEXT NOT NULL,
    repair_rule VARCHAR(100) NOT NULL,
    repaired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (repair_id),
    UNIQUE KEY uq_revision_permission_repair (revision_request_id),
    KEY idx_permission_repair_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT IGNORE INTO order_revision_permission_repairs
    (revision_request_id, order_id, previous_permitted_fields, repaired_permitted_fields, repair_rule)
SELECT
    revision_request_id,
    order_id,
    permitted_fields,
    CASE
        WHEN reason_code IN ('low_image_quality', 'wrong_design', 'invalid_format')
            THEN JSON_ARRAY('uploaded_design')
        WHEN reason_code = 'incorrect_details'
             AND LOWER(staff_instruction) REGEXP 'neede*d[[:space:]]+date|date[[:space:]]+neede*d'
            THEN JSON_ARRAY('needed_date')
    END,
    CASE
        WHEN reason_code IN ('low_image_quality', 'wrong_design', 'invalid_format')
            THEN 'image_reason'
        ELSE 'incorrect_details_explicit_needed_date'
    END
FROM order_revision_requests
WHERE active_flag = 1
  AND (permitted_fields IS NULL OR permitted_fields = '' OR JSON_VALID(permitted_fields) = 0 OR JSON_LENGTH(permitted_fields) = 0)
  AND (
      reason_code IN ('low_image_quality', 'wrong_design', 'invalid_format')
      OR (
          reason_code = 'incorrect_details'
          AND LOWER(staff_instruction) REGEXP 'neede*d[[:space:]]+date|date[[:space:]]+neede*d'
      )
  );

UPDATE order_revision_requests request
JOIN order_revision_permission_repairs repair
  ON repair.revision_request_id = request.revision_request_id
SET request.permitted_fields = repair.repaired_permitted_fields
WHERE request.active_flag = 1
  AND (request.permitted_fields IS NULL OR request.permitted_fields = '' OR JSON_VALID(request.permitted_fields) = 0 OR JSON_LENGTH(request.permitted_fields) = 0);

COMMIT;

-- Any remaining active row with empty/invalid permissions was intentionally
-- not guessed. Staff must close it and send a new field-authorized request.
SELECT revision_request_id, order_id, reason_code, staff_instruction, permitted_fields
FROM order_revision_requests
WHERE active_flag = 1
  AND (permitted_fields IS NULL OR permitted_fields = '' OR JSON_VALID(permitted_fields) = 0 OR JSON_LENGTH(permitted_fields) = 0);
