-- Migration v14: Login attempt limiting / temporary account lockout

ALTER TABLE users
  ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER password_hash,
  ADD COLUMN locked_until DATETIME DEFAULT NULL AFTER failed_login_attempts;
