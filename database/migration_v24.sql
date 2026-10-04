-- Migration v24: PayMongo payment reliability + ledger-ready payment fields
-- Add-only. No existing column, key or row is changed or removed, so every
-- existing payment (manual, paymongo, cash_walkin) keeps working as before.
--
--   payment_checkout_sessions : every checkout session ever created for a
--       payment, so a paid *older* session (after a retry) can still be matched.
--   paymongo_webhook_events   : idempotency + audit log of received webhooks.
--   payments.gateway_fee / net_amount / confirmed_via : captured on
--       confirmation, for the future organizer ledger. Existing rows stay NULL.
--
-- Rollback: database/migration_v24_rollback.sql

CREATE TABLE IF NOT EXISTS payment_checkout_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    checkout_session_id VARCHAR(100) NOT NULL,
    status ENUM('open','paid','expired','cancelled') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pcs_session (checkout_session_id),
    INDEX idx_pcs_payment (payment_id),
    CONSTRAINT fk_pcs_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paymongo_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    checkout_session_id VARCHAR(100) DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    result VARCHAR(40) NOT NULL DEFAULT 'received',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pwe_event (event_id),
    INDEX idx_pwe_payment (payment_id)
) ENGINE=InnoDB;

ALTER TABLE payments
    ADD COLUMN gateway_fee DECIMAL(10,2) DEFAULT NULL AFTER gateway_payment_method,
    ADD COLUMN net_amount DECIMAL(10,2) DEFAULT NULL AFTER gateway_fee,
    ADD COLUMN confirmed_via ENUM('return','webhook','reconcile','checkout','autocheck') DEFAULT NULL AFTER net_amount;

-- Backfill: register the current session of every existing PayMongo payment.
INSERT IGNORE INTO payment_checkout_sessions (payment_id, checkout_session_id, status)
    SELECT id, checkout_session_id,
           CASE status WHEN 'approved' THEN 'paid' WHEN 'cancelled' THEN 'cancelled' ELSE 'open' END
    FROM payments
    WHERE checkout_session_id IS NOT NULL;
