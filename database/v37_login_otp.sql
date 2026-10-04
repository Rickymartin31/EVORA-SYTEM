-- v37: one-time login codes (email OTP) for administrator sign-in. Additive only.
-- Rollback: v37_login_otp_rollback.sql
CREATE TABLE IF NOT EXISTS login_otps (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  code_hash VARCHAR(255) NOT NULL COMMENT 'password_hash of the 6-digit code; the code itself is never stored',
  expires_at DATETIME NOT NULL,
  attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  consumed_at DATETIME NULL,
  requested_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_otps_user (user_id, id),
  CONSTRAINT fk_login_otps_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
