<?php

/**
 * Organizer event earnings and payout requests (centralized EVORA payments).
 *
 *   PayMongo payment confirmed -> organizer_ledger_entries row (LedgerService)
 *   -> organizer_event_earnings: per-event totals, recalculated from the ledger
 *   -> registration closes (EventRegistrationService)
 *   -> createForEvent(): ONE pending organizer_payout_requests row built from
 *      the event's eligible ledger entries (amounts copied, never typed)
 *   -> admin: approve -> (processing ->) paid with transfer reference,
 *      or reject (entries released, automatic re-requests paused).
 *
 * Late entries (a checkout paid after closing, or a reversal of an already
 * paid entry) go into the event's next request (sequence 2, 3, ...), created
 * once no other request for that event is open. A negative balance is never
 * requested; it is carried until later entries make it positive.
 *
 * Money is handled in integer centavos (LedgerService::toCents/fromCents).
 * The database enforces the same rules with triggers (see
 * database/payment_flow_revision_migration.sql).
 */
class PayoutRequestService
{
    public const STATUS_BADGES = [
        'pending' => 'warning',
        'approved' => 'info',
        'processing' => 'primary',
        'paid' => 'success',
        'rejected' => 'danger',
    ];

    public const OPEN_STATUSES = ['pending', 'approved', 'processing'];

    /** How long full payout details stay visible after an admin reveals them (each reveal is logged). */
    public const DETAILS_REVEAL_SECONDS = 300;

    public const EARNINGS_STATUSES = [
        'collecting' => ['Registration Open', 'secondary'],
        'closed' => ['Registration Closed', 'info'],
        'payout_requested' => ['Payout Processing', 'primary'],
        'paid' => ['Paid Out', 'success'],
        'on_hold' => ['On Hold', 'warning'],
    ];

    public static function badge(string $status): string
    {
        return '<span class="badge bg-' . (self::STATUS_BADGES[$status] ?? 'secondary') . '">' . e(ucfirst($status)) . '</span>';
    }

    public static function earningsBadge(string $status): string
    {
        [$label, $color] = self::EARNINGS_STATUSES[$status] ?? [ucfirst($status), 'secondary'];

        return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
    }

    // ---------------------------------------------------------------------
    // Event earnings
    // ---------------------------------------------------------------------

