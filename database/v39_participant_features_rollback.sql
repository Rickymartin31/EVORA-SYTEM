-- Rollback of v39 (drops waitlist, saved events and the reminder log).
DROP TABLE IF EXISTS participant_reminders_sent;
DROP TABLE IF EXISTS event_favorites;
DROP TABLE IF EXISTS event_waitlist;
