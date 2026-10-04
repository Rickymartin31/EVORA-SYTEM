-- =====================================================================
-- Rollback for payout_accountability_migration.sql
-- WARNING: drops the accountability tables (agreements, acceptances, operator
-- profile + history, receipts, disputes + events, record exports) and their
-- data. Back up the database first. Payout requests, ledger, earnings and
-- payments are not touched (only the two added columns are removed).
-- =====================================================================

DROP TRIGGER IF EXISTS trg_activity_logs_no_update;
DROP TRIGGER IF EXISTS trg_activity_logs_no_delete;

DROP TABLE IF EXISTS payout_record_exports;
DROP TABLE IF EXISTS payout_dispute_events;
DROP TABLE IF EXISTS payout_disputes;
DROP TABLE IF EXISTS payout_receipt_responses;
DROP TABLE IF EXISTS payout_agreement_acceptances;
DROP TABLE IF EXISTS payout_agreements;
DROP TABLE IF EXISTS platform_operator_profile;
DROP TABLE IF EXISTS platform_operator_profile_history;

-- original payout request triggers (exact pre-migration bodies)
DROP TRIGGER IF EXISTS trg_payout_request_validate_insert;
DELIMITER $$
CREATE TRIGGER trg_payout_request_validate_insert BEFORE INSERT ON organizer_payout_requests
FOR EACH ROW
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
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payout_request_guard_update;
DELIMITER $$
CREATE TRIGGER trg_payout_request_guard_update BEFORE UPDATE ON organizer_payout_requests
FOR EACH ROW
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
END$$
DELIMITER ;

ALTER TABLE organizer_payout_requests
    DROP INDEX idx_payout_requests_status_due,
    DROP COLUMN due_at,
    DROP COLUMN proof_sha256;

ALTER TABLE system_settings
    DROP CONSTRAINT chk_settings_payout_sla,
    DROP CONSTRAINT chk_settings_dispute_window,
    DROP CONSTRAINT chk_settings_admin_response;
ALTER TABLE system_settings
    DROP COLUMN payout_sla_days,
    DROP COLUMN dispute_window_days,
    DROP COLUMN admin_response_days;
