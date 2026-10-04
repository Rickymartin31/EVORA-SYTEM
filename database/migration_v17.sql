-- Migration v17: Sports Tool #1 - Venue Management
-- Multiple named playing areas/courts/fields/rooms per event.
-- `code` is a short optional tag (e.g. "C1") for future compatibility with
-- the Match Schedule Generator, Live Scoreboard, and Reports -- not used yet.

CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) DEFAULT NULL,
    venue_type VARCHAR(50) NOT NULL,
    location VARCHAR(150) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    capacity INT DEFAULT NULL,
    status ENUM('available','maintenance','closed') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_venues_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
