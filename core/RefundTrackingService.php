<?php

/**
 * PayPal refund tracking (v36).
 *
 * PayPal payments are paid directly to the organizer, so only the organizer can refund them
 * (from their own PayPal account). When a paid participant's event is cancelled — or their
 * registration is cancelled/rejected — the payment becomes "refund due". The organizer
 * refunds it in PayPal and records the PayPal refund transaction ID here; the participant
 * can then confirm "received" or report "not received" (administrators are alerted).
 *
 * This never changes payments or paypal_transactions; the refund state lives in
 * paypal_refund_records (append-only, guarded by triggers).
 */
class RefundTrackingService
{
    /**
     * Payments that need a refund: paid via PayPal, but the event/registration will not happen.
     * Events marked as test events (sandbox test data, events.is_test = 1) never owe refunds.
     */
    private const DUE_CONDITION = 'pay.payment_channel = "paypal" AND pay.status = "approved" AND e.is_test = 0
        AND (e.status = "cancelled" OR r.status IN ("cancelled", "rejected"))';

    private const BASE_SELECT = 'SELECT pay.id AS payment_id, pay.amount, pay.paid_at, pay.gateway_payment_id AS capture_id, r.id AS registration_id, r.status AS registration_status,
            e.id AS event_id, e.name AS event_name, e.status AS event_status, e.organizer_id, o.organization_name, u.id AS participant_user_id, u.full_name AS participant_name, u.email AS participant_email,
            rr.id AS refund_id, rr.paypal_refund_id, rr.proof_path AS refund_proof_path, rr.note AS refund_note, rr.recorded_at, rr.participant_response, rr.participant_note, rr.participant_responded_at
        FROM payments pay
        JOIN registrations r ON r.id = pay.registration_id
        JOIN events e ON e.id = r.event_id
        JOIN organizers o ON o.id = e.organizer_id
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        LEFT JOIN paypal_refund_records rr ON rr.payment_id = pay.id';

