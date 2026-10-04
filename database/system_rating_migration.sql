-- =====================================================================
-- EVORA v32.1 — System Satisfaction Rating (⭐ Rate EVORA)
--
-- Extends the EXISTING system_evaluations table (v32 keeps working):
--   role                 participant | organizer | staff — who rated (backfilled
--                        from users for existing rows; admins never rate)
--   registration_rating, payment_rating, event_management_rating, checkin_rating
--                        optional 1-5 category ratings (analytics only)
--   source               + 'star_button' (the ⭐ beside the bell)
-- Triggers (replacing the v32 ones on this table):
--   one evaluation per user, role must be the user's real role and not admin,
--   nothing can be edited once given (a quick rating may still be completed
--   by the ISO survey), rows cannot be deleted.
--
-- Backup before running:  C:\xampp\evora_backups\evora_db_before_system_rating_<timestamp>.sql
-- Rollback:               database/system_rating_rollback.sql
-- =====================================================================

ALTER TABLE system_evaluations
    ADD COLUMN role ENUM('participant','organizer','staff') DEFAULT NULL AFTER user_id,
    ADD COLUMN registration_rating TINYINT DEFAULT NULL AFTER source,
    ADD COLUMN payment_rating TINYINT DEFAULT NULL AFTER registration_rating,
    ADD COLUMN event_management_rating TINYINT DEFAULT NULL AFTER payment_rating,
    ADD COLUMN checkin_rating TINYINT DEFAULT NULL AFTER event_management_rating,
    MODIFY source ENUM('full_survey','quick_prompt','star_button') NOT NULL DEFAULT 'full_survey',
    ADD KEY idx_eval_role (role),
    ADD CONSTRAINT chk_eval_categories CHECK (
        (registration_rating IS NULL OR registration_rating BETWEEN 1 AND 5)
        AND (payment_rating IS NULL OR payment_rating BETWEEN 1 AND 5)
        AND (event_management_rating IS NULL OR event_management_rating BETWEEN 1 AND 5)
        AND (checkin_rating IS NULL OR checkin_rating BETWEEN 1 AND 5));

-- Existing rows: role from the user's account (admins would stay NULL; there are none today).
UPDATE system_evaluations se
JOIN users u ON u.id = se.user_id
JOIN roles r ON r.id = u.role_id
SET se.role = r.name
WHERE se.role IS NULL AND r.name IN ('participant', 'organizer', 'staff');

DROP TRIGGER IF EXISTS trg_system_eval_one_per_user;
DROP TRIGGER IF EXISTS trg_system_eval_no_edit;

DELIMITER $$

CREATE TRIGGER trg_system_eval_one_per_user BEFORE INSERT ON system_evaluations FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(30) DEFAULT NULL;
    IF EXISTS (SELECT 1 FROM system_evaluations WHERE user_id = NEW.user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You have already evaluated EVORA.';
    END IF;
    SELECT r.name INTO v_role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = NEW.user_id;
    IF v_role IS NULL OR v_role NOT IN ('participant', 'organizer', 'staff') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only participants, organizers and staff can rate EVORA.';
    END IF;
    IF NEW.role IS NULL THEN
        SET NEW.role = v_role;
    ELSEIF NEW.role <> v_role THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evaluation role must match the user account.';
    END IF;
END$$

-- A value that was given can never change (a quick rating may be completed
-- with the ISO survey later: NULL -> value only).
CREATE TRIGGER trg_system_eval_no_edit BEFORE UPDATE ON system_evaluations FOR EACH ROW
BEGIN
    IF NEW.user_id <> OLD.user_id OR NEW.created_at <> OLD.created_at OR NEW.source <> OLD.source
       OR (OLD.role IS NOT NULL AND NOT (NEW.role <=> OLD.role))
       OR (OLD.overall_rating IS NOT NULL AND NOT (NEW.overall_rating <=> OLD.overall_rating))
       OR (OLD.comment IS NOT NULL AND NOT (NEW.comment <=> OLD.comment))
       OR (OLD.registration_rating IS NOT NULL AND NOT (NEW.registration_rating <=> OLD.registration_rating))
       OR (OLD.payment_rating IS NOT NULL AND NOT (NEW.payment_rating <=> OLD.payment_rating))
       OR (OLD.event_management_rating IS NOT NULL AND NOT (NEW.event_management_rating <=> OLD.event_management_rating))
       OR (OLD.checkin_rating IS NOT NULL AND NOT (NEW.checkin_rating <=> OLD.checkin_rating))
       OR (OLD.functionality IS NOT NULL AND (NOT (NEW.functionality <=> OLD.functionality) OR NOT (NEW.usability <=> OLD.usability)
           OR NOT (NEW.reliability <=> OLD.reliability) OR NOT (NEW.performance_efficiency <=> OLD.performance_efficiency)
           OR NOT (NEW.security <=> OLD.security) OR NOT (NEW.compatibility <=> OLD.compatibility)
           OR NOT (NEW.maintainability <=> OLD.maintainability) OR NOT (NEW.portability <=> OLD.portability))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted EVORA evaluations cannot be modified.';
    END IF;
END$$

DELIMITER ;
