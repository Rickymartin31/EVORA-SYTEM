-- Migration v25: Organizer earnings ledger, settlements, payout accounts, payouts
--
-- Money flow (centralized EVORA PayMongo account):
--   confirmed PayMongo payment -> ONE organizer_ledger_entries row (same DB
--   transaction as the payment approval) -> settlement (one per event per
--   organizer; later ones only for late entries/corrections) -> payout,
--   recorded manually by an admin (EVORA has no automated payout API).
--
-- Only PayMongo payments create ledger entries. Legacy manual and cash
-- walk-in payments were collected directly by the organizer/staff and never
-- enter this ledger.
--
-- Financial rows are protected in the database itself, not only in PHP:
--   * ON DELETE RESTRICT on every financial relationship, so the existing
--     users -> organizers -> events -> registrations -> payments cascade can
--     no longer silently delete money history;
--   * triggers forbid deleting financial rows and forbid changing recorded
--     amounts, settled entries, approved settlements, finished payouts and
--     history rows; corrections are new rows (reversal entries, new payouts);
--   * CHECK constraints: net = gross - fee, deduction = 0 (no commission
--     yet), organizer earnings = net - deduction, payout amount > 0.
--
-- Add-only: no existing row or column is changed. Rollback:
-- database/migration_v25_rollback.sql

-- ---------------------------------------------------------------------
-- Settlements: one per event per organizer (sequence_no 1); a later
-- settlement (sequence_no 2+) only for late entries/corrections, with a reason.
-- ---------------------------------------------------------------------
CREATE TABLE settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_no VARCHAR(30) NOT NULL,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    sequence_no INT NOT NULL DEFAULT 1,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    entry_count INT NOT NULL DEFAULT 0,
    gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fee_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deduction_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    settlement_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','approved','cancelled') NOT NULL DEFAULT 'draft',
    reason VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    cancelled_by INT DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancel_reason VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uq_settlement_no (settlement_no),
    UNIQUE KEY uq_settlement_event_seq (event_id, sequence_no),
    INDEX idx_settlement_org_status (organizer_id, status),
    CONSTRAINT fk_settlement_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_settlement_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_settlement_no_commission CHECK (deduction_total = 0.00),
    CONSTRAINT chk_settlement_formula CHECK (net_total = gross_total - fee_total AND settlement_amount = net_total - deduction_total)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organizer ledger: one immutable row per confirmed PayMongo payment,
