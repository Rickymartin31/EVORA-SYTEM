-- =====================================================================
-- EVORA — Payout Accountability & Dispute System (additive migration)
--
-- Adds accountability LAYERS next to the existing payout lifecycle. The
-- payout request lifecycle (pending → approved → processing → paid | rejected)
-- and its rule that paid/rejected are final are NOT changed.
--
--   1. system_settings: payout_sla_days, dispute_window_days, admin_response_days
--   2. organizer_payout_requests: due_at (set once, at creation, by the DB),
--      proof_sha256 (set once, when marked paid); guard trigger extended
--   3. payout_agreements + payout_agreement_acceptances (append-only)
--   4. platform_operator_profile + platform_operator_profile_history (history kept by trigger)
--   5. payout_receipt_responses (append-only)
--   6. payout_disputes + payout_dispute_events (events append-only)
--   7. payout_record_exports (append-only)
--   8. activity_logs: no-update / no-delete
--
-- Existing rows are not modified: old payout requests keep due_at = NULL
-- ("created before payout deadlines") and proof_sha256 = NULL.
-- Rollback: payout_accountability_rollback.sql
-- =====================================================================

-- ---------------------------------------------------------------- 1. settings
ALTER TABLE system_settings
    ADD COLUMN payout_sla_days TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Days from payout request creation until it is due',
    ADD COLUMN dispute_window_days SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Days after paid during which the organizer can report a problem',
    ADD COLUMN admin_response_days TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Days the administrator has to respond to a payout dispute';

ALTER TABLE system_settings
    ADD CONSTRAINT chk_settings_payout_sla CHECK (payout_sla_days BETWEEN 1 AND 60),
    ADD CONSTRAINT chk_settings_dispute_window CHECK (dispute_window_days BETWEEN 1 AND 365),
    ADD CONSTRAINT chk_settings_admin_response CHECK (admin_response_days BETWEEN 1 AND 30);

-- ---------------------------------------------------------------- 2. payout requests
ALTER TABLE organizer_payout_requests
    ADD COLUMN due_at DATETIME NULL DEFAULT NULL COMMENT 'Payout deadline: created_at + payout_sla_days, set by trigger at insert; NULL = created before deadlines existed' AFTER created_at,
    ADD COLUMN proof_sha256 CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 of the stored proof file, set when marked paid (file integrity only, not truthfulness)' AFTER proof_path,
    ADD INDEX idx_payout_requests_status_due (status, due_at);

