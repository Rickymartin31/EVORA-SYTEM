-- =====================================================================
-- EVORA v32 — Evaluation & Feedback
--
-- Reuses the EXISTING tables (no new `evaluations` table):
--   event_feedback      event evaluation   (overall_rating 1-5 + suggestions = comment),
--                       one per registration (existing UNIQUE key)
--   system_evaluations  EVORA platform evaluation — the ISO/IEC 25010 survey stays;
--                       a quick "overall rating + comment" prompt now writes to it too
--
-- Changes:
--   event_feedback      + updated_at; triggers: only an APPROVED registration of that
--                       event, only COMPLETED/ARCHIVED events, ratings cannot be edited
--                       or deleted
--   system_evaluations  + overall_rating, source, updated_at; the 8 ISO columns become
--                       optional (a quick rating has none) but stay all-or-nothing;
--                       triggers: one evaluation per user for NEW rows (existing rows,
--                       including user #3's two old rows, are left untouched), ratings
--                       cannot be edited (a quick rating may later be completed with the
--                       ISO survey), rows cannot be deleted
--
-- Backup before running:  C:\xampp\evora_backups\evora_db_before_evaluation_<timestamp>.sql
-- Rollback:               database/evaluation_rollback.sql
-- =====================================================================

ALTER TABLE event_feedback
    ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE system_evaluations
    MODIFY functionality TINYINT DEFAULT NULL CHECK (functionality BETWEEN 1 AND 5),
    MODIFY usability TINYINT DEFAULT NULL CHECK (usability BETWEEN 1 AND 5),
    MODIFY reliability TINYINT DEFAULT NULL CHECK (reliability BETWEEN 1 AND 5),
    MODIFY performance_efficiency TINYINT DEFAULT NULL CHECK (performance_efficiency BETWEEN 1 AND 5),
    MODIFY security TINYINT DEFAULT NULL CHECK (security BETWEEN 1 AND 5),
    MODIFY compatibility TINYINT DEFAULT NULL CHECK (compatibility BETWEEN 1 AND 5),
    MODIFY maintainability TINYINT DEFAULT NULL CHECK (maintainability BETWEEN 1 AND 5),
    MODIFY portability TINYINT DEFAULT NULL CHECK (portability BETWEEN 1 AND 5),
    ADD COLUMN overall_rating TINYINT DEFAULT NULL AFTER user_id,
    ADD COLUMN source ENUM('full_survey','quick_prompt') NOT NULL DEFAULT 'full_survey' AFTER overall_rating,
    ADD COLUMN updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT chk_eval_overall CHECK (overall_rating IS NULL OR overall_rating BETWEEN 1 AND 5),
    ADD CONSTRAINT chk_eval_iso_complete CHECK (
        (functionality IS NULL AND usability IS NULL AND reliability IS NULL AND performance_efficiency IS NULL
         AND security IS NULL AND compatibility IS NULL AND maintainability IS NULL AND portability IS NULL)
     OR (functionality IS NOT NULL AND usability IS NOT NULL AND reliability IS NOT NULL AND performance_efficiency IS NOT NULL
         AND security IS NOT NULL AND compatibility IS NOT NULL AND maintainability IS NOT NULL AND portability IS NOT NULL)),
    ADD CONSTRAINT chk_eval_has_rating CHECK (overall_rating IS NOT NULL OR functionality IS NOT NULL);

DELIMITER $$

CREATE TRIGGER trg_event_feedback_validate_insert BEFORE INSERT ON event_feedback FOR EACH ROW
BEGIN
    DECLARE v_event_status VARCHAR(30) DEFAULT NULL;
    IF NOT EXISTS (SELECT 1 FROM registrations WHERE id = NEW.registration_id AND event_id = NEW.event_id AND status = 'approved') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a participant with an approved registration for this event can evaluate it.';
    END IF;
    SELECT status INTO v_event_status FROM events WHERE id = NEW.event_id;
    IF v_event_status NOT IN ('completed', 'archived') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only completed events can be evaluated.';
    END IF;
END$$

CREATE TRIGGER trg_event_feedback_no_update BEFORE UPDATE ON event_feedback FOR EACH ROW
BEGIN
    IF NEW.overall_rating <> OLD.overall_rating OR NOT (NEW.speaker_rating <=> OLD.speaker_rating)
       OR NOT (NEW.session_rating <=> OLD.session_rating) OR NOT (NEW.venue_rating <=> OLD.venue_rating)
       OR NOT (NEW.suggestions <=> OLD.suggestions) OR NEW.event_id <> OLD.event_id OR NEW.registration_id <> OLD.registration_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Submitted event evaluations cannot be modified.';
    END IF;
END$$

CREATE TRIGGER trg_event_feedback_no_delete BEFORE DELETE ON event_feedback FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event evaluations cannot be deleted.';
END$$

CREATE TRIGGER trg_system_eval_one_per_user BEFORE INSERT ON system_evaluations FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM system_evaluations WHERE user_id = NEW.user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You have already evaluated EVORA.';
    END IF;
END$$

-- A quick rating may be completed with the ISO survey later (NULL -> value);
-- a value that was given can never change.
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

CREATE TRIGGER trg_system_eval_no_delete BEFORE DELETE ON system_evaluations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EVORA evaluations cannot be deleted.';
END$$

DELIMITER ;
