-- v38: email verification for self-registered accounts. Additive only.
-- Existing accounts (and accounts created by organizers / walk-in) count as verified, so nobody is
-- locked out: the column defaults to the insert time, and only the public sign-up forms set it NULL.
-- Rollback: v38_email_verification_rollback.sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER email;
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the emailed token; the token itself is never stored',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_verifications_token (token_hash),
  KEY fk_email_verifications_user (user_id),
  CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
