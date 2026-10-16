-- v70: GPS live tracking is optional per fun run. The organizer turns it on in Route Management
-- (with instructions); only then do runners see the Race Tracker and the GPX upload.
-- Events that already have a drawn route or recorded tracks keep it on.
-- Additive only. Rollback: v70_gps_tracking_toggle_rollback.sql
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS gps_tracking TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'v70: GPS live tracking / anti-shortcut used for this fun run';

UPDATE events SET gps_tracking = 1
WHERE id IN (SELECT event_id FROM event_route_paths) OR id IN (SELECT event_id FROM race_tracks);
