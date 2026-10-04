-- Migration v12: Event announcements (organizer -> participants & assigned staff, per event)

CREATE TABLE event_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sent_by INT NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_eventannc_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventannc_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_eventannc_event (event_id, created_at)
) ENGINE=InnoDB;
