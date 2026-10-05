-- v55: an organizer records a PayPal refund with the refund transaction ID OR a screenshot of the
-- refund (at least one; enforced by chk_pprefund_proof). The participant still confirms it.
-- The guard trigger is rebuilt NULL-safe so a recorded refund (ID, screenshot, note) stays final.
-- Rollback: v55_refund_proof_rollback.sql
ALTER TABLE paypal_refund_records
  MODIFY paypal_refund_id VARCHAR(40) NULL COMMENT 'refund transaction ID shown in the organizer''s PayPal account (optional when a screenshot is given)',
  ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL COMMENT 'screenshot/PDF of the refund in PayPal (v55)' AFTER paypal_refund_id;
ALTER TABLE paypal_refund_records DROP CONSTRAINT IF EXISTS chk_pprefund_proof;
ALTER TABLE paypal_refund_records ADD CONSTRAINT chk_pprefund_proof CHECK (paypal_refund_id IS NOT NULL OR proof_path IS NOT NULL);

DROP TRIGGER IF EXISTS trg_pprefund_guard_update;
DELIMITER //
CREATE TRIGGER trg_pprefund_guard_update BEFORE UPDATE ON paypal_refund_records FOR EACH ROW
BEGIN
    IF NEW.payment_id <> OLD.payment_id OR NEW.registration_id <> OLD.registration_id OR NEW.event_id <> OLD.event_id
       OR NEW.organizer_id <> OLD.organizer_id OR NEW.amount <> OLD.amount OR NOT (NEW.paypal_refund_id <=> OLD.paypal_refund_id)
       OR NOT (NEW.proof_path <=> OLD.proof_path)
       OR NOT (NEW.note <=> OLD.note) OR NEW.recorded_by <> OLD.recorded_by OR NEW.recorded_at <> OLD.recorded_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A recorded refund cannot be changed.';
    END IF;
    IF OLD.participant_response <> 'none' AND (NEW.participant_response <> OLD.participant_response
       OR NOT (NEW.participant_note <=> OLD.participant_note) OR NOT (NEW.participant_responded_at <=> OLD.participant_responded_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The participant already answered for this refund.';
    END IF;
END//
DELIMITER ;