    /** Every refund-due or refunded PayPal payment of one organizer (outstanding first). */
    public static function forOrganizer(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare(self::BASE_SELECT . ' WHERE e.organizer_id = :oid AND (rr.id IS NOT NULL OR (' . self::DUE_CONDITION . '))
            ORDER BY rr.id IS NOT NULL, e.event_date DESC, pay.id');
        $stmt->execute(['oid' => $organizerId]);

        return $stmt->fetchAll();
    }

    /** All of them, for administrators. */
    public static function all(PDO $db): array
    {
        return $db->query(self::BASE_SELECT . ' WHERE rr.id IS NOT NULL OR (' . self::DUE_CONDITION . ')
            ORDER BY rr.participant_response = "not_received" DESC, rr.id IS NOT NULL, pay.id DESC')->fetchAll();
    }

    /** payment_id => row, for one participant's Payments & Receipts page. */
    public static function forParticipant(PDO $db, int $userId): array
    {
        $stmt = $db->prepare(self::BASE_SELECT . ' WHERE u.id = :uid AND (rr.id IS NOT NULL OR (' . self::DUE_CONDITION . '))');
        $stmt->execute(['uid' => $userId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['payment_id']] = $row;
        }

        return $rows;
    }

    /** Number of refunds an organizer still has to make (for the sidebar badge). */
    public static function outstandingCount(PDO $db, int $organizerId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id JOIN events e ON e.id = r.event_id
            LEFT JOIN paypal_refund_records rr ON rr.payment_id = pay.id WHERE e.organizer_id = :oid AND rr.id IS NULL AND ' . self::DUE_CONDITION);
        $stmt->execute(['oid' => $organizerId]);

        return (int) $stmt->fetchColumn();
    }

    /** The payment's page in the organizer's PayPal account, where its Refund button is (v55). */
    public static function paypalActivityUrl(?string $captureId): ?string
    {
        if (!$captureId) {
            return null;
        }
        $host = (defined('PAYPAL_ENV') && PAYPAL_ENV === 'live') ? 'https://www.paypal.com' : 'https://www.sandbox.paypal.com';

        return $host . '/activity/payment/' . rawurlencode($captureId);
    }

    /**
     * The organizer records a refund they made in PayPal: the refund transaction ID, a screenshot
     * of the refund, or both — at least one (v55). The participant then confirms it.
     * Throws DomainException with a user-facing message.
     */
    public static function record(PDO $db, int $organizerId, int $userId, int $paymentId, string $paypalRefundId, string $note, ?array $proofFile = null): void
    {
        $paypalRefundId = strtoupper(trim($paypalRefundId));
        $note = trim($note);
        $hasFile = $proofFile !== null && (int) ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($paypalRefundId === '' && !$hasFile) {
            throw new DomainException('Add the PayPal refund transaction ID or a screenshot of the refund (at least one).');
        }
        if ($paypalRefundId !== '' && !preg_match('/^[A-Z0-9]{12,25}$/', $paypalRefundId)) {
            throw new DomainException('Enter the refund transaction ID exactly as PayPal shows it (letters and numbers, usually 17 characters), or leave it empty and attach a screenshot.');
        }
        if (mb_strlen($note) > 500) {
            throw new DomainException('The note can be at most 500 characters.');
        }
        $stmt = $db->prepare(self::BASE_SELECT . ' WHERE pay.id = :pid AND e.organizer_id = :oid AND ' . self::DUE_CONDITION);
        $stmt->execute(['pid' => $paymentId, 'oid' => $organizerId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('This payment is not waiting for a refund from you.');
        }
        if ($row['refund_id']) {
            throw new DomainException('A refund was already recorded for this payment.');
        }
        if ($paypalRefundId !== '') {
            if ($row['capture_id'] && strcasecmp($row['capture_id'], $paypalRefundId) === 0) {
                throw new DomainException('That is the original payment\'s transaction ID. Enter the ID of the REFUND transaction from your PayPal activity, or attach a screenshot instead.');
            }
            $dup = $db->prepare('SELECT 1 FROM paypal_refund_records WHERE paypal_refund_id = :rid');
            $dup->execute(['rid' => $paypalRefundId]);
            if ($dup->fetchColumn()) {
                throw new DomainException('That PayPal refund ID was already used for another payment.');
            }
        }
        $proofPath = null;
        if ($hasFile) {
            $proofPath = Upload::handle($proofFile, 'refund_proofs', UPLOAD_ALLOWED_DOC);
            if ($proofPath === null) {
                throw new DomainException('The screenshot must be a JPG, PNG or PDF of at most 5 MB.');
            }
        }

        $db->prepare('INSERT INTO paypal_refund_records (payment_id, registration_id, event_id, organizer_id, amount, paypal_refund_id, proof_path, note, recorded_by)
            VALUES (:pid, :rid, :eid, :oid, :amount, :ppid, :proof, :note, :by)')->execute([
            'pid' => $paymentId, 'rid' => $row['registration_id'], 'eid' => $row['event_id'], 'oid' => $organizerId, 'amount' => $row['amount'],
            'ppid' => $paypalRefundId !== '' ? $paypalRefundId : null, 'proof' => $proofPath, 'note' => $note !== '' ? $note : null, 'by' => $userId,
        ]);
        $proofText = $paypalRefundId !== '' ? 'PayPal refund ID ' . $paypalRefundId : 'a screenshot of the refund is attached';
        Logger::log($userId, 'paypal_refund_recorded', 'Payment #' . $paymentId . ' (' . currency($row['amount']) . ') refunded via PayPal: ' . $proofText);
        notify((int) $row['participant_user_id'], 'Refund Sent by Organizer',
            $row['organization_name'] . ' says it refunded your ' . currency($row['amount']) . ' PayPal payment for "' . $row['event_name'] . '" (' . $proofText . '). Check your PayPal account, then confirm on Payments & Receipts whether you received it.',
            'refund_recorded', ['category' => 'refund', 'notice_type' => 'action_required', 'reference_type' => 'payment', 'reference_id' => $paymentId]);
    }

    /** The participant confirms or disputes a recorded refund. */
    public static function respond(PDO $db, int $participantUserId, int $paymentId, string $response, string $note): void
    {
        if (!in_array($response, ['received', 'not_received'], true)) {
            throw new DomainException('Invalid answer.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new DomainException('The note can be at most 500 characters.');
        }
        $row = self::forParticipant($db, $participantUserId)[$paymentId] ?? null;
        if (!$row || !$row['refund_id']) {
            throw new DomainException('No refund was recorded for this payment.');
        }
        if ($row['participant_response'] !== 'none') {
            throw new DomainException('You already answered for this refund.');
        }
        $db->prepare('UPDATE paypal_refund_records SET participant_response = :resp, participant_note = :note, participant_responded_at = NOW() WHERE id = :id AND participant_response = "none"')
            ->execute(['resp' => $response, 'note' => $note !== '' ? $note : null, 'id' => $row['refund_id']]);
        Logger::log($participantUserId, 'paypal_refund_' . $response, 'Payment #' . $paymentId . ', refund ID ' . $row['paypal_refund_id']);

        $orgUser = $db->prepare('SELECT user_id FROM organizers WHERE id = :id');
        $orgUser->execute(['id' => $row['organizer_id']]);
        $organizerUserId = (int) $orgUser->fetchColumn();
        if ($response === 'received') {
            notify($organizerUserId, 'Refund Confirmed', $row['participant_name'] . ' confirmed receiving the ' . currency($row['amount']) . ' refund for "' . $row['event_name'] . '".', 'refund_confirmed',
                ['category' => 'refund', 'notice_type' => 'informational', 'reference_type' => 'payment', 'reference_id' => $paymentId]);

            return;
        }
        $detail = $row['participant_name'] . ' reports NOT receiving the ' . currency($row['amount']) . ' refund for "' . $row['event_name'] . '" (PayPal refund ID ' . $row['paypal_refund_id'] . ').' . ($note !== '' ? ' Note: ' . $note : '');
        notify($organizerUserId, 'Refund Not Received', $detail . ' Please check the refund in your PayPal account and contact the participant.', 'refund_disputed',
            ['category' => 'refund', 'notice_type' => 'action_required', 'reference_type' => 'payment', 'reference_id' => $paymentId]);
        NotificationService::toAdmins($db, 'Refund Not Received', $detail . ' See Refund Tracking.', 'refund_disputed',
            ['category' => 'refund', 'notice_type' => 'action_required', 'reference_type' => 'payment', 'reference_id' => $paymentId]);
    }
}
