# EVORA Payment Architecture — Centralized Collection, Registration-Closing Payouts (v27)

> **Two payment models now exist side by side.** New paid events can take **PayPal direct
> payment** (participant pays the organizer's own PayPal account — see the next section).
> Events already set up for **PayMongo** keep the centralized collection + payout model
> described in the rest of this document, and every legacy PayMongo record stays valid.
>
> Status: PayPal direct payment is built and tested in **staging only** (PayPal sandbox).
> It is not yet deployed to the real project / `evora_db`.

## PayPal direct payment (Phase 1–2)

```
Participant ─► Register for event ─► PayPal Checkout (participant logs in to PayPal and approves)
   ─► money goes DIRECTLY to the organizer's PayPal Business account (payee = organizer merchant ID)
   ─► EVORA captures the order server-side and verifies it with PayPal
        ├─ payments row approved (payment_channel = 'paypal') + paypal_transactions row 'completed'
        ├─ registration approved ─► QR code ─► notifications (participant + organizer)
        └─ NOTHING in organizer_ledger_entries, NO payout request
```

**EVORA does:**
- create the PayPal order on the server (amount taken from the database, never from the browser;
  payee = the organizer's linked merchant ID; `invoice_id` EVORA-REG{id}-…, `custom_id` REG-{id};
  idempotent `PayPal-Request-Id`)
- capture and verify server-side: order ID, COMPLETED status, exact amount, PHP currency,
  payee merchant, invoice / custom_id, and that the registration belongs to the paying participant
  (browser return parameters are never trusted on their own)
- record the transaction (`paypal_transactions`, append-only `paypal_payment_logs`)
- approve the registration, generate the QR code, send notifications, keep audit logs
- block duplicates: one completed PayPal transaction per registration (DB trigger + code),
  already-paid checks, PayPal's own `ORDER_ALREADY_CAPTURED`

**EVORA does NOT:**
- receive or hold organizer PayPal funds
- create PayPal payouts or payout requests
- put PayPal payments into `organizer_ledger_entries` (`LedgerService::recordPayment` accepts PayMongo only)
- manually transfer PayPal proceeds to organizers
- refund PayPal payments: the **organizer refunds the participant from their own PayPal account**;
  EVORA does not process PayPal refunds automatically (a refund request for a PayPal payment is
  refused with that explanation)

**Which events use PayPal.** `events.payment_provider` is set to `paypal` (with a snapshot of the
organizer's linked account in `organizer_paypal_account_id`) when a paid event is opened for
registration while PayPal is enabled and the organizer has a linked PayPal account. It is never
switched once PayMongo money exists for the event. Events with `payment_provider` NULL stay on PayMongo.

**Fully automatic — no admin payment approval.** Like the PayMongo flow, a PayPal payment needs
nobody's approval: capture COMPLETED → server-side verification → payment approved → registration
approved → QR → notifications, all automatically. The admin **only monitors** PayPal transactions
(read-only page); the admin never approves individual PayPal payments, never handles participant
money and never handles organizer payouts for PayPal. Participants and organizers are the actors:
the participant registers and pays; the organizer creates the paid event, receives the money in
PayPal and manages registrations. An organizer "Approve registration" never approves a PayPal
payment and never issues a QR — only a verified PayPal capture does.

**PayPal is the active online payment method; PayMongo is legacy/historical only.** No new PayMongo
payment is ever started — with PayPal on or off: PayPal events take PayPal; a legacy event (`payment_provider` NULL) that requires
payment takes **no new online payments** ("previous online payment method… paused") — its existing
PayMongo records keep their normal handling (an in-flight checkout can still be confirmed, payouts,
refunds, history). One event never mixes PayMongo and PayPal money. A one-time, payment-only script
(`database/paypal_phase2_provider_transition.sql`, guarded rollback alongside) moves the listed open
legacy events (#2, #86, #91) to PayPal — only if the organizer's PayPal account is confirmed and the
event has no PayMongo payment that is pending, approved or refunded (#86 has a refunded PayMongo
payment, so it stays legacy). It changes only `payment_provider` and `organizer_paypal_account_id`.
With `PAYPAL_ENABLED=0` PayPal does **not** fall back to PayMongo: new online payments are shown as
temporarily unavailable/paused and a paid event cannot be opened for registration. Walk-in cash is
unchanged.

**Navigation.** Admin "Payments (PayPal)": PayPal Transactions (read-only monitoring), Organizer
PayPal Accounts, Payment Reports (Payment Summary: PayPal paid directly to organizers vs. legacy
PayMongo collected by EVORA vs. cash walk-in). Admin "Legacy Finance (PayMongo)": Finance Ledger,
Organizer Payout Requests, Payout Accounts, Payout Disputes, Refund Requests — all "(Legacy)", kept
for historical/in-flight PayMongo records. Organizer: PayPal Account under Finance; Payment & Payouts,
Refunds and Payout Accounts under "Legacy Finance (PayMongo)".

**Organizer linking.** "Log in with PayPal" is hidden while it is unavailable
(`PayPalAccountService::OAUTH_AVAILABLE = false`). Currently supported: **admin-confirmed linking** — a **one-time merchant-account
linking/verification step per organizer, NOT participant payment approval**. Organizer self-entry of a
PayPal Account ID is not trusted automatically (EVORA cannot prove who owns an Account ID without
"Log in with PayPal"). Details: **admin-confirmed linking** — an administrator enters the
organizer's PayPal Account (merchant) ID with a verification note; EVORA asks PayPal whether that
account can receive payments (a ₱1 order that is never approved, so no money moves) and only then
links it (`verification_method = admin_confirmed`, `verified_at`, note). The organizer page says
"confirmed by an administrator". **"Log in with PayPal" (OAuth) is BLOCKED** — PayPal rejects the
app's return URL ("invalid client_id or redirect_uri") and it needs a public HTTPS address. It is
not bypassed or simulated.

**Reporting.** Admin totals show **Collected by EVORA (PayMongo)** separately from
**Paid directly to organizers (PayPal, not collected by EVORA)**; "Total Revenue" / "Total Payments
Collected" exclude PayPal so historical totals do not change (`ReportQueries::revenueSplit`).
The organizer's own event financial report includes PayPal as "PayPal Revenue (paid directly to
your PayPal account)". PayPal events are not listed on the organizer's Payment & Payouts (earnings)
page, because EVORA has nothing to pay out for them.

**Event cancellation.** When an administrator cancels an event, PayPal-paid participants are told
their refund must come from the organizer through PayPal, and the organizer is notified to refund
them from PayPal. PayMongo participants keep the existing EVORA refund flow.

**Configuration / secrets.** Client ID and Secret come from environment variables or from
`C:\xampp\evora_secrets\paypal.ini` (outside the web root, never in source, database, logs or URLs).
The real project uses its own `paypal_real.ini` and starts with PayPal **off** — see
`docs/deploy/PAYPAL_PRODUCTION_CONFIG.md`.

**Kill switch (`PAYPAL_ENABLED=0`).** PayPal events stay PayPal events (never converted to PayMongo).
While PayPal is off: nobody can register into a PayPal event (refused before any registration or
payment row is saved); an unpaid PayPal registration cannot start a new PayPal order (the page says
payment is paused, the registration is kept); the organizer cannot open registration for a PayPal
event; already-paid PayPal registrations stay approved and their QR codes stay valid; an order the
buyer had already approved can still be captured and verified, so nobody is left half-paid.
An unpaid participant whose PayPal window was closed without paying can **continue** the payment
from My Registrations (EVORA reuses the open order or starts a new one; capture is still verified).

**Why the payout/accountability features stay.** Legacy PayMongo records still exist (open events,
ledger entries not yet requested, pending/approved refunds). Payout requests, disputes, the payout
agreement, refunds and the ledger stay fully working until every legacy PayMongo record is closed.

## PayMongo centralized collection (legacy and existing events)

### Summary

"EVORA uses a centralized payment collection system. Participant payments are processed through
EVORA, while organizer earnings are automatically calculated after the event registration period
ends. The system generates payout requests to ensure accurate and traceable distribution of event
proceeds."

Organizers do **not** need a PayMongo account. They only need a payout account (GCash or bank)
and their event's registration settings.

## Payment flow

```
Participant ─► Register for event ─► Pay registration fee (PayMongo Checkout: GCash / Maya / Card)
   ─► money goes to EVORA's PayMongo account
   ─► EVORA confirms the payment (return page / webhook / reconcile job / My Registrations auto-check)
   ─► PaymentConfirmationService::confirm()  (row lock, exactly once)
        ├─ payment approved + ONE organizer_ledger_entries row (same DB transaction)
        ├─ registration approved ─► QR code ─► notification
        └─ organizer_event_earnings recalculated (gross, PayMongo fee, net, participants)

Organizer clicks Close Registration
   (optional: registration deadline passed ─► scripts/process_event_payouts.php closes it)
        ├─ event status open ─► closed, registration_closed_at stamped, new registrations stop
        ├─ final earnings calculated from the ledger
        └─ ONE organizer_payout_requests row created: status "pending"

Admin ─► Organizer Payout Requests ─► Approve: "Send Payment To" = one of the organizer's VERIFIED
         accounts (primary preselected) ─► account id + masked snapshot saved, fixed from then on
   ─► View Full Payout Details (admin-only, logged) ─► Start Sending = "processing"
   ─► sends the money via GCash / Maya / bank (outside EVORA)
   ─► Mark as Paid: reference number + proof file BOTH required ─► organizer notified ─► "Released Payout"
   ─► organizer uses the funds to prepare the event
```

Example: 100 participants × ₱500 = ₱50,000 gross, ₱1,250 PayMongo fees → payout request of ₱48,750.

## Refund flow (v30.1)

```
Payment paid
   ─► Participant: My Registrations ─► Request Refund (Cannot attend / Duplicate payment / Other)
      = "Pending Refund Request"          — or —
      Admin cancels the event ─► Refund Requests ─► Refund All Paid Participants
   ─► Admin review: Approve (ledger entry held out of payouts) / Reject (reason, participant notified)
   ─► Admin refunds in the PayMongo dashboard (EVORA never calls the refund API)
   ─► Complete Refund: refund reference + proof file BOTH required
        ├─ payment status = refunded (refunded_by, completed_at, reference, proof saved)
        ├─ registration approved ─► cancelled, QR code invalidated (every scanner refuses it)
        ├─ ledger reversal entry ─► organizer earnings recalculated immediately
        └─ notifications: participant "Refund Completed", organizer "A participant refund
           affected your earnings", admin "Refund completed successfully"
```

Example: gross ₱50,000 − refunded ₱500 = gross ₱49,500 (the reversal cancels that payment's
gross, fee and net).

- **Refund amount** is always the recorded payment amount (copied from `payments.amount`, checked by
  a database trigger). No form has an amount field.
- **Duplicate payment**: only a second PayMongo charge EVORA flagged (`paymongo_duplicate_charge` in
  `payment_history`) can be refunded this way. It refunds the extra charge only — the registration,
  payment and QR stay valid and the ledger is untouched (the extra charge never entered it).
- **Refunds only before the event starts** for "Cannot attend"/"Other", and never after check-in.
  Manual/cash payments are held by the organizer and are not refunded by EVORA.
- **Payout interaction**: a payment inside an open payout request (pending/approved/processing) cannot
  be refunded until that request is rejected or paid. A refund of an already paid-out payment creates a
  negative ledger entry that is deducted from the organizer's next payout for that event.
- **Refund All** (admin, event cancelled or removed): in one transaction, creates an approved refund for
  every paid PayMongo registration (pending requests are approved), cancels those registrations,
  invalidates their QR codes, reverses their ledger entries and recalculates earnings. Refused while the
  event has an open payout request. The admin then completes each refund with reference + proof.
- Admins cancel an event from **Event Monitoring → Cancel Event** (reason required); organizer and
  registered participants are notified.

## Notifications (v31)

Every money event notifies the people involved through the notification center (bell dropdown,
sidebar badges, Notifications page with All / Unread / Payments / Refunds / Payouts / Events /
System tabs). `NotificationService` builds each notification's link for the viewer's own role
from `reference_type` + `reference_id`; opening one (POST + CSRF) marks it read and redirects.

| Event | Participant | Organizer | Admin |
|---|---|---|---|
| PayMongo payment confirmed | Payment Confirmed | New Participant Payment | — |
| Payment failed / charged twice | Payment Failed | — | Failed Payment / Duplicate Payment Detected (action) |
| Registration closed (scheduler) | — | Registration Closed | — |
| Payout request created | — | Payout Request Created | Payout Request To Review (action) |
| Payout approved / sent / rejected | — | Payout Approved / Payout Sent / Rejected | — |
| Refund requested | Refund Request Submitted | Refund Requested (info only) | New Refund Request (action) |
| Refund approved / rejected | Refund Approved / Rejected | — | — |
| Refund completed | Refund Completed | Participant Refund (earnings adjusted) | Refund Completed |
| Event cancelled / cancellation requested | Event Cancelled | Event Cancelled | Event Cancellation Request (action) |

Notifications are sent after the business transaction commits; they never change payment,
payout or refund outcomes.

## Payout timing rule: registration closed

The payout trigger is **registration closed** — not the event date and not event completion.
Organizers receive their collected registration fees after registration closes so they can use them
to prepare the event.

- **Main trigger:** the organizer clicks **Close Registration** (Event Manage page). EVORA stops new
  registrations, finalizes the earnings and creates the payout request immediately.
- **Optional automatic closing:** if the event has a `registration_deadline`, the scheduler closes
  registration on the first day after it (the deadline day itself is still open, the same rule as the
  registration page) and creates the payout request. Events without a deadline stay open until the
  organizer closes them.
- Marking an open event "ongoing" also ends registration and triggers the payout request.
- Payout approval never checks the event date. It is blocked only if the event was cancelled or
  removed, the organizer is suspended, or there is no verified payout account.

Defense explanation: "The system releases organizer funds after registration closes because
organizers need collected registration fees to prepare the upcoming event. The event date is not
used as the payout trigger."

## Data model

| Table / column | Purpose |
|---|---|
| `organizer_ledger_entries` (v25, kept) | One immutable row per confirmed PayMongo payment (gross, fee, net). Reversal rows for corrections. New column `payout_request_id`. |
| `organizer_event_earnings` (new) | One row per event: participants, gross, fee, net, held count, requested and paid amounts, status (`collecting`, `closed`, `payout_requested`, `paid`, `on_hold`), `payout_paused_reason`. Always recalculated from the ledger. |
| `organizer_payout_requests` (new) | One row per payout: event, participants, gross, fee, amount (net), status (`pending`, `approved`, `processing`, `paid`, `rejected`), approved_by, paid_by, paid_at, reference_number, account snapshot. |
| `organizer_payout_accounts` (v25, kept) | Payout destinations — **many per organizer** (v29): method (`gcash`, `maya`, `bank`), account name, mobile/account number, bank name, status (`pending`, `verified`, `rejected`, `inactive` = removed), `is_primary` (one verified primary per organizer), `reviewed_by`/`reviewed_at` (who verified, when), `updated_at`. No duplicate active accounts. Rows are never deleted. |
| `payout_detail_access_logs` (v28) | Append-only: which admin viewed which request's full payout details, when, from which IP. |
| `payment_refunds` (v30.1) | One row per refund: payment, registration, type (`registration` / `duplicate_charge`), amount (= payment amount), reason, status (`pending`, `approved`, `completed`, `rejected`), requested_by, approved_by/at, rejected_by/at, refund_reference, proof_path, refunded_by, completed_at, ledger_reversal_id. Never deleted; completed/rejected rows are final. |
| `registrations.status` `cancelled`, `qr_codes.invalidated_at` (v30.1) | A refunded registration is cancelled (final) and its QR code invalidated. Ledger `hold_reason` `refund_pending` keeps an entry out of payouts while its refund is processed. |
| `events.registration_closed_at` (new) | When registration closed. Makes the event's ledger entries eligible for payout. |
| `finance_status_history` (v25, kept) | Append-only audit trail; now also `event_earnings` and `payout_request`. |

Ledger entry states (derived, never stored): Collecting → Ready for Payout → Payout Requested →
Payout Processing → Paid Out, plus On Hold and Reversed.

## Security

- **Amounts never come from the browser.** Payout amounts are sums of ledger entries, which are
  written from the payments table in the same transaction that approves the payment. No form has
  an amount field.
- **Organizers** are always resolved from the session. They cannot edit amounts, cannot approve or
  mark payouts, and can only see their own requests (`find(..., $organizerId)` scoping).
- **Admin actions** require an authenticated admin (`Auth::requireRole('admin')`) and a valid CSRF
  token. Rejections need a reason; paid needs a transfer reference. An admin cannot approve a payout
  to their own organizer account.
- **Database triggers** (not only PHP):
  - payout requests start as `pending`, cannot be deleted, and have immutable amounts/identity;
  - only valid status changes (`pending → approved/rejected`, `approved → processing/paid/rejected`,
    `processing → paid/rejected`); paid/rejected are final;
  - the destination is fixed at approval and must be the organizer's own **verified** account;
  - at most one open request per event (unique generated column); a transfer reference can be used
    for only one paid request;
  - a ledger entry can join only a pending request of its own event, is released only by a
    rejection, and cannot change while its request is live;
  - v25 settlements are blocked, so no entry can be paid twice;
  - CHECK constraints: amount > 0 and amount = gross − fee.
- Payout proof files are served only to admins. Organizers see the paid date and a masked reference.
- Payout destinations are masked everywhere (`•••• 8661`). Only an admin can open the full details on
  a payout request (POST + CSRF); each view is written to the append-only `payout_detail_access_logs`
  table and the activity log, and the details hide again after 5 minutes.
- A request becomes paid only from `processing`, with a reference number AND a proof file (enforced in
  PHP and by a database CHECK + trigger).
- EVORA never asks for or stores PayMongo passwords, OTPs, MPINs or API keys of organizers.
- **Refunds** (v30.1): admin-only review and completion (role re-checked in the service), CSRF on every
  POST, amounts only from payment records, reference + proof required (PHP, DB CHECK), a reference can
  complete only one refund (unique key), the requester can never approve/complete their own refund
  (trigger), one live refund per payment (unique key), refund rows cannot be deleted, a payment becomes
  `refunded` only through a completed refund and never goes back, a cancelled registration and an
  invalidated QR cannot be reinstated (triggers). Refund proof files are admin-only. Every step is in
  `finance_status_history` (`payment_refund`), `payment_history` and the activity log.

## Scheduler

`scripts/process_event_payouts.php` — run from Windows Task Scheduler, e.g. every 15 minutes:

```
Program:   C:\xampp\php\php.exe
Arguments: "C:\xampp\htdocs\EVORA SYSTEM\scripts\process_event_payouts.php"
```

`--dry-run` lists what would happen without changing anything. Admins can also press **Run Now** on
the Organizer Payout Requests page. Safe to run repeatedly: closing is idempotent, the database
allows one open request per event, entries already in a request are never requested again, and a
MySQL named lock refuses a second concurrent run.

## Edge cases

- **Late payments** (a checkout paid after closing) and **reversals of already-paid entries** go
  into the event's next payout request (`PR-<event>-2`, …) once no other request is open. A negative
  balance is carried, never requested.
- **Rejected request**: its entries are released and automatic requests for that event are paused
  until an admin resumes them (with a note of what was fixed).
- **Cancelled/removed event** or **suspended organizer**: entries go on hold; requests cannot be approved.
- **Fee not yet known** (PayMongo did not report it): the entry is on hold and excluded until
  `scripts/ledger_backfill.php --resolve-fees` verifies the fee.

## Removed / retired components

- v26 organizer-owned PayMongo connection — removed: `PaymentModeService`, `PaymentAccountService`,
  organizer Payment Settings page, admin Payment Connections page, Account-ID routing, per-account
  webhooks, payment-mode columns. Its few configuration rows are kept read-only in
  `archive_v26_organizer_payment_accounts` and `archive_v26_organizer_payment_mode_history`.
- v25 manual settlements — retired: `SettlementService`, admin Settlements pages, v25 payout
  actions, organizer settlement view. The `settlements` and `payouts` tables were empty; they are
  kept for history and new settlements are blocked.

## Backup and rollback

- Database backup (before v27): `C:\xampp\evora_backups\evora_db_before_payment_flow_revision_20260930_174810.sql`
- Code backup (before v27): `C:\xampp\evora_backups\rollback_v27_originals\`
- Procedure: see the header of `database/payment_flow_revision_rollback.sql`.
- v28 (payout proof & admin detail access): DB `C:\xampp\evora_backups\evora_db_before_payout_proof_20260930_205931.sql`,
  code `C:\xampp\evora_backups\rollback_v28_originals\`, procedure in `database/payout_proof_rollback.sql`.
- v29 (multiple payout accounts): DB `C:\xampp\evora_backups\evora_db_before_multi_payout_accounts_20260930_211537.sql`,
  code `C:\xampp\evora_backups\rollback_v29_originals\`, procedure in `database/multi_payout_accounts_rollback.sql`.
- v30.1 (refund management): DB `C:\xampp\evora_backups\evora_db_before_refund_module_20260930_213434.sql`,
  code `C:\xampp\evora_backups\rollback_v30_originals\`, procedure in `database/refund_management_rollback.sql`.
- v31 (notification center): DB `C:\xampp\evora_backups\evora_db_before_notification_center_20260930_220428.sql`,
  code `C:\xampp\evora_backups\rollback_v31_originals\`, procedure in `database/notification_center_rollback.sql`.

## Known limitations

1. Payouts are **manual transfers**: EVORA has no payout API; the admin sends GCash/bank transfers
   and records the reference.
2. By design, payout happens after registration closes, **before the event takes place**, so the
   organizer can prepare the event. If an event is cancelled after its payout was sent, EVORA refunds
   the participants (Refund All) and records the paid-out amount as a negative balance, but recovering
   that money from the organizer happens outside EVORA.
6. Refunds are **manual**: the admin refunds in the PayMongo dashboard and records the reference;
   PayMongo refund webhooks are still only logged.
3. The optional automatic closing depends on the scheduler (or Run Now); between runs an event past
   its deadline still shows "open", although registration is already refused after the deadline date.
   Events without a deadline are closed only by the organizer.
4. There is no separate registration **start** date; registration opens when the organizer opens
   the approved event.
5. No EVORA commission (deduction = 0), as in v25.
