-- Rollback for paypal_phase1_migration.sql.
-- Refuses to run when PayPal payments were already recorded: real payment
-- records are never dropped — switch PayPal off (PAYPAL_ENABLED=0) instead.

DELIMITER $$
DROP PROCEDURE IF EXISTS evora_paypal_rollback_guard $$
CREATE PROCEDURE evora_paypal_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM payments WHERE payment_channel = 'paypal') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal payments exist — do not roll back; disable PayPal instead.';
    END IF;
END $$
DELIMITER ;
CALL evora_paypal_rollback_guard();
DROP PROCEDURE evora_paypal_rollback_guard;

DROP TRIGGER IF EXISTS trg_paypal_acct_no_delete;
DROP TRIGGER IF EXISTS trg_paypal_acct_guard_update;
DROP TRIGGER IF EXISTS trg_paypal_tx_guard_update;
DROP TRIGGER IF EXISTS trg_paypal_tx_no_delete;
DROP TRIGGER IF EXISTS trg_paypal_tx_one_completed;
DROP TRIGGER IF EXISTS trg_paypal_log_no_update;
DROP TRIGGER IF EXISTS trg_paypal_log_no_delete;

ALTER TABLE events DROP FOREIGN KEY fk_event_paypal_account;
ALTER TABLE events DROP COLUMN organizer_paypal_account_id, DROP COLUMN payment_provider;

ALTER TABLE payments
    MODIFY payment_method ENUM('gcash', 'bank_transfer', 'cash') NULL,
    MODIFY payment_channel ENUM('manual', 'paymongo', 'cash_walkin') NOT NULL DEFAULT 'manual',
    MODIFY confirmed_via ENUM('return', 'webhook', 'reconcile', 'checkout', 'autocheck') NULL;

DROP TABLE IF EXISTS paypal_payment_logs;
DROP TABLE IF EXISTS paypal_transactions;
DROP TABLE IF EXISTS organizer_paypal_accounts;
