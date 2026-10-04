-- =====================================================================
-- EVORA v33 — User Account Center
--
-- Only additions (no relationship or existing column changes):
--   activity_logs.user_agent   device/browser of new log rows (for Security and
--                              Activity Log pages); old rows stay NULL
--   user_settings              one optional row per user: language/timezone
--                              (future ready) and which notification categories
--                              raise ALERTS (bell count, dropdown, sidebar badges).
--                              Notifications are still created and kept either way.
--
-- Backup before running:  C:\xampp\evora_backups\evora_db_before_account_center_<timestamp>.sql
-- Rollback:               database/account_center_rollback.sql
-- =====================================================================

ALTER TABLE activity_logs
    ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER ip_address,
    ADD KEY idx_log_user_created (user_id, created_at);

CREATE TABLE user_settings (
    user_id INT NOT NULL PRIMARY KEY,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila',
    notify_payment TINYINT(1) NOT NULL DEFAULT 1,
    notify_refund TINYINT(1) NOT NULL DEFAULT 1,
    notify_payout TINYINT(1) NOT NULL DEFAULT 1,
    notify_event TINYINT(1) NOT NULL DEFAULT 1,
    notify_system TINYINT(1) NOT NULL DEFAULT 1,
    notify_evaluation TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
