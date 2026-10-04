-- Rollback for migration v24.
--
-- ORDER MATTERS: first restore the pre-v24 PHP files from
-- C:\xampp\evora_backups\rollback_v24_originals\ (copy each file back to the
-- same relative path in the project) and delete core/PaymentConfirmationService.php
-- and scripts/, THEN run this file. The v24 code reads
-- these tables/columns and would error if they disappear first.
--
-- Data impact:
--   * payments rows are untouched except the 3 added columns are dropped
--     (fee/net/confirmed_via values recorded since v24 are lost).
--   * payments.checkout_session_id still holds each payment's latest/paid
--     session, so the old code keeps working for every existing payment.
--   * Session history and the webhook event log are dropped. Export them
--     first if you want to keep them:
--       SELECT * FROM payment_checkout_sessions;
--       SELECT * FROM paymongo_webhook_events;

ALTER TABLE payments
    DROP COLUMN confirmed_via,
    DROP COLUMN net_amount,
    DROP COLUMN gateway_fee;

DROP TABLE IF EXISTS paymongo_webhook_events;
DROP TABLE IF EXISTS payment_checkout_sessions;
