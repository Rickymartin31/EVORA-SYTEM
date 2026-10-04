-- Migration v16: Dynamic Organizer Tools Framework (Phase 1)
-- Per-event override table for optional tool visibility. Tool definitions
-- themselves live in code (core/EventTools.php), not in this table -- this
-- table only ever holds rows where an organizer deviates from a tool's
-- default enabled/disabled state for one specific event.

CREATE TABLE event_tool_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    tool_key VARCHAR(50) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tooloverride_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tooloverride (event_id, tool_key)
) ENGINE=InnoDB;
