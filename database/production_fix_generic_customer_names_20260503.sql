-- Production-safe customer rename for mrandmrsprintflow.com
-- Date: 2026-05-03
-- Scope: exactly 45 live customer rows whose current display name is exactly "Customer"
-- Safety:
-- - Aborts if total exact "Customer" rows are not 45
-- - Aborts if any target email is missing
-- - Aborts if planned Gmail emails collide with existing customers or users
-- - Backs up original rows before update
-- - Updates only customers, preserving customer_id and linked orders

DELIMITER //

DROP PROCEDURE IF EXISTS run_generic_customer_fix_20260503 //
CREATE PROCEDURE run_generic_customer_fix_20260503()
BEGIN
    DECLARE expected_count INT DEFAULT 45;
    DECLARE actual_count INT DEFAULT 0;
    DECLARE collision_count INT DEFAULT 0;
    DECLARE duplicate_count INT DEFAULT 0;
    DECLARE backup_count INT DEFAULT 0;
    DECLARE v_batch_id VARCHAR(64) DEFAULT 'generic_customer_rename_20260503';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    CREATE TABLE IF NOT EXISTS maintenance_generic_customer_rename_backup (
        batch_id VARCHAR(64) NOT NULL,
        customer_id INT NOT NULL,
        first_name VARCHAR(100) NULL,
        middle_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        email VARCHAR(255) NULL,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (batch_id, customer_id)
    ) ENGINE=InnoDB;

    SELECT COUNT(*) INTO backup_count
    FROM maintenance_generic_customer_rename_backup
    WHERE batch_id = v_batch_id;

    IF backup_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Backup rows already exist for batch generic_customer_rename_20260503. Refusing to rerun.';
    END IF;

    SELECT COUNT(*) INTO actual_count
    FROM customers
    WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer';

    IF actual_count <> expected_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Live exact Customer row count is not 45.';
    END IF;

    CREATE TEMPORARY TABLE tmp_generic_customer_updates_20260503 (
        seq INT NOT NULL,
        old_email VARCHAR(255) NOT NULL,
        new_first_name VARCHAR(100) NOT NULL,
        new_last_name VARCHAR(100) NOT NULL,
        new_email VARCHAR(255) NOT NULL,
        PRIMARY KEY (seq),
        UNIQUE KEY uq_old_email (old_email),
        UNIQUE KEY uq_new_email (new_email)
    ) ENGINE=Memory;

    INSERT INTO tmp_generic_customer_updates_20260503 (seq, old_email, new_first_name, new_last_name, new_email) VALUES
    (1,  'lance@gmail.com',                         'Maria',       'Santos',      'mariasantos@gmail.com'),
    (2,  'direct_test_99@example.com',             'Jose',        'Reyes',       'josereyes@gmail.com'),
    (3,  'admin@gmail.com',                        'Ana',         'Bautista',    'anabautista@gmail.com'),
    (4,  'admin@printflow.com',                    'Mark',        'Villanueva',  'markvillanueva@gmail.com'),
    (5,  'tester@test.com',                        'Carla',       'Mendoza',     'carlamendoza@gmail.com'),
    (6,  'admin@admin.com',                        'Kevin',       'Ramos',       'kevinramos@gmail.com'),
    (7,  'kentlloydvillanueva5@gmail.com',         'Angelica',    'Torres',      'angelicatorres@gmail.com'),
    (8,  'kentlloydvillanueva6@gmail.com',         'Bryan',       'Castillo',    'bryancastillo@gmail.com'),
    (9,  'gonk322@gmail.com',                      'Liza',        'Fernandez',   'lizafernandez@gmail.com'),
    (10, 'kenvillanueva570@gmail.com',             'Daniel',      'Garcia',      'danielgarcia@gmail.com'),
    (11, 'kakankk12312@gmail.com',                 'Patricia',    'Lopez',       'patricialopez@gmail.com'),
    (12, 'kentll62738@gmail.com',                  'Christopher', 'Aquino',      'christopheraquino@gmail.com'),
    (13, 'tester@printflow.com',                   'Michelle',    'Navarro',     'michellenavarro@gmail.com'),
    (14, 'admin_test@test.com',                    'Jerome',      'Flores',      'jeromeflores@gmail.com'),
    (15, 'arrontuazon60892@gmail.com',             'Vanessa',     'Cruz',        'vanessacruz@gmail.com'),
    (16, 'maja1211113@gmail.com',                  'Allan',       'Herrera',     'allanherrera@gmail.com'),
    (17, 'maja12111123@gmail.com',                 'Joy',         'Mercado',     'joymercado@gmail.com'),
    (18, 'maja121111321212@gmail.com',             'Patrick',     'Chavez',      'patrickchavez@gmail.com'),
    (19, 'test_maja_browser@gmail.com',            'Kimberly',    'Salazar',     'kimberlysalazar@gmail.com'),
    (20, 'success_maja_v4@gmail.com',              'Eric',        'Padilla',     'ericpadilla@gmail.com'),
    (21, 'success_maja_v10@gmail.com',             'Rochelle',    'Dominguez',   'rochelledominguez@gmail.com'),
    (22, 'success_maja_v11@gmail.com',             'Vincent',     'Ortega',      'vincentortega@gmail.com'),
    (23, 'arrontuazon60dsdfd892@gmail.com',        'Hannah',      'Molina',      'hannahmolina@gmail.com'),
    (24, 'kentlloydvillanuev21212a@gmail.com',     'Ryan',        'Espino',      'ryanespino@gmail.com'),
    (25, 'rollback_test_maja@gmail.com',           'Kristine',    'Valencia',    'kristinevalencia@gmail.com'),
    (26, 'nav_test_maja@gmail.com',                'Carlo',       'Pineda',      'carlopineda@gmail.com'),
    (27, 'admin1212121@printflow.com',             'Janine',      'Aguilar',     'janineaguilar@gmail.com'),
    (28, 'dfdsff1212121@printflow.com',            'Dennis',      'Soriano',     'dennissoriano@gmail.com'),
    (29, 'glademernavarette@gmail.com',            'Aileen',      'Zamora',      'aileenzamora@gmail.com'),
    (30, 'kentlloydvillanueva5@gmail.comw',        'Joshua',      'Cabrera',     'joshuacabrera@gmail.com'),
    (31, 'angela322116@gmail.com',                 'Nicole',      'Fuentes',     'nicolefuentes@gmail.com'),
    (32, 'testadmin@gmail.com',                    'Marvin',      'Del Rosario', 'marvindelrosario@gmail.com'),
    (33, 'admin_test@gmail.com',                   'Grace',       'Alvarado',    'gracealvarado@gmail.com'),
    (34, 'testadmin@example.com',                  'Leo',         'Santiago',    'leosantiago@gmail.com'),
    (35, 'nav_test_v2@gmail.com',                  'Camille',     'Soriano',     'camillesoriano@gmail.com'),
    (36, 'fdfdgdgf@gmail.com',                     'Arnold',      'Mendoza',     'arnoldmendoza@gmail.com'),
    (37, 'kentlloydvillanueva.edu@gmail.com',      'Bea',         'Ramos',       'bearamos@gmail.com'),
    (38, '+639300610038@phone.local',              'Noel',        'Torres',      'noeltorres@gmail.com'),
    (39, 'lisa1211249022@gmail.com',               'Katrina',     'Fernandez',   'katrinafernandez@gmail.com'),
    (40, 'lisa121124902@gmail.com',                'Francis',     'Garcia',      'francisgarcia@gmail.com'),
    (41, 'lisa12112490@gmail.com',                 'Sheila',      'Lopez',       'sheilalopez@gmail.com'),
    (42, 'dsffsdfdsf@gmail.com',                   'Arvin',       'Aquino',      'arvinaquino@gmail.com'),
    (43, 'lnzbarcenas@gmail.com',                  'Denise',      'Navarro',     'denisenavarro@gmail.com'),
    (44, 'bmildred218@gmail.com',                  'Ruben',       'Flores',      'rubenflores@gmail.com'),
    (45, 'dumayasdenise9@gmail.com',               'Maricel',     'Cruz',        'maricelcruz@gmail.com');

    SELECT COUNT(*) INTO duplicate_count
    FROM (
        SELECT new_email
        FROM tmp_generic_customer_updates_20260503
        GROUP BY new_email
        HAVING COUNT(*) > 1
    ) dupes;

    IF duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Duplicate planned Gmail addresses found in update set.';
    END IF;

    SELECT COUNT(*) INTO actual_count
    FROM customers c
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(t.old_email))
    WHERE TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) = 'Customer';

    IF actual_count <> expected_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Target email set does not match 45 live exact Customer rows.';
    END IF;

    SELECT COUNT(*) INTO collision_count
    FROM customers c
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(t.new_email))
    LEFT JOIN tmp_generic_customer_updates_20260503 own
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(own.old_email))
    WHERE own.old_email IS NULL;

    IF collision_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Planned Gmail address already exists on another customer account.';
    END IF;

    SELECT COUNT(*) INTO collision_count
    FROM users u
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(u.email)) = LOWER(TRIM(t.new_email));

    IF collision_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Planned Gmail address already exists on a user account.';
    END IF;

    INSERT INTO maintenance_generic_customer_rename_backup (
        batch_id, customer_id, first_name, middle_name, last_name, email
    )
    SELECT
        v_batch_id, c.customer_id, c.first_name, c.middle_name, c.last_name, c.email
    FROM customers c
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(t.old_email))
    WHERE TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) = 'Customer';

    SELECT COUNT(*) INTO backup_count
    FROM maintenance_generic_customer_rename_backup
    WHERE batch_id = v_batch_id;

    IF backup_count <> expected_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Backup row count is not 45.';
    END IF;

    UPDATE customers c
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(t.old_email))
    SET
        c.first_name = t.new_first_name,
        c.middle_name = NULL,
        c.last_name = t.new_last_name,
        c.email = t.new_email
    WHERE TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) = 'Customer';

    SELECT COUNT(*) INTO actual_count
    FROM customers
    WHERE TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) = 'Customer';

    IF actual_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Some Customer rows remain after update.';
    END IF;

    SELECT COUNT(*) INTO actual_count
    FROM customers c
    JOIN tmp_generic_customer_updates_20260503 t
      ON LOWER(TRIM(c.email)) = LOWER(TRIM(t.new_email))
    WHERE c.first_name = t.new_first_name
      AND c.last_name = t.new_last_name;

    IF actual_count <> expected_count THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ABORT: Post-update verification did not match all 45 planned rows.';
    END IF;

    COMMIT;

    SELECT 'SUCCESS' AS status, v_batch_id AS batch_id, expected_count AS updated_rows;
