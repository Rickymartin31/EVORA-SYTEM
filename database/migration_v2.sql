-- =====================================================================
-- EVORA migration v2 — Enhancement pass
-- Run once against an existing evora_db (fresh installs get this baked
-- into schema.sql instead).
-- =====================================================================
USE evora_db;

ALTER TABLE events
  MODIFY status ENUM('draft','pending_approval','approved','open','closed','ongoing','completed','cancelled','removed','archived') NOT NULL DEFAULT 'draft',
  ADD COLUMN admin_remarks VARCHAR(500) DEFAULT NULL AFTER status,
  ADD COLUMN registration_deadline DATE DEFAULT NULL AFTER event_time;

CREATE TABLE IF NOT EXISTS event_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    from_status VARCHAR(30) DEFAULT NULL,
    to_status VARCHAR(30) NOT NULL,
    changed_by INT DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_esl_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_esl_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN profile_picture_path VARCHAR(255) DEFAULT NULL AFTER phone;

ALTER TABLE certificates
  ADD COLUMN certificate_number VARCHAR(50) DEFAULT NULL AFTER certificate_path,
  ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL AFTER certificate_number,
  ADD UNIQUE KEY uq_cert_number (certificate_number),
  ADD UNIQUE KEY uq_cert_token (verification_token);

CREATE TABLE IF NOT EXISTS system_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    functionality TINYINT NOT NULL CHECK (functionality BETWEEN 1 AND 5),
    usability TINYINT NOT NULL CHECK (usability BETWEEN 1 AND 5),
    reliability TINYINT NOT NULL CHECK (reliability BETWEEN 1 AND 5),
    performance_efficiency TINYINT NOT NULL CHECK (performance_efficiency BETWEEN 1 AND 5),
    security TINYINT NOT NULL CHECK (security BETWEEN 1 AND 5),
    compatibility TINYINT NOT NULL CHECK (compatibility BETWEEN 1 AND 5),
    maintainability TINYINT NOT NULL CHECK (maintainability BETWEEN 1 AND 5),
    portability TINYINT NOT NULL CHECK (portability BETWEEN 1 AND 5),
    comment VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
