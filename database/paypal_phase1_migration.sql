-- =====================================================================
-- EVORA — PayPal direct payment, Phase 1 (sandbox) — ADDITIVE ONLY
-- Participant pays the organizer's own PayPal Business account; EVORA only
-- records the transaction. No PayMongo / payout / ledger object is changed.
--
--   * organizer_paypal_accounts : linked PayPal account (merchant ID only —
--                                 never a password, token or secret)
--   * paypal_transactions       : one row per PayPal order / capture
--   * paypal_payment_logs       : append-only API / redirect log (no secrets)
--   * events.payment_provider   : which provider a paid event uses
--   * payments enums            : + 'paypal' channel/method, + 'capture'
--
-- Rollback: database/paypal_phase1_rollback.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Linked organizer PayPal accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organizer_paypal_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT NOT NULL,
    environment ENUM('sandbox', 'live') NOT NULL,
    merchant_id VARCHAR(32) NOT NULL COMMENT 'PayPal account/payer ID returned by PayPal — not typed by the organizer',
    email_masked VARCHAR(120) NULL,
    verified_account TINYINT(1) NOT NULL DEFAULT 0,
    verification_method ENUM('paypal_login', 'admin_confirmed') NOT NULL,
    status ENUM('connected', 'disconnected') NOT NULL DEFAULT 'connected',
    connected_by INT NOT NULL,
    verification_note VARCHAR(255) NULL COMMENT 'admin_confirmed: how the administrator verified the account',
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME NULL,
    disconnected_at DATETIME NULL,
    disconnected_by INT NULL,
    -- one connected account per organizer per environment, and one organizer per PayPal account
    active_organizer_key VARCHAR(40) AS (IF(status = 'connected', CONCAT(organizer_id, ':', environment), NULL)) STORED,
    active_merchant_key VARCHAR(48) AS (IF(status = 'connected', CONCAT(environment, ':', merchant_id), NULL)) STORED,
    UNIQUE KEY uq_paypal_active_organizer (active_organizer_key),
    UNIQUE KEY uq_paypal_active_merchant (active_merchant_key),
    KEY idx_paypal_acct_organizer (organizer_id),
    CONSTRAINT fk_paypal_acct_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (id),
    CONSTRAINT fk_paypal_acct_connected_by FOREIGN KEY (connected_by) REFERENCES users (id),
    CONSTRAINT fk_paypal_acct_disconnected_by FOREIGN KEY (disconnected_by) REFERENCES users (id),
    CONSTRAINT chk_paypal_merchant_id CHECK (merchant_id REGEXP '^[A-Za-z0-9]{8,32}$'),
    CONSTRAINT chk_paypal_admin_note CHECK (verification_method <> 'admin_confirmed' OR (verification_note IS NOT NULL AND CHAR_LENGTH(verification_note) >= 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. PayPal orders / captures
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paypal_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL,
    event_id INT NOT NULL,
    payment_id INT NOT NULL,
    organizer_id INT NOT NULL,
    organizer_paypal_account_id INT NOT NULL,
    merchant_id VARCHAR(32) NOT NULL COMMENT 'payee snapshot at order creation',
    environment ENUM('sandbox', 'live') NOT NULL,
    paypal_order_id VARCHAR(40) NULL,
    paypal_capture_id VARCHAR(40) NULL,
    invoice_id VARCHAR(64) NOT NULL,
    request_id CHAR(36) NOT NULL COMMENT 'PayPal-Request-Id idempotency key for create',
    amount DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    paypal_fee DECIMAL(10, 2) NULL,
    net_amount DECIMAL(10, 2) NULL,
    paypal_status ENUM('created', 'approved', 'completed', 'failed', 'cancelled', 'expired', 'refunded') NOT NULL DEFAULT 'created',
    failure_reason VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL COMMENT 'buyer returned from PayPal and EVORA started capture',
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    refunded_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_paypal_order (paypal_order_id),
    UNIQUE KEY uq_paypal_capture (paypal_capture_id),
    UNIQUE KEY uq_paypal_invoice (invoice_id),
    UNIQUE KEY uq_paypal_request (request_id),
    KEY idx_paypal_tx_registration (registration_id, paypal_status),
    KEY idx_paypal_tx_event (event_id),
    CONSTRAINT fk_paypal_tx_registration FOREIGN KEY (registration_id) REFERENCES registrations (id),
    CONSTRAINT fk_paypal_tx_event FOREIGN KEY (event_id) REFERENCES events (id),
    CONSTRAINT fk_paypal_tx_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_paypal_tx_organizer FOREIGN KEY (organizer_id) REFERENCES organizers (id),
    CONSTRAINT fk_paypal_tx_account FOREIGN KEY (organizer_paypal_account_id) REFERENCES organizer_paypal_accounts (id),
    CONSTRAINT chk_paypal_tx_amount CHECK (amount > 0),
    CONSTRAINT chk_paypal_tx_completed CHECK (paypal_status NOT IN ('completed', 'refunded') OR (paypal_capture_id IS NOT NULL AND completed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Append-only log (status codes and PayPal IDs only — never tokens/secrets)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS paypal_payment_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paypal_transaction_id INT NULL,
    kind ENUM('request', 'response', 'redirect', 'webhook', 'connect') NOT NULL,
    action VARCHAR(40) NOT NULL,
    http_status SMALLINT NULL,
    summary VARCHAR(500) NULL,
    actor_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_paypal_log_tx (paypal_transaction_id),
    CONSTRAINT fk_paypal_log_tx FOREIGN KEY (paypal_transaction_id) REFERENCES paypal_transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Columns on existing tables (all nullable / widened enums only)
-- ---------------------------------------------------------------------
ALTER TABLE events
    ADD COLUMN payment_provider ENUM('paymongo', 'paypal') NULL DEFAULT NULL AFTER fee_mode,
    ADD COLUMN organizer_paypal_account_id INT NULL DEFAULT NULL AFTER payment_provider,
    ADD CONSTRAINT fk_event_paypal_account FOREIGN KEY (organizer_paypal_account_id) REFERENCES organizer_paypal_accounts (id);

ALTER TABLE payments
    MODIFY payment_method ENUM('gcash', 'bank_transfer', 'cash', 'paypal') NULL,
    MODIFY payment_channel ENUM('manual', 'paymongo', 'cash_walkin', 'paypal') NOT NULL DEFAULT 'manual',
    MODIFY confirmed_via ENUM('return', 'webhook', 'reconcile', 'checkout', 'autocheck', 'capture') NULL;

-- ---------------------------------------------------------------------
-- 5. Database-level rules
-- ---------------------------------------------------------------------
DELIMITER $$

-- Linked accounts are never deleted (only disconnected) and their identity never changes.
DROP TRIGGER IF EXISTS trg_paypal_acct_no_delete $$
CREATE TRIGGER trg_paypal_acct_no_delete BEFORE DELETE ON organizer_paypal_accounts FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Linked PayPal accounts cannot be deleted; disconnect them instead.';
END $$

DROP TRIGGER IF EXISTS trg_paypal_acct_guard_update $$
CREATE TRIGGER trg_paypal_acct_guard_update BEFORE UPDATE ON organizer_paypal_accounts FOR EACH ROW
BEGIN
    IF NEW.organizer_id <> OLD.organizer_id OR NEW.merchant_id <> OLD.merchant_id OR NEW.environment <> OLD.environment THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A linked PayPal account cannot be re-pointed; connect a new one.';
    END IF;
    IF OLD.status = 'disconnected' AND NEW.status <> 'disconnected' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A disconnected PayPal account cannot be reconnected; connect again.';
    END IF;
END $$

-- A transaction's money facts never change; completed is final except for a later refund.
DROP TRIGGER IF EXISTS trg_paypal_tx_guard_update $$
CREATE TRIGGER trg_paypal_tx_guard_update BEFORE UPDATE ON paypal_transactions FOR EACH ROW
BEGIN
    IF NEW.amount <> OLD.amount OR NEW.currency <> OLD.currency OR NEW.merchant_id <> OLD.merchant_id
       OR NEW.registration_id <> OLD.registration_id OR NEW.payment_id <> OLD.payment_id OR NEW.invoice_id <> OLD.invoice_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal transaction amount, payee and registration cannot be changed.';
    END IF;
    IF OLD.paypal_order_id IS NOT NULL AND NOT (NEW.paypal_order_id <=> OLD.paypal_order_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The PayPal order ID cannot be changed.';
    END IF;
    IF OLD.paypal_capture_id IS NOT NULL AND NOT (NEW.paypal_capture_id <=> OLD.paypal_capture_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The PayPal capture ID cannot be changed.';
    END IF;
    IF OLD.paypal_status = 'completed' AND NEW.paypal_status NOT IN ('completed', 'refunded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A completed PayPal payment is final.';
    END IF;
    IF OLD.paypal_status IN ('failed', 'cancelled', 'expired', 'refunded') AND NEW.paypal_status <> OLD.paypal_status THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A closed PayPal transaction cannot change status; start a new order.';
    END IF;
END $$

DROP TRIGGER IF EXISTS trg_paypal_tx_no_delete $$
CREATE TRIGGER trg_paypal_tx_no_delete BEFORE DELETE ON paypal_transactions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal transactions cannot be deleted.';
END $$

-- Only one completed PayPal payment per registration (duplicate guard at the database).
DROP TRIGGER IF EXISTS trg_paypal_tx_one_completed $$
CREATE TRIGGER trg_paypal_tx_one_completed BEFORE UPDATE ON paypal_transactions FOR EACH ROW
BEGIN
    IF NEW.paypal_status = 'completed' AND OLD.paypal_status <> 'completed'
       AND EXISTS (SELECT 1 FROM paypal_transactions t WHERE t.registration_id = NEW.registration_id AND t.paypal_status = 'completed' AND t.id <> NEW.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This registration already has a completed PayPal payment.';
    END IF;
END $$

DROP TRIGGER IF EXISTS trg_paypal_log_no_update $$
CREATE TRIGGER trg_paypal_log_no_update BEFORE UPDATE ON paypal_payment_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal payment logs are append-only.';
END $$

DROP TRIGGER IF EXISTS trg_paypal_log_no_delete $$
CREATE TRIGGER trg_paypal_log_no_delete BEFORE DELETE ON paypal_payment_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal payment logs are append-only.';
END $$

DELIMITER ;
