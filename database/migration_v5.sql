-- =====================================================================
-- EVORA migration v5 — Live Game Controller + Fun Run Race Ops
-- Run once against an existing evora_db (fresh installs get this baked
-- into schema.sql instead).
-- =====================================================================
USE evora_db;

ALTER TABLE events
  ADD COLUMN game_duration_minutes INT DEFAULT NULL AFTER tournament_format,
  ADD COLUMN periods_count INT DEFAULT NULL AFTER game_duration_minutes;

CREATE TABLE IF NOT EXISTS event_checkpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sequence_no INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    distance_km DECIMAL(5,2) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_checkpoint_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS race_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL UNIQUE,
    finish_time DATETIME NOT NULL,
    recorded_by INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_raceresult_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_raceresult_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
