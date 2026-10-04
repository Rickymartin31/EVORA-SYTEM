-- Undo the Phase 2 provider transition for events #2, #86, #91 — ONLY while none of them has a
-- PayPal payment yet (otherwise PayPal money would sit on a legacy event: keep them on PayPal).
-- Changes ONLY events.payment_provider and events.organizer_paypal_account_id (updated_at kept).

DELIMITER $$
DROP PROCEDURE IF EXISTS evora_provider_transition_rollback_guard $$
CREATE PROCEDURE evora_provider_transition_rollback_guard()
BEGIN
    IF EXISTS (SELECT 1 FROM payments p JOIN registrations r ON r.id = p.registration_id
               WHERE r.event_id IN (2, 86, 91) AND p.payment_channel = 'paypal') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PayPal payments exist on a transitioned event — do not roll back.';
    END IF;
END $$
DELIMITER ;
CALL evora_provider_transition_rollback_guard();
DROP PROCEDURE evora_provider_transition_rollback_guard;

UPDATE events
SET payment_provider = NULL, organizer_paypal_account_id = NULL, updated_at = updated_at
WHERE id IN (2, 86, 91) AND payment_provider = 'paypal';

SELECT id, name, payment_provider, organizer_paypal_account_id FROM events WHERE id IN (2, 86, 91);
