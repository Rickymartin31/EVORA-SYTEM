-- Migration v21: Fun Run Phase 2 - Race Day Operations
-- Checkpoint status/type expansion, checkpoint scan tracking, announcement
-- phase tagging, a separate race medical incident log, and 3 new staff
-- permissions for checkpoint scanning, medical logging, and race operations
-- visibility.

ALTER TABLE event_checkpoints
  MODIFY COLUMN checkpoint_type ENUM('start','finish','checkpoint','water_station','medical_station','emergency_point','turnaround_point') NOT NULL DEFAULT 'checkpoint',
  ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER checkpoint_type;

ALTER TABLE event_announcements
  ADD COLUMN phase ENUM('before','during','after') DEFAULT NULL AFTER message;

CREATE TABLE checkpoint_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    checkpoint_id INT NOT NULL,
    registration_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_checkpoint_scan (checkpoint_id, registration_id),
    CONSTRAINT fk_cpscan_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES event_checkpoints(id) ON DELETE CASCADE,
    CONSTRAINT fk_cpscan_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cpscan_scanner FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE race_medical_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    registration_id INT DEFAULT NULL,
    checkpoint_id INT DEFAULT NULL,
    incident_type ENUM('injury','dehydration','fatigue','cardiac','fall','other') NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    action_taken VARCHAR(500) DEFAULT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    reported_by INT NOT NULL,
    resolved_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_medinc_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_medinc_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL,
    CONSTRAINT fk_medinc_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES event_checkpoints(id) ON DELETE SET NULL,
    CONSTRAINT fk_medinc_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT fk_medinc_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_medinc_event (event_id, status)
) ENGINE=InnoDB;

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores','manage_incidents','manage_bibs','manage_finish_results','manage_checkpoints','manage_medical','view_race_operations') NOT NULL;
