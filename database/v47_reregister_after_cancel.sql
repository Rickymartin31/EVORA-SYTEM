-- v47: a participant who cancelled (or was refunded) can register for the same event again.
-- Cancelled registrations stay final (trg_registration_cancel_final) and keep their payment, refund
-- and QR history; a new registration row is made instead. Only one NOT-cancelled registration per
-- participant per event is allowed: the unique key now covers active_participant_id, which is NULL
-- for a cancelled row (NULLs never collide).
-- Rollback: v47_reregister_after_cancel_rollback.sql
ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS active_participant_id INT(11)
    GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, participant_id)) PERSISTENT
    COMMENT 'participant_id while not cancelled (unique per event); NULL once cancelled' AFTER participant_id,
  ADD KEY IF NOT EXISTS idx_reg_event_participant (event_id, participant_id),
  ADD UNIQUE KEY IF NOT EXISTS uq_registration_active (event_id, active_participant_id);
ALTER TABLE registrations DROP INDEX IF EXISTS uq_registration;
