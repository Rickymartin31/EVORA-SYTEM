-- v81: up to 3 photos per post-event review, shown publicly with the review on the event page.
-- Additive only. Rollback: v81_review_photos_rollback.sql
CREATE TABLE IF NOT EXISTS event_feedback_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feedback_photos (feedback_id),
    CONSTRAINT fk_feedback_photo FOREIGN KEY (feedback_id) REFERENCES event_feedback(id) ON DELETE CASCADE
) ENGINE=InnoDB;
