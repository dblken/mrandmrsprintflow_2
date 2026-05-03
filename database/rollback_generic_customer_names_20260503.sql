-- Rollback for production generic customer rename
-- Date: 2026-05-03
-- Batch: generic_customer_rename_20260503

DELIMITER //

DROP PROCEDURE IF EXISTS rollback_generic_customer_fix_20260503 //
CREATE PROCEDURE rollback_generic_customer_fix_20260503()
BEGIN
    DECLARE backup_count INT DEFAULT 0;
    DECLARE expected_count INT DEFAULT 45;
    DECLARE v_batch_id VARCHAR(64) DEFAULT 'generic_customer_rename_20260503';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT COUNT(*) INTO backup_count
    FROM maintenance_generic_customer_rename_backup
    WHERE batch_id = v_batch_id;

    IF backup_count <> expected_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Backup row count for rollback is not 45.';
    END IF;

    UPDATE customers c
    JOIN maintenance_generic_customer_rename_backup b
      ON b.customer_id = c.customer_id
     AND b.batch_id = v_batch_id
    SET
        c.first_name = b.first_name,
        c.middle_name = b.middle_name,
        c.last_name = b.last_name,
        c.email = b.email;

    COMMIT;

    SELECT 'ROLLBACK_SUCCESS' AS status, v_batch_id AS batch_id, backup_count AS restored_rows;
END //

DELIMITER ;

CALL rollback_generic_customer_fix_20260503();
