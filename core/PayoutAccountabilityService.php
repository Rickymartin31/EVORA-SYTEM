<?php

/**
 * Payout accountability layers (separate from the payout lifecycle).
 *
 * The payout request itself keeps its lifecycle and final states
 * (pending → approved → processing → paid | rejected; paid/rejected final).
 * This service adds, next to it:
 *   1. Timeliness  — derived from due_at (set by the database at creation):
 *                    on_time | due_soon | overdue | paid_late | legacy
 *   2. Receipt     — awaiting_confirmation | confirmed_received | no_issue_reported
 *   3. Dispute     — payout_disputes (open | awaiting_organizer | resolved | withdrawn)
 *                    with an append-only event timeline
 *   4. Evidence    — the Payout Record PDF, every export logged
 * plus the versioned Organizer Payout Agreement and the Platform Operator Profile.
 *
 * Nothing here decides who is right in a dispute: it records what each side
 * entered, when, and what EVORA's payout record shows.
 */
class PayoutAccountabilityService
{
    public const DUE_SOON_HOURS = 24;

    public const TIMELINESS = [
        'on_time' => ['On time', 'success'],
        'due_soon' => ['Due soon', 'warning'],
        'overdue' => ['Overdue', 'danger'],
        'paid_on_time' => ['Paid on time', 'success'],
        'paid_late' => ['Paid late', 'warning'],
        'legacy' => ['Created before payout deadlines', 'secondary'],
        'not_applicable' => ['Not applicable (rejected)', 'secondary'],
    ];

    public const RECEIPT = [
        'awaiting_confirmation' => ['Awaiting organizer confirmation', 'warning'],
        'confirmed_received' => ['Confirmed received by organizer', 'success'],
        'no_issue_reported' => ['No issue reported', 'secondary'],
    ];

    public const REASONS = [
        'not_received' => 'Payment not received',
        'wrong_account' => 'Payment was sent to the wrong payout account',
        'incorrect_amount' => 'Amount received is incorrect',
        'reference_clarification' => 'Payment reference/proof needs clarification',
        'other' => 'Other payout problem',
    ];
    /** Reasons that make sense while the payout has not been marked paid yet. */
    public const UNPAID_REASONS = ['not_received', 'other'];

    public const TYPES = [
        'overdue_unpaid' => 'Overdue — not yet paid',
        'not_received_after_paid' => 'Marked paid — organizer reports a problem',
    ];

    public const DISPUTE_STATUS = [
        'open' => ['Open — awaiting administrator', 'warning'],
        'awaiting_organizer' => ['Administrator responded — awaiting organizer', 'info'],
        'resolved' => ['Resolved', 'success'],
        'withdrawn' => ['Withdrawn by organizer', 'secondary'],
    ];

    public const RESOLUTIONS = [
        'confirmed_received' => 'Organizer confirmed the payment was received',
        'payment_corrected' => 'Organizer accepted a payment correction',
    ];

    public const EVENT_LABELS = [
        'dispute_opened' => 'Dispute opened',
        'organizer_message' => 'Organizer message',
        'admin_response' => 'Administrator response',
        'payment_correction' => 'Payment correction recorded',
        'organizer_acceptance' => 'Organizer accepted the resolution',
        'organizer_withdrawal' => 'Organizer withdrew the dispute',
        'resolution' => 'Dispute resolved',
        'attachment_added' => 'Supporting proof added',
        'reference_added' => 'Payment reference added',
        'dispute_reopened' => 'Organizer continued the dispute',
    ];

    /** Neutral wording used in the UI and in every Payout Record. */
    public const NEUTRAL_NOTICE = 'If a payout dispute remains unresolved, you may share this record with a lawyer, government office, or other party of your choice. This record shows what was entered in EVORA and when. It does not decide who is at fault or what the outcome of any claim will be.';

    public const AGREEMENT_TEMPLATE = <<<'TXT'
1. Purpose
This agreement explains how EVORA handles the registration payments collected for your events and how your earnings are released to you as payouts. EVORA maintains records and evidence relating to organizer payouts to support accountability, transparency, and dispute documentation.

2. Scope
This agreement applies to paid events and paid race categories that you create in EVORA after accepting this version. It covers payouts, payout records, payout problem reports, and refunds that affect your earnings. It does not cover free events.

3. Payout eligibility
Participants pay through EVORA's PayMongo account. Each confirmed payment is recorded in EVORA's financial ledger. PayMongo's processing fee is deducted from each payment, and the remainder is recorded as your earnings for that event. A payout can only be sent to a payout account (GCash, Maya or bank) that you registered in EVORA and that has been verified.

4. Payout creation
When registration for your event closes, on the registration deadline or when you close it yourself, EVORA calculates your earnings from the recorded payments and creates a payout request for that amount. The amounts are copied from the ledger and cannot be typed or changed by hand.

5. Payout deadline
Each payout request receives a payout deadline when it is created, based on the payout period configured in EVORA at that time (currently 3 days after the payout request is created). The deadline that applies to a payout is the one shown on that payout and cannot be changed afterwards. If a payout is not marked paid by its deadline, EVORA shows it as overdue to you and to the administrator. A later change to the configured period applies only to payout requests created after the change.

6. Payout processing
The administrator reviews the payout request, approves it, and sends the payout outside EVORA by GCash, Maya or bank transfer to your verified payout account. The amount and the destination account are fixed when the payout is approved and cannot be changed afterwards. A paid or rejected payout request is final.

7. Payout evidence
When a payout is marked paid, the administrator must record the transfer reference and upload proof of the transfer. EVORA stores a SHA-256 hash of the proof file at that moment. The hash shows whether the stored file has changed later; it does not show whether the contents of the proof are true.

8. Organizer receipt confirmation
After a payout is marked paid, please check that you received the correct amount in the correct account and use "Confirm Received" in EVORA. If you do not confirm and do not report a problem within the reporting period, EVORA shows "No issue reported". This is not recorded as a confirmation of receipt.

9. Reporting a payout problem
You may use "Report a Problem" when (a) a payout is overdue and has not been marked paid, or (b) EVORA records a payout as paid but you report that the funds were not received, were sent to the wrong account, or were not the correct amount. For paid payouts, the reporting period is the period configured in EVORA (currently 30 days after the payout is marked paid) and is shown on your payout page. Only one problem report can be open for a payout at a time. When you report a problem about a paid payout, the transfer reference and proof of transfer become visible to you.

10. Dispute process
A problem report becomes a dispute with its own timeline. The administrator may respond, ask for clarification, add a transfer reference or proof, or record a payment correction with a new transfer reference and proof. A correction is added as a new entry; the original payout record is never edited. You may accept the resolution, continue the dispute, or withdraw it. Every message, reference and attachment is kept in the timeline and cannot be edited or deleted. EVORA records each side's entries; it does not decide who is right.

11. Administrator response timeline
The administrator is asked to respond to a dispute within the response period configured in EVORA (currently 3 days after the dispute is opened or continued). The response deadline is shown on the dispute. This is an EVORA administrative response timeline, not a promise that the dispute will be resolved within that period. If the response deadline passes without a response, EVORA shows "No response from administrator". This label describes the timeline only and is not a finding of fault.

12. Refunds and payout reversals
A participant refund cannot be completed while that payment is part of an open payout request. If a participant is refunded after the related payout was marked paid, EVORA records a reversal of those earnings in the ledger. The reversed amount is deducted from a later payout for the same event, if one is created. A negative balance is never requested as a payout. If no later payout is available, the amount remains outstanding in EVORA's records, you are notified, and it is to be returned to the platform operator. EVORA does not collect such amounts automatically and does not add fees or penalties.

13. Payout records and evidence
You can download a Payout Record for any of your own payouts at any time without the administrator's approval. It includes the payout amount and calculation, the destination snapshot, the payout deadline and timeliness, the status history, the transfer reference and proof hash where applicable, any dispute timeline, the agreement version you accepted, and the platform operator information. Each generated record is logged with a hash of its contents. You cannot see another organizer's payouts, disputes or evidence, and other organizers cannot see yours.

14. Record retention
Payout requests, status history, dispute timelines, agreement versions, acceptance records, operator profile versions and Payout Record export logs are kept in EVORA and cannot be edited or deleted through the system.

15. Platform operator information
The platform operator that holds collected payments and sends payouts is identified in the Platform Operator Profile shown with this agreement. Each change to that profile is kept as a new version, and every Payout Record shows the operator information.

16. Escalation
Raise payout problems first through "Report a Problem" in EVORA so that they are recorded in the dispute timeline. If an escalation contact is listed in the Platform Operator Profile, you may also contact it. If a payout dispute remains unresolved, you and the platform operator may use the available records and evidence for appropriate external review or professional advice.

17. Limitations of the system
EVORA's records document system activity and do not by themselves determine legal fault or liability. EVORA does not send payouts automatically; payouts are sent by the administrator outside EVORA. EVORA cannot confirm that a transfer reached your account; only you and your payment provider can. This agreement describes how EVORA works and is not legal advice.

18. Agreement acceptance
You accept this agreement in EVORA before creating paid events or paid race categories. EVORA records the agreement version, the date and time, your user account, your IP address and browser, and a SHA-256 hash of the agreement text. Acceptance records cannot be edited or deleted.

19. Effective date
This version takes effect on the effective date shown with it in EVORA.

20. Version information
This is version 1.0 of the Organizer Payout Agreement. A published version is never edited. Any change is published as a new version with its own text hash, earlier versions and acceptances are kept, and you will be asked to accept the new version before creating new paid events.
TXT;

