-- v43: what to attach to a queued email (built when it is sent: receipt / e-ticket / calendar).
-- Additive. Rollback: v43_email_attachments_rollback.sql
ALTER TABLE email_queue ADD COLUMN IF NOT EXISTS attachments VARCHAR(500) NULL COMMENT 'JSON list, e.g. [{"type":"ticket","registration_id":12}]' AFTER body_text;
