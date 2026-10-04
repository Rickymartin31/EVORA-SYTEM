# EVORA — Payout Accountability & Dispute System

EVORA collects participant payments centrally and an administrator sends organizer payouts
manually (GCash, Maya or bank). Software cannot stop an authorized person from misusing funds.
This feature makes every payout **visible, traceable, time-bound, auditable, disputable and
documented**, and lets the organizer obtain their own records without the administrator.

## What did NOT change

- Payout lifecycle: `pending → approved → processing → paid`, or `→ rejected`.
- The database rule that `paid` and `rejected` payout requests are final (trigger kept; only
  stricter checks were added).
- Amounts, destination snapshot, reference + proof requirement, unique paid reference,
  ledger, earnings, refunds, PayMongo collection.

## Layers added next to the payout

| Layer | Values | Source |
|---|---|---|
| Timeliness | On time · Due soon (≤ 24 h) · Overdue · Paid on time · Paid late · Created before payout deadlines | derived from `due_at` (no scheduler) |
| Receipt | Awaiting organizer confirmation · Confirmed received · No issue reported | `payout_receipt_responses` + reporting window |
| Dispute | open · awaiting_organizer · resolved · withdrawn (+ derived "No response from administrator") | `payout_disputes` + append-only `payout_dispute_events` |
| Evidence | Payout Record PDF with integrity code; every export logged | `payout_record_exports` |
| Agreement | versioned Organizer Payout Agreement; acceptance with version, time, user, IP, browser, text hash | `payout_agreements`, `payout_agreement_acceptances` |
| Operator | Platform Operator Profile, every change a new version (no government ID data) | `platform_operator_profile` + history |

## Key rules (database-enforced)

- `due_at` is computed by the insert trigger (`created_at + payout_sla_days`) and can never change;
  old payouts keep `NULL` (no invented deadlines).
- `proof_sha256` is written exactly once, in the `processing → paid` transition. It proves the stored
  file has not changed; it does **not** prove the proof's contents are true.
- One open dispute per payout (`uq_one_open_dispute_per_payout`).
- Dispute identity/reason immutable; resolved/withdrawn final; events, receipts, acceptances,
  agreements, operator history and record exports cannot be edited or deleted.
- `activity_logs` is now append-only as well (user deletion still works: FK `SET NULL` does not fire triggers).

## Flow

1. Organizer accepts the current agreement before creating a paid event (free events unaffected).
2. Registration closes → payout request created with a deadline.
3. Admin approves → processing → paid (reference + proof; proof hash stored). Organizer is asked to confirm.
4. Organizer: **Confirm Received**, or **Report a Problem** (within the reporting period after paid,
   or once the payout is overdue and unpaid).
5. Dispute: admin responds by the response deadline (clarification, reference, proof, or a payment
   correction with new reference + proof — never editing the original payout). Organizer accepts the
   resolution or continues the dispute (new response deadline), or withdraws it.
6. If the response deadline passes: "No response from administrator" (derived; not a legal finding).
7. Organizer can download the Payout Record at any time.

## Settings (admin → Agreement & Operator)

`payout_sla_days` (default 3), `dispute_window_days` (default 30), `admin_response_days` (default 3).
Changes apply only to payouts/disputes created afterwards.

## Notifications (existing notification center, deduplicated)

Organizer: payout due soon, overdue, marked paid (please confirm), dispute recorded, administrator
responded, response deadline approaching. Admin: payout due soon, overdue, dispute opened/continued/
withdrawn, response deadline approaching, organizer accepted resolution. Due/overdue alerts are raised
when payout or dispute pages open and by `scripts/process_event_payouts.php`; the overdue **state**
never depends on them.

## Deployment (only after approval)

1. Back up `evora_db`.
2. Copy the changed/new files from the staging copy into the project (list in the implementation report).
3. Run `database/payout_accountability_migration.sql` on `evora_db`.
4. Admin: save the Platform Operator Profile, review the agreement text **with the adviser/legal
   professional**, then publish version 1.0.

Rollback: restore the files and run `database/payout_accountability_rollback.sql` (drops the new
tables and their data — back up first).

## Requires human / legal review

- Agreement text (template is a neutral starting point, not legal advice).
- Operator details (legal name, registration, address, escalation contact).
- Deadline values (3 / 30 / 3 days).
- Retention period for disputes and Payout Records.
