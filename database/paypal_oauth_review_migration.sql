-- Organizer PayPal linking, option B: an organizer links their own PayPal account with
-- "Log in with PayPal", then an administrator approves it once (checks it is the organizer's
-- PayPal Business account — PayPal's sign-in does not report the account type).
-- Only an APPROVED connected account can be used for paid events. This is account review,
-- never payment approval: participant payments stay verified and approved automatically.
--
-- Adds approved_at / approved_by. Existing admin-confirmed links were already checked by an
-- administrator, so they are backfilled as approved by that administrator. Nothing else changes.

ALTER TABLE organizer_paypal_accounts
    ADD COLUMN approved_at DATETIME NULL DEFAULT NULL COMMENT 'administrator approved this account for paid events' AFTER verified_at,
    ADD COLUMN approved_by INT(11) NULL DEFAULT NULL AFTER approved_at,
    ADD KEY fk_paypal_acct_approved_by (approved_by),
    ADD CONSTRAINT fk_paypal_acct_approved_by FOREIGN KEY (approved_by) REFERENCES users (id);

UPDATE organizer_paypal_accounts
SET approved_at = COALESCE(verified_at, connected_at), approved_by = connected_by
WHERE verification_method = 'admin_confirmed' AND approved_at IS NULL;

SELECT id, organizer_id, verification_method, status, approved_at IS NOT NULL AS approved FROM organizer_paypal_accounts ORDER BY id;
