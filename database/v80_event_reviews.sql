-- v80: Post-event evaluation and public reviews (panel revision after the final defense).
-- Adds to event_feedback: satisfaction per aspect (by event type), effectiveness (expectations met),
-- would join again, what went well, improvement tags, and the organizer's public reply.
-- The participant's answers stay locked by trg_event_feedback_no_update, which watches only the
-- original rating/comment columns; the new survey columns are written once on insert, and the
-- organizer reply columns may be set later. Additive only. Rollback: v80_event_reviews_rollback.sql
ALTER TABLE event_feedback
    ADD COLUMN IF NOT EXISTS organization_rating TINYINT DEFAULT NULL COMMENT 'v80: organization and schedule, 1-5' AFTER overall_rating,
    ADD COLUMN IF NOT EXISTS checkin_rating TINYINT DEFAULT NULL COMMENT 'v80: registration and check-in, 1-5' AFTER organization_rating,
    ADD COLUMN IF NOT EXISTS route_rating TINYINT DEFAULT NULL COMMENT 'v80 fun run: route, marshals and safety, 1-5' AFTER venue_rating,
    ADD COLUMN IF NOT EXISTS kit_rating TINYINT DEFAULT NULL COMMENT 'v80 fun run: race kit and items, 1-5' AFTER route_rating,
    ADD COLUMN IF NOT EXISTS officiating_rating TINYINT DEFAULT NULL COMMENT 'v80 sports: officiating and match schedule, 1-5' AFTER kit_rating,
    ADD COLUMN IF NOT EXISTS expectations_met ENUM('yes','partly','no') DEFAULT NULL COMMENT 'v80: effectiveness' AFTER officiating_rating,
    ADD COLUMN IF NOT EXISTS join_again ENUM('yes','maybe','no') DEFAULT NULL COMMENT 'v80: would join this organizer again' AFTER expectations_met,
    ADD COLUMN IF NOT EXISTS liked VARCHAR(500) DEFAULT NULL COMMENT 'v80: what went well' AFTER join_again,
    ADD COLUMN IF NOT EXISTS improvement_tags VARCHAR(255) DEFAULT NULL COMMENT 'v80: comma list of areas to improve' AFTER liked,
    ADD COLUMN IF NOT EXISTS organizer_reply VARCHAR(1000) DEFAULT NULL COMMENT 'v80: public reply of the organizer' AFTER suggestions,
    ADD COLUMN IF NOT EXISTS organizer_replied_at DATETIME DEFAULT NULL AFTER organizer_reply;
