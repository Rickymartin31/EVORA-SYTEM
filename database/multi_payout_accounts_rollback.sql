-- Rollback for database/multi_payout_accounts_migration.sql (v29)
--
-- ORDER MATTERS:
--   1. Restore the PHP files from C:\xampp\evora_backups\rollback_v29_originals\
--      (full copy of core/, modules/, scripts/, docs/, public/*.php and
--      database/schema.sql taken before v29).
--   2. THEN run this file.
--
-- Data note: organizers keep all their account rows. v28 code treats the
-- NEWEST pending/verified account as the current one, so an organizer with
-- several active accounts will be paid to the newest one after rollback —
-- review Payout Accounts afterwards. Access-log rows written from the Payout
-- Accounts page (no payout request) are kept in a backup table first.
--
-- Alternative: restore the whole database from
--   C:\xampp\evora_backups\evora_db_before_multi_payout_accounts_<timestamp>.sql

CREATE TABLE archive_v29_account_detail_access_logs AS
    SELECT * FROM payout_detail_access_logs WHERE payout_request_id IS NULL;

-- The table is append-only (triggers); lift that only for this cleanup.
DROP TRIGGER IF EXISTS trg_pdal_no_delete;
DELETE FROM payout_detail_access_logs WHERE payout_request_id IS NULL;
DELIMITER //
CREATE TRIGGER trg_pdal_no_delete BEFORE DELETE ON payout_detail_access_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout detail access logs are append-only.';
END//
DELIMITER ;

ALTER TABLE payout_detail_access_logs
    MODIFY payout_request_id INT NOT NULL;

ALTER TABLE organizer_payout_accounts
    DROP CONSTRAINT chk_payout_account_primary_verified,
    DROP INDEX uq_payout_account_active,
    DROP INDEX uq_payout_account_primary,
    DROP COLUMN active_account_key,
    DROP COLUMN primary_organizer_id,
    DROP COLUMN updated_at,
    DROP COLUMN is_primary;