    private static ?array $settingsCache = null;

    // =====================================================================
    // Settings
    // =====================================================================

    public static function settings(PDO $db): array
    {
        if (self::$settingsCache === null) {
            $row = $db->query('SELECT payout_sla_days, dispute_window_days, admin_response_days FROM system_settings ORDER BY id LIMIT 1')->fetch() ?: [];
            self::$settingsCache = [
                'payout_sla_days' => (int) ($row['payout_sla_days'] ?? 3),
                'dispute_window_days' => (int) ($row['dispute_window_days'] ?? 30),
                'admin_response_days' => (int) ($row['admin_response_days'] ?? 3),
            ];
        }

        return self::$settingsCache;
    }

    public static function saveSettings(PDO $db, int $adminId, int $sla, int $window, int $response): void
    {
        if ($sla < 1 || $sla > 60 || $window < 1 || $window > 365 || $response < 1 || $response > 30) {
            throw new DomainException('Payout deadline must be 1–60 days, reporting period 1–365 days, and admin response period 1–30 days.');
        }
        $db->prepare('UPDATE system_settings SET payout_sla_days = :sla, dispute_window_days = :win, admin_response_days = :resp, updated_by = :by WHERE id = (SELECT id FROM (SELECT MIN(id) id FROM system_settings) t)')
            ->execute(['sla' => $sla, 'win' => $window, 'resp' => $response, 'by' => $adminId]);
        self::$settingsCache = null;
        Logger::log($adminId, 'payout_accountability_settings', "Payout deadline {$sla} day(s), reporting period {$window} day(s), admin response {$response} day(s)");
    }

    // =====================================================================
    // 1. Timeliness (derived — no scheduler needed)
    // =====================================================================

    /** @return array{code:string,label:string,badge:string,due_at:?string} */
    public static function timeliness(array $request, ?int $now = null): array
    {
        $now ??= time();
        $due = $request['due_at'] ?? null;
        $status = $request['status'];
        if ($status === 'rejected') {
            $code = 'not_applicable';
        } elseif ($due === null) {
            $code = 'legacy';
        } elseif ($status === 'paid') {
            $code = strtotime((string) $request['paid_at']) > strtotime($due) ? 'paid_late' : 'paid_on_time';
        } elseif ($now > strtotime($due)) {
            $code = 'overdue';
        } elseif (strtotime($due) - $now <= self::DUE_SOON_HOURS * 3600) {
            $code = 'due_soon';
        } else {
            $code = 'on_time';
        }
        [$label, $badge] = self::TIMELINESS[$code];

        return ['code' => $code, 'label' => $label, 'badge' => $badge, 'due_at' => $due];
    }

    public static function timelinessBadge(array $request): string
    {
        $t = self::timeliness($request);

        return '<span class="badge bg-' . $t['badge'] . '">' . e($t['label']) . '</span>';
    }

    // =====================================================================
    // 2. Receipt
    // =====================================================================

    public static function receiptResponse(PDO $db, int $requestId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payout_receipt_responses WHERE payout_request_id = :id AND response = "received"');
        $stmt->execute(['id' => $requestId]);

        return $stmt->fetch() ?: null;
    }

    /** Reporting window end for a paid payout (paid_at + dispute_window_days). */
    public static function windowEndsAt(PDO $db, array $request): ?int
    {
        if ($request['status'] !== 'paid' || empty($request['paid_at'])) {
            return null;
        }

        return strtotime($request['paid_at']) + self::settings($db)['dispute_window_days'] * 86400;
    }

    /** @return array{code:string,label:string,badge:string,window_ends_at:?int}|null  (null when not paid) */
    public static function receiptState(PDO $db, array $request, ?int $now = null): ?array
    {
        if ($request['status'] !== 'paid') {
            return null;
        }
        $now ??= time();
        $windowEnd = self::windowEndsAt($db, $request);
        if (self::receiptResponse($db, (int) $request['id'])) {
            $code = 'confirmed_received';
        } elseif (!self::openDispute($db, (int) $request['id']) && $windowEnd !== null && $now > $windowEnd) {
            $code = 'no_issue_reported';
        } else {
            $code = 'awaiting_confirmation';
        }
        [$label, $badge] = self::RECEIPT[$code];

        return ['code' => $code, 'label' => $label, 'badge' => $badge, 'window_ends_at' => $windowEnd];
    }

    // =====================================================================
    // 3. Disputes — reading
    // =====================================================================

    public static function openDispute(PDO $db, int $requestId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payout_disputes WHERE open_request_key = :id');
        $stmt->execute(['id' => $requestId]);

        return $stmt->fetch() ?: null;
    }

    public static function disputesFor(PDO $db, int $requestId): array
    {
        $stmt = $db->prepare('SELECT * FROM payout_disputes WHERE payout_request_id = :id ORDER BY id DESC');
        $stmt->execute(['id' => $requestId]);

        return $stmt->fetchAll();
    }

