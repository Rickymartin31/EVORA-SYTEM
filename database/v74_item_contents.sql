-- v74: what an item contains, e.g. a fun run "Race Kit" with "T-shirt and race bib".
-- Shown to organizers, staff and in claiming reminders. Additive only.
-- Rollback: v74_item_contents_rollback.sql
ALTER TABLE event_items
  ADD COLUMN IF NOT EXISTS contents VARCHAR(255) DEFAULT NULL COMMENT 'v74: what is inside, e.g. T-shirt and race bib' AFTER variant_label;
