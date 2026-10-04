-- Undo paypal_oauth_review_migration.sql. Restore the previous code FIRST (it ignores approval).
-- Refuses while a connected account is still waiting for approval: the old code would treat it
-- as usable for paid events. Disconnect or approve those first.

DELIMITER $$
DROP PROCEDURE IF EXISTS evora_paypal_review_rollback_guard $$
CREATE PROCEDURE evora_paypal_review_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM organizer_paypal_accounts WHERE status = 'connected' AND approved_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A connected PayPal account is waiting for administrator approval — approve or disconnect it before rolling back.';
    END IF;
END $$
DELIMITER ;
CALL evora_paypal_review_rollback_guard();
DROP PROCEDURE evora_paypal_review_rollback_guard;

ALTER TABLE organizer_paypal_accounts
    DROP FOREIGN KEY fk_paypal_acct_approved_by,
    DROP KEY fk_paypal_acct_approved_by,
    DROP COLUMN approved_by,
    DROP COLUMN approved_at;
