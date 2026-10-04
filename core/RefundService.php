<?php

/**
 * Manual refund management (v30.1) for payments EVORA collected through
 * PayMongo. EVORA never calls the PayMongo refund API: the admin refunds from
 * the PayMongo dashboard and records the reference + proof here.
 *
 *   participant request (pending)  ─┐
 *   admin Refund All (cancelled event, approved) ─┤
 *        -> admin approve (ledger entry held out of payouts)
 *        -> admin refunds externally, enters reference + proof
 *        -> complete(): payment "refunded", registration "cancelled",
 *           QR invalidated, ledger reversal, earnings recalculated,
 *           participant / organizer / admin notified
 *   (or reject: hold released, participant told why)
 *
 * Two kinds of refund:
 *   registration      the participant's paid registration fee (cancels the
 *                     registration)
 *   duplicate_charge  a second PayMongo charge EVORA flagged for the same
 *                     registration (payment_history 'paymongo_duplicate_charge');
 *                     the registration stays valid and the ledger is untouched,
 *                     because the second charge never entered it.
 *
 * Amounts are always copied from payments.amount (a database trigger checks
 * it); no form sends an amount. Every status change is written to the
 * append-only finance_status_history and the activity log.
 */
class RefundService
{
    public const STATUSES = [
        'pending' => ['Pending Refund Request', 'warning'],
        'approved' => ['Approved – Processing', 'info'],
        'completed' => ['Refunded', 'success'],
        'rejected' => ['Rejected', 'danger'],
    ];

    public const REASONS = [
        'cannot_attend' => 'Cannot attend',
        'duplicate_payment' => 'Duplicate payment',
        'other' => 'Other',
        'event_cancelled' => 'Event cancelled',
    ];

    /** Reasons a participant can choose (event_cancelled is admin-only, via Refund All). */
    public const PARTICIPANT_REASONS = ['cannot_attend', 'duplicate_payment', 'other'];

    /** Events a participant can no longer ask a "cannot attend" refund for. */
    public const STARTED_EVENT_STATUSES = ['ongoing', 'completed', 'archived'];

    public const REFUND_ALL_EVENT_STATUSES = ['cancelled', 'removed'];

    private const SELECT_SQL = '
        SELECT rf.*, pay.status AS payment_status, pay.payment_channel, pay.gateway_payment_id, pay.paid_at,
               pay.reference_no AS payment_reference, r.status AS registration_status, r.event_id, r.participant_id,
               e.name AS event_name, e.status AS event_status, e.event_date, e.organizer_id,
               o.organization_name, u.id AS participant_user_id, u.full_name AS participant_name, u.email AS participant_email,
               rq.full_name AS requested_by_name, ap.full_name AS approved_by_name,
               rj.full_name AS rejected_by_name, rb.full_name AS refunded_by_name
        FROM payment_refunds rf
        JOIN payments pay ON pay.id = rf.payment_id
        JOIN registrations r ON r.id = rf.registration_id
        JOIN events e ON e.id = r.event_id
        JOIN organizers o ON o.id = e.organizer_id
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        JOIN users rq ON rq.id = rf.requested_by
        LEFT JOIN users ap ON ap.id = rf.approved_by
        LEFT JOIN users rj ON rj.id = rf.rejected_by
        LEFT JOIN users rb ON rb.id = rf.refunded_by';

    // ---------------------------------------------------------------------
    // Display helpers
    // ---------------------------------------------------------------------

    public static function number(int $refundId): string
    {
        return 'RF-' . str_pad((string) $refundId, 6, '0', STR_PAD_LEFT);
    }

    public static function badge(string $status): string
    {
        [$label, $color] = self::STATUSES[$status] ?? [ucfirst($status), 'secondary'];

        return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
    }

