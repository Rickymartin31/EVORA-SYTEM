-- EVORA v29: multiple verified payout accounts per organizer
--
-- organizer_payout_accounts already supports many rows per organizer (v25);
-- it is extended, not duplicated:
--   * is_primary       — the organizer's default payout destination; only a
--                        verified account can be primary; at most one per
--                        organizer (unique generated column)
--   * updated_at       — last change
--   * no duplicate active accounts: the same method + number (+ bank) cannot be
--     pending/verified twice for one organizer (unique generated column)
-- Existing columns are reused: reviewed_by / reviewed_at = verified_by /
-- verified_at; status 'inactive' = removed by the organizer (rows are never
-- deleted, so every payout keeps pointing at the exact account it used).
--
-- payout_detail_access_logs.payout_request_id becomes nullable so that
-- admin reveals on the Payout Accounts page (no payout request) are logged too.
--
-- Backup: C:\xampp\evora_backups\evora_db_before_multi_payout_accounts_<timestamp>.sql
-- Rollback: database/multi_payout_accounts_rollback.sql

ALTER TABLE organizer_payout_accounts
    ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN primary_organizer_id INT AS (IF(is_primary = 1, organizer_id, NULL)) PERSISTENT,
    ADD COLUMN active_account_key VARCHAR(220) AS (IF(status IN ('pending', 'verified'),
        CONCAT(organizer_id, ':', method, ':', account_number, ':', LOWER(COALESCE(bank_name, ''))), NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_payout_account_primary (primary_organizer_id),
    ADD UNIQUE KEY uq_payout_account_active (active_account_key),
    ADD CONSTRAINT chk_payout_account_primary_verified CHECK (is_primary = 0 OR status = 'verified');

-- Each organizer's latest verified account becomes their primary account.
UPDATE organizer_payout_accounts a
JOIN (SELECT organizer_id, MAX(id) AS id FROM organizer_payout_accounts WHERE status = 'verified' GROUP BY organizer_id) v ON v.id = a.id
SET a.is_primary = 1;

ALTER TABLE payout_detail_access_logs
    MODIFY payout_request_id INT DEFAULT NULL;
