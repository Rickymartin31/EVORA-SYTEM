-- Rollback of v37 (drops one-time login codes only).
DROP TABLE IF EXISTS login_otps;
