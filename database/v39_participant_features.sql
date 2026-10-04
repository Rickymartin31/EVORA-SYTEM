-- v39: participant features — waitlist, saved (favorite) events, and a log of automatic
-- reminders already sent (so each is sent once). Additive only. Rollback: v39_participant_features_rollback.sql
CREATE TABLE IF NOT EXISTS event_waitlist (
  id INT(11) NOT NULL AUTO_INCREMENT,
  event_id INT(11) NOT NULL,
  participant_id INT(11) NOT NULL,
  race_category_id INT(11) NULL COMMENT 'fun runs: the category the participant waits for',
  status ENUM('waiting','offered','registered','left','expired') NOT NULL DEFAULT 'waiting',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  offered_at DATETIME NULL,
  offer_expires_at DATETIME NULL COMMENT 'a freed slot is held for this participant until then',
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_waitlist_event_participant (event_id, participant_id),
  KEY idx_waitlist_queue (event_id, status, id),
  KEY fk_waitlist_participant (participant_id),
  KEY fk_waitlist_race_category (race_category_id),
  CONSTRAINT fk_waitlist_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
  CONSTRAINT fk_waitlist_participant FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE,
  CONSTRAINT fk_waitlist_race_category FOREIGN KEY (race_category_id) REFERENCES race_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_favorites (
  user_id INT(11) NOT NULL,
  event_id INT(11) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, event_id),
  KEY fk_favorites_event (event_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participant_reminders_sent (
  user_id INT(11) NOT NULL,
  event_id INT(11) NOT NULL,
  kind VARCHAR(30) NOT NULL COMMENT 'event_tomorrow | saved_deadline_soon',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, event_id, kind),
  KEY fk_reminders_event (event_id),
  CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reminders_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
