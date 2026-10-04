-- Rollback of v38 (unverified accounts become able to log in again).
DROP TABLE IF EXISTS email_verifications;
ALTER TABLE users DROP COLUMN IF EXISTS email_verified_at;
