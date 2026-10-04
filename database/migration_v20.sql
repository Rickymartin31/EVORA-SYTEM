-- Migration v20: Fun Run Phase 1 - staff permissions for bib assignment and
-- finish-line recording, mirroring the existing manage_claims/manage_walkin pattern.

ALTER TABLE staff_permissions
  MODIFY COLUMN permission_key ENUM('scan_qr','verify_participant','record_attendance','manage_claims','view_announcements','manage_walkin','manage_live_scores','manage_incidents','manage_bibs','manage_finish_results') NOT NULL;