    /**
     * Recalculates the event's earnings row from its ledger entries and the
     * event's payout requests. Creates the row when missing. Returns the row.
     */
    public static function recalculate(PDO $db, int $eventId, ?int $actorUserId = null): ?array
    {
        $eventStmt = $db->prepare('SELECT id, organizer_id, status, registration_closed_at FROM events WHERE id = :id');
        $eventStmt->execute(['id' => $eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            return null;
        }

        $entriesStmt = $db->prepare(
            'SELECT l.id, l.entry_type, l.registration_id, l.gross_amount, l.gateway_fee, l.net_amount,
                    (rv.id IS NOT NULL) AS is_reversed, ' . LedgerService::STATE_SQL . ' AS state
             ' . LedgerService::FROM_SQL . ' WHERE l.event_id = :eid'
        );
        $entriesStmt->execute(['eid' => $eventId]);

        $gross = $fee = $net = $eligible = 0;
        $held = 0;
        $participants = [];
        foreach ($entriesStmt->fetchAll() as $entry) {
            if ($entry['state'] === 'reversed') {
                continue;
            }
            if ($entry['gateway_fee'] === null) {
                $held++; // fee not verified yet: not counted until it is
                continue;
            }
            $gross += LedgerService::toCents($entry['gross_amount']);
            $fee += LedgerService::toCents($entry['gateway_fee']);
            $net += LedgerService::toCents($entry['net_amount']);
            if ($entry['entry_type'] === 'payment' && !$entry['is_reversed']) {
                $participants[(int) $entry['registration_id']] = true;
            }
            if ($entry['state'] === 'on_hold') {
                $held++;
            } elseif ($entry['state'] === 'eligible') {
                $eligible += LedgerService::toCents($entry['net_amount']);
            }
        }

        $reqStmt = $db->prepare(
            'SELECT COALESCE(SUM(CASE WHEN status IN ("pending", "approved", "processing") THEN amount END), 0) requested,
                    COALESCE(SUM(CASE WHEN status = "paid" THEN amount END), 0) paid,
                    SUM(status IN ("pending", "approved", "processing")) open_count
             FROM organizer_payout_requests WHERE event_id = :eid'
        );
        $reqStmt->execute(['eid' => $eventId]);
        $req = $reqStmt->fetch();

        $current = self::earningsForEvent($db, $eventId);
        $paused = $current['payout_paused_reason'] ?? null;

        if (in_array($event['status'], ['cancelled', 'removed'], true)) {
            $status = 'on_hold';
        } elseif ($event['registration_closed_at'] === null) {
            $status = 'collecting';
        } elseif ($paused !== null) {
            $status = 'on_hold';
        } elseif ((int) $req['open_count'] > 0) {
            $status = 'payout_requested';
        } elseif ($eligible > 0) {
            $status = 'closed'; // waiting for the scheduler to create the request
        } elseif ($held > 0) {
            $status = 'on_hold';
        } elseif (LedgerService::toCents($req['paid']) > 0) {
            $status = 'paid';
        } else {
            $status = 'closed';
        }

        $db->prepare(
            'INSERT INTO organizer_event_earnings
                (organizer_id, event_id, participant_count, gross_amount, gateway_fee, net_amount, held_count,
                 requested_amount, paid_amount, status, last_calculated_at)
             VALUES (:org, :event, :pc, :gross, :fee, :net, :held, :requested, :paid, :status, NOW())
             ON DUPLICATE KEY UPDATE participant_count = VALUES(participant_count), gross_amount = VALUES(gross_amount),
                gateway_fee = VALUES(gateway_fee), net_amount = VALUES(net_amount), held_count = VALUES(held_count),
                requested_amount = VALUES(requested_amount), paid_amount = VALUES(paid_amount),
                status = VALUES(status), last_calculated_at = NOW()'
        )->execute([
            'org' => $event['organizer_id'], 'event' => $eventId, 'pc' => count($participants),
            'gross' => LedgerService::fromCents($gross), 'fee' => LedgerService::fromCents($fee), 'net' => LedgerService::fromCents($net),
            'held' => $held, 'requested' => $req['requested'], 'paid' => $req['paid'], 'status' => $status,
        ]);

        $row = self::earningsForEvent($db, $eventId);
        if (!$current || $current['status'] !== $status) {
            FinanceHistory::record($db, 'event_earnings', (int) $row['id'], $current['status'] ?? null, $status, $actorUserId,
                'Net ' . LedgerService::fromCents($net) . ' from ' . count($participants) . ' participant(s)');
        }

        return $row;
    }

    /** Recalculates without ever failing the caller (used right after a payment is confirmed). */
    public static function recalculateQuietly(PDO $db, int $eventId): void
    {
        try {
            self::recalculate($db, $eventId);
        } catch (Throwable $e) {
            Logger::log(null, 'earnings_recalculate_error', 'Event #' . $eventId . ': ' . $e->getMessage());
        }
    }

    public static function earningsForEvent(PDO $db, int $eventId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_event_earnings WHERE event_id = :eid');
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The organizer's paid events with their earnings and latest payout
     * request. $organizerId always comes from the session.
     */
    public static function earningsForOrganizer(PDO $db, int $organizerId): array
    {
        // PayPal events are paid directly to the organizer's PayPal account, so EVORA
        // has nothing to pay out for them; they are left out unless (never expected)
        // ledger money exists for the event.
        $stmt = $db->prepare(
            'SELECT e.id AS event_id, e.name AS event_name, e.event_date, e.status AS event_status,
                    e.registration_deadline, e.registration_closed_at,
                    er.id AS earnings_id, er.participant_count, er.gross_amount, er.gateway_fee, er.net_amount, er.held_count,
                    er.requested_amount, er.paid_amount, er.status AS earnings_status, er.payout_paused_reason,
                    (SELECT r.id FROM organizer_payout_requests r WHERE r.event_id = e.id ORDER BY r.id DESC LIMIT 1) AS last_request_id,
                    (SELECT r.status FROM organizer_payout_requests r WHERE r.event_id = e.id ORDER BY r.id DESC LIMIT 1) AS last_request_status
             FROM events e
             LEFT JOIN organizer_event_earnings er ON er.event_id = e.id
             WHERE e.organizer_id = :oid AND e.status NOT IN ("draft", "removed")
               AND (er.id IS NOT NULL OR e.fee > 0 OR EXISTS (SELECT 1 FROM race_categories rc WHERE rc.event_id = e.id AND rc.fee > 0))
               AND (e.payment_provider IS NULL OR e.payment_provider <> "paypal" OR er.gross_amount > 0)
             ORDER BY e.event_date DESC'
        );
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetchAll();
    }

    // ---------------------------------------------------------------------
    // Creating payout requests
    // ---------------------------------------------------------------------

    /**
     * Creates the event's payout request from its eligible ledger entries.
     * Returns the new request id, or null when there is nothing to request
     * (registration still open, request already open, payouts paused, no
     * eligible entries, or a zero/negative balance). Safe to call repeatedly:
     * rows are locked and the database allows one open request per event.
     * $actorUserId null = the scheduler.
     */
    public static function createForEvent(PDO $db, int $eventId, ?int $actorUserId = null): ?int
    {
        self::recalculate($db, $eventId, $actorUserId); // makes sure the earnings row exists

        $db->beginTransaction();
        try {
            $eventStmt = $db->prepare('SELECT id, organizer_id, name, status, registration_closed_at FROM events WHERE id = :id FOR UPDATE');
            $eventStmt->execute(['id' => $eventId]);
            $event = $eventStmt->fetch();

            $earningsStmt = $db->prepare('SELECT * FROM organizer_event_earnings WHERE event_id = :eid FOR UPDATE');
            $earningsStmt->execute(['eid' => $eventId]);
            $earnings = $earningsStmt->fetch();

            $openStmt = $db->prepare('SELECT id FROM organizer_payout_requests WHERE event_id = :eid AND status IN ("pending", "approved", "processing") FOR UPDATE');
            $openStmt->execute(['eid' => $eventId]);

            if (!$event || !$earnings || $event['registration_closed_at'] === null
                || in_array($event['status'], ['cancelled', 'removed'], true)
                || $earnings['payout_paused_reason'] !== null || $openStmt->fetchColumn()) {
                $db->commit();

                return null;
            }

            $db->prepare('SELECT id FROM organizer_ledger_entries WHERE event_id = :eid AND payout_request_id IS NULL FOR UPDATE')
                ->execute(['eid' => $eventId]);
            $eligibleStmt = $db->prepare(
                'SELECT x.* FROM (
                    SELECT l.id, l.organizer_id, l.entry_type, l.registration_id, l.gross_amount, l.gateway_fee, l.net_amount,
                           l.organizer_earnings, (rv.id IS NOT NULL) AS is_reversed, ' . LedgerService::STATE_SQL . ' AS state
                    ' . LedgerService::FROM_SQL . ' WHERE l.event_id = :eid) x
                 WHERE x.state = "eligible" ORDER BY x.id'
            );
            $eligibleStmt->execute(['eid' => $eventId]);
            $entries = $eligibleStmt->fetchAll();

            $sum = ['gross' => 0, 'fee' => 0, 'earnings' => 0];
            $participants = [];
            foreach ($entries as $entry) {
                if ((int) $entry['organizer_id'] !== (int) $event['organizer_id']) {
                    throw new DomainException('Ledger entry #' . $entry['id'] . ' does not belong to this event\'s organizer.');
                }
                $sum['gross'] += LedgerService::toCents($entry['gross_amount']);
                $sum['fee'] += LedgerService::toCents($entry['gateway_fee']);
                $sum['earnings'] += LedgerService::toCents($entry['organizer_earnings']);
                if ($entry['entry_type'] === 'payment' && !$entry['is_reversed']) {
                    $participants[(int) $entry['registration_id']] = true;
                }
            }
            if (!$entries || $sum['earnings'] <= 0) {
                $db->commit();

                return null;
            }

            $seqStmt = $db->prepare('SELECT COALESCE(MAX(sequence_no), 0) FROM organizer_payout_requests WHERE event_id = :eid');
            $seqStmt->execute(['eid' => $eventId]);
            $sequence = (int) $seqStmt->fetchColumn() + 1;
            $requestNo = sprintf('PR-%06d-%d', $eventId, $sequence);

            $db->prepare(
                'INSERT INTO organizer_payout_requests
                    (request_no, organizer_id, event_id, earnings_id, sequence_no, entry_count, participant_count,
                     gross_amount, gateway_fee, amount, status, created_by)
                 VALUES (:no, :org, :event, :earn, :seq, :count, :pc, :gross, :fee, :amount, "pending", :by)'
            )->execute([
                'no' => $requestNo, 'org' => $event['organizer_id'], 'event' => $eventId, 'earn' => $earnings['id'],
                'seq' => $sequence, 'count' => count($entries), 'pc' => count($participants),
                'gross' => LedgerService::fromCents($sum['gross']), 'fee' => LedgerService::fromCents($sum['fee']),
                'amount' => LedgerService::fromCents($sum['earnings']), 'by' => $actorUserId,
            ]);
            $requestId = (int) $db->lastInsertId();

            $ids = array_map('intval', array_column($entries, 'id'));
            $link = $db->prepare(
                'UPDATE organizer_ledger_entries SET payout_request_id = ? WHERE payout_request_id IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $link->execute(array_merge([$requestId], $ids));
            if ($link->rowCount() !== count($ids)) {
                throw new RuntimeException('Some ledger entries changed while the payout request was being created.');
            }

            FinanceHistory::record($db, 'payout_request', $requestId, null, 'pending', $actorUserId,
                $requestNo . ': ' . count($ids) . ' entries, net ' . LedgerService::fromCents($sum['earnings']) . ($sequence > 1 ? ' (late entries after an earlier request)' : ''));
            foreach ($ids as $id) {
                FinanceHistory::record($db, 'ledger_entry', $id, 'eligible', 'requested', $actorUserId, 'Payout request ' . $requestNo);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::recalculate($db, $eventId, $actorUserId);
        Logger::log($actorUserId, 'payout_request_created', $requestNo . ' for event #' . $eventId . ' (' . LedgerService::fromCents($sum['earnings']) . ')');
        self::notifyOrganizer($db, (int) $event['organizer_id'], 'Payout Request Created',
            'A payout request of ' . currency(LedgerService::fromCents($sum['earnings'])) . ' for "' . $event['name'] . '" was created and is waiting for admin approval.', ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $requestId]);
        self::notifyAdmins($db, 'Payout Request To Review', $requestNo . ' for "' . $event['name'] . '" (' . currency(LedgerService::fromCents($sum['earnings'])) . ') is waiting for approval.', ['category' => 'payout', 'notice_type' => 'action_required', 'reference_type' => 'payout_request', 'reference_id' => $requestId]);

        return $requestId;
    }

    /**
     * The scheduled job (scripts/process_event_payouts.php, also the admin
     * "Run now" button): close due registrations, then create payout requests
     * and refresh earnings. Idempotent; a second concurrent run is refused.
     */
    public static function processDueEvents(PDO $db, ?int $actorUserId = null): array
    {
        if ((int) $db->query('SELECT GET_LOCK("evora_process_event_payouts", 0)')->fetchColumn() !== 1) {
            throw new DomainException('The payout scheduler is already running. Try again in a moment.');
        }

        $summary = ['closed' => [], 'stamped' => 0, 'requests' => [], 'recalculated' => 0, 'errors' => []];
        try {
            foreach (EventRegistrationService::dueForClosing($db) as $event) {
                try {
                    if (EventRegistrationService::close($db, (int) $event['id'], $actorUserId)) {
                        $summary['closed'][] = '#' . $event['id'] . ' ' . $event['name'];
                    }
                } catch (Throwable $e) {
                    $summary['errors'][] = 'Closing event #' . $event['id'] . ': ' . $e->getMessage();
                }
            }
            $summary['stamped'] = EventRegistrationService::markAllClosed($db);

            $candidates = $db->query(
                'SELECT DISTINCT l.event_id FROM organizer_ledger_entries l JOIN events e ON e.id = l.event_id
                 WHERE l.payout_request_id IS NULL AND e.registration_closed_at IS NOT NULL ORDER BY l.event_id'
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($candidates as $eventId) {
                try {
                    if ($requestId = self::createForEvent($db, (int) $eventId, $actorUserId)) {
                        $summary['requests'][] = $requestId;
                    }
                } catch (Throwable $e) {
                    $summary['errors'][] = 'Payout request for event #' . $eventId . ': ' . $e->getMessage();
                }
            }

            $all = $db->query('SELECT event_id FROM organizer_ledger_entries UNION SELECT event_id FROM organizer_event_earnings')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all as $eventId) {
                try {
                    self::recalculate($db, (int) $eventId, $actorUserId);
                    $summary['recalculated']++;
                } catch (Throwable $e) {
                    $summary['errors'][] = 'Earnings for event #' . $eventId . ': ' . $e->getMessage();
                }
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK("evora_process_event_payouts")');
        }

        foreach ($summary['errors'] as $error) {
            Logger::log($actorUserId, 'payout_scheduler_error', $error);
        }
        Logger::log($actorUserId, 'payout_scheduler_run', count($summary['closed']) . ' registration(s) closed, '
            . count($summary['requests']) . ' payout request(s) created, ' . count($summary['errors']) . ' error(s)');

        return $summary;
    }

    // ---------------------------------------------------------------------
    // Admin actions (amounts are never read from the request)
    // ---------------------------------------------------------------------

    /**
     * Approves a pending request after re-checking its entries, and fixes
     * the destination: the VERIFIED payout account the admin selected (the
     * organizer's primary when none is given). The account id and a masked
     * snapshot are saved on the request; the database refuses any change to
     * them afterwards and any destination that is not the organizer's own
     * verified account.
     */
    public static function approve(PDO $db, int $requestId, int $adminId, string $remarks = '', ?int $payoutAccountId = null): void
    {
        $request = self::transition($db, $requestId, $adminId, ['pending'], 'approved', $remarks, function (PDO $db, array $request) use ($adminId, $remarks, $payoutAccountId) {
            $ctx = $db->prepare('SELECT e.status AS event_status, o.status AS organizer_status, o.user_id AS organizer_user_id
                                 FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :eid');
            $ctx->execute(['eid' => $request['event_id']]);
            $context = $ctx->fetch();
            if (in_array($context['event_status'], ['cancelled', 'removed'], true)) {
                throw new DomainException('The event is ' . $context['event_status'] . '. Reject this request and refund participants instead.');
            }
            if ($context['organizer_status'] !== 'approved') {
                throw new DomainException('The organizer is not in good standing (' . $context['organizer_status'] . '). Payouts are on hold.');
            }
            if ((int) $context['organizer_user_id'] === $adminId) {
                throw new DomainException('You cannot approve a payout to your own organizer account.');
            }
            self::assertEntriesMatch($db, $request);

            $account = $payoutAccountId
                ? PayoutService::findForOrganizer($db, $payoutAccountId, (int) $request['organizer_id'])
                : PayoutService::primaryAccount($db, (int) $request['organizer_id']);
            if (!$account) {
                throw new DomainException($payoutAccountId ? 'That payout account does not belong to this organizer.' : 'The organizer has no verified payout account yet. Verify one under Payout Accounts first.');
            }
            if ($account['status'] !== 'verified') {
                throw new DomainException('Payouts can only be sent to a verified account. ' . PayoutService::describe($account) . ' is ' . strtolower(PayoutService::ACCOUNT_LABELS[$account['status']]) . '.');
            }
            $db->prepare(
                'UPDATE organizer_payout_requests SET status = "approved", approved_by = :by, approved_at = NOW(),
                    payout_account_id = :aid, method = :method, account_name_snapshot = :name,
                    account_number_masked = :masked, bank_name_snapshot = :bank, remarks = :remarks
                 WHERE id = :id'
            )->execute([
                'by' => $adminId, 'aid' => $account['id'], 'method' => $account['method'], 'name' => $account['account_name'],
                'masked' => PayoutService::mask($account['account_number']), 'bank' => $account['bank_name'],
                'remarks' => $remarks !== '' ? mb_substr($remarks, 0, 500) : null, 'id' => $request['id'],
            ]);
        });

        $request = self::find($db, $requestId);
        self::notifyOrganizer($db, (int) $request['organizer_id'], 'Payout Approved',
            'Payout ' . $request['request_no'] . ' for ' . currency($request['amount']) . ' was approved and will be sent to ' . PayoutService::methodLabel($request['method']) . ' ' . $request['account_number_masked'] . '.', ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $request['id']]);
    }

    public static function markProcessing(PDO $db, int $requestId, int $adminId, string $remarks = ''): void
    {
        self::transition($db, $requestId, $adminId, ['approved'], 'processing', $remarks, function (PDO $db, array $request) use ($adminId) {
            $db->prepare('UPDATE organizer_payout_requests SET status = "processing", processing_by = :by, processing_at = NOW() WHERE id = :id')
                ->execute(['by' => $adminId, 'id' => $request['id']]);
        });
        $request = self::find($db, $requestId);
        if ($request) {
            NotificationService::safely(fn () => self::notifyOrganizer($db, (int) $request['organizer_id'], 'Payout Processing',
                'Payout ' . $request['request_no'] . ' for ' . currency($request['amount']) . ' is now being sent to ' . PayoutService::methodLabel($request['method']) . ' ' . $request['account_number_masked'] . '.',
                ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $requestId]));
        }
    }

    /**
     * Records that the admin actually sent the money (outside EVORA).
     * Only from "processing", and only with BOTH the GCash/Maya/bank transfer
     * reference and a proof file (receipt / transfer confirmation). The
     * database repeats both rules.
     */
    public static function markPaid(PDO $db, int $requestId, int $adminId, string $reference, ?string $proofPath): void
    {
        $reference = trim($reference);
        if (!preg_match('/^[A-Za-z0-9 \-_.\/#]{4,100}$/', $reference)) {
            throw new DomainException('Enter the GCash/Maya/bank transfer reference number (4–100 letters, digits, - _ . / #).');
        }
        if ($proofPath === null || trim($proofPath) === '') {
            throw new DomainException('Upload the proof of transfer (receipt screenshot or bank confirmation) before marking the payout as paid.');
        }
        // Integrity of the stored proof file (shows later changes to the file; not whether its contents are true).
        $proofFile = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $proofPath);
        $proofHash = is_file($proofFile) ? hash_file('sha256', $proofFile) : false;
        if (!$proofHash) {
            throw new DomainException('The uploaded proof file could not be read. Please upload it again.');
        }
        try {
            self::transition($db, $requestId, $adminId, ['processing'], 'paid', 'Reference ' . $reference . '; proof ' . $proofPath, function (PDO $db, array $request) use ($adminId, $reference, $proofPath, $proofHash) {
                $db->prepare('UPDATE organizer_payout_requests SET status = "paid", paid_by = :by, paid_at = NOW(), reference_number = :ref, proof_path = :proof, proof_sha256 = :hash WHERE id = :id')
                    ->execute(['by' => $adminId, 'ref' => $reference, 'proof' => $proofPath, 'hash' => $proofHash, 'id' => $request['id']]);
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('That transfer reference was already used for another paid payout.');
            }
            throw $e;
        }

        $request = self::find($db, $requestId);
        self::notifyOrganizer($db, (int) $request['organizer_id'], 'Payout Sent',
            'Payout ' . $request['request_no'] . ' for ' . currency($request['amount']) . ' was sent to ' . PayoutService::methodLabel($request['method']) . ' ' . $request['account_number_masked'] . ' (ref ' . PayoutService::maskReference($reference) . ').', ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $request['id']]);
        if (class_exists('PayoutAccountabilityService')) {
            PayoutAccountabilityService::notifyAwaitingConfirmation($db, $request);
        }
    }

    // ---------------------------------------------------------------------
    // Full payout details (admin-only, audited)
    // ---------------------------------------------------------------------

    /**
     * The complete payout destination(s) for a request: after approval, only
     * the account fixed at approval; before approval, all of the organizer's
     * pending/verified accounts (primary first) so the admin can choose.
     * Not logged — callers must only use it after revealDetails().
     */
    public static function fullDetails(PDO $db, array $request): array
    {
        if ($request['payout_account_id']) {
            $stmt = $db->prepare('SELECT id, organizer_id, method, account_name, account_number, bank_name, status, is_primary FROM organizer_payout_accounts WHERE id = :id AND organizer_id = :oid');
            $stmt->execute(['id' => $request['payout_account_id'], 'oid' => $request['organizer_id']]);
        } else {
            $stmt = $db->prepare(
                'SELECT id, organizer_id, method, account_name, account_number, bank_name, status, is_primary FROM organizer_payout_accounts
                 WHERE organizer_id = :oid AND status IN ("pending", "verified") ORDER BY is_primary DESC, status = "verified" DESC, id'
            );
            $stmt->execute(['oid' => $request['organizer_id']]);
        }
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$account) {
            $account['fixed_at_approval'] = (bool) $request['payout_account_id'];
        }

        return $accounts;
    }

    /**
     * Admin opens the full payout details. Re-checks that the actor is an
     * active admin (defense in depth), writes an append-only access log row
     * and an activity log entry, and returns the details.
     */
    public static function revealDetails(PDO $db, int $requestId, int $adminId): array
    {
        self::assertActiveAdmin($db, $adminId);
        $request = self::find($db, $requestId);
        if (!$request) {
            throw new DomainException('Payout request not found.');
        }
        $details = self::fullDetails($db, $request);
        if (!$details) {
            throw new DomainException('The organizer has no payout account on file.');
        }

        foreach ($details as $account) {
            self::logReveal($db, $requestId, (int) $account['id'], $adminId);
        }
        Logger::log($adminId, 'payout_details_viewed', 'Viewed full payout details of ' . $request['request_no'] . ': '
            . implode('; ', array_map(fn($a) => '#' . $a['id'] . ' ' . PayoutService::describe($a), $details)));

        return $details;
    }

    /** Admin opens one account's full details on the Payout Accounts page (logged without a request). */
    public static function revealAccount(PDO $db, int $accountId, int $adminId): array
    {
        self::assertActiveAdmin($db, $adminId);
        $stmt = $db->prepare('SELECT * FROM organizer_payout_accounts WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new DomainException('Payout account not found.');
        }
        self::logReveal($db, null, $accountId, $adminId);
        Logger::log($adminId, 'payout_details_viewed', 'Viewed full details of payout account #' . $accountId . ' (organizer #' . $account['organizer_id'] . ', ' . PayoutService::describe($account) . ')');

        return $account;
    }

    private static function assertActiveAdmin(PDO $db, int $userId): void
    {
        $role = $db->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.status = "active"');
        $role->execute(['id' => $userId]);
        if ($role->fetchColumn() !== 'admin') {
            throw new DomainException('Only administrators can view full payout details.');
        }
    }

    private static function logReveal(PDO $db, ?int $requestId, int $accountId, int $adminId): void
    {
        $db->prepare('INSERT INTO payout_detail_access_logs (payout_request_id, payout_account_id, admin_user_id, ip_address) VALUES (:rid, :aid, :uid, :ip)')
            ->execute(['rid' => $requestId, 'aid' => $accountId, 'uid' => $adminId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    /** Who viewed the request's full payout details, newest first. */
    public static function detailAccessLog(PDO $db, int $requestId): array
    {
        $stmt = $db->prepare(
            'SELECT l.*, u.full_name AS admin_name FROM payout_detail_access_logs l JOIN users u ON u.id = l.admin_user_id
             WHERE l.payout_request_id = :rid ORDER BY l.id DESC'
        );
        $stmt->execute(['rid' => $requestId]);

        return $stmt->fetchAll();
    }

    /**
     * Rejects a request: its ledger entries are released and automatic
     * re-requests for the event are paused until an admin resumes them.
     */
    public static function reject(PDO $db, int $requestId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new DomainException('A reason of at least 10 characters is required to reject a payout request.');
        }
        self::transition($db, $requestId, $adminId, self::OPEN_STATUSES, 'rejected', $reason, function (PDO $db, array $request) use ($adminId, $reason) {
            $db->prepare('UPDATE organizer_payout_requests SET status = "rejected", rejected_by = :by, rejected_at = NOW(), reject_reason = :reason WHERE id = :id')
                ->execute(['by' => $adminId, 'reason' => mb_substr($reason, 0, 500), 'id' => $request['id']]);

            $entries = $db->prepare('SELECT id FROM organizer_ledger_entries WHERE payout_request_id = :rid FOR UPDATE');
            $entries->execute(['rid' => $request['id']]);
            $ids = $entries->fetchAll(PDO::FETCH_COLUMN);
            $db->prepare('UPDATE organizer_ledger_entries SET payout_request_id = NULL WHERE payout_request_id = :rid')->execute(['rid' => $request['id']]);
            foreach ($ids as $id) {
                FinanceHistory::record($db, 'ledger_entry', (int) $id, 'requested', 'released', $adminId, 'Payout request ' . $request['request_no'] . ' rejected');
            }

            $db->prepare('UPDATE organizer_event_earnings SET payout_paused_reason = :reason WHERE id = :id')
                ->execute(['reason' => mb_substr('Request ' . $request['request_no'] . ' rejected: ' . $reason, 0, 500), 'id' => $request['earnings_id']]);
        });

        $request = self::find($db, $requestId);
        self::notifyOrganizer($db, (int) $request['organizer_id'], 'Payout Request Rejected',
            'Payout ' . $request['request_no'] . ' was rejected: ' . $reason . '. EVORA will contact you before creating a new request.', ['category' => 'payout', 'notice_type' => 'status', 'reference_type' => 'payout_request', 'reference_id' => $request['id']]);
    }

    /** Clears a pause set by a rejection and immediately re-creates the request. */
    public static function resume(PDO $db, int $eventId, int $adminId, string $remarks): ?int
    {
        $remarks = trim($remarks);
        if (mb_strlen($remarks) < 10) {
            throw new DomainException('Explain what was fixed (at least 10 characters).');
        }
        $earnings = self::earningsForEvent($db, $eventId);
        if (!$earnings || $earnings['payout_paused_reason'] === null) {
            throw new DomainException('Payouts for this event are not paused.');
        }
        $db->prepare('UPDATE organizer_event_earnings SET payout_paused_reason = NULL WHERE id = :id AND payout_paused_reason IS NOT NULL')
            ->execute(['id' => $earnings['id']]);
        FinanceHistory::record($db, 'event_earnings', (int) $earnings['id'], 'on_hold', 'resumed', $adminId, $remarks);
        Logger::log($adminId, 'payout_resumed', 'Event #' . $eventId . ': ' . $remarks);

        return self::createForEvent($db, $eventId, $adminId);
    }

    /** Row-locked status change; records history; never reports a change that did not happen. */
    private static function transition(PDO $db, int $requestId, int $adminId, array $allowedFrom, string $to, string $reason, callable $apply): array
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM organizer_payout_requests WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new DomainException('Payout request not found.');
            }
            if (!in_array($request['status'], $allowedFrom, true)) {
                throw new DomainException('A ' . $request['status'] . ' payout request cannot be marked ' . $to . '.');
            }
            $apply($db, $request);
            $check = $db->prepare('SELECT status FROM organizer_payout_requests WHERE id = :id');
            $check->execute(['id' => $requestId]);
            if ($check->fetchColumn() !== $to) {
                throw new RuntimeException('Payout request #' . $requestId . ' was not updated to ' . $to . '.');
            }
            FinanceHistory::record($db, 'payout_request', $requestId, $request['status'], $to, $adminId, $reason !== '' ? $reason : null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Logger::log($adminId, 'payout_request_' . $to, $request['request_no'] . ' marked ' . $to . ($reason !== '' ? ': ' . $reason : ''));
        self::recalculate($db, (int) $request['event_id'], $adminId);

        return $request;
    }

    /** The request's linked entries must still add up to its recorded amounts and be free of holds. */
    private static function assertEntriesMatch(PDO $db, array $request): void
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) c, COALESCE(SUM(gross_amount), 0) gross, COALESCE(SUM(gateway_fee), 0) fee,
                    COALESCE(SUM(organizer_earnings), 0) earnings,
                    SUM(hold_reason IS NOT NULL OR gateway_fee IS NULL OR organizer_id <> :org OR event_id <> :eid) problems
             FROM organizer_ledger_entries WHERE payout_request_id = :rid'
        );
        $stmt->execute(['rid' => $request['id'], 'org' => $request['organizer_id'], 'eid' => $request['event_id']]);
        $actual = $stmt->fetch();
        if ((int) $actual['problems'] > 0
            || (int) $actual['c'] !== (int) $request['entry_count']
            || LedgerService::toCents($actual['gross']) !== LedgerService::toCents($request['gross_amount'])
            || LedgerService::toCents($actual['fee']) !== LedgerService::toCents($request['gateway_fee'])
            || LedgerService::toCents($actual['earnings']) !== LedgerService::toCents($request['amount'])) {
            throw new DomainException('This request no longer matches its ledger entries. Reject it; a corrected request can then be created.');
        }
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    private const SELECT_SQL = '
        SELECT r.*, e.name AS event_name, e.event_date, e.status AS event_status, e.registration_deadline, e.registration_closed_at,
               o.organization_name, o.user_id AS organizer_user_id,
               cu.full_name AS created_by_name, au.full_name AS approved_by_name, pu.full_name AS paid_by_name,
               xu.full_name AS rejected_by_name, gu.full_name AS processing_by_name,
               ca.id AS current_account_id, ca.method AS current_account_method, ca.account_name AS current_account_name,
               ca.account_number AS current_account_number, ca.bank_name AS current_account_bank, ca.status AS current_account_status
        FROM organizer_payout_requests r
        JOIN events e ON e.id = r.event_id
        JOIN organizers o ON o.id = r.organizer_id
        LEFT JOIN users cu ON cu.id = r.created_by
        LEFT JOIN users au ON au.id = r.approved_by
        LEFT JOIN users pu ON pu.id = r.paid_by
        LEFT JOIN users xu ON xu.id = r.rejected_by
        LEFT JOIN users gu ON gu.id = r.processing_by
        LEFT JOIN organizer_payout_accounts ca ON ca.id = (
            SELECT a.id FROM organizer_payout_accounts a WHERE a.organizer_id = r.organizer_id AND a.status IN ("pending", "verified")
            ORDER BY a.is_primary DESC, a.status = "verified" DESC, a.id DESC LIMIT 1)';

