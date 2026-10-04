<?php

/**
 * Organizer earnings ledger for the centralized EVORA PayMongo account.
 *
 * One immutable organizer_ledger_entries row per confirmed PayMongo payment,
 * written by recordPayment() inside the same transaction that approves the
 * payment (PaymentConfirmationService::confirm). Corrections are reversal
 * rows; nothing is ever edited or deleted (the database enforces this too).
 *
 * Money is handled in integer centavos in PHP and DECIMAL in MySQL — never
 * floats. Organizer earnings = gross - gateway fee - deduction (deduction is
 * 0: no EVORA commission yet).
 *
 * An entry's state is derived, never stored, so it cannot drift:
 *   pending     event registration still open (collecting)
 *   on_hold     hold_reason set, event removed/cancelled, or organizer suspended
 *   eligible    registration closed; waiting for its payout request
 *   requested   included in a pending/approved payout request
 *   processing  its payout request is being transferred
 *   paid        its payout request was marked paid
 *   reversed    neutralized by a reversal before it was paid out
 *
 * (v25 settlements are retired; payouts are organizer_payout_requests,
 * created by PayoutRequestService when the event's registration closes.
 * hold_until is still written but no longer decides anything.)
 */
class LedgerService
{
    public const STATES = [
        'pending' => ['Collecting', 'secondary'],
        'on_hold' => ['On Hold', 'warning'],
        'eligible' => ['Ready for Payout', 'info'],
        'requested' => ['Payout Requested', 'primary'],
        'processing' => ['Payout Processing', 'primary'],
        'paid' => ['Paid Out', 'success'],
        'reversed' => ['Reversed', 'dark'],
    ];

    public const HOLD_REASONS = [
        'fee_unknown' => 'Gateway fee not yet verified',
        'amount_mismatch' => 'PayMongo amount differs from EVORA amount',
        'manual_hold' => 'Held by admin',
        'refund_pending' => 'Refund in progress',
    ];

    /** Joins every state query relies on. Aliases: l, e, o, pr, rv, og. */
    public const FROM_SQL = '
        FROM organizer_ledger_entries l
        JOIN events e ON e.id = l.event_id
        JOIN organizers o ON o.id = l.organizer_id
        LEFT JOIN organizer_payout_requests pr ON pr.id = l.payout_request_id
        LEFT JOIN organizer_ledger_entries rv ON rv.reverses_entry_id = l.id
        LEFT JOIN organizer_ledger_entries og ON og.id = l.reverses_entry_id';

    // A reversal of an entry that was never paid out cancels it (both rows
    // "reversed"); a reversal of an already-paid entry is a live negative
    // entry that is deducted in the event's next payout request.
    public const STATE_SQL = "
        CASE
            WHEN pr.status = 'paid' THEN 'paid'
            WHEN pr.status = 'processing' THEN 'processing'
            WHEN pr.status IN ('pending', 'approved') THEN 'requested'
            WHEN (l.entry_type = 'payment' AND rv.id IS NOT NULL) OR (l.entry_type = 'reversal' AND og.payout_request_id IS NULL) THEN 'reversed'
            WHEN l.hold_reason IS NOT NULL OR e.status IN ('removed', 'cancelled') OR o.status = 'suspended' THEN 'on_hold'
            WHEN e.registration_closed_at IS NOT NULL THEN 'eligible'
            ELSE 'pending'
        END";

    // ---------------------------------------------------------------------
    // Money helpers (integer centavos)
    // ---------------------------------------------------------------------

    public static function toCents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function holdDays(PDO $db): int
    {
        $days = $db->query('SELECT settlement_hold_days FROM system_settings WHERE id = 1')->fetchColumn();

        return $days === false ? 3 : (int) $days;
    }

    public static function stateBadge(string $state): string
    {
        [$label, $color] = self::STATES[$state] ?? [ucfirst($state), 'secondary'];

        return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
    }

    // ---------------------------------------------------------------------
    // Recording
    // ---------------------------------------------------------------------

