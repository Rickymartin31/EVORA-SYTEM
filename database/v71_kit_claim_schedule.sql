-- v71: race kit / item claiming before event day.
-- The organizer sets when and where items are claimed (one or more days) and can remind every
-- participant who has not claimed yet; on the last claiming day EVORA reminds them automatically.
-- Claiming outside the schedule (e.g. on event day) is still allowed.
-- Additive only. Rollback: v71_kit_claim_schedule_rollback.sql
CREATE TABLE IF NOT EXISTS event_claim_schedules (
  id INT NOT NULL AUTO_INCREMENT,
  event_id INT NOT NULL,
  claim_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  location VARCHAR(255) NOT NULL,
  note VARCHAR(300) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claim_schedules_event (event_id, claim_date),
  CONSTRAINT fk_claim_schedules_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_claim_reminders (
  id INT NOT NULL AUTO_INCREMENT,
  event_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL COMMENT 'manual | automatic',
  sent_by INT DEFAULT NULL COMMENT 'organizer user id; NULL when automatic',
  recipients INT NOT NULL DEFAULT 0,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claim_reminders_event (event_id, sent_at),
  CONSTRAINT fk_claim_reminders_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
