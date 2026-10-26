-- Rollback of v71: removes the claiming schedule and the reminder log.
-- Items, item claims and the automatic per-participant reminder marks are untouched.
DROP TABLE IF EXISTS event_claim_reminders;
DROP TABLE IF EXISTS event_claim_schedules;
