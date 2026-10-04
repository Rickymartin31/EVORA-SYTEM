-- EVORA payment architecture migration: organizer-owned payments
--
-- From: EVORA holds participant money (ledger -> settlement -> manual payout)
-- To:   organizers connect their OWN PayMongo account (PayMongo Linked
--       Accounts). Participant payments for their events are created with the
--       documented "Account-ID" header, are attributed to the organizer's
--       account, and settle there. EVORA only creates the checkout, confirms
--       the payment and manages the registration.
--
-- Two payment modes:
--   paymongo_connected — organizer-owned (recommended). No ledger, no
--                        settlement, no payout.
--   evora_payment      — the existing v24/v25 flow, kept as an optional
--                        fallback and for all existing data.
--
-- Additive only. Every existing organizer, event and payment becomes
-- evora_payment (which is exactly how their money was handled). The v25
-- ledger, settlements, payouts, payout accounts and finance history are kept
-- untouched: they hold real historical money records.
--
-- Rollback: database/payment_architecture_rollback.sql

-- ---------------------------------------------------------------------
-- Organizer payment mode
-- ---------------------------------------------------------------------
ALTER TABLE organizers
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') DEFAULT NULL AFTER status;

UPDATE organizers SET payment_mode = 'evora_payment' WHERE payment_mode IS NULL;

ALTER TABLE organizers
    ADD CONSTRAINT chk_organizer_approved_has_mode CHECK (status <> 'approved' OR payment_mode IS NOT NULL);

-- ---------------------------------------------------------------------
-- Event snapshot: an event remembers the payment mode and PayMongo account
-- it uses; later changes to the organizer's settings never change it once it
-- has paid registrations.
-- ---------------------------------------------------------------------
ALTER TABLE events
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') DEFAULT NULL AFTER manual_payment_enabled,
    ADD COLUMN paymongo_account_id VARCHAR(100) DEFAULT NULL AFTER payment_mode;

UPDATE events SET payment_mode = 'evora_payment' WHERE payment_mode IS NULL;

ALTER TABLE events
    ADD CONSTRAINT chk_event_paymongo_account CHECK (payment_mode = 'paymongo_connected' OR paymongo_account_id IS NULL);

-- ---------------------------------------------------------------------
-- Payment snapshot: where each payment belongs
-- ---------------------------------------------------------------------
ALTER TABLE payments
    ADD COLUMN payment_mode ENUM('evora_payment','paymongo_connected') NOT NULL DEFAULT 'evora_payment' AFTER payment_channel,
    ADD COLUMN paymongo_account_id VARCHAR(100) DEFAULT NULL AFTER payment_mode,
    ADD CONSTRAINT chk_payment_account_only_connected CHECK (payment_mode = 'paymongo_connected' OR paymongo_account_id IS NULL);

-- ---------------------------------------------------------------------
-- Organizer PayMongo account connection. Stores only PayMongo identifiers,
-- status, permissions and timestamps — never passwords, OTPs, MPINs or API
-- keys. The one secret here is EVORA's OWN webhook signing secret for the
-- webhook EVORA registers on the organizer's account, stored encrypted.
-- "connected" can only be set by the server after PayMongo's API confirms the
-- account is EVORA's linked child and activated.
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payment_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    provider ENUM('paymongo') NOT NULL DEFAULT 'paymongo',
    paymongo_account_id VARCHAR(100) DEFAULT NULL,
    relationship_id VARCHAR(100) DEFAULT NULL,
    link_method ENUM('invitation','account_creation') DEFAULT NULL,
    requested_email VARCHAR(150) DEFAULT NULL,
    status ENUM('not_connected','pending','connected','action_required','disconnected','error') NOT NULL DEFAULT 'not_connected',
    permissions TEXT DEFAULT NULL,
    activation_status VARCHAR(30) DEFAULT NULL,
    verification_status VARCHAR(30) DEFAULT NULL,
    webhook_id VARCHAR(100) DEFAULT NULL,
    webhook_token CHAR(40) DEFAULT NULL,
    webhook_secret_enc TEXT DEFAULT NULL,
    connected_at DATETIME DEFAULT NULL,
    disconnected_at DATETIME DEFAULT NULL,
    last_synced_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    current_organizer_id INT AS (IF(status <> 'disconnected', organizer_id, NULL)) PERSISTENT,
    current_account_id VARCHAR(100) AS (IF(status <> 'disconnected', paymongo_account_id, NULL)) PERSISTENT,
    UNIQUE KEY uq_opa_current_organizer (current_organizer_id),
    UNIQUE KEY uq_opa_current_account (current_account_id),
    UNIQUE KEY uq_opa_webhook_token (webhook_token),
    INDEX idx_opa_organizer (organizer_id),
    CONSTRAINT fk_opa_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_opa_connected_has_account CHECK (status <> 'connected' OR (paymongo_account_id IS NOT NULL AND connected_at IS NOT NULL)),
    CONSTRAINT chk_opa_permissions_json CHECK (permissions IS NULL OR JSON_VALID(permissions))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Append-only history of payment mode / connection changes
