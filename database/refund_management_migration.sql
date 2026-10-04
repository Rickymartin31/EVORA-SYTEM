-- =====================================================================
-- EVORA v30.1 — Refund Management Module
--
-- Adds a manual refund lifecycle on top of the v27–v29 payment / ledger /
-- payout system (nothing there is redesigned):
--
--   Payment paid -> refund request (participant) or Refund All (cancelled event)
--   -> admin review (approve / reject)
--   -> admin refunds externally (PayMongo dashboard), enters reference + proof
--   -> payment "refunded", registration "cancelled", QR invalidated,
--      ledger reversal, earnings recalculated, notifications.
--
-- Only missing structures are created:
--   * payment_refunds                     (new table)
--   * registrations.status               + 'cancelled'
--   * qr_codes.invalidated_at / _reason   (new columns)
--   * organizer_ledger_entries.hold_reason + 'refund_pending'
--   * finance_status_history.entity_type  + 'payment_refund'
--   * triggers guarding the new rules
--
-- Backup before running:  C:\xampp\evora_backups\evora_db_before_refund_module_<timestamp>.sql
-- Rollback:               database/refund_management_rollback.sql
-- Requires MariaDB 10.2+ (CHECK constraints, PERSISTENT generated columns).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Enum extensions (existing values unchanged, existing rows untouched)
-- ---------------------------------------------------------------------
ALTER TABLE registrations
    MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE organizer_ledger_entries
    MODIFY hold_reason ENUM('fee_unknown','amount_mismatch','manual_hold','refund_pending') DEFAULT NULL;

ALTER TABLE finance_status_history
    MODIFY entity_type ENUM('ledger_entry','settlement','payout','payout_account','event_earnings','payout_request','payment_refund') NOT NULL;

-- ---------------------------------------------------------------------
-- 2. QR invalidation
-- ---------------------------------------------------------------------
ALTER TABLE qr_codes
    ADD COLUMN invalidated_at DATETIME DEFAULT NULL AFTER generated_at,
    ADD COLUMN invalidated_reason VARCHAR(255) DEFAULT NULL AFTER invalidated_at;

