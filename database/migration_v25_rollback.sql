-- Rollback for migration v25 (organizer ledger, settlements, payouts).
--
-- ORDER MATTERS: first restore the pre-v25 PHP files from
-- C:\xampp\evora_backups\rollback_v25_originals\ (copy each back to the same
-- relative path) and delete the v25-only files:
--   core/LedgerService.php, core/SettlementService.php, core/PayoutService.php,
--   core/FinanceHistory.php, scripts/ledger_backfill.php,
--   modules/organizer/earnings.php, modules/organizer/settlement_view.php,
--   modules/organizer/payout_account.php, modules/organizer/payout_account_save.php,
--   modules/admin/finance.php, modules/admin/finance_action.php,
--   modules/admin/settlements.php, modules/admin/settlement_view.php,
--   modules/admin/settlement_action.php, modules/admin/payout_action.php,
--   modules/admin/payout_accounts.php, modules/admin/payout_account_action.php
-- THEN run this file.
--
-- SAFE ONLY BEFORE REAL MONEY WAS SETTLED OR PAID OUT. Once a settlement is
-- approved or a payout recorded, those rows are the record of money owed
-- and sent: export them first, and prefer fixing forward over rolling back.
--   SELECT * FROM organizer_ledger_entries; SELECT * FROM settlements;
--   SELECT * FROM payouts; SELECT * FROM organizer_payout_accounts;
--   SELECT * FROM finance_status_history;
-- Ledger entries alone can be rebuilt from payments with
-- scripts/ledger_backfill.php --apply.

DROP TRIGGER IF EXISTS trg_fsh_no_delete;
DROP TRIGGER IF EXISTS trg_fsh_no_update;
DROP TRIGGER IF EXISTS trg_payout_guard_update;
DROP TRIGGER IF EXISTS trg_payout_no_delete;
DROP TRIGGER IF EXISTS trg_payout_validate_insert;
DROP TRIGGER IF EXISTS trg_payout_account_guard_update;
DROP TRIGGER IF EXISTS trg_payout_account_no_delete;
DROP TRIGGER IF EXISTS trg_settlement_guard_update;
DROP TRIGGER IF EXISTS trg_settlement_no_delete;
DROP TRIGGER IF EXISTS trg_ledger_guard_update;
DROP TRIGGER IF EXISTS trg_ledger_no_delete;

DROP TABLE IF EXISTS finance_status_history;
DROP TABLE IF EXISTS payouts;
DROP TABLE IF EXISTS organizer_payout_accounts;
DROP TABLE IF EXISTS organizer_ledger_entries;
DROP TABLE IF EXISTS settlements;

ALTER TABLE system_settings
    DROP CONSTRAINT chk_settlement_hold_days,
    DROP COLUMN settlement_hold_days;