    public static function reasonLabel(string $code): string
    {
        return self::REASONS[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    public static function find(PDO $db, int $refundId): ?array
    {
        $stmt = $db->prepare(self::SELECT_SQL . ' WHERE rf.id = :id');
        $stmt->execute(['id' => $refundId]);

        return $stmt->fetch() ?: null;
    }

    public static function listAll(PDO $db, string $status = '', int $eventId = 0): array
    {
        $where = [];
        $params = [];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'rf.status = :status';
            $params['status'] = $status;
        }
        if ($eventId > 0) {
            $where[] = 'r.event_id = :eid';
            $params['eid'] = $eventId;
        }
        $stmt = $db->prepare(self::SELECT_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY FIELD(rf.status, "pending", "approved", "completed", "rejected"), rf.id DESC');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function counts(PDO $db): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->query('SELECT status, COUNT(*) c FROM payment_refunds GROUP BY status')->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /** The participant's refunds, newest first, grouped by registration id. */
    public static function forParticipant(PDO $db, int $participantId): array
    {
        $stmt = $db->prepare(self::SELECT_SQL . ' WHERE r.participant_id = :pid ORDER BY rf.id DESC');
        $stmt->execute(['pid' => $participantId]);
        $byRegistration = [];
        foreach ($stmt->fetchAll() as $refund) {
            $byRegistration[(int) $refund['registration_id']][] = $refund;
        }

        return $byRegistration;
    }

    /**
     * The payment's organizer ledger entry with its payout situation, or null
     * (payment made before the ledger existed). $lock = SELECT ... FOR UPDATE.
     */
    public static function ledgerEntry(PDO $db, int $paymentId, bool $lock = false): ?array
    {
        $stmt = $db->prepare(
            'SELECT l.*, pr.status AS request_status, pr.request_no, rv.id AS reversal_id
             FROM organizer_ledger_entries l
             LEFT JOIN organizer_payout_requests pr ON pr.id = l.payout_request_id
             LEFT JOIN organizer_ledger_entries rv ON rv.reverses_entry_id = l.id
             WHERE l.payment_id = :pid AND l.entry_type = "payment"' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['pid' => $paymentId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Checks whether the signed-in participant may ask for a refund of this
     * registration's payment. Throws DomainException with the reason if not.
     * Returns the registration/payment row plus duplicate_history_id.
     */
    public static function eligibility(PDO $db, int $registrationId, int $participantUserId, string $reasonCode): array
    {
        $stmt = $db->prepare(
            'SELECT r.id AS registration_id, r.status AS registration_status, r.event_id,
                    e.name AS event_name, e.status AS event_status, e.event_date,
                    pay.id AS payment_id, pay.amount, pay.status AS payment_status, pay.payment_channel
             FROM registrations r
             JOIN participants p ON p.id = r.participant_id
             JOIN events e ON e.id = r.event_id
             LEFT JOIN payments pay ON pay.registration_id = r.id
             WHERE r.id = :rid AND p.user_id = :uid'
        );
        $stmt->execute(['rid' => $registrationId, 'uid' => $participantUserId]);
        $row = $stmt->fetch();

        if (!$row || !$row['payment_id']) {
            throw new DomainException('Registration or payment not found.');
        }
        if ($row['payment_channel'] === 'paypal') {
            throw new DomainException('This payment was paid directly to the organizer\'s PayPal account. EVORA does not collect or hold PayPal funds, so EVORA cannot refund it — please ask the organizer, who refunds PayPal payments from their own PayPal account.');
        }
        if ($row['payment_channel'] !== 'paymongo') {
            throw new DomainException('This payment was made directly to the organizer (manual/cash), so EVORA cannot refund it. Please ask the organizer.');
        }
        if ($row['payment_status'] !== 'approved') {
            throw new DomainException($row['payment_status'] === 'refunded' ? 'This payment was already refunded.' : 'Only paid registrations can be refunded.');
        }

        $row['duplicate_history_id'] = null;
        if ($reasonCode === 'duplicate_payment') {
            $dup = $db->prepare(
                'SELECT h.id FROM payment_history h
                 WHERE h.payment_id = :pid AND h.action = "paymongo_duplicate_charge"
                   AND NOT EXISTS (SELECT 1 FROM payment_refunds f WHERE f.duplicate_history_id = h.id AND f.status <> "rejected")
                 ORDER BY h.id LIMIT 1'
            );
            $dup->execute(['pid' => $row['payment_id']]);
            $historyId = $dup->fetchColumn();
            if (!$historyId) {
                throw new DomainException('EVORA has no record of an extra charge for this payment (or it is already being refunded). If you were charged twice, contact EVORA support with both receipts.');
            }
            $row['duplicate_history_id'] = (int) $historyId;

            return $row;
        }

        if ($row['registration_status'] !== 'approved') {
            throw new DomainException('This registration is ' . $row['registration_status'] . ' and cannot be refunded.');
        }
        $eventOff = in_array($row['event_status'], self::REFUND_ALL_EVENT_STATUSES, true);
        if (!$eventOff && (in_array($row['event_status'], self::STARTED_EVENT_STATUSES, true) || $row['event_date'] < date('Y-m-d'))) {
            throw new DomainException('Refunds can only be requested before the event starts.');
        }
        $att = $db->prepare('SELECT 1 FROM attendance WHERE registration_id = :rid');
        $att->execute(['rid' => $registrationId]);
        if ($att->fetch()) {
            throw new DomainException('You already checked in to this event, so the fee cannot be refunded.');
        }
        $live = $db->prepare('SELECT status FROM payment_refunds WHERE payment_id = :pid AND refund_type = "registration" AND status <> "rejected"');
        $live->execute(['pid' => $row['payment_id']]);
        if ($status = $live->fetchColumn()) {
            throw new DomainException('A refund for this registration is already ' . ($status === 'completed' ? 'completed' : 'in progress') . '.');
        }

        return $row;
    }

    // ---------------------------------------------------------------------
    // Participant: request
    // ---------------------------------------------------------------------

    public static function request(PDO $db, int $registrationId, int $participantUserId, string $reasonCode, string $details): int
    {
        if (!in_array($reasonCode, self::PARTICIPANT_REASONS, true)) {
            throw new DomainException('Choose a reason for the refund.');
        }
        $details = trim($details);
        if ($reasonCode === 'other' && mb_strlen($details) < 10) {
            throw new DomainException('Please describe the reason (at least 10 characters).');
        }
        if (mb_strlen($details) > 500) {
            throw new DomainException('The reason can be at most 500 characters.');
        }

        $db->beginTransaction();
        try {
            $ctx = self::eligibility($db, $registrationId, $participantUserId, $reasonCode);
            $db->prepare('SELECT id FROM payments WHERE id = :id FOR UPDATE')->execute(['id' => $ctx['payment_id']]);

            // amount comes from the payment row (the insert trigger re-checks it)
            $db->prepare(
                'INSERT INTO payment_refunds (payment_id, registration_id, refund_type, duplicate_history_id, amount, reason_code, reason, status, requested_by)
                 SELECT pay.id, pay.registration_id, :type, :dup, pay.amount, :code, :reason, "pending", :by FROM payments pay WHERE pay.id = :pid'
            )->execute([
                'type' => $ctx['duplicate_history_id'] ? 'duplicate_charge' : 'registration',
                'dup' => $ctx['duplicate_history_id'], 'code' => $reasonCode,
                'reason' => $details !== '' ? $details : null, 'by' => $participantUserId, 'pid' => $ctx['payment_id'],
            ]);
            $refundId = (int) $db->lastInsertId();
            FinanceHistory::record($db, 'payment_refund', $refundId, null, 'pending', $participantUserId, self::reasonLabel($reasonCode) . ($details !== '' ? ': ' . $details : ''));
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                throw new DomainException('A refund request for this payment is already open.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Logger::log($participantUserId, 'refund_requested', self::number($refundId) . ' for registration #' . $registrationId . ' (' . $reasonCode . ')');
        $label = self::number($refundId);
        notify($participantUserId, 'Refund Request Submitted',
            'Your refund request ' . $label . ' for "' . $ctx['event_name'] . '" (' . currency($ctx['amount']) . ') was submitted and is waiting for admin review.', 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId]);
        $organizerId = (int) (self::find($db, $refundId)['organizer_id'] ?? 0);
        PayoutRequestService::notifyOrganizer($db, $organizerId, 'Refund Requested',
            'A participant requested a refund for "' . $ctx['event_name'] . '" (' . currency($ctx['amount']) . '). No action needed — an EVORA admin reviews it.', ['category' => 'refund', 'notice_type' => 'informational', 'reference_type' => 'refund', 'reference_id' => $refundId], 'refund');
        PayoutRequestService::notifyAdmins($db, 'New Refund Request',
            $label . ' requires review: ' . self::reasonLabel($reasonCode) . ' — ' . $ctx['event_name'] . ' (' . currency($ctx['amount']) . ').', ['category' => 'refund', 'notice_type' => 'action_required', 'reference_type' => 'refund', 'reference_id' => $refundId], 'refund');

        return $refundId;
    }

    // ---------------------------------------------------------------------
    // Admin: review
    // ---------------------------------------------------------------------

    public static function approve(PDO $db, int $refundId, int $adminId, string $remarks = ''): void
    {
        self::assertActiveAdmin($db, $adminId);
        $remarks = mb_substr(trim($remarks), 0, 500);
        $heldEntry = false;

        $db->beginTransaction();
        try {
            $refund = self::lock($db, $refundId);
            if ($refund['status'] !== 'pending') {
                throw new DomainException('Only pending refund requests can be approved.');
            }
            if ((int) $refund['requested_by'] === $adminId) {
                throw new DomainException('You cannot approve a refund you requested.');
            }
            $payment = self::lockPayment($db, (int) $refund['payment_id']);
            if ($payment['status'] !== 'approved') {
                throw new DomainException('The payment is ' . $payment['status'] . ', not paid, so it cannot be refunded.');
            }

            if ($refund['refund_type'] === 'registration' && ($entry = self::ledgerEntry($db, (int) $refund['payment_id'], true)) && !$entry['reversal_id']) {
                if (in_array($entry['request_status'], PayoutRequestService::OPEN_STATUSES, true)) {
                    throw new DomainException('This payment is part of payout request ' . $entry['request_no'] . ' (' . $entry['request_status']
                        . '). Reject that payout request, or wait until it is paid, then approve the refund.');
                }
                // keep it out of the organizer's next payout while the money goes back
                $heldEntry = LedgerService::holdForRefund($db, (int) $entry['id'], $adminId, 'Refund ' . self::number($refundId) . ' approved');
            }

            $db->prepare('UPDATE payment_refunds SET status = "approved", approved_by = :by, approved_at = NOW(), review_remarks = :remarks WHERE id = :id')
                ->execute(['by' => $adminId, 'remarks' => $remarks !== '' ? $remarks : null, 'id' => $refundId]);
            FinanceHistory::record($db, 'payment_refund', $refundId, 'pending', 'approved', $adminId, $remarks !== '' ? $remarks : null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $refund = self::find($db, $refundId);
        if ($heldEntry) {
            PayoutRequestService::recalculateQuietly($db, (int) $refund['event_id']);
        }
        Logger::log($adminId, 'refund_approved', self::number($refundId) . ' approved');
        notify((int) $refund['participant_user_id'], 'Refund Approved',
            'Your refund ' . self::number($refundId) . ' for "' . $refund['event_name'] . '" (' . currency($refund['amount']) . ') was approved and is being processed through PayMongo. We will notify you once the money is returned to your original payment method.', 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId]);
        self::notifyOtherAdmins($db, $adminId, 'Refund Requires Completion',
            self::number($refundId) . ' (' . currency($refund['amount']) . ', ' . $refund['event_name'] . ') was approved. Refund it in PayMongo, then complete the refund record with the refund ID and proof.', $refundId);
        NotificationService::safely(fn () => PayoutRequestService::notifyOrganizer($db, (int) $refund['organizer_id'], 'Refund Approved',
            'A participant refund ' . self::number($refundId) . ' (' . currency($refund['amount']) . ') for "' . $refund['event_name'] . '" was approved. It will be deducted from your earnings for this event.',
            ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId], 'refund'));
    }

    public static function reject(PDO $db, int $refundId, int $adminId, string $reason): void
    {
        self::assertActiveAdmin($db, $adminId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new DomainException('Give the participant a reason of 10–500 characters when rejecting a refund.');
        }
        $released = false;

        $db->beginTransaction();
        try {
            $refund = self::lock($db, $refundId);
            if (!in_array($refund['status'], ['pending', 'approved'], true)) {
                throw new DomainException('This refund is already ' . $refund['status'] . '.');
            }
            $regStatus = $db->prepare('SELECT status FROM registrations WHERE id = :id');
            $regStatus->execute(['id' => $refund['registration_id']]);
            if ($refund['ledger_reversal_id'] || ($refund['refund_type'] === 'registration' && $regStatus->fetchColumn() === 'cancelled')) {
                throw new DomainException('This registration was already cancelled (event cancelled) — the refund must be completed, not rejected.');
            }
            if ($refund['refund_type'] === 'registration' && ($entry = self::ledgerEntry($db, (int) $refund['payment_id'], true))) {
                $released = LedgerService::releaseRefundHold($db, (int) $entry['id'], $adminId, 'Refund ' . self::number($refundId) . ' rejected');
            }
            $db->prepare('UPDATE payment_refunds SET status = "rejected", rejected_by = :by, rejected_at = NOW(), review_remarks = :reason WHERE id = :id')
                ->execute(['by' => $adminId, 'reason' => $reason, 'id' => $refundId]);
            FinanceHistory::record($db, 'payment_refund', $refundId, $refund['status'], 'rejected', $adminId, $reason);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $refund = self::find($db, $refundId);
        if ($released) {
            PayoutRequestService::recalculateQuietly($db, (int) $refund['event_id']);
        }
        Logger::log($adminId, 'refund_rejected', self::number($refundId) . ' rejected: ' . $reason);
        notify((int) $refund['participant_user_id'], 'Refund Request Rejected',
            'Your refund request ' . self::number($refundId) . ' for "' . $refund['event_name'] . '" was rejected. Reason: ' . $reason, 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId]);
        NotificationService::safely(fn () => PayoutRequestService::notifyOrganizer($db, (int) $refund['organizer_id'], 'Refund Rejected',
            'Refund request ' . self::number($refundId) . ' for "' . $refund['event_name'] . '" was rejected. Your earnings are not affected.',
            ['category' => 'refund', 'notice_type' => 'informational', 'reference_type' => 'refund', 'reference_id' => $refundId], 'refund'));
        NotificationService::toOtherAdmins($db, $adminId, 'Refund Rejected',
            self::number($refundId) . ' (' . $refund['event_name'] . ') was rejected by another administrator. Reason: ' . $reason, 'refund',
            ['category' => 'refund', 'notice_type' => 'informational', 'reference_type' => 'refund', 'reference_id' => $refundId]);
    }

    // ---------------------------------------------------------------------
    // Admin: complete (after refunding externally)
    // ---------------------------------------------------------------------

    public static function complete(PDO $db, int $refundId, int $adminId, string $reference, ?string $proofPath): void
    {
        self::assertActiveAdmin($db, $adminId);
        $reference = trim($reference);
        if (!preg_match('/^[A-Za-z0-9 \-_.\/#]{4,100}$/', $reference)) {
            throw new DomainException('Enter the refund reference number (4–100 letters, digits, - _ . / #), e.g. the PayMongo refund ID.');
        }
        if ($proofPath === null || trim($proofPath) === '') {
            throw new DomainException('Upload the refund proof (PayMongo refund screenshot or receipt) before completing the refund.');
        }
        $ledgerChanged = false;

        $db->beginTransaction();
        try {
            $refund = self::lock($db, $refundId);
            if ($refund['status'] !== 'approved') {
                throw new DomainException($refund['status'] === 'pending'
                    ? 'Approve the refund request before completing it.'
                    : 'This refund is already ' . $refund['status'] . '.');
            }
            if ((int) $refund['requested_by'] === $adminId && $refund['reason_code'] !== 'event_cancelled') {
                throw new DomainException('You cannot complete a refund you requested.');
            }
            $payment = self::lockPayment($db, (int) $refund['payment_id']);
            $label = self::number($refundId);

            if ($refund['refund_type'] === 'registration') {
                if ($payment['status'] !== 'approved') {
                    throw new DomainException('The payment is ' . $payment['status'] . ', so this refund cannot be completed.');
                }
                $reversalId = $refund['ledger_reversal_id'] ? (int) $refund['ledger_reversal_id'] : null;
                if ($entry = self::ledgerEntry($db, (int) $refund['payment_id'], true)) {
                    LedgerService::releaseRefundHold($db, (int) $entry['id'], $adminId, 'Refund ' . $label . ' completed');
                    if (!$entry['reversal_id']) {
                        $reversalId = LedgerService::reverseInTransaction($db, (int) $entry['id'], 'Refund ' . $label . ' (' . self::reasonLabel($refund['reason_code']) . ')', $adminId);
                    }
                    $reversalId ??= (int) $entry['reversal_id'];
                    $ledgerChanged = true;
                }

                $db->prepare(
                    'UPDATE payment_refunds SET status = "completed", refund_reference = :ref, proof_path = :proof, refunded_by = :by,
                        completed_at = NOW(), ledger_reversal_id = :rev WHERE id = :id'
                )->execute(['ref' => $reference, 'proof' => $proofPath, 'by' => $adminId, 'rev' => $reversalId, 'id' => $refundId]);

                $db->prepare('UPDATE payments SET status = "refunded" WHERE id = :id AND status = "approved"')->execute(['id' => $payment['id']]);
                self::paymentHistory($db, (int) $payment['id'], 'refunded', $adminId, $label . ' completed, reference ' . $reference);
                self::cancelRegistration($db, (int) $refund['registration_id'], $label . ' completed');
            } else {
                $db->prepare(
                    'UPDATE payment_refunds SET status = "completed", refund_reference = :ref, proof_path = :proof, refunded_by = :by, completed_at = NOW() WHERE id = :id'
                )->execute(['ref' => $reference, 'proof' => $proofPath, 'by' => $adminId, 'id' => $refundId]);
                self::paymentHistory($db, (int) $payment['id'], 'duplicate_charge_refunded', $adminId, $label . ' (extra charge) completed, reference ' . $reference);
            }

            FinanceHistory::record($db, 'payment_refund', $refundId, 'approved', 'completed', $adminId, 'Reference ' . $reference . '; proof ' . $proofPath);
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                throw new DomainException('That refund reference was already used for another refund.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $refund = self::find($db, $refundId);
        if ($ledgerChanged) {
            self::recalculateLogged($db, (int) $refund['event_id'], $adminId);
        }
        Logger::log($adminId, 'refund_completed', self::number($refundId) . ' completed (ref ' . $reference . ')');

        notify((int) $refund['participant_user_id'], 'Refund Completed',
            'Your refund ' . self::number($refundId) . ' of ' . currency($refund['amount']) . ' for "' . $refund['event_name'] . '" was sent back to your original payment method (ref ' . PayoutService::maskReference($reference) . ').'
            . ($refund['refund_type'] === 'registration' ? ' Your registration and QR code are now cancelled.' : ''), 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId]);
        if ($ledgerChanged) {
            PayoutRequestService::notifyOrganizer($db, (int) $refund['organizer_id'], 'Participant Refund',
                'A participant refund affected your earnings: ' . currency($refund['amount']) . ' for "' . $refund['event_name'] . '" was refunded, and your event earnings were recalculated.', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId], 'refund');
        }
        notify($adminId, 'Refund Completed', 'Refund ' . self::number($refundId) . ' completed successfully.', 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'refund', 'reference_id' => $refundId]);
    }

    // ---------------------------------------------------------------------
    // Admin: Refund All (cancelled / removed event)
    // ---------------------------------------------------------------------

    /**
     * Events that are cancelled/removed and still have EVORA-collected
     * payments waiting for a refund, or approved refunds not yet completed.
     */
    public static function eventsNeedingRefunds(PDO $db): array
    {
        return $db->query(
            'SELECT e.id, e.name, e.status, e.event_date, o.organization_name,
                    SUM(pay.status = "approved" AND NOT EXISTS (SELECT 1 FROM payment_refunds f WHERE f.payment_id = pay.id
                        AND f.refund_type = "registration" AND f.status IN ("approved", "completed"))) AS to_refund,
                    SUM(EXISTS (SELECT 1 FROM payment_refunds f WHERE f.payment_id = pay.id AND f.refund_type = "registration" AND f.status = "approved")) AS processing,
                    SUM(CASE WHEN pay.status = "approved" THEN pay.amount ELSE 0 END) AS amount_to_return
             FROM events e
             JOIN organizers o ON o.id = e.organizer_id
             JOIN registrations r ON r.event_id = e.id
             JOIN payments pay ON pay.registration_id = r.id AND pay.payment_channel = "paymongo"
             WHERE e.status IN ("cancelled", "removed")
             GROUP BY e.id, e.name, e.status, e.event_date, o.organization_name
             HAVING to_refund > 0 OR processing > 0
             ORDER BY e.event_date DESC'
        )->fetchAll();
    }

    /**
     * Creates approved refund records for every paid PayMongo registration of a
     * cancelled/removed event, and immediately cancels those registrations,
     * invalidates their QR codes and reverses their ledger entries (one
     * transaction). The admin then refunds each one externally and completes
     * it with reference + proof, which marks the payment refunded.
     */
    public static function refundAllForEvent(PDO $db, int $eventId, int $adminId, string $remarks): array
    {
        self::assertActiveAdmin($db, $adminId);
        $remarks = trim($remarks);
        if (mb_strlen($remarks) < 10 || mb_strlen($remarks) > 500) {
            throw new DomainException('Enter a reason of 10–500 characters (it is shown to participants and the organizer).');
        }
        $summary = ['created' => 0, 'approved_existing' => 0, 'total_cents' => 0, 'paid_out_cents' => 0, 'refund_ids' => [], 'participants' => []];

        $db->beginTransaction();
        try {
            $eventStmt = $db->prepare('SELECT id, name, status, organizer_id FROM events WHERE id = :id FOR UPDATE');
            $eventStmt->execute(['id' => $eventId]);
            $event = $eventStmt->fetch();
            if (!$event) {
                throw new DomainException('Event not found.');
            }
            if (!in_array($event['status'], self::REFUND_ALL_EVENT_STATUSES, true)) {
                throw new DomainException('Refund All is only for cancelled or removed events. This event is ' . $event['status'] . '.');
            }
            $open = $db->prepare('SELECT request_no, status FROM organizer_payout_requests WHERE event_id = :eid AND status IN ("pending", "approved", "processing") FOR UPDATE');
            $open->execute(['eid' => $eventId]);
            if ($openRequest = $open->fetch()) {
                throw new DomainException('Payout request ' . $openRequest['request_no'] . ' for this event is ' . $openRequest['status']
                    . '. Reject it first (Organizer Payout Requests) so the collected money is not paid out, then run Refund All.');
            }

            $payStmt = $db->prepare(
                'SELECT pay.*, u.id AS participant_user_id FROM payments pay
                 JOIN registrations r ON r.id = pay.registration_id
                 JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
                 WHERE r.event_id = :eid AND pay.payment_channel = "paymongo" AND pay.status = "approved"
                   AND NOT EXISTS (SELECT 1 FROM payment_refunds f WHERE f.payment_id = pay.id AND f.refund_type = "registration" AND f.status = "approved")
                 ORDER BY pay.id FOR UPDATE'
            );
            $payStmt->execute(['eid' => $eventId]);
            $payments = $payStmt->fetchAll();
            if (!$payments) {
                throw new DomainException('Nothing left to refund: every paid PayMongo registration of this event is already refunded or has an approved refund waiting to be completed.');
            }

            foreach ($payments as $payment) {
                $existing = $db->prepare('SELECT * FROM payment_refunds WHERE payment_id = :pid AND refund_type = "registration" AND status <> "rejected" FOR UPDATE');
                $existing->execute(['pid' => $payment['id']]);
                $refund = $existing->fetch();

                if ($refund && $refund['status'] === 'pending') {
                    $db->prepare('UPDATE payment_refunds SET status = "approved", approved_by = :by, approved_at = NOW(), review_remarks = :remarks WHERE id = :id')
                        ->execute(['by' => $adminId, 'remarks' => 'Event cancelled: ' . $remarks, 'id' => $refund['id']]);
                    FinanceHistory::record($db, 'payment_refund', (int) $refund['id'], 'pending', 'approved', $adminId, 'Refund All — event cancelled: ' . $remarks);
                    $refundId = (int) $refund['id'];
                    $summary['approved_existing']++;
                } else {
                    $db->prepare(
                        'INSERT INTO payment_refunds (payment_id, registration_id, refund_type, amount, reason_code, reason, status, requested_by, approved_by, approved_at)
                         VALUES (:pid, :rid, "registration", :amount, "event_cancelled", :reason, "approved", :by, :by2, NOW())'
                    )->execute([
                        'pid' => $payment['id'], 'rid' => $payment['registration_id'], 'amount' => $payment['amount'],
                        'reason' => $remarks, 'by' => $adminId, 'by2' => $adminId,
                    ]);
                    $refundId = (int) $db->lastInsertId();
                    FinanceHistory::record($db, 'payment_refund', $refundId, null, 'approved', $adminId, 'Refund All — event cancelled: ' . $remarks);
                    $summary['created']++;
                }
                $label = self::number($refundId);

                if ($entry = self::ledgerEntry($db, (int) $payment['id'], true)) {
                    LedgerService::releaseRefundHold($db, (int) $entry['id'], $adminId, 'Refund ' . $label . ' — event cancelled');
                    if (!$entry['reversal_id']) {
                        if ($entry['request_status'] === 'paid') {
                            $summary['paid_out_cents'] += LedgerService::toCents($entry['net_amount']);
                        }
                        $reversalId = LedgerService::reverseInTransaction($db, (int) $entry['id'], 'Refund ' . $label . ' (event cancelled)', $adminId);
                    } else {
                        $reversalId = (int) $entry['reversal_id'];
                    }
                    $db->prepare('UPDATE payment_refunds SET ledger_reversal_id = :rev WHERE id = :id AND ledger_reversal_id IS NULL')
                        ->execute(['rev' => $reversalId, 'id' => $refundId]);
                }
                self::cancelRegistration($db, (int) $payment['registration_id'], $label . ' — event cancelled');

                $summary['total_cents'] += LedgerService::toCents($payment['amount']);
                $summary['refund_ids'][] = $refundId;
                $summary['participants'][(int) $payment['participant_user_id']] = true;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::recalculateLogged($db, $eventId, $adminId);
        $count = count($summary['refund_ids']);
        Logger::log($adminId, 'refund_all', 'Event #' . $eventId . ': ' . $count . ' refund(s), ' . LedgerService::fromCents($summary['total_cents']) . ' — ' . $remarks);

        foreach (array_keys($summary['participants']) as $participantUserId) {
            notify($participantUserId, 'Event Cancelled — Refund in Progress',
                '"' . $event['name'] . '" was cancelled. Your registration and QR code are cancelled, and your payment is being refunded to your original payment method.', 'refund', ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]);
        }
        $organizerMessage = 'A participant refund affected your earnings: "' . $event['name'] . '" was cancelled and ' . $count
            . ' paid registration(s) (' . currency(LedgerService::fromCents($summary['total_cents'])) . ') are being refunded. Your event earnings were recalculated.';
        if ($summary['paid_out_cents'] > 0) {
            $organizerMessage .= ' ' . currency(LedgerService::fromCents($summary['paid_out_cents'])) . ' of it was already paid out to you and must be returned to EVORA.';
        }
        PayoutRequestService::notifyOrganizer($db, (int) $event['organizer_id'], 'Participant Refunds', $organizerMessage, ['category' => 'refund', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId], 'refund');

        if ($count > 0) {
            self::notifyOtherAdmins($db, $adminId, 'Refunds Require Completion',
                $count . ' refund(s) for "' . $event['name'] . '" were approved by Refund All. Refund each one in PayMongo, then complete its refund record.', null);
        }
        unset($summary['participants']);

        return $summary;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Registration -> cancelled and its QR code invalidated (caller's transaction). */
    private static function cancelRegistration(PDO $db, int $registrationId, string $reason): void
    {
        $db->prepare('UPDATE registrations SET status = "cancelled" WHERE id = :id AND status <> "cancelled"')->execute(['id' => $registrationId]);
        $db->prepare('UPDATE qr_codes SET invalidated_at = NOW(), invalidated_reason = :reason WHERE registration_id = :id AND invalidated_at IS NULL')
            ->execute(['reason' => mb_substr('Registration cancelled: ' . $reason, 0, 255), 'id' => $registrationId]);
    }

    private static function paymentHistory(PDO $db, int $paymentId, string $action, int $adminId, string $remarks): void
    {
        $db->prepare('INSERT INTO payment_history (payment_id, action, performed_by, remarks) VALUES (:pid, :action, :by, :remarks)')
            ->execute(['pid' => $paymentId, 'action' => $action, 'by' => $adminId, 'remarks' => mb_substr($remarks, 0, 500)]);
    }

    private static function lock(PDO $db, int $refundId): array
    {
        $stmt = $db->prepare('SELECT * FROM payment_refunds WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            throw new DomainException('Refund not found.');
        }

        return $refund;
    }

    private static function lockPayment(PDO $db, int $paymentId): array
    {
        $stmt = $db->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $paymentId]);

        return $stmt->fetch();
    }

    /** Earnings are recalculated right after a refund; a failure is logged, never hidden. */
    private static function recalculateLogged(PDO $db, int $eventId, int $adminId): void
    {
        try {
            PayoutRequestService::recalculate($db, $eventId, $adminId);
        } catch (Throwable $e) {
            Logger::log($adminId, 'earnings_recalculate_error', 'Event #' . $eventId . ' after refund: ' . $e->getMessage());
        }
    }

    /** Notification only (v30.2): tells the other active admins an approved refund still needs completing. */
    private static function notifyOtherAdmins(PDO $db, int $actorAdminId, string $title, string $message, ?int $refundId): void
    {
        $stmt = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" AND u.status = "active" AND u.id <> :me');
        $stmt->execute(['me' => $actorAdminId]);
        $meta = ['category' => 'refund', 'notice_type' => 'action_required'] + ($refundId ? ['reference_type' => 'refund', 'reference_id' => $refundId] : []);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminUserId) {
            notify((int) $adminUserId, $title, $message, 'refund', $meta);
        }
    }

    private static function assertActiveAdmin(PDO $db, int $userId): void
    {
        $role = $db->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.status = "active"');
        $role->execute(['id' => $userId]);
        if ($role->fetchColumn() !== 'admin') {
            throw new DomainException('Only administrators can manage refunds.');
        }
    }
}
