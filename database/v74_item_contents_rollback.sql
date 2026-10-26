-- Rollback of v74: removes the "what's inside" text of event items (items and claims are untouched).
ALTER TABLE event_items DROP COLUMN IF EXISTS contents;