-- plus reversal rows for corrections. State (pending / on hold / eligible /
-- in settlement / settled / paid / reversed) is derived from these facts by
-- LedgerService, so it can never drift out of sync.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    registration_id INT DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    entry_type ENUM('payment','reversal') NOT NULL,
    source_key VARCHAR(100) NOT NULL,
    paymongo_payment_id VARCHAR(100) DEFAULT NULL,
    checkout_session_id VARCHAR(100) DEFAULT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    gross_amount DECIMAL(10,2) NOT NULL,
    gateway_fee DECIMAL(10,2) DEFAULT NULL,
    net_amount DECIMAL(10,2) DEFAULT NULL,
    deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    organizer_earnings DECIMAL(10,2) DEFAULT NULL,
    event_date DATE NOT NULL,
    hold_until DATE NOT NULL,
    hold_reason ENUM('fee_unknown','amount_mismatch','manual_hold') DEFAULT NULL,
    reverses_entry_id INT DEFAULT NULL,
    settlement_id INT DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ledger_source (source_key),
    UNIQUE KEY uq_ledger_reverses (reverses_entry_id),
    INDEX idx_ledger_org_settlement (organizer_id, settlement_id),
    INDEX idx_ledger_event_settlement (event_id, settlement_id),
    INDEX idx_ledger_payment (payment_id),
    CONSTRAINT fk_ledger_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_reverses FOREIGN KEY (reverses_entry_id) REFERENCES organizer_ledger_entries(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_no_commission CHECK (deduction_amount = 0.00),
    CONSTRAINT chk_ledger_formula CHECK (
        (gateway_fee IS NULL AND net_amount IS NULL AND organizer_earnings IS NULL AND hold_reason = 'fee_unknown')
        OR (gateway_fee IS NOT NULL AND net_amount = gross_amount - gateway_fee AND organizer_earnings = net_amount - deduction_amount)
    ),
    CONSTRAINT chk_ledger_reversal_link CHECK ((entry_type = 'reversal') = (reverses_entry_id IS NOT NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Organizer payout destination. Rows are never edited: a change is a new
-- 'pending' row and the old one becomes 'inactive', so every payout keeps
-- pointing at the exact account it was sent to. No secrets are stored.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payout_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    method ENUM('gcash','bank') NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    account_number VARCHAR(40) NOT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','verified','rejected','inactive') NOT NULL DEFAULT 'pending',
    submitted_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_remarks VARCHAR(500) DEFAULT NULL,
    INDEX idx_payout_account_org_status (organizer_id, status),
    CONSTRAINT fk_payout_account_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payout_account_bank CHECK (method <> 'bank' OR (bank_name IS NOT NULL AND bank_name <> ''))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Payouts: recorded manually by an admin after sending the money outside
-- EVORA. Amount must equal the approved settlement amount (trigger).
-- At most one pending/processing/paid payout per settlement (unique key on
-- a generated column); a failed/cancelled payout stays as history.
-- ---------------------------------------------------------------------
CREATE TABLE payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_no VARCHAR(30) NOT NULL,
    settlement_id INT NOT NULL,
    organizer_id INT NOT NULL,
    payout_account_id INT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('gcash','bank') NOT NULL,
    account_name_snapshot VARCHAR(150) NOT NULL,
    account_number_masked VARCHAR(40) NOT NULL,
    bank_name_snapshot VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','processing','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    status_reason VARCHAR(500) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_by INT DEFAULT NULL,
    processing_at DATETIME DEFAULT NULL,
    finished_by INT DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    active_settlement_id INT AS (IF(status IN ('pending','processing','paid'), settlement_id, NULL)) PERSISTENT,
    paid_reference_key VARCHAR(120) AS (IF(status = 'paid', CONCAT(method, ':', reference_no), NULL)) PERSISTENT,
    UNIQUE KEY uq_payout_no (payout_no),
    UNIQUE KEY uq_payout_active_settlement (active_settlement_id),
    UNIQUE KEY uq_payout_paid_reference (paid_reference_key),
    INDEX idx_payout_org_status (organizer_id, status),
    INDEX idx_payout_settlement (settlement_id),
    CONSTRAINT fk_payout_settlement FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_processing_by FOREIGN KEY (processing_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payout_finished_by FOREIGN KEY (finished_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payout_amount CHECK (amount > 0),
    CONSTRAINT chk_payout_paid_reference CHECK (status <> 'paid' OR (reference_no IS NOT NULL AND reference_no <> ''))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Append-only history of every financial status change.
-- ---------------------------------------------------------------------
CREATE TABLE finance_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('ledger_entry','settlement','payout','payout_account') NOT NULL,
    entity_id INT NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) NOT NULL,
    actor_user_id INT DEFAULT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fsh_entity (entity_type, entity_id),
    CONSTRAINT fk_fsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE system_settings
    ADD COLUMN settlement_hold_days INT NOT NULL DEFAULT 3,
    ADD CONSTRAINT chk_settlement_hold_days CHECK (settlement_hold_days BETWEEN 0 AND 30);

-- ---------------------------------------------------------------------
-- Immutability triggers
-- ---------------------------------------------------------------------
DELIMITER //

CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON organizer_ledger_entries FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries cannot be deleted; record a reversal entry instead.';
END//

CREATE TRIGGER trg_ledger_guard_update BEFORE UPDATE ON organizer_ledger_entries FOR EACH ROW
BEGIN
    DECLARE v_settlement_status VARCHAR(20) DEFAULT NULL;

    IF NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NOT (NEW.registration_id <=> OLD.registration_id) OR NOT (NEW.payment_id <=> OLD.payment_id)
       OR NEW.entry_type <> OLD.entry_type OR NEW.source_key <> OLD.source_key
       OR NOT (NEW.paymongo_payment_id <=> OLD.paymongo_payment_id) OR NOT (NEW.checkout_session_id <=> OLD.checkout_session_id)
       OR NEW.currency <> OLD.currency OR NEW.gross_amount <> OLD.gross_amount
       OR NEW.deduction_amount <> OLD.deduction_amount OR NEW.event_date <> OLD.event_date
       OR NEW.hold_until <> OLD.hold_until OR NOT (NEW.reverses_entry_id <=> OLD.reverses_entry_id)
       OR NOT (NEW.remarks <=> OLD.remarks) OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entry identity and gross amount are immutable.';
    END IF;

    -- The only amount write ever allowed: a one-time fee fill on an
    -- unsettled entry recorded without a known fee.
    IF NOT (NEW.gateway_fee <=> OLD.gateway_fee) OR NOT (NEW.net_amount <=> OLD.net_amount)
       OR NOT (NEW.organizer_earnings <=> OLD.organizer_earnings) THEN
        IF OLD.gateway_fee IS NOT NULL OR OLD.settlement_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recorded ledger amounts are immutable.';
        END IF;
    END IF;

    -- Entries of an approved settlement are frozen.
    IF OLD.settlement_id IS NOT NULL THEN
        SELECT status INTO v_settlement_status FROM settlements WHERE id = OLD.settlement_id;
        IF v_settlement_status = 'approved'
           AND (NOT (NEW.settlement_id <=> OLD.settlement_id) OR NOT (NEW.hold_reason <=> OLD.hold_reason)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Entries of an approved settlement cannot change.';
        END IF;
    END IF;
END//

CREATE TRIGGER trg_settlement_no_delete BEFORE DELETE ON settlements FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements cannot be deleted; cancel a draft instead.';
END//

CREATE TRIGGER trg_settlement_guard_update BEFORE UPDATE ON settlements FOR EACH ROW
BEGIN
    IF OLD.status IN ('approved', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved or cancelled settlements are immutable.';
    END IF;
    IF NEW.settlement_no <> OLD.settlement_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency OR NEW.entry_count <> OLD.entry_count
       OR NEW.gross_total <> OLD.gross_total OR NEW.fee_total <> OLD.fee_total OR NEW.net_total <> OLD.net_total
       OR NEW.deduction_total <> OLD.deduction_total OR NEW.settlement_amount <> OLD.settlement_amount
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement totals and identity are immutable.';
    END IF;
END//

CREATE TRIGGER trg_payout_account_no_delete BEFORE DELETE ON organizer_payout_accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout accounts cannot be deleted; they become inactive.';
END//

CREATE TRIGGER trg_payout_account_guard_update BEFORE UPDATE ON organizer_payout_accounts FOR EACH ROW
BEGIN
    IF NEW.organizer_id <> OLD.organizer_id OR NEW.method <> OLD.method OR NEW.account_name <> OLD.account_name
       OR NEW.account_number <> OLD.account_number OR NOT (NEW.bank_name <=> OLD.bank_name)
       OR NEW.submitted_by <> OLD.submitted_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout account details are immutable; submit a new account.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('verified', 'rejected', 'inactive'))
        OR (OLD.status = 'verified' AND NEW.status = 'inactive')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout account status change.';
    END IF;
END//

CREATE TRIGGER trg_payout_validate_insert BEFORE INSERT ON payouts FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_amount DECIMAL(12,2) DEFAULT NULL;
    DECLARE v_organizer INT DEFAULT NULL;
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    SELECT status, settlement_amount, organizer_id INTO v_status, v_amount, v_organizer
    FROM settlements WHERE id = NEW.settlement_id;
    SELECT organizer_id, status INTO v_account_org, v_account_status
    FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;

    IF v_status IS NULL OR v_status <> 'approved' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts require an approved settlement.';
    END IF;
    IF NEW.amount <> v_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout amount must equal the settlement amount.';
    END IF;
    IF NEW.organizer_id <> v_organizer OR v_account_org IS NULL OR v_account_org <> v_organizer THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout organizer/account mismatch.';
    END IF;
    IF v_account_status <> 'verified' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout account must be verified.';
    END IF;
    IF NEW.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts must start as pending.';
    END IF;
END//

CREATE TRIGGER trg_payout_no_delete BEFORE DELETE ON payouts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts cannot be deleted; cancel or fail them instead.';
END//

CREATE TRIGGER trg_payout_guard_update BEFORE UPDATE ON payouts FOR EACH ROW
BEGIN
    IF OLD.status IN ('paid', 'failed', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finished payouts are immutable.';
    END IF;
    IF NEW.payout_no <> OLD.payout_no OR NEW.settlement_id <> OLD.settlement_id OR NEW.organizer_id <> OLD.organizer_id
       OR NEW.payout_account_id <> OLD.payout_account_id OR NEW.currency <> OLD.currency OR NEW.amount <> OLD.amount
       OR NEW.method <> OLD.method OR NEW.account_name_snapshot <> OLD.account_name_snapshot
       OR NEW.account_number_masked <> OLD.account_number_masked OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout amount and destination are immutable.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('processing', 'failed', 'cancelled'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'failed'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout status change.';
    END IF;
END//

CREATE TRIGGER trg_fsh_no_update BEFORE UPDATE ON finance_status_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance history is append-only.';
END//

CREATE TRIGGER trg_fsh_no_delete BEFORE DELETE ON finance_status_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Finance history is append-only.';
END//

DELIMITER ;
