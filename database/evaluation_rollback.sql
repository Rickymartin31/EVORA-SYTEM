-- =====================================================================
-- EVORA v32 — Evaluation & Feedback: ROLLBACK
--
-- 1. Restore the code files from C:\xampp\evora_backups\rollback_v32_originals\
--    and delete the new files:
--      core/EvaluationService.php
--      modules/shared/partials/evaluation_prompt.php, modules/shared/evaluation_action.php
--      modules/organizer/event_feedback_view.php
-- 2. mysql -uroot evora_db < database/evaluation_rollback.sql
--
-- Event feedback rows are kept (they fit the old table). Quick platform ratings
-- have no ISO scores, so they cannot live in the old NOT NULL columns: they are
-- copied to archive_v32_quick_evaluations and removed; ISO survey rows stay.
-- =====================================================================

DROP TRIGGER IF EXISTS trg_event_feedback_validate_insert;
DROP TRIGGER IF EXISTS trg_event_feedback_no_update;
DROP TRIGGER IF EXISTS trg_event_feedback_no_delete;
DROP TRIGGER IF EXISTS trg_system_eval_one_per_user;
DROP TRIGGER IF EXISTS trg_system_eval_no_edit;
DROP TRIGGER IF EXISTS trg_system_eval_no_delete;

ALTER TABLE event_feedback DROP COLUMN updated_at;

CREATE TABLE IF NOT EXISTS archive_v32_quick_evaluations AS
    SELECT * FROM system_evaluations WHERE functionality IS NULL OR source = 'quick_prompt';
DELETE FROM system_evaluations WHERE functionality IS NULL;

ALTER TABLE system_evaluations
    DROP CONSTRAINT chk_eval_overall,
    DROP CONSTRAINT chk_eval_iso_complete,
    DROP CONSTRAINT chk_eval_has_rating,
    DROP COLUMN updated_at,
    DROP COLUMN source,
    DROP COLUMN overall_rating,
    MODIFY functionality TINYINT NOT NULL CHECK (functionality BETWEEN 1 AND 5),
    MODIFY usability TINYINT NOT NULL CHECK (usability BETWEEN 1 AND 5),
    MODIFY reliability TINYINT NOT NULL CHECK (reliability BETWEEN 1 AND 5),
    MODIFY performance_efficiency TINYINT NOT NULL CHECK (performance_efficiency BETWEEN 1 AND 5),
    MODIFY security TINYINT NOT NULL CHECK (security BETWEEN 1 AND 5),
    MODIFY compatibility TINYINT NOT NULL CHECK (compatibility BETWEEN 1 AND 5),
    MODIFY maintainability TINYINT NOT NULL CHECK (maintainability BETWEEN 1 AND 5),
    MODIFY portability TINYINT NOT NULL CHECK (portability BETWEEN 1 AND 5);
