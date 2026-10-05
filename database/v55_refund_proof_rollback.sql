-- Rollback of v55. Refuses when a refund was recorded with a screenshot only (no refund ID),
-- because the old table requires an ID and that record would be lost.
DROP PROCEDURE IF EXISTS evora_v55_rollback_guard;
DELIMITER //
CREATE PROCEDURE evora_v55_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM paypal_refund_records WHERE paypal_refund_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Some refunds were recorded with a screenshot only; not rolling back.';
    END IF;
END//
DELIMITER ;
CALL evora_v55_rollback_guard();
DROP PROCEDURE IF EXISTS evora_v55_rollback_guard;
ALTER TABLE paypal_refund_records DROP CONSTRAINT IF EXISTS chk_pprefund_proof;
ALTER TABLE paypal_refund_records DROP COLUMN IF EXISTS proof_path, MODIFY paypal_refund_id VARCHAR(40) NOT NULL COMMENT 'refund transaction ID shown in the organizer''s PayPal account';
DROP TRIGGER IF EXISTS trg_pprefund_guard_update;
DELIMITER //
CREATE TRIGGER trg_pprefund_guard_update BEFORE UPDATE ON paypal_refund_records FOR EACH ROW
BEGIN
    IF NEW.payment_id <> OLD.payment_id OR NEW.registration_id <> OLD.registration_id OR NEW.event_id <> OLD.event_id
       OR NEW.organizer_id <> OLD.organizer_id OR NEW.amount <> OLD.amount OR NEW.paypal_refund_id <> OLD.paypal_refund_id
       OR NOT (NEW.note <=> OLD.note) OR NEW.recorded_by <> OLD.recorded_by OR NEW.recorded_at <> OLD.recorded_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded refund cannot be changed.';
    END IF;
    IF OLD.participant_response <> 'none' AND (NEW.participant_response <> OLD.participant_response
       OR NOT (NEW.participant_note <=> OLD.participant_note) OR NOT (NEW.participant_responded_at <=> OLD.participant_responded_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The participant already answered for this refund.';
    END IF;
END//
DELIMITER ;
