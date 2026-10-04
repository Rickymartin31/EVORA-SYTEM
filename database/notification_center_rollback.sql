-- =====================================================================
-- EVORA v31 — Notification Center: ROLLBACK
--
-- 1. Restore the code files from C:\xampp\evora_backups\rollback_v31_originals\
--    and delete the new files: core/NotificationService.php, modules/organizer/refunds.php
-- 2. mysql -uroot evora_db < database/notification_center_rollback.sql
--
-- Notifications themselves are kept (title, message, type, is_read); only the
-- v31 columns are removed. Links/categories are archived first.
-- =====================================================================

CREATE TABLE IF NOT EXISTS archive_v31_notification_meta AS
    SELECT id AS notification_id, category, notice_type, reference_type, reference_id, read_at FROM notifications;

ALTER TABLE notifications
    DROP KEY idx_notif_user_unread,
    DROP KEY idx_notif_reference,
    DROP COLUMN read_at,
    DROP COLUMN reference_id,
    DROP COLUMN reference_type,
    DROP COLUMN notice_type,
    DROP COLUMN category;