-- ---------------------------------------------------------------------
-- 3. payment_refunds
--    amount is always copied from payments.amount (checked by trigger);
--    no form ever sends an amount.
-- ---------------------------------------------------------------------
CREATE TABLE payment_refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    registration_id INT NOT NULL,
    refund_type ENUM('registration','duplicate_charge') NOT NULL DEFAULT 'registration',
    duplicate_history_id INT DEFAULT NULL,          -- payment_history row that flagged the second charge
    amount DECIMAL(10,2) NOT NULL,
    reason_code ENUM('cannot_attend','duplicate_payment','event_cancelled','other') NOT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    status ENUM('pending','approved','completed','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT NOT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejected_by INT DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    review_remarks VARCHAR(500) DEFAULT NULL,
    refund_reference VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    refunded_by INT DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    ledger_reversal_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- one live (not rejected) registration refund per payment,
    -- one live refund per flagged duplicate charge,
    -- a refund reference can complete only one refund
    active_payment_key INT AS (IF(refund_type = 'registration' AND status <> 'rejected', payment_id, NULL)) PERSISTENT,
    active_duplicate_key INT AS (IF(refund_type = 'duplicate_charge' AND status <> 'rejected', duplicate_history_id, NULL)) PERSISTENT,
    completed_reference_key VARCHAR(100) AS (IF(status = 'completed', LOWER(refund_reference), NULL)) PERSISTENT,

    UNIQUE KEY uq_refund_active_payment (active_payment_key),
    UNIQUE KEY uq_refund_active_duplicate (active_duplicate_key),
    UNIQUE KEY uq_refund_reference (completed_reference_key),
    KEY idx_refund_status (status),
    KEY idx_refund_registration (registration_id),

    CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_refund_registration FOREIGN KEY (registration_id) REFERENCES registrations(id),
    CONSTRAINT fk_refund_duplicate FOREIGN KEY (duplicate_history_id) REFERENCES payment_history(id),
    CONSTRAINT fk_refund_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_refund_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_refund_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id),
    CONSTRAINT fk_refund_refunded_by FOREIGN KEY (refunded_by) REFERENCES users(id),
    CONSTRAINT fk_refund_ledger_reversal FOREIGN KEY (ledger_reversal_id) REFERENCES organizer_ledger_entries(id),

    CONSTRAINT chk_refund_amount CHECK (amount > 0),
    CONSTRAINT chk_refund_duplicate_link CHECK ((refund_type = 'duplicate_charge') = (duplicate_history_id IS NOT NULL)),
    CONSTRAINT chk_refund_approved CHECK (status NOT IN ('approved','completed') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CONSTRAINT chk_refund_rejected CHECK (status <> 'rejected' OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND review_remarks IS NOT NULL)),
    CONSTRAINT chk_refund_completed CHECK (status <> 'completed' OR (refund_reference IS NOT NULL AND refund_reference <> ''
        AND proof_path IS NOT NULL AND proof_path <> '' AND refunded_by IS NOT NULL AND completed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Triggers
-- ---------------------------------------------------------------------
DELIMITER $$

-- A refund starts pending (participant) or approved (admin Refund All), for a
-- PAID PayMongo payment, for exactly the amount EVORA collected.
CREATE TRIGGER trg_refund_validate_insert BEFORE INSERT ON payment_refunds FOR EACH ROW
BEGIN
    DECLARE v_reg INT DEFAULT NULL;
    DECLARE v_amount DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_channel VARCHAR(20) DEFAULT NULL;
    DECLARE v_status VARCHAR(20) DEFAULT NULL;

    IF NEW.status NOT IN ('pending', 'approved') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A refund must start as pending or approved.';
    END IF;
    IF NEW.refund_reference IS NOT NULL OR NEW.proof_path IS NOT NULL OR NEW.refunded_by IS NOT NULL
       OR NEW.completed_at IS NOT NULL OR NEW.rejected_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new refund cannot already be completed or rejected.';
    END IF;

    SELECT registration_id, amount, payment_channel, status INTO v_reg, v_amount, v_channel, v_status
      FROM payments WHERE id = NEW.payment_id;
    IF v_reg IS NULL OR v_reg <> NEW.registration_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund registration does not match the payment.';
    END IF;
    IF v_channel <> 'paymongo' OR v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only paid PayMongo payments collected by EVORA can be refunded.';
    END IF;
    IF NEW.amount <> v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund amount must equal the recorded payment amount.';
    END IF;
    IF NEW.duplicate_history_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM payment_history WHERE id = NEW.duplicate_history_id AND payment_id = NEW.payment_id
          AND action = 'paymongo_duplicate_charge') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Duplicate-charge refund must point to a flagged duplicate charge of this payment.';
    END IF;
END$$

-- Identity and amount are immutable; only valid status moves; final states are final.
CREATE TRIGGER trg_refund_guard_update BEFORE UPDATE ON payment_refunds FOR EACH ROW
BEGIN
    IF OLD.status IN ('completed', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed or rejected refunds cannot change.';
    END IF;
    IF NEW.payment_id <> OLD.payment_id OR NEW.registration_id <> OLD.registration_id
       OR NEW.refund_type <> OLD.refund_type OR NOT (NEW.duplicate_history_id <=> OLD.duplicate_history_id)
       OR NEW.amount <> OLD.amount OR NEW.reason_code <> OLD.reason_code OR NOT (NEW.reason <=> OLD.reason)
       OR NEW.requested_by <> OLD.requested_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund identity and amount are immutable.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('completed', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid refund status change.';
    END IF;
    IF OLD.approved_by IS NOT NULL AND (NOT (NEW.approved_by <=> OLD.approved_by) OR NOT (NEW.approved_at <=> OLD.approved_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund approval cannot be changed.';
    END IF;
    IF OLD.ledger_reversal_id IS NOT NULL AND NOT (NEW.ledger_reversal_id <=> OLD.ledger_reversal_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund ledger reversal cannot be changed.';
    END IF;
    -- the person who asked for a refund can never approve or complete it
    -- (admin-initiated Refund All rows are the only exception)
    IF NEW.reason_code <> 'event_cancelled'
       AND (NEW.approved_by = NEW.requested_by OR NEW.refunded_by = NEW.requested_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The requester cannot approve or complete their own refund.';
    END IF;
END$$

CREATE TRIGGER trg_refund_no_delete BEFORE DELETE ON payment_refunds FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund records cannot be deleted.';
END$$

-- A payment becomes "refunded" only through a completed registration refund,
-- and a refunded payment can never go back.
CREATE TRIGGER trg_payment_refund_guard BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF OLD.status = 'refunded' AND NEW.status <> 'refunded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A refunded payment cannot change status.';
    END IF;
    IF NEW.status = 'refunded' AND OLD.status <> 'refunded' AND NOT EXISTS (
        SELECT 1 FROM payment_refunds WHERE payment_id = OLD.id AND refund_type = 'registration' AND status = 'completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A payment can be marked refunded only by completing its refund.';
    END IF;
END$$

-- A cancelled registration is final (cannot be re-approved or re-rejected).
CREATE TRIGGER trg_registration_cancel_final BEFORE UPDATE ON registrations FOR EACH ROW
BEGIN
    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A cancelled registration cannot be reinstated.';
    END IF;
END$$

-- An invalidated QR code stays invalid.
CREATE TRIGGER trg_qr_invalidation_final BEFORE UPDATE ON qr_codes FOR EACH ROW
BEGIN
    IF OLD.invalidated_at IS NOT NULL AND (NOT (NEW.invalidated_at <=> OLD.invalidated_at) OR NEW.code_token <> OLD.code_token) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An invalidated QR code cannot be reactivated.';
    END IF;
END$$

DELIMITER ;