    public static function findDispute(PDO $db, int $disputeId, ?int $organizerId = null): ?array
    {
        $sql = 'SELECT d.*, pr.request_no, pr.amount, pr.status AS payout_status, pr.due_at, pr.paid_at, pr.event_id,
                       pr.method, pr.account_name_snapshot, pr.account_number_masked, pr.bank_name_snapshot,
                       pr.reference_number, pr.proof_path, pr.proof_sha256,
                       e.name AS event_name, o.organization_name, o.user_id AS organizer_user_id, ou.full_name AS opened_by_name
                FROM payout_disputes d
                JOIN organizer_payout_requests pr ON pr.id = d.payout_request_id
                JOIN events e ON e.id = pr.event_id
                JOIN organizers o ON o.id = d.organizer_id
                JOIN users ou ON ou.id = d.opened_by
                WHERE d.id = :id' . ($organizerId !== null ? ' AND d.organizer_id = :oid' : '');
        $params = ['id' => $disputeId];
        if ($organizerId !== null) {
            $params['oid'] = $organizerId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    public static function events(PDO $db, int $disputeId): array
    {
        $stmt = $db->prepare('SELECT ev.*, u.full_name AS actor_name FROM payout_dispute_events ev JOIN users u ON u.id = ev.actor_user_id
                              WHERE ev.dispute_id = :id ORDER BY ev.id');
        $stmt->execute(['id' => $disputeId]);

        return $stmt->fetchAll();
    }

    /** "No response from administrator": open, current response deadline passed. Derived, never stored. */
    public static function noAdminResponse(array $dispute, ?int $now = null): bool
    {
        return $dispute['status'] === 'open' && ($now ?? time()) > strtotime($dispute['admin_response_due_at']);
    }

    public static function disputeBadge(array $dispute): string
    {
        [$label, $badge] = self::DISPUTE_STATUS[$dispute['status']] ?? [ucfirst($dispute['status']), 'secondary'];
        $html = '<span class="badge bg-' . $badge . '">' . e($label) . '</span>';
        if (self::noAdminResponse($dispute)) {
            $html .= ' <span class="badge bg-danger">No response from administrator</span>';
        }

        return $html;
    }

    public static function listDisputes(PDO $db, string $filter = ''): array
    {
        $where = match ($filter) {
            'open' => 'd.status IN ("open","awaiting_organizer")',
            'awaiting_admin' => 'd.status = "open"',
            'no_response' => 'd.status = "open" AND d.admin_response_due_at < NOW()',
            'closed' => 'd.status IN ("resolved","withdrawn")',
            default => '1=1',
        };
        $stmt = $db->query(
            'SELECT d.*, pr.request_no, pr.amount, pr.status AS payout_status, e.name AS event_name, o.organization_name
             FROM payout_disputes d
             JOIN organizer_payout_requests pr ON pr.id = d.payout_request_id
             JOIN events e ON e.id = pr.event_id
             JOIN organizers o ON o.id = d.organizer_id
             WHERE ' . $where . ' ORDER BY (d.status IN ("open","awaiting_organizer")) DESC, d.admin_response_due_at, d.id DESC'
        );

        return $stmt->fetchAll();
    }

    /** Dashboard metrics for the admin payout area. */
    public static function adminMetrics(PDO $db): array
    {
        $m = $db->query(
            'SELECT
                SUM(status IN ("pending","approved","processing")) AS pending,
                SUM(status IN ("pending","approved","processing") AND due_at IS NOT NULL AND due_at >= NOW() AND due_at <= DATE_ADD(NOW(), INTERVAL ' . self::DUE_SOON_HOURS . ' HOUR)) AS due_soon,
                SUM(status IN ("pending","approved","processing") AND due_at IS NOT NULL AND due_at < NOW()) AS overdue,
                SUM(status = "paid") AS paid
             FROM organizer_payout_requests'
        )->fetch();
        $d = $db->query('SELECT SUM(status IN ("open","awaiting_organizer")) AS open_disputes, SUM(status = "open") AS awaiting_admin,
                                SUM(status = "open" AND admin_response_due_at < NOW()) AS no_response FROM payout_disputes')->fetch();

        return array_map('intval', array_merge($m ?: [], $d ?: []));
    }

    /** Whether the organizer may report a problem now, and as which dispute type. @return array{0:bool,1:?string,2:string} */
    public static function canReport(PDO $db, array $request, ?int $now = null): array
    {
        $now ??= time();
        if (self::openDispute($db, (int) $request['id'])) {
            return [false, null, 'A dispute is already open for this payout.'];
        }
        if ($request['status'] === 'paid') {
            $end = self::windowEndsAt($db, $request);
            if ($end !== null && $now > $end) {
                return [false, null, 'The reporting period for this payout ended on ' . date('M d, Y', $end) . '.'];
            }

            return [true, 'not_received_after_paid', ''];
        }
        if (in_array($request['status'], ['pending', 'approved', 'processing'], true)) {
            if ($request['due_at'] === null || $now <= strtotime($request['due_at'])) {
                return [false, null, $request['due_at'] === null ? 'This payout was created before payout deadlines existed.' : 'You can report a problem if the payout is not paid by ' . date('M d, Y g:i A', strtotime($request['due_at'])) . '.'];
            }

            return [true, 'overdue_unpaid', ''];
        }

        return [false, null, 'Rejected payouts cannot be disputed here.'];
    }

    /** The organizer may view the transfer proof once a dispute exists for a paid payout. */
    public static function organizerCanSeeProof(PDO $db, array $request): bool
    {
        if ($request['status'] !== 'paid' || empty($request['proof_path'])) {
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM payout_disputes WHERE payout_request_id = :id AND type = "not_received_after_paid" LIMIT 1');
        $stmt->execute(['id' => $request['id']]);

        return (bool) $stmt->fetchColumn();
    }

    // =====================================================================
    // 3. Disputes — organizer actions
    // =====================================================================

    /** Locks the payout and returns it with its organizer's user id; throws unless the user owns it. */
    private static function lockOwnedRequest(PDO $db, int $requestId, int $organizerUserId): array
    {
        $stmt = $db->prepare('SELECT pr.*, o.user_id AS organizer_user_id, e.name AS event_name
                              FROM organizer_payout_requests pr JOIN organizers o ON o.id = pr.organizer_id JOIN events e ON e.id = pr.event_id
                              WHERE pr.id = :id FOR UPDATE');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();
        if (!$request || (int) $request['organizer_user_id'] !== $organizerUserId) {
            throw new DomainException('Payout not found.');
        }

        return $request;
    }

    public static function confirmReceived(PDO $db, int $requestId, int $organizerUserId, ?string $ip): void
    {
        $db->beginTransaction();
        try {
            $request = self::lockOwnedRequest($db, $requestId, $organizerUserId);
            if ($request['status'] !== 'paid') {
                throw new DomainException('You can confirm receipt once the payout is marked paid.');
            }
            if (self::openDispute($db, $requestId)) {
                throw new DomainException('A dispute is open for this payout. Accept the administrator\'s resolution or withdraw the dispute instead.');
            }
            if (self::receiptResponse($db, $requestId)) {
                throw new DomainException('You already confirmed receiving this payout.');
            }
            $db->prepare('INSERT INTO payout_receipt_responses (payout_request_id, organizer_user_id, response, ip_address) VALUES (:id, :uid, "received", :ip)')
                ->execute(['id' => $requestId, 'uid' => $organizerUserId, 'ip' => $ip ? mb_substr($ip, 0, 45) : null]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        Logger::log($organizerUserId, 'payout_receipt_confirmed', 'Confirmed receiving payout ' . $request['request_no']);
    }

    public static function openNewDispute(PDO $db, int $requestId, int $organizerUserId, string $reasonCode, string $statement, ?string $ip): int
    {
        $statement = trim($statement);
        if (!isset(self::REASONS[$reasonCode])) {
            throw new DomainException('Choose what the problem is.');
        }
        if (mb_strlen($statement) < 10 || mb_strlen($statement) > 2000) {
            throw new DomainException('Describe the problem in 10–2000 characters.');
        }
        $db->beginTransaction();
        try {
            $request = self::lockOwnedRequest($db, $requestId, $organizerUserId);
            [$allowed, $type, $why] = self::canReport($db, $request);
            if (!$allowed) {
                throw new DomainException($why);
            }
            if ($type === 'overdue_unpaid' && !in_array($reasonCode, self::UNPAID_REASONS, true)) {
                throw new DomainException('This payout has not been marked paid yet. Choose "Payment not received" or "Other payout problem".');
            }
            $days = self::settings($db)['admin_response_days'];
            $insert = $db->prepare(
                'INSERT INTO payout_disputes (dispute_no, payout_request_id, organizer_id, opened_by, type, reason_code, admin_response_due_at)
                 VALUES (:no, :rid, :oid, :uid, :type, :reason, DATE_ADD(NOW(), INTERVAL :days DAY))'
            );
            $disputeId = null;
            for ($attempt = 0; $attempt < 3 && $disputeId === null; $attempt++) {
                try {
                    $insert->execute([
                        'no' => 'PD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), 'rid' => $requestId,
                        'oid' => $request['organizer_id'], 'uid' => $organizerUserId, 'type' => $type, 'reason' => $reasonCode, 'days' => $days,
                    ]);
                    $disputeId = (int) $db->lastInsertId();
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_one_open_dispute_per_payout')) {
                        throw new DomainException('A dispute is already open for this payout.');
                    }
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
            if ($disputeId === null) {
                throw new RuntimeException('Could not create a dispute number. Please try again.');
            }
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'dispute_opened', self::REASONS[$reasonCode] . ': ' . $statement);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $dispute = self::findDispute($db, $disputeId);
        Logger::log($organizerUserId, 'payout_dispute_opened', $dispute['dispute_no'] . ' for payout ' . $request['request_no'] . ' (' . $type . ')');
        NotificationService::toAdmins($db, 'Payout Dispute Opened',
            $dispute['dispute_no'] . ': the organizer reported a problem with payout ' . $request['request_no'] . ' (' . currency($request['amount']) . ', ' . $request['event_name'] . '). Please respond by ' . date('M d, Y g:i A', strtotime($dispute['admin_response_due_at'])) . '.',
            'payout_dispute', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_dispute', 'reference_id' => $disputeId]);
        PayoutRequestService::notifyOrganizer($db, (int) $request['organizer_id'], 'Payout Dispute Recorded',
            'Your report for payout ' . $request['request_no'] . ' was recorded as ' . $dispute['dispute_no'] . '. The administrator is asked to respond by ' . date('M d, Y g:i A', strtotime($dispute['admin_response_due_at'])) . '.',
            ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $requestId], 'payout_dispute');

        return $disputeId;
    }

    /** Locks a dispute the organizer owns. */
    private static function lockOwnedDispute(PDO $db, int $disputeId, int $organizerUserId): array
    {
        $stmt = $db->prepare('SELECT d.*, o.user_id AS organizer_user_id FROM payout_disputes d JOIN organizers o ON o.id = d.organizer_id WHERE d.id = :id FOR UPDATE');
        $stmt->execute(['id' => $disputeId]);
        $dispute = $stmt->fetch();
        if (!$dispute || (int) $dispute['organizer_user_id'] !== $organizerUserId) {
            throw new DomainException('Dispute not found.');
        }

        return $dispute;
    }

    public static function acceptResolution(PDO $db, int $disputeId, int $organizerUserId, ?string $ip): string
    {
        $db->beginTransaction();
        try {
            $dispute = self::lockOwnedDispute($db, $disputeId, $organizerUserId);
            if ($dispute['status'] !== 'awaiting_organizer') {
                throw new DomainException('There is no administrator response to accept right now.');
            }
            $reqStmt = $db->prepare('SELECT * FROM organizer_payout_requests WHERE id = :id FOR UPDATE');
            $reqStmt->execute(['id' => $dispute['payout_request_id']]);
            $request = $reqStmt->fetch();
            $correction = $db->prepare('SELECT 1 FROM payout_dispute_events WHERE dispute_id = :id AND event_type = "payment_correction" LIMIT 1');
            $correction->execute(['id' => $disputeId]);
            $resolution = $correction->fetchColumn() ? 'payment_corrected' : 'confirmed_received';
            if ($resolution === 'confirmed_received' && $request['status'] !== 'paid') {
                throw new DomainException('This payout is not marked paid yet. You can continue the dispute or withdraw it.');
            }
            $db->prepare('UPDATE payout_disputes SET status = "resolved", resolution = :res, closed_at = NOW() WHERE id = :id')
                ->execute(['res' => $resolution, 'id' => $disputeId]);
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'organizer_acceptance', 'Organizer accepted the administrator\'s resolution.');
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'resolution', self::RESOLUTIONS[$resolution] . '.');
            if ($request['status'] === 'paid' && !self::receiptResponse($db, (int) $request['id'])) {
                $db->prepare('INSERT INTO payout_receipt_responses (payout_request_id, organizer_user_id, response, ip_address) VALUES (:id, :uid, "received", :ip)')
                    ->execute(['id' => $request['id'], 'uid' => $organizerUserId, 'ip' => $ip ? mb_substr($ip, 0, 45) : null]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $dispute = self::findDispute($db, $disputeId);
        Logger::log($organizerUserId, 'payout_dispute_resolved', $dispute['dispute_no'] . ' resolved: ' . $resolution);
        NotificationService::toAdmins($db, 'Organizer Accepted Resolution',
            $dispute['dispute_no'] . ' (payout ' . $dispute['request_no'] . '): ' . self::RESOLUTIONS[$resolution] . '.',
            'payout_dispute', ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_dispute', 'reference_id' => $disputeId]);

        return $resolution;
    }

    public static function continueDispute(PDO $db, int $disputeId, int $organizerUserId, string $message): void
    {
        $message = trim($message);
        if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
            throw new DomainException('Explain why the problem is not resolved (10–2000 characters).');
        }
        $db->beginTransaction();
        try {
            $dispute = self::lockOwnedDispute($db, $disputeId, $organizerUserId);
            if ($dispute['status'] !== 'awaiting_organizer') {
                throw new DomainException('This dispute is not waiting for your answer.');
            }
            $db->prepare('UPDATE payout_disputes SET status = "open", admin_response_due_at = DATE_ADD(NOW(), INTERVAL :days DAY) WHERE id = :id')
                ->execute(['days' => self::settings($db)['admin_response_days'], 'id' => $disputeId]);
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'dispute_reopened', $message);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $dispute = self::findDispute($db, $disputeId);
        Logger::log($organizerUserId, 'payout_dispute_continued', $dispute['dispute_no']);
        NotificationService::toAdmins($db, 'Payout Dispute Continued',
            $dispute['dispute_no'] . ' (payout ' . $dispute['request_no'] . '): the organizer did not accept the response. Please respond by ' . date('M d, Y g:i A', strtotime($dispute['admin_response_due_at'])) . '.',
            'payout_dispute', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_dispute', 'reference_id' => $disputeId]);
    }

    public static function addOrganizerMessage(PDO $db, int $disputeId, int $organizerUserId, string $message): void
    {
        $message = trim($message);
        if (mb_strlen($message) < 2 || mb_strlen($message) > 2000) {
            throw new DomainException('Write a message of up to 2000 characters.');
        }
        $db->beginTransaction();
        try {
            $dispute = self::lockOwnedDispute($db, $disputeId, $organizerUserId);
            if (!in_array($dispute['status'], ['open', 'awaiting_organizer'], true)) {
                throw new DomainException('This dispute is closed.');
            }
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'organizer_message', $message);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function withdraw(PDO $db, int $disputeId, int $organizerUserId, string $message): void
    {
        $db->beginTransaction();
        try {
            $dispute = self::lockOwnedDispute($db, $disputeId, $organizerUserId);
            if (!in_array($dispute['status'], ['open', 'awaiting_organizer'], true)) {
                throw new DomainException('This dispute is already closed.');
            }
            $db->prepare('UPDATE payout_disputes SET status = "withdrawn", closed_at = NOW() WHERE id = :id')->execute(['id' => $disputeId]);
            self::addEvent($db, $disputeId, $organizerUserId, 'organizer', 'organizer_withdrawal', trim($message) !== '' ? mb_substr(trim($message), 0, 2000) : 'Withdrawn by the organizer.');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $dispute = self::findDispute($db, $disputeId);
        Logger::log($organizerUserId, 'payout_dispute_withdrawn', $dispute['dispute_no']);
        NotificationService::toAdmins($db, 'Payout Dispute Withdrawn',
            $dispute['dispute_no'] . ' (payout ' . $dispute['request_no'] . ') was withdrawn by the organizer.',
            'payout_dispute', ['category' => 'payout', 'notice_type' => 'informational', 'reference_type' => 'payout_dispute', 'reference_id' => $disputeId]);
    }

    // =====================================================================
    // 3. Disputes — administrator actions
    // =====================================================================

    public const ADMIN_ACTIONS = [
        'response' => 'Respond',
        'clarification' => 'Add clarification',
        'correction' => 'Record a payment correction',
        'reference' => 'Add payment reference',
        'proof' => 'Add supporting proof',
    ];

    public static function adminAct(PDO $db, int $disputeId, int $adminId, string $action, string $message, string $reference, ?string $attachmentPath): void
    {
        $message = trim($message);
        $reference = trim($reference);
        if (!isset(self::ADMIN_ACTIONS[$action])) {
            throw new DomainException('Unknown action.');
        }
        if (in_array($action, ['response', 'clarification'], true) && (mb_strlen($message) < 10 || mb_strlen($message) > 2000)) {
            throw new DomainException('Write a response of 10–2000 characters.');
        }
        if (in_array($action, ['correction', 'reference'], true) && !preg_match('/^[A-Za-z0-9 \-_.\/#]{4,100}$/', $reference)) {
            throw new DomainException('Enter the GCash/Maya/bank transfer reference number (4–100 letters, digits, - _ . / #).');
        }
        if (in_array($action, ['correction', 'proof'], true) && ($attachmentPath === null || $attachmentPath === '')) {
            throw new DomainException('Upload the proof of transfer (JPG, PNG or PDF).');
        }
        if ($action === 'correction' && mb_strlen($message) < 10) {
            throw new DomainException('Describe the correction (at least 10 characters), e.g. the amount and account it was resent to.');
        }
        $hash = null;
        if ($attachmentPath !== null && $attachmentPath !== '') {
            $hash = hash_file('sha256', STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $attachmentPath)) ?: null;
            if ($hash === null) {
                throw new DomainException('The uploaded file could not be read.');
            }
        }
        $eventType = ['response' => 'admin_response', 'clarification' => 'admin_response', 'correction' => 'payment_correction', 'reference' => 'reference_added', 'proof' => 'attachment_added'][$action];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT d.*, o.user_id AS organizer_user_id FROM payout_disputes d JOIN organizers o ON o.id = d.organizer_id WHERE d.id = :id FOR UPDATE');
            $stmt->execute(['id' => $disputeId]);
            $dispute = $stmt->fetch();
            if (!$dispute) {
                throw new DomainException('Dispute not found.');
            }
            if (!in_array($dispute['status'], ['open', 'awaiting_organizer'], true)) {
                throw new DomainException('This dispute is closed.');
            }
            $admin = $db->prepare('SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND r.name = "admin" AND u.status = "active"');
            $admin->execute(['id' => $adminId]);
            if (!$admin->fetchColumn()) {
                throw new DomainException('Only an active administrator can respond to disputes.');
            }
            if ((int) $dispute['organizer_user_id'] === $adminId) {
                throw new DomainException('You cannot respond to a dispute about your own organizer payout.');
            }
            self::addEvent($db, $disputeId, $adminId, 'admin', $eventType, $message !== '' ? $message : null, $attachmentPath ?: null, $hash, $reference !== '' ? $reference : null);
            $db->prepare('UPDATE payout_disputes SET status = "awaiting_organizer", first_admin_response_at = COALESCE(first_admin_response_at, NOW()) WHERE id = :id')
                ->execute(['id' => $disputeId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $dispute = self::findDispute($db, $disputeId);
        Logger::log($adminId, 'payout_dispute_admin_' . $action, $dispute['dispute_no'] . ($reference !== '' ? ' ref ' . PayoutService::maskReference($reference) : ''));
        PayoutRequestService::notifyOrganizer($db, (int) $dispute['organizer_id'], 'Administrator Responded to Your Dispute',
            $dispute['dispute_no'] . ' (payout ' . $dispute['request_no'] . '): ' . self::ADMIN_ACTIONS[$action] . '. Review it and accept the resolution or continue the dispute.',
            ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_request', 'reference_id' => $dispute['payout_request_id']], 'payout_dispute');
    }

    private static function addEvent(PDO $db, int $disputeId, int $actorId, string $role, string $type, ?string $message, ?string $attachment = null, ?string $hash = null, ?string $reference = null): void
    {
        $db->prepare('INSERT INTO payout_dispute_events (dispute_id, actor_user_id, actor_role, event_type, message, attachment_path, attachment_sha256, reference_number)
                      VALUES (:d, :a, :r, :t, :m, :p, :h, :ref)')
            ->execute(['d' => $disputeId, 'a' => $actorId, 'r' => $role, 't' => $type, 'm' => $message !== null ? mb_substr($message, 0, 4000) : null,
                       'p' => $attachment, 'h' => $hash, 'ref' => $reference]);
    }

    // =====================================================================
    // Notifications without a scheduler (deduplicated)
    // =====================================================================

    /** Sends a notification once per (user, title, reference, message). */
    public static function notifyOnce(PDO $db, int $userId, string $title, string $message, string $type, array $meta): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM notifications WHERE user_id = :u AND title = :t AND reference_type <=> :rt AND reference_id <=> :rid AND message = :m LIMIT 1');
        $stmt->execute(['u' => $userId, 't' => mb_substr($title, 0, 200), 'rt' => $meta['reference_type'] ?? null, 'rid' => $meta['reference_id'] ?? null, 'm' => mb_substr($message, 0, 500)]);
        if ($stmt->fetchColumn()) {
            return false;
        }
        NotificationService::create($db, $userId, $title, $message, $type, $meta);

        return true;
    }

    /**
     * Raises due-soon / overdue / response-deadline alerts from the timestamps.
     * Called when payout and dispute pages open (and by the scheduler script);
     * the truth always comes from due_at / admin_response_due_at, not from this.
     */
    public static function sweepAlerts(PDO $db): int
    {
        if ((int) $db->query('SELECT GET_LOCK("evora_payout_alerts", 0)')->fetchColumn() !== 1) {
            return 0;
        }
        $sent = 0;
        try {
            $admins = $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" AND u.status = "active"')->fetchAll(PDO::FETCH_COLUMN);
            $rows = $db->query(
                'SELECT pr.id, pr.request_no, pr.amount, pr.due_at, pr.organizer_id, o.user_id AS organizer_user_id, e.name AS event_name,
                        (pr.due_at < NOW()) AS is_overdue
                 FROM organizer_payout_requests pr JOIN organizers o ON o.id = pr.organizer_id JOIN events e ON e.id = pr.event_id
                 WHERE pr.status IN ("pending","approved","processing") AND pr.due_at IS NOT NULL
                   AND pr.due_at <= DATE_ADD(NOW(), INTERVAL ' . self::DUE_SOON_HOURS . ' HOUR)'
            )->fetchAll();
            foreach ($rows as $r) {
                $due = date('M d, Y g:i A', strtotime($r['due_at']));
                $title = $r['is_overdue'] ? 'Payout Overdue' : 'Payout Due Soon';
                $meta = ['category' => 'payout', 'notice_type' => $r['is_overdue'] ? 'action_required' : 'status', 'reference_type' => 'payout_request', 'reference_id' => (int) $r['id']];
                $orgMsg = 'Payout ' . $r['request_no'] . ' (' . currency($r['amount']) . ', ' . $r['event_name'] . ($r['is_overdue'] ? ') was due on ' . $due . ' and is not marked paid yet. You can use Report a Problem on the payout page.' : ') is due by ' . $due . '.');
                $sent += (int) self::notifyOnce($db, (int) $r['organizer_user_id'], $title, $orgMsg, 'payout_timeliness', $meta);
                $adminMsg = 'Payout ' . $r['request_no'] . ' (' . currency($r['amount']) . ', ' . $r['event_name'] . ($r['is_overdue'] ? ') passed its deadline of ' . $due . '.' : ') is due by ' . $due . '.');
                foreach ($admins as $adminId) {
                    $sent += (int) self::notifyOnce($db, (int) $adminId, $title, $adminMsg, 'payout_timeliness', ['notice_type' => 'action_required'] + $meta);
                }
            }
            $disputes = $db->query(
                'SELECT d.id, d.dispute_no, d.payout_request_id, d.admin_response_due_at, o.user_id AS organizer_user_id, pr.request_no
                 FROM payout_disputes d JOIN organizers o ON o.id = d.organizer_id JOIN organizer_payout_requests pr ON pr.id = d.payout_request_id
                 WHERE d.status = "open" AND d.admin_response_due_at > NOW() AND d.admin_response_due_at <= DATE_ADD(NOW(), INTERVAL ' . self::DUE_SOON_HOURS . ' HOUR)'
            )->fetchAll();
            foreach ($disputes as $d) {
                $due = date('M d, Y g:i A', strtotime($d['admin_response_due_at']));
                foreach ($admins as $adminId) {
                    $sent += (int) self::notifyOnce($db, (int) $adminId, 'Dispute Response Deadline Approaching',
                        $d['dispute_no'] . ' (payout ' . $d['request_no'] . ') needs your response by ' . $due . '.', 'payout_dispute',
                        ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_dispute', 'reference_id' => (int) $d['id']]);
                }
                $sent += (int) self::notifyOnce($db, (int) $d['organizer_user_id'], 'Dispute Response Deadline Approaching',
                    'The administrator is asked to respond to ' . $d['dispute_no'] . ' by ' . $due . '.', 'payout_dispute',
                    ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => (int) $d['payout_request_id']]);
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK("evora_payout_alerts")');
        }

        return $sent;
    }

    /** After markPaid: asks the organizer to confirm receipt. */
    public static function notifyAwaitingConfirmation(PDO $db, array $request): void
    {
        $orgUser = $db->prepare('SELECT user_id FROM organizers WHERE id = :id');
        $orgUser->execute(['id' => $request['organizer_id']]);
        $days = self::settings($db)['dispute_window_days'];
        self::notifyOnce($db, (int) $orgUser->fetchColumn(), 'Please Confirm Your Payout',
            'Payout ' . $request['request_no'] . ' was marked paid. Check your account, then choose Confirm Received or Report a Problem within ' . $days . ' days.',
            'payout_receipt', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_request', 'reference_id' => (int) $request['id']]);
    }

    // =====================================================================
    // Platform Operator Profile
    // =====================================================================

    public static function operatorProfile(PDO $db): ?array
    {
        return $db->query('SELECT * FROM platform_operator_profile WHERE id = 1')->fetch() ?: null;
    }

    public static function operatorVersion(PDO $db, int $version): ?array
    {
        $stmt = $db->prepare('SELECT * FROM platform_operator_profile_history WHERE version = :v');
        $stmt->execute(['v' => $version]);

        return $stmt->fetch() ?: null;
    }

    public static function operatorHistory(PDO $db): array
    {
        return $db->query('SELECT h.*, u.full_name AS changed_by_name FROM platform_operator_profile_history h JOIN users u ON u.id = h.changed_by ORDER BY h.version DESC')->fetchAll();
    }

    public const OPERATOR_FIELDS = [
        'operator_legal_name' => ['Legal operator name', 200, true],
        'responsible_officer' => ['Responsible officer', 150, true],
        'officer_position' => ['Officer position', 100, true],
        'support_email' => ['Official email', 150, true],
        'support_phone' => ['Official phone', 30, true],
        'official_address' => ['Official address', 300, true],
        'registration_no' => ['Registration number', 60, false],
        'escalation_contact' => ['Escalation contact', 300, false],
    ];
    public const REGISTRATION_TYPES = ['none' => 'Not registered', 'dti' => 'DTI', 'sec' => 'SEC', 'cda' => 'CDA'];

    public static function saveOperatorProfile(PDO $db, int $adminId, array $input): int
    {
        $data = [];
        foreach (self::OPERATOR_FIELDS as $key => [$label, $max, $required]) {
            $v = trim((string) ($input[$key] ?? ''));
            if ($required && $v === '') {
                throw new DomainException($label . ' is required.');
            }
            if (mb_strlen($v) > $max) {
                throw new DomainException($label . ' is too long (max ' . $max . ' characters).');
            }
            $data[$key] = $v === '' ? null : $v;
        }
        if (!filter_var($data['support_email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid official email.');
        }
        if (!preg_match('/^[0-9+\-() ]{7,30}$/', $data['support_phone'])) {
            throw new DomainException('Enter a valid official phone number.');
        }
        $type = (string) ($input['registration_type'] ?? 'none');
        if (!isset(self::REGISTRATION_TYPES[$type])) {
            throw new DomainException('Choose a registration type.');
        }
        if ($type !== 'none' && $data['registration_no'] === null) {
            throw new DomainException('Enter the registration number for the selected registration type.');
        }
        $data['registration_type'] = $type;
        $data['updated_by'] = $adminId;

        $db->beginTransaction();
        try {
            $current = $db->query('SELECT * FROM platform_operator_profile WHERE id = 1 FOR UPDATE')->fetch();
            if (!$current) {
                $cols = array_keys($data);
                $db->prepare('INSERT INTO platform_operator_profile (id, version, ' . implode(', ', $cols) . ') VALUES (1, 1, :' . implode(', :', $cols) . ')')->execute($data);
                $version = 1;
            } else {
                $changed = false;
                foreach ($data as $k => $v) {
                    if ($k !== 'updated_by' && (string) $current[$k] !== (string) $v) {
                        $changed = true;
                    }
                }
                if (!$changed) {
                    $db->commit();
                    return (int) $current['version'];
                }
                $version = (int) $current['version'] + 1;
                $sets = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($data)));
                $db->prepare("UPDATE platform_operator_profile SET $sets, version = :version, updated_at = NOW() WHERE id = 1")->execute($data + ['version' => $version]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        Logger::log($adminId, 'operator_profile_updated', 'Platform operator profile saved as version ' . $version);

        return $version;
    }

    // =====================================================================
    // Organizer Payout Agreement
    // =====================================================================

    public static function currentAgreement(PDO $db): ?array
    {
        return $db->query('SELECT * FROM payout_agreements WHERE effective_at <= NOW() ORDER BY effective_at DESC, id DESC LIMIT 1')->fetch() ?: null;
    }

    public static function agreements(PDO $db): array
    {
        return $db->query('SELECT a.*, u.full_name AS created_by_name, (SELECT COUNT(*) FROM payout_agreement_acceptances x WHERE x.agreement_id = a.id) AS acceptances
                           FROM payout_agreements a JOIN users u ON u.id = a.created_by ORDER BY a.effective_at DESC, a.id DESC')->fetchAll();
    }

    public static function findAgreement(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payout_agreements WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public static function publishAgreement(PDO $db, int $adminId, string $version, string $title, string $body, string $effectiveAt): int
    {
        $version = trim($version);
        $title = trim($title);
        $body = str_replace("\r\n", "\n", trim($body));
        if (!preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $version)) {
            throw new DomainException('Version must be 1–20 letters, digits, dots or dashes (e.g. 1.0).');
        }
        if ($title === '' || mb_strlen($title) > 200) {
            throw new DomainException('Enter a title (up to 200 characters).');
        }
        if (mb_strlen($body) < 200) {
            throw new DomainException('The agreement text is too short.');
        }
        $ts = strtotime($effectiveAt);
        if ($ts === false || $ts < time() - 300) {
            throw new DomainException('The effective date must be now or in the future.');
        }
        $operator = self::operatorProfile($db);
        if (!$operator) {
            throw new DomainException('Save the Platform Operator Profile first — the agreement must name the operator.');
        }
        try {
            $db->prepare('INSERT INTO payout_agreements (version, title, body, body_sha256, operator_profile_version, effective_at, created_by)
                          VALUES (:v, :t, :b, :h, :op, :eff, :by)')
                ->execute(['v' => $version, 't' => $title, 'b' => $body, 'h' => hash('sha256', $body), 'op' => $operator['version'], 'eff' => date('Y-m-d H:i:s', max($ts, time())), 'by' => $adminId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('Version ' . $version . ' already exists. Use a new version number.');
            }
            throw $e;
        }
        $id = (int) $db->lastInsertId();
        Logger::log($adminId, 'payout_agreement_published', 'Published payout agreement version ' . $version);
        NotificationService::toApprovedOrganizers($db, 'New Payout Agreement Available',
            'Organizer Payout Agreement version ' . $version . ' is available (effective ' . date('M d, Y', max($ts, time())) . '). Read and accept it before creating new paid events.',
            'payout_agreement', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_agreement', 'reference_id' => $id]);

        return $id;
    }

    public static function acceptance(PDO $db, int $organizerId, int $agreementId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payout_agreement_acceptances WHERE organizer_id = :o AND agreement_id = :a');
        $stmt->execute(['o' => $organizerId, 'a' => $agreementId]);

        return $stmt->fetch() ?: null;
    }

    public static function organizerAcceptances(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare('SELECT x.*, a.version, a.title FROM payout_agreement_acceptances x JOIN payout_agreements a ON a.id = x.agreement_id WHERE x.organizer_id = :o ORDER BY x.accepted_at DESC');
        $stmt->execute(['o' => $organizerId]);

        return $stmt->fetchAll();
    }

    /** True when a current agreement exists and this organizer has not accepted it. */
    public static function organizerNeedsAgreement(PDO $db, int $organizerId): bool
    {
        $current = self::currentAgreement($db);

        return $current !== null && self::acceptance($db, $organizerId, (int) $current['id']) === null;
    }

    public static function acceptAgreement(PDO $db, int $organizerId, int $userId, int $agreementId, ?string $ip, ?string $userAgent): void
    {
        $current = self::currentAgreement($db);
        if (!$current || (int) $current['id'] !== $agreementId) {
            throw new DomainException('This is not the current agreement. Please reload the page.');
        }
        if (self::acceptance($db, $organizerId, $agreementId)) {
            return;
        }
        try {
            $db->prepare('INSERT INTO payout_agreement_acceptances (agreement_id, organizer_id, user_id, body_sha256, ip_address, user_agent)
                          VALUES (:a, :o, :u, :h, :ip, :ua)')
                ->execute(['a' => $agreementId, 'o' => $organizerId, 'u' => $userId, 'h' => $current['body_sha256'],
                           'ip' => $ip ? mb_substr($ip, 0, 45) : null, 'ua' => $userAgent ? mb_substr($userAgent, 0, 255) : null]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
        Logger::log($userId, 'payout_agreement_accepted', 'Accepted payout agreement version ' . $current['version']);
    }

    // =====================================================================
    // 4. Payout Record (PDF)
    // =====================================================================

    /** Everything the Payout Record shows. $forOrganizer applies the organizer's view rules. */
    public static function recordData(PDO $db, int $requestId, bool $forOrganizer): ?array
    {
        $request = PayoutRequestService::find($db, $requestId);
        if (!$request) {
            return null;
        }
        $orgStmt = $db->prepare('SELECT o.organization_name, o.contact_number, u.full_name, u.email FROM organizers o JOIN users u ON u.id = o.user_id WHERE o.id = :id');
        $orgStmt->execute(['id' => $request['organizer_id']]);
        $eventStmt = $db->prepare('SELECT name, event_date, venue, registration_closed_at FROM events WHERE id = :id');
        $eventStmt->execute(['id' => $request['event_id']]);
        $history = $db->prepare('SELECT h.*, u.full_name AS actor_name FROM finance_status_history h LEFT JOIN users u ON u.id = h.actor_user_id
                                 WHERE h.entity_type = "payout_request" AND h.entity_id = :id ORDER BY h.id');
        $history->execute(['id' => $requestId]);

        $disputes = [];
        foreach (self::disputesFor($db, $requestId) as $d) {
            $disputes[] = ['dispute' => $d, 'events' => self::events($db, (int) $d['id']), 'no_response' => self::noAdminResponse($d)];
        }
        $canSeeProof = !$forOrganizer || self::organizerCanSeeProof($db, $request);
        $proofHashNow = null;
        if (!empty($request['proof_path'])) {
            $file = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $request['proof_path']);
            $proofHashNow = is_file($file) ? hash_file('sha256', $file) : null;
        }
        $acceptance = null;
        $accStmt = $db->prepare('SELECT x.*, a.version, a.title, a.body_sha256 AS agreement_hash, a.operator_profile_version FROM payout_agreement_acceptances x
                                 JOIN payout_agreements a ON a.id = x.agreement_id
                                 WHERE x.organizer_id = :o AND x.accepted_at <= :t ORDER BY x.accepted_at DESC, x.id DESC LIMIT 1');
        $accStmt->execute(['o' => $request['organizer_id'], 't' => $request['created_at']]);
        $acceptance = $accStmt->fetch() ?: null;
        $operator = $acceptance ? self::operatorVersion($db, (int) $acceptance['operator_profile_version']) : self::operatorProfile($db);

        return [
            'request' => $request,
            'organizer' => $orgStmt->fetch() ?: [],
            'event' => $eventStmt->fetch() ?: [],
            'timeliness' => self::timeliness($request),
            'receipt' => self::receiptState($db, $request),
            'receipt_response' => self::receiptResponse($db, $requestId),
            'history' => $history->fetchAll(),
            'disputes' => $disputes,
            'can_see_proof' => $canSeeProof,
            'proof_hash_now' => $proofHashNow,
            'acceptance' => $acceptance,
            'operator' => $operator,
            'settings' => self::settings($db),
            'for_organizer' => $forOrganizer,
        ];
    }

    public static function renderRecordHtml(array $r, string $generatedAt, string $contentHash): string
    {
        $req = $r['request'];
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $dt = fn ($v) => $v ? date('M d, Y g:i A', strtotime($v)) : '—';
        $row = fn ($k, $v) => '<tr><th>' . $h($k) . '</th><td>' . $v . '</td></tr>';
        $money = fn ($v) => 'PHP ' . number_format((float) $v, 2);

        $html = '<html><head><meta charset="UTF-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111827; }
            h1 { font-size: 18px; margin: 0 0 2px; } h2 { font-size: 12.5px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d1d5db; page-break-after: avoid; } tr { page-break-inside: avoid; }
            .muted { color: #4b5563; } table { width: 100%; border-collapse: collapse; } th, td { text-align: left; vertical-align: top; padding: 3px 6px; border-bottom: 1px solid #eef0f4; }
            th { width: 34%; color: #374151; font-weight: bold; } .tl td, .tl th { font-size: 10px; } .notice { border: 1px solid #9ca3af; padding: 8px; margin-top: 14px; background: #f9fafb; }
            .hash { font-family: DejaVu Sans Mono, monospace; font-size: 9px; word-break: break-all; } img.proof { max-width: 100%; max-height: 360px; border: 1px solid #d1d5db; }
        </style></head><body>';
        $html .= '<h1>PAYOUT RECORD</h1><div class="muted">' . $h($req['request_no']) . ' · Generated by EVORA on ' . $h($generatedAt) . '</div>';

        $html .= '<h2>1. Organizer</h2><table>'
            . $row('Organization', $h($r['organizer']['organization_name'] ?? '—'))
            . $row('Account holder', $h($r['organizer']['full_name'] ?? '—'))
            . $row('Email', $h($r['organizer']['email'] ?? '—')) . '</table>';
        $html .= '<h2>2. Event</h2><table>'
            . $row('Event', $h($r['event']['name'] ?? '—'))
            . $row('Event date', $h(!empty($r['event']['event_date']) ? date('M d, Y', strtotime($r['event']['event_date'])) : '—'))
            . $row('Registration closed', $h($dt($r['event']['registration_closed_at'] ?? null))) . '</table>';
        $t = $r['timeliness'];
        $html .= '<h2>3. Payout</h2><table>'
            . $row('Payout ID', $h($req['request_no']))
            . $row('Status (payout lifecycle)', $h(ucfirst($req['status'])))
            . $row('Gross collected', $h($money($req['gross_amount'])))
            . $row('PayMongo fees', $h($money($req['gateway_fee'])))
            . $row('Final payout amount', '<b>' . $h($money($req['amount'])) . '</b>')
            . $row('Calculation', $h($req['entry_count'] . ' payment(s) from ' . $req['participant_count'] . ' participant(s): gross − PayMongo fees = payout amount'))
            . $row('Created', $h($dt($req['created_at'])))
            . $row('Payout deadline', $h($req['due_at'] ? $dt($req['due_at']) : 'Created before payout deadlines'))
            . $row('Timeliness', $h($t['label']))
            . $row('Destination (masked)', $h($req['method'] ? PayoutService::methodLabel($req['method']) . ' · ' . ($req['account_name_snapshot'] ?? '') . ' · ' . ($req['account_number_masked'] ?? '') . ($req['bank_name_snapshot'] ? ' · ' . $req['bank_name_snapshot'] : '') : 'Not yet fixed (set at approval)'))
            . $row('Paid at', $h($dt($req['paid_at'])))
            . $row('Transfer reference', $h($req['reference_number'] ? ($r['for_organizer'] && !$r['can_see_proof'] ? PayoutService::maskReference($req['reference_number']) : $req['reference_number']) : '—'))
            . '</table>';

        $html .= '<h2>4. Proof of transfer</h2>';
        if (empty($req['proof_path'])) {
            $html .= '<p class="muted">No proof of transfer recorded (the payout is not marked paid).</p>';
        } else {
            $html .= '<table>' . $row('SHA-256 recorded when marked paid', '<span class="hash">' . $h($req['proof_sha256'] ?: 'Not recorded (paid before proof hashes were introduced)') . '</span>')
                . $row('SHA-256 of the file now', '<span class="hash">' . $h($r['proof_hash_now'] ?: 'File not found') . '</span>' . (!$req['proof_sha256'] && $r['proof_hash_now'] ? '<br><span class="muted">Computed when this record was generated.</span>' : ''))
                . '</table><p class="muted">A SHA-256 hash shows whether the stored file has changed. It does not show whether the contents of the proof are true.</p>';
            if ($r['can_see_proof']) {
                $file = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $req['proof_path']);
                $mime = is_file($file) ? (mime_content_type($file) ?: '') : '';
                if (in_array($mime, ['image/png', 'image/jpeg'], true) && filesize($file) < 4 * 1048576) {
                    $html .= '<img class="proof" src="data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($file)) . '">';
                } else {
                    $html .= '<p class="muted">Proof file on record: ' . $h(basename($req['proof_path'])) . ' (' . $h($mime ?: 'unknown type') . ').</p>';
                }
            } else {
                $html .= '<p class="muted">The proof of transfer is kept by EVORA. It becomes visible to the organizer if a problem is reported for this paid payout.</p>';
            }
        }

        $html .= '<h2>5. Status history and administrator actions</h2>';
        if (!$r['history']) {
            $html .= '<p class="muted">No status changes are recorded for this payout.</p>';
        } else {
            $html .= '<table class="tl"><tr><th>Date</th><th>Change</th><th>By</th></tr>';
            foreach ($r['history'] as $ev) {
                $html .= '<tr><td>' . $h($dt($ev['created_at'])) . '</td><td>' . $h(($ev['from_status'] ?? 'new') . ' → ' . $ev['to_status']) . ($ev['reason'] && !$r['for_organizer'] ? '<br><span class="muted">' . $h(mb_substr($ev['reason'], 0, 300)) . '</span>' : '') . '</td><td>' . $h($ev['actor_name'] ?? 'System') . '</td></tr>';
            }
            $html .= '</table>';
        }

        $rc = $r['receipt'];
        $html .= '<h2>6. Organizer receipt</h2><table>'
            . $row('Receipt status', $h($rc ? $rc['label'] : 'Not applicable (payout not marked paid)'))
            . ($r['receipt_response'] ? $row('Confirmed received on', $h($dt($r['receipt_response']['created_at']))) : '')
            . ($rc && $rc['window_ends_at'] ? $row('Reporting period ends', $h(date('M d, Y g:i A', $rc['window_ends_at']))) : '')
            . '</table>';

        $html .= '<h2>7. Disputes</h2>';
        if (!$r['disputes']) {
            $html .= '<p class="muted">No dispute was opened for this payout.</p>';
        }
        foreach ($r['disputes'] as $block) {
            $d = $block['dispute'];
            $html .= '<table>' . $row('Dispute', $h($d['dispute_no'] . ' — ' . (self::TYPES[$d['type']] ?? $d['type'])))
                . $row('Reason', $h(self::REASONS[$d['reason_code']] ?? $d['reason_code']))
                . $row('Status', $h((self::DISPUTE_STATUS[$d['status']][0] ?? $d['status']) . ($d['resolution'] ? ' — ' . self::RESOLUTIONS[$d['resolution']] : '') . ($block['no_response'] ? ' — No response from administrator within the response period' : '')))
                . $row('Opened', $h($dt($d['opened_at'])))
                . $row('Administrator response due', $h($dt($d['admin_response_due_at'])))
                . $row('First administrator response', $h($dt($d['first_admin_response_at'])))
                . $row('Closed', $h($dt($d['closed_at']))) . '</table>';
            $html .= '<table class="tl"><tr><th>Date</th><th>Entry</th><th>By</th></tr>';
            foreach ($block['events'] as $ev) {
                $detail = $h(self::EVENT_LABELS[$ev['event_type']] ?? $ev['event_type']);
                if ($ev['message']) {
                    $detail .= '<br>' . nl2br($h($ev['message']));
                }
                if ($ev['reference_number']) {
                    $detail .= '<br>Reference: ' . $h($ev['reference_number']);
                }
                if ($ev['attachment_path']) {
                    $detail .= '<br>Attachment SHA-256: <span class="hash">' . $h($ev['attachment_sha256']) . '</span>';
                }
                $html .= '<tr><td>' . $h($dt($ev['created_at'])) . '</td><td>' . $detail . '</td><td>' . $h($ev['actor_name'] . ' (' . $ev['actor_role'] . ')') . '</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<h2>8. Organizer Payout Agreement</h2>';
        if ($r['acceptance']) {
            $a = $r['acceptance'];
            $html .= '<table>' . $row('Version accepted', $h($a['version'] . ' — ' . $a['title']))
                . $row('Accepted on', $h($dt($a['accepted_at'])))
                . $row('Agreement text SHA-256', '<span class="hash">' . $h($a['agreement_hash']) . '</span>') . '</table>';
        } else {
            $html .= '<p class="muted">No payout agreement acceptance is on record before this payout was created.</p>';
        }

        $html .= '<h2>9. Platform operator</h2>';
        if ($r['operator']) {
            $o = $r['operator'];
            $html .= '<table>' . $row('Operator', $h($o['operator_legal_name']))
                . $row('Responsible officer', $h($o['responsible_officer'] . ', ' . $o['officer_position']))
                . $row('Official email / phone', $h($o['support_email'] . ' / ' . $o['support_phone']))
                . $row('Official address', $h($o['official_address']))
                . $row('Registration', $h(($o['registration_type'] === 'none' ? 'Not registered' : strtoupper($o['registration_type']) . ' ' . $o['registration_no'])))
                . ($o['escalation_contact'] ? $row('Escalation contact', $h($o['escalation_contact'])) : '')
                . $row('Operator profile version', $h($o['version'])) . '</table>';
        } else {
            $html .= '<p class="muted">No platform operator profile was on record.</p>';
        }

        $html .= '<div class="notice"><b>About this record.</b> Generated by EVORA on ' . $h($generatedAt) . '. Contents reflect EVORA\'s records at the time of generation. This record does not determine fault or the outcome of any legal or financial claim.<br><br>'
            . $h(self::NEUTRAL_NOTICE) . '<br><br>Content integrity code (SHA-256 of the record data): <span class="hash">' . $h($contentHash) . '</span><br>'
            . 'Each generated record is also logged in EVORA with the SHA-256 of the PDF file.</div>';

        return $html . '</body></html>';
    }

    /** Builds the PDF, logs the export, and returns [bytes, documentSha256, filename]. */
    public static function generateRecord(PDO $db, int $requestId, int $userId, bool $forOrganizer): array
    {
        $data = self::recordData($db, $requestId, $forOrganizer);
        if (!$data) {
            throw new DomainException('Payout not found.');
        }
        $generatedAt = date('M d, Y g:i:s A') . ' (UTC' . date('P') . ')';
        $canonical = $data;
        unset($canonical['timeliness'], $canonical['receipt'], $canonical['for_organizer']);
        $contentHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $generatedAt);

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(self::renderRecordHtml($data, $generatedAt, $contentHash), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $bytes = $dompdf->output();
        $docHash = hash('sha256', $bytes);

        $openOrLast = $data['disputes'][0]['dispute']['id'] ?? null;
        $db->prepare('INSERT INTO payout_record_exports (payout_request_id, dispute_id, generated_by, document_sha256) VALUES (:r, :d, :u, :h)')
            ->execute(['r' => $requestId, 'd' => $openOrLast, 'u' => $userId, 'h' => $docHash]);
        Logger::log($userId, 'payout_record_generated', 'Payout Record for ' . $data['request']['request_no'] . ' (PDF SHA-256 ' . substr($docHash, 0, 16) . '…)');

        return [$bytes, $docHash, 'EVORA-Payout-Record-' . $data['request']['request_no'] . '-' . date('Ymd-His') . '.pdf'];
    }

    public static function exportsFor(PDO $db, int $requestId): array
    {
        $stmt = $db->prepare('SELECT x.*, u.full_name FROM payout_record_exports x JOIN users u ON u.id = x.generated_by WHERE x.payout_request_id = :id ORDER BY x.id DESC');
        $stmt->execute(['id' => $requestId]);

        return $stmt->fetchAll();
    }
}