DROP TRIGGER IF EXISTS trg_payout_request_validate_insert;
DELIMITER $$
CREATE TRIGGER trg_payout_request_validate_insert BEFORE INSERT ON organizer_payout_requests
FOR EACH ROW
BEGIN
    DECLARE v_event_org INT DEFAULT NULL;
    DECLARE v_earn_event INT DEFAULT NULL;
    DECLARE v_sla INT DEFAULT NULL;

    SELECT organizer_id INTO v_event_org FROM events WHERE id = NEW.event_id;
    SELECT event_id INTO v_earn_event FROM organizer_event_earnings WHERE id = NEW.earnings_id;

    IF NEW.status <> 'pending' OR NEW.payout_account_id IS NOT NULL OR NEW.approved_by IS NOT NULL
       OR NEW.paid_by IS NOT NULL OR NEW.reference_number IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout requests must start as pending, unapproved and unpaid.';
    END IF;
    IF v_event_org IS NULL OR v_event_org <> NEW.organizer_id OR v_earn_event IS NULL OR v_earn_event <> NEW.event_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request organizer/event/earnings mismatch.';
    END IF;

    -- v-accountability: the deadline is always computed here, never supplied by the application.
    IF NEW.proof_sha256 IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A new payout request cannot carry a proof hash.';
    END IF;
    SELECT payout_sla_days INTO v_sla FROM system_settings ORDER BY id LIMIT 1;
    SET NEW.due_at = DATE_ADD(COALESCE(NEW.created_at, NOW()), INTERVAL COALESCE(v_sla, 3) DAY);
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_payout_request_guard_update;
DELIMITER $$
CREATE TRIGGER trg_payout_request_guard_update BEFORE UPDATE ON organizer_payout_requests
FOR EACH ROW
BEGIN
    DECLARE v_account_org INT DEFAULT NULL;
    DECLARE v_account_status VARCHAR(20) DEFAULT NULL;

    IF OLD.status IN ('paid', 'rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid or rejected payout requests are final.';
    END IF;

    IF NEW.request_no <> OLD.request_no OR NEW.organizer_id <> OLD.organizer_id OR NEW.event_id <> OLD.event_id
       OR NEW.earnings_id <> OLD.earnings_id OR NEW.sequence_no <> OLD.sequence_no OR NEW.currency <> OLD.currency
       OR NEW.entry_count <> OLD.entry_count OR NEW.participant_count <> OLD.participant_count
       OR NEW.gross_amount <> OLD.gross_amount OR NEW.gateway_fee <> OLD.gateway_fee OR NEW.amount <> OLD.amount
       OR NOT (NEW.created_by <=> OLD.created_by) OR NEW.created_at <> OLD.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout request amounts and identity are immutable.';
    END IF;

    IF OLD.payout_account_id IS NOT NULL
       AND (NOT (NEW.payout_account_id <=> OLD.payout_account_id) OR NOT (NEW.method <=> OLD.method)
            OR NOT (NEW.account_name_snapshot <=> OLD.account_name_snapshot)
            OR NOT (NEW.account_number_masked <=> OLD.account_number_masked)
            OR NOT (NEW.bank_name_snapshot <=> OLD.bank_name_snapshot)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payout destination cannot change after approval.';
    END IF;
    IF OLD.payout_account_id IS NULL AND NEW.payout_account_id IS NOT NULL THEN
        SELECT organizer_id, status INTO v_account_org, v_account_status FROM organizer_payout_accounts WHERE id = NEW.payout_account_id;
        IF v_account_org IS NULL OR v_account_org <> NEW.organizer_id OR v_account_status <> 'verified' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payouts go only to the organizer''s own verified payout account.';
        END IF;
    END IF;

    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected'))
        OR (OLD.status = 'approved' AND NEW.status IN ('processing', 'rejected'))
        OR (OLD.status = 'processing' AND NEW.status IN ('paid', 'rejected'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid payout request status change (paid requires processing first).';
    END IF;

    -- v-accountability additions (stricter only):
    -- the deadline is fixed at creation (old requests stay NULL: no invented deadlines)
    IF NOT (NEW.due_at <=> OLD.due_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payout deadline is fixed when the request is created.';
    END IF;
    -- the proof hash is written exactly once, in the processing -> paid transition, together with the proof
    IF NOT (NEW.proof_sha256 <=> OLD.proof_sha256)
       AND NOT (OLD.proof_sha256 IS NULL AND OLD.status = 'processing' AND NEW.status = 'paid'
                AND NEW.proof_sha256 REGEXP '^[0-9a-f]{64}$' AND NEW.proof_path IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The proof hash is recorded only when the payout is marked paid.';
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 4. operator profile (before agreements reference it)
CREATE TABLE platform_operator_profile (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    operator_legal_name VARCHAR(200) NOT NULL,
    responsible_officer VARCHAR(150) NOT NULL,
    officer_position VARCHAR(100) NOT NULL,
    support_email VARCHAR(150) NOT NULL,
    support_phone VARCHAR(30) NOT NULL,
    official_address VARCHAR(300) NOT NULL,
    registration_type ENUM('none','dti','sec','cda') NOT NULL DEFAULT 'none',
    registration_no VARCHAR(60) NULL,
    escalation_contact VARCHAR(300) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_operator_single_row CHECK (id = 1),
    CONSTRAINT fk_operator_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Who operates EVORA and receives organizer funds (no government ID data)';

CREATE TABLE platform_operator_profile_history (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version INT UNSIGNED NOT NULL,
    operator_legal_name VARCHAR(200) NOT NULL,
    responsible_officer VARCHAR(150) NOT NULL,
    officer_position VARCHAR(100) NOT NULL,
    support_email VARCHAR(150) NOT NULL,
    support_phone VARCHAR(30) NOT NULL,
    official_address VARCHAR(300) NOT NULL,
    registration_type ENUM('none','dti','sec','cda') NOT NULL,
    registration_no VARCHAR(60) NULL,
    escalation_contact VARCHAR(300) NULL,
    changed_by INT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operator_history_version (version),
    CONSTRAINT fk_operator_history_by FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_operator_profile_before_update BEFORE UPDATE ON platform_operator_profile
FOR EACH ROW
BEGIN
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each operator profile change must create the next version.';
    END IF;
END$$
CREATE TRIGGER trg_operator_profile_before_insert BEFORE INSERT ON platform_operator_profile
FOR EACH ROW
BEGIN
    IF NEW.version <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The operator profile starts at version 1.';
    END IF;
END$$
CREATE TRIGGER trg_operator_profile_after_insert AFTER INSERT ON platform_operator_profile
FOR EACH ROW
BEGIN
    INSERT INTO platform_operator_profile_history (version, operator_legal_name, responsible_officer, officer_position, support_email,
        support_phone, official_address, registration_type, registration_no, escalation_contact, changed_by, changed_at)
    VALUES (NEW.version, NEW.operator_legal_name, NEW.responsible_officer, NEW.officer_position, NEW.support_email,
        NEW.support_phone, NEW.official_address, NEW.registration_type, NEW.registration_no, NEW.escalation_contact, NEW.updated_by, NEW.updated_at);
END$$
CREATE TRIGGER trg_operator_profile_after_update AFTER UPDATE ON platform_operator_profile
FOR EACH ROW
BEGIN
    INSERT INTO platform_operator_profile_history (version, operator_legal_name, responsible_officer, officer_position, support_email,
        support_phone, official_address, registration_type, registration_no, escalation_contact, changed_by, changed_at)
    VALUES (NEW.version, NEW.operator_legal_name, NEW.responsible_officer, NEW.officer_position, NEW.support_email,
        NEW.support_phone, NEW.official_address, NEW.registration_type, NEW.registration_no, NEW.escalation_contact, NEW.updated_by, NEW.updated_at);
END$$
CREATE TRIGGER trg_operator_profile_no_delete BEFORE DELETE ON platform_operator_profile
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The operator profile cannot be deleted.';
END$$
CREATE TRIGGER trg_operator_history_no_update BEFORE UPDATE ON platform_operator_profile_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operator profile history is append-only.';
END$$
CREATE TRIGGER trg_operator_history_no_delete BEFORE DELETE ON platform_operator_profile_history
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operator profile history is append-only.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 3. agreements
CREATE TABLE payout_agreements (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    body_sha256 CHAR(64) NOT NULL,
    operator_profile_version INT UNSIGNED NOT NULL COMMENT 'platform_operator_profile_history.version shown with this agreement',
    effective_at DATETIME NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payout_agreement_version (version),
    KEY idx_payout_agreement_effective (effective_at),
    CONSTRAINT fk_payout_agreement_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_payout_agreement_operator FOREIGN KEY (operator_profile_version) REFERENCES platform_operator_profile_history (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_agreement_acceptances (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agreement_id INT NOT NULL,
    organizer_id INT NOT NULL,
    user_id INT NOT NULL,
    body_sha256 CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agreement_acceptance (organizer_id, agreement_id),
    KEY idx_acceptance_agreement (agreement_id),
    CONSTRAINT fk_acceptance_agreement FOREIGN KEY (agreement_id) REFERENCES payout_agreements (id),
    CONSTRAINT fk_acceptance_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (id),
    CONSTRAINT fk_acceptance_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_payout_agreement_check_insert BEFORE INSERT ON payout_agreements
FOR EACH ROW
BEGIN
    IF NEW.body_sha256 <> SHA2(NEW.body, 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agreement body hash does not match the body.';
    END IF;
END$$
CREATE TRIGGER trg_payout_agreement_no_update BEFORE UPDATE ON payout_agreements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agreement versions cannot be edited. Publish a new version instead.';
END$$
CREATE TRIGGER trg_payout_agreement_no_delete BEFORE DELETE ON payout_agreements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agreement versions cannot be deleted.';
END$$
CREATE TRIGGER trg_acceptance_check_insert BEFORE INSERT ON payout_agreement_acceptances
FOR EACH ROW
BEGIN
    DECLARE v_hash CHAR(64) DEFAULT NULL;
    DECLARE v_org_user INT DEFAULT NULL;
    SELECT body_sha256 INTO v_hash FROM payout_agreements WHERE id = NEW.agreement_id;
    SELECT user_id INTO v_org_user FROM organizers WHERE id = NEW.organizer_id;
    IF v_hash IS NULL OR v_hash <> NEW.body_sha256 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acceptance must record the exact agreement text that was accepted.';
    END IF;
    IF v_org_user IS NULL OR v_org_user <> NEW.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the organizer account holder can accept the agreement.';
    END IF;
END$$
CREATE TRIGGER trg_acceptance_no_update BEFORE UPDATE ON payout_agreement_acceptances
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agreement acceptances are append-only.';
END$$
CREATE TRIGGER trg_acceptance_no_delete BEFORE DELETE ON payout_agreement_acceptances
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agreement acceptances are append-only.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 5. receipt responses
CREATE TABLE payout_receipt_responses (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payout_request_id INT NOT NULL,
    organizer_user_id INT NOT NULL,
    response ENUM('received') NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_receipt_response (payout_request_id, response),
    CONSTRAINT fk_receipt_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests (id),
    CONSTRAINT fk_receipt_user FOREIGN KEY (organizer_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_receipt_check_insert BEFORE INSERT ON payout_receipt_responses
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_org_user INT DEFAULT NULL;
    SELECT pr.status, o.user_id INTO v_status, v_org_user
      FROM organizer_payout_requests pr JOIN organizers o ON o.id = pr.organizer_id WHERE pr.id = NEW.payout_request_id;
    IF v_status IS NULL OR v_status <> 'paid' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receipt can only be confirmed for a paid payout.';
    END IF;
    IF v_org_user <> NEW.organizer_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the payout''s organizer can confirm receipt.';
    END IF;
END$$
CREATE TRIGGER trg_receipt_no_update BEFORE UPDATE ON payout_receipt_responses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receipt responses are append-only.';
END$$
CREATE TRIGGER trg_receipt_no_delete BEFORE DELETE ON payout_receipt_responses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receipt responses are append-only.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 6. disputes
CREATE TABLE payout_disputes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dispute_no VARCHAR(30) NOT NULL,
    payout_request_id INT NOT NULL,
    organizer_id INT NOT NULL,
    opened_by INT NOT NULL,
    type ENUM('overdue_unpaid','not_received_after_paid') NOT NULL,
    reason_code ENUM('not_received','wrong_account','incorrect_amount','reference_clarification','other') NOT NULL,
    status ENUM('open','awaiting_organizer','resolved','withdrawn') NOT NULL DEFAULT 'open',
    resolution ENUM('confirmed_received','payment_corrected') NULL DEFAULT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_response_due_at DATETIME NOT NULL COMMENT 'Deadline for the current admin response round',
    first_admin_response_at DATETIME NULL DEFAULT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    open_request_key INT AS (IF(status IN ('open','awaiting_organizer'), payout_request_id, NULL)) PERSISTENT,
    UNIQUE KEY uq_payout_dispute_no (dispute_no),
    UNIQUE KEY uq_one_open_dispute_per_payout (open_request_key),
    KEY idx_dispute_status_due (status, admin_response_due_at),
    KEY idx_dispute_request (payout_request_id),
    KEY idx_dispute_organizer (organizer_id),
    CONSTRAINT fk_dispute_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests (id),
    CONSTRAINT fk_dispute_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (id),
    CONSTRAINT fk_dispute_opened_by FOREIGN KEY (opened_by) REFERENCES users (id),
    CONSTRAINT chk_dispute_resolution CHECK ((status = 'resolved') = (resolution IS NOT NULL)),
    CONSTRAINT chk_dispute_closed CHECK ((status IN ('resolved','withdrawn')) = (closed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_dispute_events (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT NOT NULL,
    actor_user_id INT NOT NULL,
    actor_role ENUM('organizer','admin','system') NOT NULL,
    event_type ENUM('dispute_opened','organizer_message','admin_response','payment_correction','organizer_acceptance',
                    'organizer_withdrawal','resolution','attachment_added','reference_added','dispute_reopened') NOT NULL,
    message TEXT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_sha256 CHAR(64) NULL,
    reference_number VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dispute_events_dispute (dispute_id, id),
    CONSTRAINT fk_dispute_event_dispute FOREIGN KEY (dispute_id) REFERENCES payout_disputes (id),
    CONSTRAINT fk_dispute_event_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
    CONSTRAINT chk_dispute_event_attachment CHECK ((attachment_path IS NULL) = (attachment_sha256 IS NULL)),
    CONSTRAINT chk_dispute_event_correction CHECK (event_type <> 'payment_correction' OR (attachment_path IS NOT NULL AND reference_number IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_dispute_check_insert BEFORE INSERT ON payout_disputes
FOR EACH ROW
BEGIN
    DECLARE v_status VARCHAR(20) DEFAULT NULL;
    DECLARE v_org INT DEFAULT NULL;
    SELECT status, organizer_id INTO v_status, v_org FROM organizer_payout_requests WHERE id = NEW.payout_request_id;
    IF v_org IS NULL OR v_org <> NEW.organizer_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dispute organizer does not match the payout.';
    END IF;
    IF NEW.status <> 'open' OR NEW.resolution IS NOT NULL OR NEW.closed_at IS NOT NULL OR NEW.first_admin_response_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A dispute must start open and unanswered.';
    END IF;
    IF (NEW.type = 'not_received_after_paid' AND v_status <> 'paid')
       OR (NEW.type = 'overdue_unpaid' AND v_status NOT IN ('pending','approved','processing')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dispute type does not match the payout status.';
    END IF;
END$$
CREATE TRIGGER trg_dispute_guard_update BEFORE UPDATE ON payout_disputes
FOR EACH ROW
BEGIN
    IF OLD.status IN ('resolved','withdrawn') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Resolved or withdrawn disputes are final.';
    END IF;
    IF NEW.dispute_no <> OLD.dispute_no OR NEW.payout_request_id <> OLD.payout_request_id OR NEW.organizer_id <> OLD.organizer_id
       OR NEW.opened_by <> OLD.opened_by OR NEW.type <> OLD.type OR NEW.reason_code <> OLD.reason_code OR NEW.opened_at <> OLD.opened_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dispute identity and reason are immutable.';
    END IF;
    IF OLD.first_admin_response_at IS NOT NULL AND NOT (NEW.first_admin_response_at <=> OLD.first_admin_response_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The first administrator response time cannot change.';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
        (OLD.status = 'open' AND NEW.status IN ('awaiting_organizer','withdrawn'))
        OR (OLD.status = 'awaiting_organizer' AND NEW.status IN ('resolved','open','withdrawn'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid dispute status change.';
    END IF;
END$$
CREATE TRIGGER trg_dispute_no_delete BEFORE DELETE ON payout_disputes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout disputes cannot be deleted.';
END$$
CREATE TRIGGER trg_dispute_event_no_update BEFORE UPDATE ON payout_dispute_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dispute events are append-only.';
END$$
CREATE TRIGGER trg_dispute_event_no_delete BEFORE DELETE ON payout_dispute_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dispute events are append-only.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 7. record exports
CREATE TABLE payout_record_exports (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payout_request_id INT NOT NULL,
    dispute_id INT NULL,
    generated_by INT NOT NULL,
    document_sha256 CHAR(64) NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_record_export_request (payout_request_id),
    CONSTRAINT fk_record_export_request FOREIGN KEY (payout_request_id) REFERENCES organizer_payout_requests (id),
    CONSTRAINT fk_record_export_dispute FOREIGN KEY (dispute_id) REFERENCES payout_disputes (id),
    CONSTRAINT fk_record_export_by FOREIGN KEY (generated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER trg_record_export_no_update BEFORE UPDATE ON payout_record_exports
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout record exports are append-only.';
END$$
CREATE TRIGGER trg_record_export_no_delete BEFORE DELETE ON payout_record_exports
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payout record exports are append-only.';
END$$
DELIMITER ;

-- ---------------------------------------------------------------- 8. activity log immutability
-- (InnoDB foreign-key actions such as fk_log_user ON DELETE SET NULL do not fire triggers,
--  so deleting a user still works.)
DELIMITER $$
CREATE TRIGGER trg_activity_logs_no_update BEFORE UPDATE ON activity_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity logs are append-only.';
END$$
CREATE TRIGGER trg_activity_logs_no_delete BEFORE DELETE ON activity_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity logs are append-only.';
END$$
DELIMITER ;
