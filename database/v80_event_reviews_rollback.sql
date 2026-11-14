-- Rollback of v80_event_reviews.sql
ALTER TABLE event_feedback
    DROP COLUMN IF EXISTS organizer_replied_at,
    DROP COLUMN IF EXISTS organizer_reply,
    DROP COLUMN IF EXISTS improvement_tags,
    DROP COLUMN IF EXISTS liked,
    DROP COLUMN IF EXISTS join_again,
    DROP COLUMN IF EXISTS expectations_met,
    DROP COLUMN IF EXISTS officiating_rating,
    DROP COLUMN IF EXISTS kit_rating,
    DROP COLUMN IF EXISTS route_rating,
    DROP COLUMN IF EXISTS checkin_rating,
    DROP COLUMN IF EXISTS organization_rating;