    /**
     * Records the ledger entry for a confirmed PayMongo payment. MUST run
     * inside the caller's transaction, after the payment row was locked, so
     * payment approval and its ledger entry commit or roll back together.
     *
     * $gateway (all optional): payment_id, checkout_session_id, fee,
     * net_amount, amount — as reported by PayMongo.
     *
     * Idempotent: source_key 'payment:<id>' is UNIQUE, so a repeat call
     * returns the existing entry id and writes nothing. Real database errors
     * are NOT swallowed (no INSERT IGNORE) — they abort the approval.
     *
     * Returns the entry id, or null for non-PayMongo payments (manual and
     * cash money is held by the organizer and never enters this ledger).
     */
    public static function recordPayment(PDO $db, array $payment, array $gateway, ?int $actorUserId, string $origin): ?int
    {
        if (($payment['payment_channel'] ?? null) !== 'paymongo') {
            return null;
        }

        $sourceKey = 'payment:' . (int) $payment['id'];
        $existing = $db->prepare('SELECT id FROM organizer_ledger_entries WHERE source_key = :key');
        $existing->execute(['key' => $sourceKey]);
        if ($existingId = $existing->fetchColumn()) {
            return (int) $existingId;
        }

        $ownerStmt = $db->prepare(
            'SELECT r.id AS registration_id, e.id AS event_id, e.organizer_id, e.event_date
             FROM registrations r JOIN events e ON e.id = r.event_id
             WHERE r.id = :rid'
        );
        $ownerStmt->execute(['rid' => $payment['registration_id']]);
        $owner = $ownerStmt->fetch();
        if (!$owner) {
            throw new RuntimeException('Ledger: registration #' . $payment['registration_id'] . ' has no event/organizer.');
        }

        $amounts = self::computeAmounts($payment['amount'], $gateway);
        $holdUntil = (new DateTimeImmutable($owner['event_date']))->modify('+' . self::holdDays($db) . ' days')->format('Y-m-d');

        $insert = $db->prepare(
            'INSERT INTO organizer_ledger_entries
                (organizer_id, event_id, registration_id, payment_id, entry_type, source_key,
                 paymongo_payment_id, checkout_session_id, gross_amount, gateway_fee, net_amount,
                 deduction_amount, organizer_earnings, event_date, hold_until, hold_reason, remarks, created_by)
             VALUES (:org, :event, :reg, :pay, "payment", :key, :pmid, :csid, :gross, :fee, :net,
                 0.00, :earnings, :event_date, :hold_until, :hold, :remarks, :by)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $insert->execute([
            'org' => $owner['organizer_id'],
            'event' => $owner['event_id'],
            'reg' => $owner['registration_id'],
            'pay' => $payment['id'],
            'key' => $sourceKey,
            'pmid' => $gateway['payment_id'] ?? $payment['gateway_payment_id'] ?? null,
            'csid' => $gateway['checkout_session_id'] ?? $payment['checkout_session_id'] ?? null,
            'gross' => $amounts['gross'],
            'fee' => $amounts['fee'],
            'net' => $amounts['net'],
            'earnings' => $amounts['earnings'],
            'event_date' => $owner['event_date'],
            'hold_until' => $holdUntil,
            'hold' => $amounts['hold_reason'],
            'remarks' => 'Recorded via ' . $origin,
            'by' => $actorUserId,
        ]);
        $entryId = (int) $db->lastInsertId();

        if ($insert->rowCount() === 1) {
            FinanceHistory::record($db, 'ledger_entry', $entryId, null, $amounts['hold_reason'] ? 'on_hold' : 'recorded', $actorUserId,
                'Payment #' . $payment['id'] . ' via ' . $origin . ($amounts['hold_reason'] ? ' — ' . self::HOLD_REASONS[$amounts['hold_reason']] : ''));
            $history = $db->prepare('INSERT INTO payment_history (payment_id, action, performed_by, remarks) VALUES (:pid, "ledger_recorded", :by, :remarks)');
            $history->execute([
                'pid' => $payment['id'],
                'by' => $actorUserId,
                'remarks' => 'Ledger entry #' . $entryId . ' for organizer #' . $owner['organizer_id']
                    . ($amounts['earnings'] !== null ? ' — earnings ' . $amounts['earnings'] : ' — fee unknown'),
            ]);
        }

        return $entryId;
    }

    /**
     * gross comes from EVORA's payment row; fee/net from PayMongo. When
     * PayMongo reports a net amount, the recorded fee is gross - net (every
     * deduction PayMongo took), so earnings always equal what EVORA received.
     */
    public static function computeAmounts($grossAmount, array $gateway): array
    {
        $grossCents = self::toCents($grossAmount);
        $holdReason = null;

        if (isset($gateway['amount']) && $gateway['amount'] !== null && self::toCents($gateway['amount']) !== $grossCents) {
            $holdReason = 'amount_mismatch';
        }

        $netCents = null;
        if (isset($gateway['net_amount']) && $gateway['net_amount'] !== null) {
            $netCents = self::toCents($gateway['net_amount']);
        } elseif (isset($gateway['fee']) && $gateway['fee'] !== null) {
            $netCents = $grossCents - self::toCents($gateway['fee']);
        }

        if ($netCents === null) {
            return ['gross' => self::fromCents($grossCents), 'fee' => null, 'net' => null, 'earnings' => null, 'hold_reason' => 'fee_unknown'];
        }

        return [
            'gross' => self::fromCents($grossCents),
            'fee' => self::fromCents($grossCents - $netCents),
            'net' => self::fromCents($netCents),
            'earnings' => self::fromCents($netCents), // net - deduction (0)
            'hold_reason' => $holdReason,
        ];
    }

    /**
     * The one amount write the database allows: filling the fee of an
     * unsettled entry that was recorded without one. Returns true if filled.
     */
    public static function resolveFee(PDO $db, int $entryId, array $gateway, ?int $actorUserId): bool
    {
        $stmt = $db->prepare('SELECT * FROM organizer_ledger_entries WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch();
        if (!$entry || $entry['gateway_fee'] !== null || $entry['settlement_id'] !== null || $entry['payout_request_id'] !== null) {
            return false;
        }

        $amounts = self::computeAmounts($entry['gross_amount'], $gateway);
        if ($amounts['fee'] === null) {
            return false;
        }

        $update = $db->prepare(
            'UPDATE organizer_ledger_entries SET gateway_fee = :fee, net_amount = :net, organizer_earnings = :earnings, hold_reason = :hold
             WHERE id = :id AND gateway_fee IS NULL AND settlement_id IS NULL AND payout_request_id IS NULL'
        );
        $update->execute(['fee' => $amounts['fee'], 'net' => $amounts['net'], 'earnings' => $amounts['earnings'], 'hold' => $amounts['hold_reason'], 'id' => $entryId]);
        if ($update->rowCount() !== 1) {
            return false;
        }

        FinanceHistory::record($db, 'ledger_entry', $entryId, 'on_hold', $amounts['hold_reason'] ? 'on_hold' : 'recorded', $actorUserId,
            'Gateway fee verified: ' . $amounts['fee'] . ' (net ' . $amounts['net'] . ')');

        return true;
    }

    // ---------------------------------------------------------------------
    // Admin corrections (no typed amounts anywhere)
    // ---------------------------------------------------------------------

    public static function placeHold(PDO $db, int $entryId, string $remarks, int $adminId): void
    {
        $db->beginTransaction();
        try {
            $entry = self::lockUnsettled($db, $entryId);
            if ($entry['hold_reason'] !== null) {
                throw new DomainException('This entry is already on hold.');
            }
            $db->prepare('UPDATE organizer_ledger_entries SET hold_reason = "manual_hold" WHERE id = :id')->execute(['id' => $entryId]);
            FinanceHistory::record($db, 'ledger_entry', $entryId, 'recorded', 'on_hold', $adminId, $remarks);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Releases a manual hold or a reviewed amount mismatch. fee_unknown can only clear by verifying the fee. */
    public static function releaseHold(PDO $db, int $entryId, string $remarks, int $adminId): void
    {
        $db->beginTransaction();
        try {
            $entry = self::lockUnsettled($db, $entryId);
            if (!in_array($entry['hold_reason'], ['manual_hold', 'amount_mismatch'], true)) {
                throw new DomainException(match ($entry['hold_reason']) {
                    'fee_unknown' => 'A fee-unknown hold clears only when the real PayMongo fee is verified (ledger_backfill.php --resolve-fees).',
                    'refund_pending' => 'This entry is held for a refund. It clears when the refund is completed or rejected (Refund Requests).',
                    default => 'This entry is not on hold.',
                });
            }
            $db->prepare('UPDATE organizer_ledger_entries SET hold_reason = NULL WHERE id = :id')->execute(['id' => $entryId]);
            FinanceHistory::record($db, 'ledger_entry', $entryId, 'on_hold', 'recorded', $adminId, $remarks);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Neutralizes a payment entry with a new reversal entry carrying the same
     * amounts negated (copied from the database, never typed). Not yet paid
     * out: both rows become "reversed" and are excluded from payouts. Already
     * paid out: the negative entry is deducted in the event's next payout
     * request. Not allowed while the entry is in an open payout request.
     */
    public static function reverse(PDO $db, int $entryId, string $remarks, int $adminId): int
    {
        $db->beginTransaction();
        try {
            $reversalId = self::reverseInTransaction($db, $entryId, $remarks, $adminId);
            $db->commit();

            return $reversalId;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * reverse() for callers that already hold a transaction (refund completion,
     * Refund All): the reversal commits or rolls back with the caller's work.
     */
    public static function reverseInTransaction(PDO $db, int $entryId, string $remarks, int $adminId): int
    {
        if (!$db->inTransaction()) {
            throw new LogicException('reverseInTransaction() must run inside a transaction.');
        }

        $stmt = $db->prepare('SELECT l.*, pr.status AS request_status FROM organizer_ledger_entries l LEFT JOIN organizer_payout_requests pr ON pr.id = l.payout_request_id WHERE l.id = :id FOR UPDATE');
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch();
        if (!$entry || $entry['entry_type'] !== 'payment') {
            throw new DomainException('Only payment entries can be reversed.');
        }
        if (in_array($entry['request_status'], ['pending', 'approved', 'processing'], true)) {
            throw new DomainException('This entry is in an open payout request. Reject that request before reversing it.');
        }
        $already = $db->prepare('SELECT id FROM organizer_ledger_entries WHERE reverses_entry_id = :id');
        $already->execute(['id' => $entryId]);
        if ($already->fetchColumn()) {
            throw new DomainException('This entry has already been reversed.');
        }

        $negate = fn($v) => $v === null ? null : self::fromCents(-self::toCents($v));
        $insert = $db->prepare(
            'INSERT INTO organizer_ledger_entries
                (organizer_id, event_id, registration_id, payment_id, entry_type, source_key, paymongo_payment_id, checkout_session_id,
                 gross_amount, gateway_fee, net_amount, deduction_amount, organizer_earnings, event_date, hold_until,
                 hold_reason, reverses_entry_id, remarks, created_by)
             VALUES (:org, :event, :reg, :pay, "reversal", :key, :pmid, :csid, :gross, :fee, :net, 0.00, :earnings,
                 :event_date, :hold_until, :hold, :reverses, :remarks, :by)'
        );
        $insert->execute([
            'org' => $entry['organizer_id'], 'event' => $entry['event_id'], 'reg' => $entry['registration_id'],
            'pay' => $entry['payment_id'], 'key' => 'reversal:' . $entryId,
            'pmid' => $entry['paymongo_payment_id'], 'csid' => $entry['checkout_session_id'],
            'gross' => $negate($entry['gross_amount']), 'fee' => $negate($entry['gateway_fee']),
            'net' => $negate($entry['net_amount']), 'earnings' => $negate($entry['organizer_earnings']),
            'event_date' => $entry['event_date'], 'hold_until' => $entry['hold_until'],
            'hold' => $entry['gateway_fee'] === null ? 'fee_unknown' : null,
            'reverses' => $entryId, 'remarks' => $remarks, 'by' => $adminId,
        ]);
        $reversalId = (int) $db->lastInsertId();

        FinanceHistory::record($db, 'ledger_entry', $entryId, $entry['request_status'] === 'paid' ? 'paid' : 'recorded', 'reversed', $adminId, $remarks);
        FinanceHistory::record($db, 'ledger_entry', $reversalId, null, 'recorded', $adminId, 'Reversal of entry #' . $entryId . ': ' . $remarks);

        return $reversalId;
    }

    /**
     * Keeps a payment entry out of payout requests while its refund is being
     * processed (caller's transaction). Only entries not yet in any payout
     * request, and not already held for another reason, get the hold.
     * Returns true if the hold was placed.
     */
    public static function holdForRefund(PDO $db, int $entryId, int $adminId, string $remarks): bool
    {
        $stmt = $db->prepare('UPDATE organizer_ledger_entries SET hold_reason = "refund_pending" WHERE id = :id AND hold_reason IS NULL AND payout_request_id IS NULL AND settlement_id IS NULL');
        $stmt->execute(['id' => $entryId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        FinanceHistory::record($db, 'ledger_entry', $entryId, 'recorded', 'on_hold', $adminId, $remarks);

        return true;
    }

    /** Clears a refund hold (refund rejected or completed); caller's transaction. */
    public static function releaseRefundHold(PDO $db, int $entryId, int $adminId, string $remarks): bool
    {
        $stmt = $db->prepare('UPDATE organizer_ledger_entries SET hold_reason = NULL WHERE id = :id AND hold_reason = "refund_pending"');
        $stmt->execute(['id' => $entryId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        FinanceHistory::record($db, 'ledger_entry', $entryId, 'on_hold', 'recorded', $adminId, $remarks);

        return true;
    }

    private static function lockUnsettled(PDO $db, int $entryId): array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_ledger_entries WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch();
        if (!$entry) {
            throw new DomainException('Ledger entry not found.');
        }
        if ($entry['settlement_id'] !== null || $entry['payout_request_id'] !== null) {
            throw new DomainException('This entry is already part of a payout request.');
        }

        return $entry;
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    /**
     * Ledger rows with derived state. Filters (all optional, validated here):
     * organizer_id, event_id, state, hold_reason, request_status ('none' or a
     * payout request status), payout_request_id, date_from, date_to.
     */
    public static function entries(PDO $db, array $filters = [], int $limit = 500): array
    {
        [$where, $params] = self::filterSql($filters);
        $sql = 'SELECT * FROM (
                    SELECT l.*, e.name AS event_name, e.status AS event_status, e.registration_closed_at, o.organization_name,
                        pr.request_no, pr.status AS request_status,
                        (rv.id IS NOT NULL) AS is_reversed, u.full_name AS participant_name,
                        ' . self::STATE_SQL . ' AS state
                    ' . self::FROM_SQL . '
                    LEFT JOIN registrations r ON r.id = l.registration_id
                    LEFT JOIN participants pa ON pa.id = r.participant_id
                    LEFT JOIN users u ON u.id = pa.user_id
                ) x WHERE 1 = 1' . $where . ' ORDER BY x.id DESC LIMIT ' . max(1, $limit);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Totals per state: ['state' => ['count', 'gross', 'fee', 'net', 'earnings']] plus 'all'. */
    public static function totalsByState(PDO $db, array $filters = []): array
    {
        [$where, $params] = self::filterSql($filters);
        $stmt = $db->prepare(
            'SELECT x.state, COUNT(*) c, COALESCE(SUM(x.gross_amount), 0) gross, COALESCE(SUM(x.gateway_fee), 0) fee,
                    COALESCE(SUM(x.net_amount), 0) net, COALESCE(SUM(x.organizer_earnings), 0) earnings
             FROM (SELECT l.*, ' . self::STATE_SQL . ' AS state, pr.status AS request_status
                   ' . self::FROM_SQL . ') x
             WHERE 1 = 1' . $where . ' GROUP BY x.state'
        );
        $stmt->execute($params);

        $totals = [];
        $all = ['count' => 0, 'gross' => 0, 'fee' => 0, 'net' => 0, 'earnings' => 0];
        foreach (array_keys(self::STATES) as $state) {
            $totals[$state] = ['count' => 0, 'gross' => 0, 'fee' => 0, 'net' => 0, 'earnings' => 0];
        }
        foreach ($stmt->fetchAll() as $row) {
            $bucket = ['count' => (int) $row['c'], 'gross' => self::toCents($row['gross']), 'fee' => self::toCents($row['fee']),
                'net' => self::toCents($row['net']), 'earnings' => self::toCents($row['earnings'])];
            $totals[$row['state']] = $bucket;
            foreach ($bucket as $k => $v) {
                $all[$k] += $v;
            }
        }
        $totals['all'] = $all;

        return $totals; // amounts in centavos
    }

    private static function filterSql(array $filters): array
    {
        $where = '';
        $params = [];
        foreach (['organizer_id', 'event_id', 'payout_request_id'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND x.$key = :$key";
                $params[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['state']) && isset(self::STATES[$filters['state']])) {
            $where .= ' AND x.state = :state';
            $params['state'] = $filters['state'];
        }
        if (!empty($filters['hold_reason']) && isset(self::HOLD_REASONS[$filters['hold_reason']])) {
            $where .= ' AND x.hold_reason = :hold_reason';
            $params['hold_reason'] = $filters['hold_reason'];
        }
        if (!empty($filters['request_status'])) {
            if ($filters['request_status'] === 'none') {
                $where .= ' AND x.request_status IS NULL';
            } elseif (isset(PayoutRequestService::STATUS_BADGES[$filters['request_status']])) {
                $where .= ' AND x.request_status = :request_status';
                $params['request_status'] = $filters['request_status'];
            }
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            if (!empty($filters[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                $where .= " AND DATE(x.created_at) $op :$key";
                $params[$key] = $filters[$key];
            }
        }

        return [$where, $params];
    }
}
