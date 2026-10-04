-- Rollback of v41 (accounts made with Google keep working with Forgot password).
ALTER TABLE users DROP INDEX IF EXISTS uq_users_google_sub, DROP COLUMN IF EXISTS google_sub;
