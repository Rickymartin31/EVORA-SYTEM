-- Migration v10: Certificate Generation and Management enhancement.
-- Additive only — existing certificates rows keep working unchanged
-- (generated_by defaults NULL for historical rows, status defaults 'issued').

ALTER TABLE certificates
  ADD COLUMN generated_by INT DEFAULT NULL AFTER issued_at,
  ADD COLUMN status ENUM('issued','revoked') NOT NULL DEFAULT 'issued' AFTER generated_by,
  ADD CONSTRAINT fk_cert_generated_by FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE event_certificate_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL UNIQUE,
    certificate_title VARCHAR(150) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    venue_override VARCHAR(200) DEFAULT NULL,
    organization_name_override VARCHAR(150) DEFAULT NULL,
    organizer_name_override VARCHAR(150) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    signatory1_name VARCHAR(150) DEFAULT NULL,
    signatory1_title VARCHAR(150) DEFAULT NULL,
    signatory1_signature_path VARCHAR(255) DEFAULT NULL,
    signatory2_name VARCHAR(150) DEFAULT NULL,
    signatory2_title VARCHAR(150) DEFAULT NULL,
    signatory2_signature_path VARCHAR(255) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_certsettings_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_certsettings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