    /** One request, scoped to an organizer when $organizerId is given (organizer pages pass their session organizer). */
    public static function find(PDO $db, int $requestId, ?int $organizerId = null): ?array
    {
        $sql = self::SELECT_SQL . ' WHERE r.id = :id';
        $params = ['id' => $requestId];
        if ($organizerId !== null) {
            $sql .= ' AND r.organizer_id = :oid';
            $params['oid'] = $organizerId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }

    public static function listAll(PDO $db, array $filters = []): array
    {
        $sql = self::SELECT_SQL . ' WHERE 1 = 1';
        $params = [];
        if (!empty($filters['organizer_id'])) {
            $sql .= ' AND r.organizer_id = :oid';
            $params['oid'] = (int) $filters['organizer_id'];
        }
        if (!empty($filters['status']) && isset(self::STATUS_BADGES[$filters['status']])) {
            $sql .= ' AND r.status = :status';
            $params['status'] = $filters['status'];
        }
        $stmt = $db->prepare($sql . ' ORDER BY FIELD(r.status, "pending", "approved", "processing", "paid", "rejected"), r.id DESC');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function forOrganizer(PDO $db, int $organizerId): array
    {
        return self::listAll($db, ['organizer_id' => $organizerId]);
    }

    /** Count and amount per status: ['pending' => ['count' => n, 'amount' => '0.00'], ...]. */
    public static function totalsByStatus(PDO $db, ?int $organizerId = null): array
    {
        $sql = 'SELECT status, COUNT(*) c, COALESCE(SUM(amount), 0) total FROM organizer_payout_requests';
        $params = [];
        if ($organizerId !== null) {
            $sql .= ' WHERE organizer_id = :oid';
            $params['oid'] = $organizerId;
        }
        $stmt = $db->prepare($sql . ' GROUP BY status');
        $stmt->execute($params);

        $totals = [];
        foreach (array_keys(self::STATUS_BADGES) as $status) {
            $totals[$status] = ['count' => 0, 'amount' => '0.00'];
        }
        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['status']] = ['count' => (int) $row['c'], 'amount' => $row['total']];
        }

        return $totals;
    }

