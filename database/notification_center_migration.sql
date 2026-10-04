-- =====================================================================
-- EVORA v31 — Notification Center & User Alert System
--
-- Extends the EXISTING notifications table (no new table): every row keeps
-- its user_id, title, message, type, is_read and created_at. Added:
--   category        payment | refund | payout | event | system  (tabs, sidebar badges)
--   notice_type     informational | action_required | status   (View vs Review)
--   reference_type  what the notification is about (payment, refund, payout_request, ...)
--   reference_id    its id — the link is built from these for the viewer's role
--   read_at         when it was read
-- The existing `type` column is unchanged: it remains the fine-grained event
-- key (payment_status, new_registration, ...) used by existing code.
--
-- Backup before running:  C:\xampp\evora_backups\evora_db_before_notification_center_<timestamp>.sql
-- Rollback:               database/notification_center_rollback.sql
-- =====================================================================

ALTER TABLE notifications
    ADD COLUMN category ENUM('payment','refund','payout','event','system') NOT NULL DEFAULT 'system' AFTER type,
    ADD COLUMN notice_type ENUM('informational','action_required','status') NOT NULL DEFAULT 'informational' AFTER category,
    ADD COLUMN reference_type VARCHAR(40) DEFAULT NULL AFTER notice_type,
    ADD COLUMN reference_id INT DEFAULT NULL AFTER reference_type,
    ADD COLUMN read_at DATETIME DEFAULT NULL AFTER is_read,
    ADD KEY idx_notif_user_unread (user_id, is_read, category),
    ADD KEY idx_notif_reference (reference_type, reference_id);

-- Categorize existing notifications from their type (title only where one
-- type covered two things). Titles, messages and read flags are untouched.
UPDATE notifications SET
    category = CASE
        WHEN type IN ('payment_status', 'payment_submission') THEN 'payment'
        WHEN type = 'refund' THEN 'refund'
        WHEN type = 'finance' AND title LIKE '%Refund%' THEN 'refund'
        WHEN type = 'finance' THEN 'payout'
        WHEN type IN ('new_registration', 'registration_status', 'event_submitted', 'event_approved', 'event_rejected',
                      'event_removed', 'event_cancelled', 'event_reminder', 'route_update', 'announcement',
                      'staff_assignment', 'certificate_issued') THEN 'event'
        ELSE 'system'
    END,
    notice_type = CASE
        WHEN type IN ('event_submitted', 'organizer_application', 'payment_submission') THEN 'action_required'
        WHEN type = 'finance' AND (title LIKE '%To Review%' OR title LIKE '%To Verify%') THEN 'action_required'
        WHEN type IN ('payment_status', 'registration_status', 'event_approved', 'event_rejected', 'event_removed',
                      'event_cancelled', 'organizer_status', 'refund', 'finance', 'certificate_issued') THEN 'status'
        ELSE 'informational'
    END;

UPDATE notifications SET read_at = created_at WHERE is_read = 1 AND read_at IS NULL;
