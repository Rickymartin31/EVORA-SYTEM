-- Rollback for database/payment_flow_revision_migration.sql (v27)
--
-- ORDER MATTERS:
--   1. Restore the PHP files from C:\xampp\evora_backups\rollback_v27_originals\
--      (a full copy of core/, modules/, scripts/, docs/, public/*.php,
--      public/assets/ and database/schema.sql taken before v27 — copy them
--      back over the project) and delete the files v27 added:
--        core/PayoutRequestService.php, core/EventRegistrationService.php,
--        modules/admin/payout_requests.php, modules/admin/payout_request_view.php,
--        modules/admin/payout_request_action.php,
--        modules/organizer/payout_request_view.php,
--        scripts/process_event_payouts.php
--   2. THEN run this file. It restores the v26 database shape (organizer-owned
--      PayMongo columns/tables/triggers) with every row on EVORA Payment, which
--      is exactly what the data was before v27.
--
-- Alternative: restore the whole database from
--   C:\xampp\evora_backups\evora_db_before_payment_flow_revision_<timestamp>.sql
-- (this also discards every registration/payment made after the backup).
--
-- Money safety: this file refuses to run once any payout request has been
-- approved, is processing or was paid — those record real money movements.
-- Export them first and decide how to carry them over:
--   SELECT * FROM organizer_payout_requests;
--   SELECT * FROM organizer_ledger_entries WHERE payout_request_id IS NOT NULL;
--
-- Not reversed: finance_status_history.entity_type keeps its two extra enum
-- values ('event_earnings','payout_request'); history rows are append-only and
-- may already use them. The extra values are harmless to v26 code.

DELIMITER //
CREATE PROCEDURE evora_v27_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM organizer_payout_requests WHERE status IN ('approved','processing','paid')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback aborted: approved/processing/paid payout requests exist. Export and resolve them first.';
    END IF;
END//
DELIMITER ;
CALL evora_v27_rollback_guard();
DROP PROCEDURE evora_v27_rollback_guard;

-- ---------------------------------------------------------------------
-- Undo PART B
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_payout_request_guard_update;
DROP TRIGGER IF EXISTS trg_payout_request_no_delete;
DROP TRIGGER IF EXISTS trg_payout_request_validate_insert;
DROP TRIGGER IF EXISTS trg_earnings_guard_update;
DROP TRIGGER IF EXISTS trg_earnings_no_delete;
DROP TRIGGER IF EXISTS trg_ledger_payout_request_guard;
DROP TRIGGER IF EXISTS trg_settlement_retired;

ALTER TABLE organizer_ledger_entries
    DROP FOREIGN KEY fk_ledger_payout_request,
    DROP INDEX idx_ledger_payout_request,
    DROP COLUMN payout_request_id;

DROP TABLE IF EXISTS organizer_payout_requests;
DROP TABLE IF EXISTS organizer_event_earnings;

-- Events closed automatically keep status "closed"; only the timestamp goes.
ALTER TABLE events DROP COLUMN registration_closed_at;

-- ---------------------------------------------------------------------
-- Undo PART A: restore the v26 shape (all rows = EVORA Payment)
-- ---------------------------------------------------------------------
RENAME TABLE archive_v26_organizer_payment_accounts TO organizer_payment_accounts,
             archive_v26_organizer_payment_mode_history TO organizer_payment_mode_history;

ALTER TABLE organizers
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') DEFAULT NULL AFTER status;
UPDATE organizers SET payment_mode = 'evora_payment' WHERE payment_mode IS NULL;
ALTER TABLE organizers
    ADD CONSTRAINT chk_organizer_approved_has_mode CHECK (status <> 'approved' OR payment_mode IS NOT NULL);

ALTER TABLE events
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') DEFAULT NULL AFTER manual_payment_enabled,
    ADD COLUMN paymongo_account_id VARCHAR(100) DEFAULT NULL AFTER payment_mode;
UPDATE events SET payment_mode = 'evora_payment' WHERE payment_mode IS NULL;
ALTER TABLE events
    ADD CONSTRAINT chk_event_paymongo_account CHECK (payment_mode = 'paymongo_connected' OR paymongo_account_id IS NULL);

ALTER TABLE payments
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') NOT NULL DEFAULT 'evora_payment' AFTER payment_channel,
    ADD COLUMN paymongo_account_id VARCHAR(100) DEFAULT NULL AFTER payment_mode,
    ADD CONSTRAINT chk_payment_account_only_connected CHECK (payment_mode = 'paymongo_connected' OR paymongo_account_id IS NULL);

DELIMITER //

CREATE TRIGGER trg_ledger_evora_payment_only BEFORE INSERT ON organizer_ledger_entries FOR EACH ROW
BEGIN
    IF NEW.payment_id IS NOT NULL
       AND (SELECT payment_mode FROM payments WHERE id = NEW.payment_id) = 'paymongo_connected' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayMongo Connected payments never create EVORA ledger entries.';
    END IF;
END//

CREATE TRIGGER trg_payment_mode_lock BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF OLD.status IN ('approved', 'refunded')
       AND (NEW.payment_mode <> OLD.payment_mode OR NOT (NEW.paymongo_account_id <=> OLD.paymongo_account_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payment mode of a completed payment cannot be changed.';
    END IF;
END//

CREATE TRIGGER trg_event_payment_mode_lock BEFORE UPDATE ON events FOR EACH ROW
BEGIN
    IF OLD.payment_mode IS NOT NULL
       AND (NOT (NEW.payment_mode <=> OLD.payment_mode) OR NOT (NEW.paymongo_account_id <=> OLD.paymongo_account_id))
       AND EXISTS (SELECT 1 FROM payments p JOIN registrations r ON r.id = p.registration_id
                   WHERE r.event_id = OLD.id AND p.payment_channel = 'paymongo' AND p.status IN ('approved', 'refunded')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This event already has paid registrations; its payment account is locked.';
    END IF;
END//

CREATE TRIGGER trg_pmh_no_update BEFORE UPDATE ON organizer_payment_mode_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment mode history is append-only.';
END//

CREATE TRIGGER trg_pmh_no_delete BEFORE DELETE ON organizer_payment_mode_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment mode history is append-only.';
END//

CREATE TRIGGER trg_opa_no_delete BEFORE DELETE ON organizer_payment_accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment account records cannot be deleted; they are marked disconnected.';
END//

DELIMITER ;
