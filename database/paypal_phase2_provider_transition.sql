-- EVORA PayPal Phase 2 — one-time payment-provider transition for existing open events.
-- Run ONLY after the organizer's PayPal account has been confirmed (admin-confirmed linking)
-- and PayPal is the active provider. Changes ONLY events.payment_provider and
-- events.organizer_paypal_account_id (updated_at is kept as it was).
--
-- Converted: the listed events that are open, still on the legacy provider (NULL), whose
-- organizer has a connected PayPal account in @env, and that have NO PayMongo money
-- (no PayMongo payment that is pending, approved or refunded) — so no event mixes providers.
-- Events with PayMongo money stay legacy: they take no new online payments while PayPal is
-- active, and their PayMongo records keep their existing handling (payout, refund, history).

SET @env = 'sandbox';

SELECT e.id, e.name, e.status, e.payment_provider AS provider_before, e.organizer_paypal_account_id AS account_before
FROM events e WHERE e.id IN (2, 86, 91);

UPDATE events e
JOIN organizer_paypal_accounts a
  ON a.organizer_id = e.organizer_id AND a.status = 'connected' AND a.environment = @env
SET e.payment_provider = 'paypal',
    e.organizer_paypal_account_id = a.id,
    e.updated_at = e.updated_at
WHERE e.id IN (2, 86, 91)
  AND e.status = 'open'
  AND e.payment_provider IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM payments p JOIN registrations r ON r.id = p.registration_id
      WHERE r.event_id = e.id AND p.payment_channel = 'paymongo' AND p.status IN ('pending', 'approved', 'refunded'));

SELECT ROW_COUNT() AS events_converted;

SELECT e.id, e.name, e.payment_provider AS provider_after, e.organizer_paypal_account_id AS account_after,
       (SELECT GROUP_CONCAT(DISTINCT CONCAT(p.payment_channel, '/', p.status)) FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = e.id) AS existing_payments
FROM events e WHERE e.id IN (2, 86, 91);
