-- =====================================================================
-- EVORA v30.1 — Refund Management Module: ROLLBACK
--
-- Procedure:
--   1. Restore the code files from C:\xampp\evora_backups\rollback_v30_originals\
--      (copy the folder contents over the project) and delete the new files:
--        core/RefundService.php
--        modules/admin/refunds.php, refund_view.php, refund_action.php
--        modules/participant/refund_request.php, refund_request_save.php
--   2. Run this file:  mysql -uroot evora_db < database/refund_management_rollback.sql
--   (Or restore the full dump evora_db_before_refund_module_<timestamp>.sql —
--    that also discards every payment/registration made after the migration.)
--
-- Nothing is thrown away: refund rows, cancelled-registration ids and QR
-- invalidations are copied to archive_v30_* tables first. Money already
-- refunded stays refunded (payments.status 'refunded' existed before v30.1),
-- ledger reversals stay (they are the v25 mechanism), and
-- finance_status_history rows of type 'payment_refund' are kept, so that enum
-- value stays (the table is append-only).
-- =====================================================================

DROP TRIGGER IF EXISTS trg_refund_validate_insert;
DROP TRIGGER IF EXISTS trg_refund_guard_update;
DROP TRIGGER IF EXISTS trg_refund_no_delete;
DROP TRIGGER IF EXISTS trg_payment_refund_guard;
DROP TRIGGER IF EXISTS trg_registration_cancel_final;
DROP TRIGGER IF EXISTS trg_qr_invalidation_final;

-- 1. Archive refunds, then drop the table.
CREATE TABLE IF NOT EXISTS archive_v30_payment_refunds AS
    SELECT id, payment_id, registration_id, refund_type, duplicate_history_id, amount, reason_code, reason, status,
           requested_by, approved_by, approved_at, rejected_by, rejected_at, review_remarks, refund_reference,
           proof_path, refunded_by, completed_at, ledger_reversal_id, created_at, updated_at
    FROM payment_refunds;
DROP TABLE IF EXISTS payment_refunds;

-- 2. Cancelled registrations -> rejected (ids archived). Scanners already
--    refuse rejected registrations, so their QR codes stay unusable.
CREATE TABLE IF NOT EXISTS archive_v30_cancelled_registrations AS
    SELECT id AS registration_id, event_id, participant_id, NOW() AS archived_at FROM registrations WHERE status = 'cancelled';
UPDATE registrations SET status = 'rejected' WHERE status = 'cancelled';
ALTER TABLE registrations
    MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';

-- 3. QR invalidations archived, columns removed.
CREATE TABLE IF NOT EXISTS archive_v30_qr_invalidations AS
    SELECT id AS qr_code_id, registration_id, invalidated_at, invalidated_reason FROM qr_codes WHERE invalidated_at IS NOT NULL;
ALTER TABLE qr_codes
    DROP COLUMN invalidated_reason,
    DROP COLUMN invalidated_at;

-- 4. Refund holds become manual holds (the entry stays out of payouts until an
--    admin releases it on the Finance Ledger page).
UPDATE organizer_ledger_entries SET hold_reason = 'manual_hold' WHERE hold_reason = 'refund_pending';
ALTER TABLE organizer_ledger_entries
    MODIFY hold_reason ENUM('fee_unknown','amount_mismatch','manual_hold') DEFAULT NULL;
