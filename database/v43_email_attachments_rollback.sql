-- Rollback of v43.
ALTER TABLE email_queue DROP COLUMN IF EXISTS attachments;
