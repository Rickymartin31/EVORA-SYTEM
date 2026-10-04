-- v41: "Continue with Google" for participants. Stores Google's stable account id ("sub") only.
-- Additive. Rollback: v41_google_signin_rollback.sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS google_sub VARCHAR(64) NULL COMMENT 'Google account id for Continue with Google (participants only)' AFTER email_verified_at,
  ADD UNIQUE KEY IF NOT EXISTS uq_users_google_sub (google_sub);