END //

DELIMITER ;

CALL run_generic_customer_fix_20260503();

SELECT customer_id, first_name, last_name, email
FROM customers
WHERE email IN (
    'mariasantos@gmail.com',
    'josereyes@gmail.com',
    'anabautista@gmail.com',
    'markvillanueva@gmail.com',
    'carlamendoza@gmail.com',
    'kevinramos@gmail.com',
    'angelicatorres@gmail.com',
    'bryancastillo@gmail.com',
    'lizafernandez@gmail.com',
    'danielgarcia@gmail.com',
    'patricialopez@gmail.com',
    'christopheraquino@gmail.com',
    'michellenavarro@gmail.com',
    'jeromeflores@gmail.com',
    'vanessacruz@gmail.com',
    'allanherrera@gmail.com',
    'joymercado@gmail.com',
    'patrickchavez@gmail.com',
    'kimberlysalazar@gmail.com',
    'ericpadilla@gmail.com',
    'rochelledominguez@gmail.com',
    'vincentortega@gmail.com',
    'hannahmolina@gmail.com',
    'ryanespino@gmail.com',
    'kristinevalencia@gmail.com',
    'carlopineda@gmail.com',
    'janineaguilar@gmail.com',
    'dennissoriano@gmail.com',
    'aileenzamora@gmail.com',
    'joshuacabrera@gmail.com',
    'nicolefuentes@gmail.com',
    'marvindelrosario@gmail.com',
    'gracealvarado@gmail.com',
    'leosantiago@gmail.com',
    'camillesoriano@gmail.com',
    'arnoldmendoza@gmail.com',
    'bearamos@gmail.com',
    'noeltorres@gmail.com',
    'katrinafernandez@gmail.com',
    'francisgarcia@gmail.com',
    'sheilalopez@gmail.com',
    'arvinaquino@gmail.com',
    'denisenavarro@gmail.com',
    'rubenflores@gmail.com',
    'maricelcruz@gmail.com'
)
ORDER BY customer_id;
