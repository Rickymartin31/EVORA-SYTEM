-- EVORA v28: secure manual payout processing
--   * Maya payout accounts (GCash / Maya / Bank Transfer)
--   * a payout request can become "paid" only from "processing", and only
--     with a transfer reference AND a proof file (database-enforced)
--   * append-only audit log of every admin who viewed full payout details
--
-- No existing column is duplicated: reference_number, proof_path, paid_at and
-- paid_by already exist on organizer_payout_requests (v27). No data is
-- removed; the legacy v25 payouts table is untouched.
--
-- Backup: C:\xampp\evora_backups\evora_db_before_payout_proof_<timestamp>.sql
-- Rollback: database/payout_proof_rollback.sql

-- ---------------------------------------------------------------------
-- Maya as a payout method
-- ---------------------------------------------------------------------
ALTER TABLE organizer_payout_accounts
    MODIFY method ENUM('gcash','maya','bank') NOT NULL;

ALTER TABLE organizer_payout_requests
    MODIFY method ENUM('gcash','maya','bank') DEFAULT NULL;

-- ---------------------------------------------------------------------
-- Paid requires reference number AND proof of transfer
-- ---------------------------------------------------------------------
ALTER TABLE organizer_payout_requests
    DROP CONSTRAINT chk_opr_paid,
    ADD CONSTRAINT chk_opr_paid CHECK (status <> 'paid'
        OR (paid_by IS NOT NULL AND paid_at IS NOT NULL
            AND reference_number IS NOT NULL AND reference_number <> ''
            AND proof_path IS NOT NULL AND proof_path <> ''));

-- ---------------------------------------------------------------------
-- Who viewed an organizer's full payout details, and when
-- ---------------------------------------------------------------------
CREATE TABLE payout_detail_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_request_id INT NOT NULL,
    payout_account_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pdal_request (payout_request_id),
    CONSTRAINT fk_pdal_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pdal_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pdal_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

DELIMITER //

CREATE TRIGGER trg_pdal_no_update BEFORE UPDATE ON payout_detail_access_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout detail access logs are append-only.';
END//

CREATE TRIGGER trg_pdal_no_delete BEFORE DELETE ON payout_detail_access_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout detail access logs are append-only.';
END//

-- Same rules as v27, except: approved -> processing -> paid (no direct
-- approved -> paid; the admin marks "processing" when starting the transfer).
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
        OR (OLD.status = 'approved' AND NEW.status IN ('processing', 'rejected'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout request status change (paid requires processing first).';
    END IF;
END//

DELIMITER ;