-- ---------------------------------------------------------------------
CREATE TABLE organizer_payment_mode_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    from_mode VARCHAR(30) DEFAULT NULL,
    to_mode VARCHAR(30) NOT NULL,
    actor_user_id INT DEFAULT NULL,
    actor_role ENUM('organizer','admin','system') NOT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pmh_organizer (organizer_id),
    CONSTRAINT fk_pmh_organizer FOREIGN KEY (organizer_id) REFERENCES organizers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pmh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO organizer_payment_mode_history (organizer_id, from_mode, to_mode, actor_role, reason)
    SELECT id, NULL, 'evora_payment', 'system', 'Payment architecture migration: existing organizer kept on EVORA Payment'
    FROM organizers;

-- ---------------------------------------------------------------------
-- Triggers
-- ---------------------------------------------------------------------
DELIMITER //

-- Organizer-owned money is never recorded in EVORA's ledger (no double counting).
CREATE TRIGGER trg_ledger_evora_payment_only BEFORE INSERT ON organizer_ledger_entries FOR EACH ROW
BEGIN
    IF NEW.payment_id IS NOT NULL
       AND (SELECT payment_mode FROM payments WHERE id = NEW.payment_id) = 'paymongo_connected' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayMongo Connected payments never create EVORA ledger entries.';
    END IF;
END//

-- Historical ownership of money never moves.
CREATE TRIGGER trg_payment_mode_lock BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF OLD.status IN ('approved', 'refunded')
       AND (NEW.payment_mode <> OLD.payment_mode OR NOT (NEW.paymongo_account_id <=> OLD.paymongo_account_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The payment mode of a completed payment cannot be changed.';
    END IF;
END//

CREATE TRIGGER trg_event_payment_mode_lock BEFORE UPDATE ON events FOR EACH ROW
BEGIN
    IF OLD.payment_mode IS NOT NULL
       AND (NOT (NEW.payment_mode <=> OLD.payment_mode) OR NOT (NEW.paymongo_account_id <=> OLD.paymongo_account_id))
       AND EXISTS (SELECT 1 FROM payments p JOIN registrations r ON r.id = p.registration_id
                   WHERE r.event_id = OLD.id AND p.payment_channel = 'paymongo' AND p.status IN ('approved', 'refunded')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This event already has paid registrations; its payment account is locked.';
    END IF;
END//

CREATE TRIGGER trg_pmh_no_update BEFORE UPDATE ON organizer_payment_mode_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment mode history is append-only.';
END//

CREATE TRIGGER trg_pmh_no_delete BEFORE DELETE ON organizer_payment_mode_history FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment mode history is append-only.';
END//

CREATE TRIGGER trg_opa_no_delete BEFORE DELETE ON organizer_payment_accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment account records cannot be deleted; they are marked disconnected.';
END//

DELIMITER ;
