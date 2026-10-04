-- Migration v23: Automatic Certificate Issuance After Event Completion
-- Adds an organizer-controlled auto-issue toggle (and an optional attendance
-- gate for it) to the existing event_certificate_settings table. No new
-- tables — automatic issuance reuses the existing certificates/registrations/
-- payments/attendance tables and the existing CertificateService::issue().

ALTER TABLE event_certificate_settings
  ADD COLUMN auto_issue_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER signatory2_signature_path,
  ADD COLUMN attendance_required TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_issue_enabled;