    /** Events whose automatic payout requests are paused after a rejection. */
    public static function pausedEvents(PDO $db): array
    {
        return $db->query(
            'SELECT er.*, e.name AS event_name, o.organization_name FROM organizer_event_earnings er
             JOIN events e ON e.id = er.event_id JOIN organizers o ON o.id = er.organizer_id
             WHERE er.payout_paused_reason IS NOT NULL ORDER BY er.updated_at DESC'
        )->fetchAll();
    }

    /** Closed events whose eligible entries are still waiting for the scheduler. */
    public static function awaitingScheduler(PDO $db): array
    {
        return $db->query(
            'SELECT er.*, e.name AS event_name, e.registration_closed_at, o.organization_name FROM organizer_event_earnings er
             JOIN events e ON e.id = er.event_id JOIN organizers o ON o.id = er.organizer_id
             WHERE er.status = "closed" AND er.payout_paused_reason IS NULL
               AND EXISTS (SELECT 1 FROM organizer_ledger_entries l WHERE l.event_id = er.event_id AND l.payout_request_id IS NULL)
             ORDER BY e.registration_closed_at'
        )->fetchAll();
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    /** $meta: category, notice_type, reference_type, reference_id (see NotificationService). */
    public static function notifyOrganizer(PDO $db, int $organizerId, string $title, string $message, array $meta = [], string $type = 'finance'): void
    {
        $stmt = $db->prepare('SELECT user_id FROM organizers WHERE id = :id');
        $stmt->execute(['id' => $organizerId]);
        if ($userId = $stmt->fetchColumn()) {
            notify((int) $userId, $title, $message, $type, $meta);
        }
    }

    public static function notifyAdmins(PDO $db, string $title, string $message, array $meta = [], string $type = 'finance'): void
    {
        foreach ($db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" AND u.status = "active"')->fetchAll(PDO::FETCH_COLUMN) as $adminUserId) {
            notify((int) $adminUserId, $title, $message, $type, $meta);
        }
    }
}
