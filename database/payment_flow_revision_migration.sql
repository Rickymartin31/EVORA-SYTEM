-- EVORA payment flow revision (v27): centralized EVORA payments with
-- registration-closing based payouts.
--
-- Replaces:
--   * v26 organizer-owned PayMongo accounts (organizer connects own account,
--     Account-ID routing) — REMOVED. No organizer ever completed a connection
--     and no payment or event ever used it (checked below before anything
--     changes).
--   * v25 manual settlements (admin builds a settlement after a post-event
--     hold, then creates a payout) — RETIRED. Both tables are empty; they are
--     kept for history and new rows are blocked by triggers.
--
-- New flow:
--   participant pays EVORA's PayMongo account -> PaymentConfirmationService
--   approves it and writes ONE organizer_ledger_entries row (unchanged v25
--   ledger, same transaction) -> organizer_event_earnings keeps the per-event
--   totals -> when registration closes (deadline passed: scheduler, or the
--   organizer closes it) scripts/process_event_payouts.php creates ONE
--   organizer_payout_requests row from the eligible ledger entries -> admin
--   approves / rejects / marks it paid with the transfer reference.
--
-- Every amount comes from the ledger (which comes from payments). Nothing is
-- typed by a person. Database triggers repeat the important rules.
--
-- Backup taken before this migration:
--   C:\xampp\evora_backups\evora_db_before_payment_flow_revision_<timestamp>.sql
-- Rollback: database/payment_flow_revision_rollback.sql

-- =====================================================================
-- PART A — remove v26 organizer-owned PayMongo connection
-- =====================================================================

