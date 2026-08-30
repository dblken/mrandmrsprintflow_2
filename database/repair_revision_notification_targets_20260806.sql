-- Existing revision-submission notifications stored the correct internal
-- orders.order_id in data_id but used the overly broad Order/Review type.
-- Normalize their semantic type so every notification surface and push route
-- treats them as design/customization review work. Safe to run repeatedly.

START TRANSACTION;

UPDATE notifications
SET type = 'Design'
WHERE COALESCE(data_id, 0) > 0
  AND type IN ('Order', 'Review', 'Rating', 'Design', 'Status', 'Job Order')
  AND (
      LOWER(message) LIKE '%submitted revised details%'
      OR LOWER(message) LIKE '%submitted the requested updates%'
      OR LOWER(message) LIKE '%resubmitted revised details%'
      OR LOWER(message) LIKE '%resubmitted a revised design%'
      OR LOWER(message) LIKE '%re-uploaded design%'
      OR LOWER(message) LIKE '%design re-upload%'
      OR LOWER(message) LIKE '%revision submitted%'
      OR LOWER(message) LIKE '%resubmitted for review%'
  );

COMMIT;
