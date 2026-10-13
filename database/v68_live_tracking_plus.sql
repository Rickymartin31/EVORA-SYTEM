-- v68: Strava-like live tracking for fun runs.
-- * off_route_m / max_off_route_m: how far the runner's latest / worst position is from the official route.
-- * checkpoints_passed / last_checkpoint: progress along the mapped checkpoints while running.
-- * source / gpx_path: a track can also come from a GPX file uploaded after the race (Strava, watches).
-- Additive only. Rollback: v68_live_tracking_plus_rollback.sql
ALTER TABLE race_tracks
  ADD COLUMN IF NOT EXISTS source ENUM('live','gpx') NOT NULL DEFAULT 'live' AFTER status,
  ADD COLUMN IF NOT EXISTS gpx_path VARCHAR(255) NULL AFTER source,
  ADD COLUMN IF NOT EXISTS off_route_m SMALLINT UNSIGNED NULL AFTER distance_m,
  ADD COLUMN IF NOT EXISTS max_off_route_m SMALLINT UNSIGNED NULL AFTER off_route_m,
  ADD COLUMN IF NOT EXISTS checkpoints_passed TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_off_route_m,
  ADD COLUMN IF NOT EXISTS last_checkpoint VARCHAR(100) NULL AFTER checkpoints_passed;
