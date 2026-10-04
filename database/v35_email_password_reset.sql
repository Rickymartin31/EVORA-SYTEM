-- v35: outgoing email (queue) + Forgot password. Additive only: new tables and one new
-- user_settings column. Rollback: v35_email_password_reset_rollback.sql
CREATE TABLE IF NOT EXISTS email_queue (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NULL,
  to_email VARCHAR(150) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL,
  status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  notification_id INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_email_queue_status (status, id),
  KEY fk_email_queue_user (user_id),
  CONSTRAINT fk_email_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the emailed token; the token itself is never stored',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  requested_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY fk_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_settings
  ADD COLUMN IF NOT EXISTS notify_email TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'also send notifications by email' AFTER notify_evaluation;

-- Record when (and which version of) the User Agreement + Privacy Policy a new account accepted.
-- Existing accounts stay NULL (created before the checkbox existed).
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS terms_accepted_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS terms_version VARCHAR(10) NULL AFTER terms_accepted_at;
