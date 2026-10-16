-- Rollback of v70: the per-event switch goes away (routes, checkpoints and tracks are untouched).
ALTER TABLE events DROP COLUMN IF EXISTS gps_tracking;
