-- Migration v22: Seminar/Workshop Phase 1
-- Speaker profile fields, richer session scheduling, per-session attendance
-- tracking, event feedback, seminar-specific optional registration fields,
-- and 3 new staff permissions.

ALTER TABLE event_speakers
  ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER speaker_bio,
  ADD COLUMN organization VARCHAR(150) DEFAULT NULL AFTER photo_path,
  ADD COLUMN position VARCHAR(100) DEFAULT NULL AFTER organization,
  ADD COLUMN contact_email VARCHAR(150) DEFAULT NULL AFTER position,
  ADD COLUMN contact_phone VARCHAR(30) DEFAULT NULL AFTER contact_email,
  ADD COLUMN social_links VARCHAR(500) DEFAULT NULL AFTER contact_phone;

ALTER TABLE event_schedule
  ADD COLUMN session_date DATE DEFAULT NULL AFTER event_id,
  ADD COLUMN description TEXT DEFAULT NULL AFTER session_title,
  ADD COLUMN venue VARCHAR(200) DEFAULT NULL AFTER end_time,
  ADD COLUMN session_type ENUM('keynote','lecture','workshop','breakout','panel','hands_on') NOT NULL DEFAULT 'lecture' AFTER venue,
  ADD COLUMN status ENUM('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled' AFTER session_type;

ALTER TABLE registrations
  ADD COLUMN organization VARCHAR(150) DEFAULT NULL,
  ADD COLUMN profession VARCHAR(100) DEFAULT NULL,
  ADD COLUMN dietary_preference VARCHAR(100) DEFAULT NULL,
  ADD COLUMN special_requirements VARCHAR(255) DEFAULT NULL;

CREATE TABLE session_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    registration_id INT NOT NULL,
    scanned_by INT NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_attendance (session_id, registration_id),
    CONSTRAINT fk_sessatt_session FOREIGN KEY (session_id) REFERENCES event_schedule(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessatt_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessatt_scanner FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    registration_id INT NOT NULL,
    overall_rating TINYINT NOT NULL CHECK (overall_rating BETWEEN 1 AND 5),
    speaker_rating TINYINT DEFAULT NULL CHECK (speaker_rating BETWEEN 1 AND 5),
    session_rating TINYINT DEFAULT NULL CHECK (session_rating BETWEEN 1 AND 5),
    venue_rating TINYINT DEFAULT NULL CHECK (venue_rating BETWEEN 1 AND 5),
    suggestions VARCHAR(1000) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_feedback_registration (event_id, registration_id),
    CONSTRAINT fk_feedback_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores','manage_incidents','manage_bibs','manage_finish_results','manage_checkpoints','manage_medical','view_race_operations','manage_sessions','manage_speakers','manage_feedback') NOT NULL;
