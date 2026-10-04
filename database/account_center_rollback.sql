-- =====================================================================
-- EVORA v33 — User Account Center: ROLLBACK
--
-- 1. Restore the code files from C:\xampp\evora_backups\rollback_v33_originals\
--    and delete the new files: core/AccountService.php and modules/account/
-- 2. mysql -uroot evora_db < database/account_center_rollback.sql
--
-- Preferences are archived first; no notification or log row is deleted.
-- =====================================================================

CREATE TABLE IF NOT EXISTS archive_v33_user_settings AS SELECT * FROM user_settings;
DROP TABLE IF EXISTS user_settings;

CREATE TABLE IF NOT EXISTS archive_v33_log_user_agents AS
    SELECT id AS activity_log_id, user_agent FROM activity_logs WHERE user_agent IS NOT NULL;
ALTER TABLE activity_logs
    DROP KEY idx_log_user_created,
    DROP COLUMN user_agent;
