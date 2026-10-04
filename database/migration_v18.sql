-- Migration v18: Sports Tool #3 - Live Scoreboard / Match Center
-- Adds 'paused' as a real match status (previously only 'scheduled'/'ongoing'/
-- 'completed'/'cancelled' existed, and 'ongoing' itself was never actually
-- written by any existing code path) and a new staff permission for live
-- match/score control. No new tables -- match_scores.score_detail (JSON,
-- already sport-flexible) is reused for set scores and match notes.

ALTER TABLE matches
  MODIFY COLUMN status ENUM('scheduled','ongoing','paused','completed','cancelled') NOT NULL DEFAULT 'scheduled';

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores') NOT NULL;
