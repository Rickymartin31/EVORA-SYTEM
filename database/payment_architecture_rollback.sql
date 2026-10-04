-- Rollback for database/payment_architecture_migration.sql
--
-- ORDER MATTERS:
--   1. Restore the pre-change PHP files from
--      C:\xampp\evora_backups\rollback_v26_originals\ (copy each back to the
--      same relative path in the project).
--   2. Delete the files added by this change:
--      core/PaymentModeService.php, core/PaymentAccountService.php,
--      modules/organizer/payment_setup.php, modules/organizer/payment_setup_save.php,
--      modules/admin/payment_accounts.php, modules/admin/payment_account_action.php,
--      modules/admin/organizer_payment_mode_action.php,
--      docs/PAYMENT_ARCHITECTURE.md
--   3. THEN run this file.
--
-- Data impact: removes the payment mode columns, organizer PayMongo account
-- connections and payment mode history. v24/v25 tables (payments, ledger,
-- settlements, payouts, payout accounts, finance history) are untouched.
--
-- WARNING: if any paymongo_connected payment was already made, its money is
-- in that organizer's own PayMongo account. Export these rows first; after
-- rollback EVORA can no longer tell those payments apart from EVORA Payment:
--   SELECT * FROM payments WHERE payment_mode = 'paymongo_connected';
--   SELECT * FROM organizer_payment_accounts;

DROP TRIGGER IF EXISTS trg_opa_no_delete;
DROP TRIGGER IF EXISTS trg_pmh_no_delete;
DROP TRIGGER IF EXISTS trg_pmh_no_update;
DROP TRIGGER IF EXISTS trg_event_payment_mode_lock;
DROP TRIGGER IF EXISTS trg_payment_mode_lock;
DROP TRIGGER IF EXISTS trg_ledger_evora_payment_only;

DROP TABLE IF EXISTS organizer_payment_mode_history;
DROP TABLE IF EXISTS organizer_payment_accounts;

ALTER TABLE payments
    DROP CONSTRAINT chk_payment_account_only_connected,
    DROP COLUMN paymongo_account_id,
    DROP COLUMN payment_mode;

ALTER TABLE events
    DROP CONSTRAINT chk_event_paymongo_account,
    DROP COLUMN paymongo_account_id,
    DROP COLUMN payment_mode;

ALTER TABLE organizers
    DROP CONSTRAINT chk_organizer_approved_has_mode,
    DROP COLUMN payment_mode;
