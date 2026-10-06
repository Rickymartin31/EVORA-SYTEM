-- v56: live race tracking for fun runs.
-- * Checkpoints get a map position (lat/lng) and a pass radius.
-- * event_route_paths: the official route drawn on the map by the organizer.
-- * race_tracks / race_track_points: a runner's GPS track sent from the EVORA tracker on their phone,
--   and the anti-shortcut verdict computed from it.
-- Additive only. Rollback: v56_live_tracking_rollback.sql
ALTER TABLE event_checkpoints
  ADD COLUMN IF NOT EXISTS lat DECIMAL(9,6) NULL COMMENT 'map position (v56)' AFTER distance_km,
  ADD COLUMN IF NOT EXISTS lng DECIMAL(9,6) NULL AFTER lat,
  ADD COLUMN IF NOT EXISTS radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'a runner passes it within this distance' AFTER lng;

CREATE TABLE IF NOT EXISTS event_route_paths (
  event_id INT(11) NOT NULL,
  points_json MEDIUMTEXT NOT NULL COMMENT '[[lat,lng],...] in running order',
  distance_m INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by INT(11) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  CONSTRAINT fk_route_path_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_route_path_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_tracks (
  id INT(11) NOT NULL AUTO_INCREMENT,
  registration_id INT(11) NOT NULL,
  event_id INT(11) NOT NULL,
  status ENUM('active','finished') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  last_lat DECIMAL(9,6) NULL,
  last_lng DECIMAL(9,6) NULL,
  last_accuracy_m SMALLINT UNSIGNED NULL,
  last_point_at DATETIME NULL,
  point_count INT UNSIGNED NOT NULL DEFAULT 0,
  distance_m INT UNSIGNED NOT NULL DEFAULT 0,
  verdict ENUM('pending','verified','possible_shortcut','insufficient') NOT NULL DEFAULT 'pending',
  verdict_detail VARCHAR(500) NULL,
  checked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_race_track_registration (registration_id),
  KEY idx_race_track_event (event_id, status),
  CONSTRAINT fk_race_track_registration FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE,
  CONSTRAINT fk_race_track_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS race_track_points (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  track_id INT(11) NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  accuracy_m SMALLINT UNSIGNED NULL,
  recorded_at DATETIME NOT NULL COMMENT 'time on the runner''s phone when the position was taken',
  PRIMARY KEY (id),
  UNIQUE KEY uq_track_point_time (track_id, recorded_at),
  CONSTRAINT fk_track_point_track FOREIGN KEY (track_id) REFERENCES race_tracks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
