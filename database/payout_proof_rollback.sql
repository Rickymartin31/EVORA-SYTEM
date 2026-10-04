-- Rollback for database/payout_proof_migration.sql (v28)
--
-- ORDER MATTERS:
--   1. Restore the PHP files from C:\xampp\evora_backups\rollback_v28_originals\
--      (full copy of core/, modules/, scripts/, docs/, public/*.php and
--      database/schema.sql taken before v28).
--   2. THEN run this file.
--
-- Refuses to run while any Maya payout account or Maya payout request exists
-- (v27 cannot represent Maya). The detail access log is exported first if
-- you need it:  SELECT * FROM payout_detail_access_logs;
--
-- Alternative: restore the whole database from
--   C:\xampp\evora_backups\evora_db_before_payout_proof_<timestamp>.sql

DELIMITER //
CREATE PROCEDURE evora_v28_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM organizer_payout_accounts WHERE method = 'maya')
       OR EXISTS (SELECT 1 FROM organizer_payout_requests WHERE method = 'maya') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback aborted: Maya payout accounts/requests exist.';
    END IF;
END//
DELIMITER ;
CALL evora_v28_rollback_guard();
DROP PROCEDURE evora_v28_rollback_guard;

DROP TRIGGER IF EXISTS trg_pdal_no_update;
DROP TRIGGER IF EXISTS trg_pdal_no_delete;
DROP TABLE IF EXISTS payout_detail_access_logs;

ALTER TABLE organizer_payout_requests
    DROP CONSTRAINT chk_opr_paid,
    ADD CONSTRAINT chk_opr_paid CHECK (status <> 'paid'
        OR (paid_by IS NOT NULL AND paid_at IS NOT NULL AND reference_number IS NOT NULL AND reference_number <> ''));

ALTER TABLE organizer_payout_requests
    MODIFY method ENUM('gcash','bank') DEFAULT NULL;

ALTER TABLE organizer_payout_accounts
    MODIFY method ENUM('gcash','bank') NOT NULL;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payout_request_guard_update//

CREATE TRIGGER trg_payout_request_guard_update BEFORE UPDATE ON organizer_payout_requests FOR EACH ROW
BEGIN
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    IF OLD.status IN ('paid', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid or rejected payout requests are final.';
    END IF;

    IF NEW.request_no <> OLD.request_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.earnings_id <> OLD.earnings_id OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency
       OR NEW.entry_count <> OLD.entry_count OR NEW.participant_count <> OLD.participant_count
       OR NEW.gross_amount <> OLD.gross_amount OR NEW.gateway_fee <> OLD.gateway_fee OR NEW.amount <> OLD.amount
       OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request amounts and identity are immutable.';
    END IF;

    IF OLD.payout_account_id IS NOT NULL
       AND (NOT (NEW.payout_account_id <=> OLD.payout_account_id) OR NOT (NEW.method <=> OLD.method)
            OR NOT (NEW.account_name_snapshot <=> OLD.account_name_snapshot)
            OR NOT (NEW.account_number_masked <=> OLD.account_number_masked)
            OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payout destination cannot change after approval.';
    END IF;
    IF OLD.payout_account_id IS NULL AND NEW.payout_account_id IS NOT NULL THEN
        SELECT organizer_id, status INTO v_account_org, v_account_status FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;
        IF v_account_org IS NULL OR v_account_org <> NEW.organizer_id OR v_account_status <> 'verified' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts go only to the organizer''s own verified payout account.';
        END IF;
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('processing', 'paid', 'rejected'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout request status change.';
    END IF;
END//

DELIMITER ;
