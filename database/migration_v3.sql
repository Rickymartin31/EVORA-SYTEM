-- =====================================================================
-- EVORA migration v3 — Organizer Assistant / Event Control Center
-- Run once against an existing evora_db (fresh installs get this baked
-- into schema.sql instead).
-- =====================================================================
USE evora_db;

ALTER TABLE event_staff
  ADD COLUMN assignment_area VARCHAR(100) DEFAULT NULL AFTER user_id,
  ADD COLUMN task_note VARCHAR(255) DEFAULT NULL AFTER assignment_area,
  ADD COLUMN task_status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started' AFTER task_note;
