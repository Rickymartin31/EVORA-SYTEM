-- Migration v19: Sports Tool #4 - Incident Reports & Dispute Management
-- New table for tournament incidents (misconduct, complaints, disputes,
-- venue/safety/equipment issues), optionally linked to a match and/or venue.
-- Status workflow is strictly one-directional: open -> under_review -> resolved|dismissed.

CREATE TABLE event_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    match_id INT DEFAULT NULL,
    venue_id INT DEFAULT NULL,
    incident_type ENUM('player_misconduct','team_complaint','referee_issue','schedule_delay','venue_problem','safety_issue','equipment_issue','other') NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    description TEXT NOT NULL,
    status ENUM('open','under_review','resolved','dismissed') NOT NULL DEFAULT 'open',
    attachment_path VARCHAR(255) DEFAULT NULL,
    reported_by INT NOT NULL,
    resolved_by INT DEFAULT NULL,
    resolution_notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_incident_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_incident_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL,
    CONSTRAINT fk_incident_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE SET NULL,
    CONSTRAINT fk_incident_reporter FOREIGN KEY (reported_by) REFERENCES users(id),
    CONSTRAINT fk_incident_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_incident_event (event_id, status)
) ENGINE=InnoDB;

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores','manage_incidents') NOT NULL;
