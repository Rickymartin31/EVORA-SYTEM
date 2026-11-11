-- v78: Verified Organizer Badge (panel revision after the final defense).
-- An APPROVED organizer can apply for a separate, stricter Verified badge: they upload the
-- required documents, the system runs automatic checks, an administrator validates every
-- document against a checklist and approves the badge. The badge lasts one year (or until the
-- government ID expires, whichever is earlier) and can be renewed or revoked.
-- Additive only. Rollback: v78_verified_badge_rollback.sql

CREATE TABLE IF NOT EXISTS organizer_badge_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    status ENUM('pending','needs_resubmission','approved','rejected') NOT NULL DEFAULT 'pending',
    organization_type ENUM('business','association','school_org','lgu_ngo','other') NOT NULL,
    social_link VARCHAR(255) DEFAULT NULL COMMENT 'official page or website of the organization',
    past_events_note VARCHAR(1000) DEFAULT NULL COMMENT 'events the organization has run before',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    remarks VARCHAR(500) DEFAULT NULL,
    KEY idx_badge_app_organizer (organizer_id, status),
    CONSTRAINT fk_badge_app_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE CASCADE,
    CONSTRAINT fk_badge_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organizer_badge_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    doc_type ENUM('government_id','selfie_with_id','organization_proof','past_event_proof') NOT NULL,
    document_kind VARCHAR(80) DEFAULT NULL COMMENT 'e.g. Driver''s License, DTI Business Name Registration',
    reference_no VARCHAR(60) DEFAULT NULL COMMENT 'ID or registration number as printed on the document',
    expires_on DATE DEFAULT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the file, to detect the same file used by another organizer',
    auto_flags VARCHAR(255) DEFAULT NULL COMMENT 'comma list set by the system: duplicate_file, expires_soon',
    status ENUM('pending','accepted','rejected','replaced') NOT NULL DEFAULT 'pending',
    check_readable TINYINT(1) DEFAULT NULL,
    check_name_match TINYINT(1) DEFAULT NULL,
    check_valid_date TINYINT(1) DEFAULT NULL,
    check_authentic TINYINT(1) DEFAULT NULL,
    rejection_reason VARCHAR(255) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_badge_doc_application (application_id),
    KEY idx_badge_doc_hash (file_hash),
    CONSTRAINT fk_badge_doc_application FOREIGN KEY (application_id) REFERENCES organizer_badge_applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_badge_doc_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE organizers
    ADD COLUMN IF NOT EXISTS badge_verified_at DATETIME DEFAULT NULL COMMENT 'v78: when the Verified badge was granted' AFTER remarks,
    ADD COLUMN IF NOT EXISTS badge_expires_on DATE DEFAULT NULL COMMENT 'v78: badge is valid through this date' AFTER badge_verified_at,
    ADD COLUMN IF NOT EXISTS badge_application_id INT DEFAULT NULL COMMENT 'v78: application that granted the badge' AFTER badge_expires_on,
    ADD COLUMN IF NOT EXISTS badge_revoked_at DATETIME DEFAULT NULL COMMENT 'v78: set when an administrator revokes the badge' AFTER badge_application_id,
    ADD COLUMN IF NOT EXISTS badge_revoke_reason VARCHAR(255) DEFAULT NULL AFTER badge_revoked_at;
