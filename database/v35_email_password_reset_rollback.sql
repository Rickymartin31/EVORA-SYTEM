-- Rollback of v35 (drops the email queue, reset tokens, email preference and terms-acceptance columns; no other data touched).
ALTER TABLE users DROP COLUMN IF EXISTS terms_version, DROP COLUMN IF EXISTS terms_accepted_at;
ALTER TABLE user_settings DROP COLUMN IF EXISTS notify_email;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS email_queue;