-- Safety: stop here (the mysql client aborts on the error) if any payment or
-- event was ever routed to an organizer's own PayMongo account.
DELIMITER //
CREATE PROCEDURE evora_v27_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM payments WHERE payment_mode = 'paymongo_connected')
       OR EXISTS (SELECT 1 FROM events WHERE payment_mode = 'paymongo_connected')
       OR EXISTS (SELECT 1 FROM organizer_payment_accounts WHERE status = 'connected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'v27 aborted: organizer-owned PayMongo payments exist. Resolve them before removing v26.';
    END IF;
END//
DELIMITER ;
CALL evora_v27_guard();
DROP PROCEDURE evora_v27_guard;

DROP TRIGGER IF EXISTS trg_ledger_evora_payment_only;
DROP TRIGGER IF EXISTS trg_payment_mode_lock;
DROP TRIGGER IF EXISTS trg_event_payment_mode_lock;
DROP TRIGGER IF EXISTS trg_pmh_no_update;
DROP TRIGGER IF EXISTS trg_pmh_no_delete;
DROP TRIGGER IF EXISTS trg_opa_no_delete;

-- The few v26 rows (connection requests / mode choices) are kept, read-only,
-- as archive tables instead of being deleted.
RENAME TABLE organizer_payment_accounts TO archive_v26_organizer_payment_accounts,
             organizer_payment_mode_history TO archive_v26_organizer_payment_mode_history;

ALTER TABLE payments
    DROP CONSTRAINT chk_payment_account_only_connected,
    DROP COLUMN paymongo_account_id,
    DROP COLUMN payment_mode;

ALTER TABLE events
    DROP CONSTRAINT chk_event_paymongo_account,
    DROP COLUMN paymongo_account_id,
    DROP COLUMN payment_mode;

ALTER TABLE organizers
    DROP CONSTRAINT chk_organizer_approved_has_mode,
    DROP COLUMN payment_mode;

-- =====================================================================
-- PART B — registration closing + event earnings + payout requests
-- =====================================================================

-- When registration closed (automatically after the deadline, or by the
-- organizer). Payout requests are only created for events that have it.
ALTER TABLE events
    ADD COLUMN registration_closed_at DATETIME DEFAULT NULL AFTER registration_deadline;

-- ---------------------------------------------------------------------
-- Per-event organizer earnings. One row per event; totals are always
-- recalculated from organizer_ledger_entries by PayoutRequestService (never
-- typed). payout_paused_reason is set when an admin rejects a payout request:
-- the scheduler then stops re-creating requests until an admin resumes it.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_event_earnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    participant_count INT NOT NULL DEFAULT 0,
    gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    gateway_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    held_count INT NOT NULL DEFAULT 0,
    requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('collecting','closed','payout_requested','paid','on_hold') NOT NULL DEFAULT 'collecting',
    payout_paused_reason VARCHAR(500) DEFAULT NULL,
    last_calculated_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_earnings_event (event_id),
    INDEX idx_earnings_org_status (organizer_id, status),
    CONSTRAINT fk_earnings_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_earnings_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT chk_earnings_formula CHECK (net_amount = gross_amount - gateway_fee)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Payout requests. Created by the scheduler (created_by NULL = system) from
-- the event's eligible ledger entries; amounts are copied from those entries.
--   pending -> approved -> (processing ->) paid
--   pending / approved / processing -> rejected (entries are released)
-- At most one open (pending/approved/processing) request per event, and a
-- transfer reference can be used for only one paid request.
-- The payout account is snapshotted when the admin approves.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payout_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(30) NOT NULL,
    organizer_id INT NOT NULL,
    event_id INT NOT NULL,
    earnings_id INT NOT NULL,
    sequence_no INT NOT NULL DEFAULT 1,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    entry_count INT NOT NULL,
    participant_count INT NOT NULL,
    gross_amount DECIMAL(12,2) NOT NULL,
    gateway_fee DECIMAL(12,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending','approved','processing','paid','rejected') NOT NULL DEFAULT 'pending',
    payout_account_id INT DEFAULT NULL,
    method ENUM('gcash','bank') DEFAULT NULL,
    account_name_snapshot VARCHAR(150) DEFAULT NULL,
    account_number_masked VARCHAR(40) DEFAULT NULL,
    bank_name_snapshot VARCHAR(100) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    processing_by INT DEFAULT NULL,
    processing_at DATETIME DEFAULT NULL,
    paid_by INT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    proof_path VARCHAR(255) DEFAULT NULL,
    rejected_by INT DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    reject_reason VARCHAR(500) DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_event_id INT AS (IF(status IN ('pending','approved','processing'), event_id, NULL)) PERSISTENT,
    paid_reference_key VARCHAR(120) AS (IF(status = 'paid', CONCAT(method, ':', reference_number), NULL)) PERSISTENT,
    UNIQUE KEY uq_payout_request_no (request_no),
    UNIQUE KEY uq_payout_request_event_seq (event_id, sequence_no),
    UNIQUE KEY uq_payout_request_open_event (open_event_id),
    UNIQUE KEY uq_payout_request_paid_reference (paid_reference_key),
    INDEX idx_payout_request_org_status (organizer_id, status),
    INDEX idx_payout_request_status (status),
    CONSTRAINT fk_opr_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_earnings FOREIGN KEY (earnings_id) REFERENCES organizer_event_earnings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_account FOREIGN KEY (payout_account_id) REFERENCES organizer_payout_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_processing_by FOREIGN KEY (processing_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opr_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_opr_amount CHECK (amount > 0 AND amount = gross_amount - gateway_fee),
    CONSTRAINT chk_opr_approved CHECK (status NOT IN ('approved','processing','paid')
        OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND payout_account_id IS NOT NULL AND method IS NOT NULL)),
    CONSTRAINT chk_opr_paid CHECK (status <> 'paid'
        OR (paid_by IS NOT NULL AND paid_at IS NOT NULL AND reference_number IS NOT NULL AND reference_number <> '')),
    CONSTRAINT chk_opr_rejected CHECK (status <> 'rejected'
        OR (rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND reject_reason IS NOT NULL))
) ENGINE=InnoDB;

-- Ledger entries point at the payout request that pays them out.
ALTER TABLE organizer_ledger_entries
    ADD COLUMN payout_request_id INT DEFAULT NULL AFTER settlement_id,
    ADD INDEX idx_ledger_payout_request (payout_request_id),
    ADD CONSTRAINT fk_ledger_payout_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests(id) ON DELETE RESTRICT;

ALTER TABLE finance_status_history
    MODIFY entity_type ENUM('ledger_entry','settlement','payout','payout_account','event_earnings','payout_request') NOT NULL;

-- =====================================================================
-- Triggers
-- =====================================================================
DELIMITER //

-- v25 settlements/payouts are retired: payout requests replace them, so a
-- ledger entry can never be paid out twice.
CREATE TRIGGER trg_settlement_retired BEFORE INSERT ON settlements FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements are retired; payouts are created as organizer payout requests.';
END//

-- Payout request linkage on ledger entries (runs after the v25 guard).
CREATE TRIGGER trg_ledger_payout_request_guard BEFORE UPDATE ON organizer_ledger_entries FOR EACH ROW FOLLOWS trg_ledger_guard_update
BEGIN
    DECLARE v_new_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_new_event INT DEFAULT NULL;
    DECLARE v_old_status VARCHAR(20) DEFAULT NULL;

    IF OLD.settlement_id IS NULL AND NEW.settlement_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlements are retired; ledger entries are paid through payout requests.';
    END IF;

    IF OLD.payout_request_id IS NOT NULL THEN
        SELECT status INTO v_old_status FROM organizer_payout_requests WHERE id = OLD.payout_request_id;
    END IF;

    IF NOT (NEW.payout_request_id <=> OLD.payout_request_id) THEN
        IF OLD.payout_request_id IS NULL THEN
            -- Linking: only unsettled entries, only into a pending request of the same event.
            SELECT status, event_id INTO v_new_status, v_new_event FROM organizer_payout_requests WHERE id = NEW.payout_request_id;
            IF OLD.settlement_id IS NOT NULL OR v_new_status IS NULL OR v_new_status <> 'pending' OR v_new_event <> OLD.event_id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ledger entry can only join a pending payout request of its own event.';
            END IF;
        ELSEIF NEW.payout_request_id IS NULL THEN
            -- Releasing: only when that request was rejected.
            IF v_old_status <> 'rejected' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are released only by rejecting their payout request.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A ledger entry cannot move between payout requests.';
        END IF;
    END IF;

    -- Entries inside a live payout request are frozen.
    IF OLD.payout_request_id IS NOT NULL AND v_old_status <> 'rejected'
       AND (NOT (NEW.hold_reason <=> OLD.hold_reason) OR NOT (NEW.gateway_fee <=> OLD.gateway_fee)
            OR NOT (NEW.net_amount <=> OLD.net_amount) OR NOT (NEW.organizer_earnings <=> OLD.organizer_earnings)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Entries of a payout request cannot change.';
    END IF;
END//

CREATE TRIGGER trg_earnings_no_delete BEFORE DELETE ON organizer_event_earnings FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event earnings records cannot be deleted.';
END//

CREATE TRIGGER trg_earnings_guard_update BEFORE UPDATE ON organizer_event_earnings FOR EACH ROW
BEGIN
    IF NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event earnings identity is immutable.';
    END IF;
END//

CREATE TRIGGER trg_payout_request_validate_insert BEFORE INSERT ON organizer_payout_requests FOR EACH ROW
BEGIN
    DECLARE v_event_org INT DEFAULT NULL;
    DECLARE v_earn_event INT DEFAULT NULL;

    SELECT organizer_id INTO v_event_org FROM events WHERE id = NEW.event_id;
    SELECT event_id INTO v_earn_event FROM organizer_event_earnings WHERE id = NEW.earnings_id;

    IF NEW.status <> 'pending' OR NEW.payout_account_id IS NOT NULL OR NEW.approved_by IS NOT NULL
       OR NEW.paid_by IS NOT NULL OR NEW.reference_number IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout requests must start as pending, unapproved and unpaid.';
    END IF;
    IF v_event_org IS NULL OR v_event_org <> NEW.organizer_id OR v_earn_event IS NULL OR v_earn_event <> NEW.event_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request organizer/event/earnings mismatch.';
    END IF;
END//

CREATE TRIGGER trg_payout_request_no_delete BEFORE DELETE ON organizer_payout_requests FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout requests cannot be deleted; reject them instead.';
END//

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

    -- Destination is fixed once set (at approval).
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
