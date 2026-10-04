-- Rollback of v47. Refuses when a participant already registered again after cancelling (two rows
-- for the same event), because the old one-row-per-participant key could not hold them.
DROP PROCEDURE IF EXISTS evora_v47_rollback_guard;
DELIMITER //
CREATE PROCEDURE evora_v47_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM registrations GROUP BY event_id, participant_id HAVING COUNT(*) > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Some participants registered again after cancelling; not rolling back.';
    END IF;
END//
DELIMITER ;
CALL evora_v47_rollback_guard();
DROP PROCEDURE IF EXISTS evora_v47_rollback_guard;
ALTER TABLE registrations ADD UNIQUE KEY IF NOT EXISTS uq_registration (event_id, participant_id);
ALTER TABLE registrations DROP INDEX IF EXISTS uq_registration_active, DROP INDEX IF EXISTS idx_reg_event_participant;
ALTER TABLE registrations DROP COLUMN IF EXISTS active_participant_id;
