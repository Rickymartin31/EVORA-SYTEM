-- =====================================================================
-- EVORA v32.1 — System Satisfaction Rating: ROLLBACK (back to v32)
--
-- 1. Restore the code files from C:\xampp\evora_backups\rollback_v32_1_originals\
--    (no new code files were added in v32.1).
-- 2. mysql -uroot evora_db < database/system_rating_rollback.sql
--
-- Every evaluation row is kept. The v32.1 columns are archived first; rows
-- submitted with the ⭐ button are relabelled 'quick_prompt' (same shape).
-- =====================================================================

CREATE TABLE IF NOT EXISTS archive_v32_1_system_rating_meta AS
    SELECT id AS evaluation_id, role, source, registration_rating, payment_rating, event_management_rating, checkin_rating
    FROM system_evaluations;

DROP TRIGGER IF EXISTS trg_system_eval_one_per_user;
DROP TRIGGER IF EXISTS trg_system_eval_no_edit;

UPDATE system_evaluations SET source = 'quick_prompt' WHERE source = 'star_button';

ALTER TABLE system_evaluations
    DROP CONSTRAINT chk_eval_categories,
    DROP KEY idx_eval_role,
    DROP COLUMN checkin_rating,
    DROP COLUMN event_management_rating,
    DROP COLUMN payment_rating,
    DROP COLUMN registration_rating,
    DROP COLUMN role,
    MODIFY source ENUM('full_survey','quick_prompt') NOT NULL DEFAULT 'full_survey';

DELIMITER $$
CREATE TRIGGER trg_system_eval_one_per_user BEFORE INSERT ON system_evaluations FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM system_evaluations WHERE user_id = NEW.user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You have already evaluated EVORA.';
    END IF;
END$$

CREATE TRIGGER trg_system_eval_no_edit BEFORE UPDATE ON system_evaluations FOR EACH ROW
BEGIN
    IF NEW.user_id <> OLD.user_id OR NEW.created_at <> OLD.created_at
       OR (OLD.overall_rating IS NOT NULL AND NOT (NEW.overall_rating <=> OLD.overall_rating))
       OR (OLD.comment IS NOT NULL AND NOT (NEW.comment <=> OLD.comment))
       OR (OLD.functionality IS NOT NULL AND (NOT (NEW.functionality <=> OLD.functionality) OR NOT (NEW.usability <=> OLD.usability)
           OR NOT (NEW.reliability <=> OLD.reliability) OR NOT (NEW.performance_efficiency <=> OLD.performance_efficiency)
           OR NOT (NEW.security <=> OLD.security) OR NOT (NEW.compatibility <=> OLD.compatibility)
           OR NOT (NEW.maintainability <=> OLD.maintainability) OR NOT (NEW.portability <=> OLD.portability))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted EVORA evaluations cannot be modified.';
    END IF;
END$$
DELIMITER ;
