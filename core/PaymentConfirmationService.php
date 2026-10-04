<?php

/**
 * The single place where a PayMongo payment becomes "approved".
 *
 * Every path that can learn a payment was paid — the return page, the webhook,
 * the reconcile script, the checkout retry guard and the My Registrations
 * auto-check — goes through confirm(). The payment row is locked first, so
 * whichever path gets there first approves it, writes payment_history,
 * approves the registration, issues the QR and notifies the participant;
 * every later call is a no-op.
 */
class PaymentConfirmationService
{
    /** payment_history action recorded for each confirmation path. */
    private const HISTORY_ACTIONS = [
        'return' => 'paymongo_return_paid',
        'webhook' => 'paymongo_webhook_paid',
        'reconcile' => 'paymongo_reconcile_paid',
        'checkout' => 'paymongo_checkout_paid',
        'autocheck' => 'paymongo_autocheck_paid',
    ];

    /** Seconds between automatic re-checks of the same payment (per browser session). */
    private const AUTOCHECK_INTERVAL = 60;

    /** Max PayMongo lookups one My Registrations page load may make. */
    private const AUTOCHECK_LIMIT = 3;

    /**
     * Finds the payment a checkout session belongs to — the payment's current
     * session first, then any older session kept in payment_checkout_sessions.
     */
    public static function findPaymentBySessionId(PDO $db, string $checkoutSessionId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM payments WHERE checkout_session_id = :csid');
        $stmt->execute(['csid' => $checkoutSessionId]);
        $payment = $stmt->fetch();
        if ($payment) {
            return $payment;
        }

        $stmt = $db->prepare(
            'SELECT pay.* FROM payment_checkout_sessions s
             JOIN payments pay ON pay.id = s.payment_id
             WHERE s.checkout_session_id = :csid'
        );
        $stmt->execute(['csid' => $checkoutSessionId]);

        return $stmt->fetch() ?: null;
    }

    public static function recordSession(PDO $db, int $paymentId, string $checkoutSessionId): void
    {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO payment_checkout_sessions (payment_id, checkout_session_id) VALUES (:pid, :csid)'
        );
        $stmt->execute(['pid' => $paymentId, 'csid' => $checkoutSessionId]);
    }

    /** A session already marked paid is never downgraded. */
    public static function markSession(PDO $db, string $checkoutSessionId, string $status): void
    {
        $stmt = $db->prepare(
            'UPDATE payment_checkout_sessions SET status = :status
             WHERE checkout_session_id = :csid AND status <> "paid"'
        );
        $stmt->execute(['status' => $status, 'csid' => $checkoutSessionId]);
    }

    /**
     * Sessions of a payment that could still turn out paid: the current one
     * plus any older open/cancelled ones. Current session first.
     */
    public static function checkableSessions(PDO $db, array $payment): array
    {
        $sessions = [];
        if (!empty($payment['checkout_session_id'])) {
            $sessions[] = $payment['checkout_session_id'];
        }

        $stmt = $db->prepare(
            'SELECT checkout_session_id FROM payment_checkout_sessions
             WHERE payment_id = :pid AND status IN ("open", "cancelled")
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['pid' => $payment['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
            if (!in_array($sessionId, $sessions, true)) {
                $sessions[] = $sessionId;
            }
        }

        return $sessions;
    }

    /**
     * Asks PayMongo whether any checkable session of this payment was paid and,
     * if so, confirms it. Returns "paid", "unpaid" or "error" (API unreachable).
     */
    public static function verifyWithGateway(PDO $db, array $payment, string $via, ?int $actorUserId = null, int $timeout = 20): string
    {
        $result = 'unpaid';
        foreach (self::checkableSessions($db, $payment) as $sessionId) {
            try {
                $session = PaymentGatewayService::retrieveCheckoutSession($sessionId, $timeout);
            } catch (RuntimeException $e) {
                Logger::log($actorUserId, 'paymongo_verify_error', 'Payment #' . $payment['id'] . ': ' . $e->getMessage());
                $result = 'error';
                continue;
            }

            if ($session['paid']) {
                try {
                    self::confirm($db, (int) $payment['id'], [
                        'checkout_session_id' => $sessionId,
                        'payment_id' => $session['payment_id'],
                        'payment_method' => $session['payment_method'],
                        'fee' => $session['fee'],
                        'net_amount' => $session['net_amount'],
                        'amount' => $session['amount'],
                    ], $via, $actorUserId);
                } catch (Throwable $e) {
                    // Approval (and its ledger entry) rolled back; the payment
                    // stays pending and is retried by autocheck/reconcile.
                    Logger::log($actorUserId, 'paymongo_confirm_error', 'Payment #' . $payment['id'] . ': ' . $e->getMessage());

                    return 'error';
                }

                return 'paid';
            }
            if ($session['status'] === 'expired') {
                self::markSession($db, $sessionId, 'expired');
            }
        }

        return $result;
    }

    /**
     * Marks a payment approved and runs the post-payment steps exactly once.
     *
     * $gateway keys (all optional): checkout_session_id, payment_id,
     * payment_method, fee, net_amount.
     *
     * Returns true only for the call that actually approved the payment.
     */
    public static function confirm(PDO $db, int $paymentId, array $gateway, string $via, ?int $actorUserId = null, ?string $webhookEventId = null): bool
    {
        $sessionId = $gateway['checkout_session_id'] ?? null;
        $gatewayPaymentId = $gateway['payment_id'] ?? null;

        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $paymentId]);
            $payment = $lock->fetch();

            if (!$payment || in_array($payment['status'], ['approved', 'refunded'], true)) {
                $db->commit();
                if ($payment && $sessionId) {
                    self::markSession($db, $sessionId, 'paid');
                }
                // a charge arriving after the payment was paid (or already refunded) is money to give back
                if ($payment) {
                    self::flagDuplicateCharge($db, $payment, $gatewayPaymentId, $sessionId);
                }

                return false;
            }

            $update = $db->prepare(
                'UPDATE payments SET status = "approved",
                    checkout_session_id = COALESCE(:csid, checkout_session_id),
                    gateway_payment_id = :gwid, gateway_payment_method = :gwmethod,
                    gateway_fee = :fee, net_amount = :net, confirmed_via = :via,
                    webhook_event_id = COALESCE(:eid, webhook_event_id),
                    paid_at = COALESCE(paid_at, NOW()), reviewed_at = COALESCE(reviewed_at, NOW())
                 WHERE id = :id'
            );
            $update->execute([
                'csid' => $sessionId,
                'gwid' => $gatewayPaymentId,
                'gwmethod' => $gateway['payment_method'] ?? null,
                'fee' => $gateway['fee'] ?? null,
                'net' => $gateway['net_amount'] ?? null,
                'via' => $via,
                'eid' => $webhookEventId,
                'id' => $paymentId,
            ]);

            $history = $db->prepare('INSERT INTO payment_history (payment_id, action, performed_by, remarks) VALUES (:pid, :action, :by, :remarks)');
            $history->execute([
                'pid' => $paymentId,
                'action' => self::HISTORY_ACTIONS[$via] ?? 'paymongo_paid',
                'by' => $actorUserId,
                'remarks' => 'PayMongo payment ' . ($gatewayPaymentId ?? 'n/a') . ($sessionId ? ' (session ' . $sessionId . ')' : ''),
            ]);

            // Organizer ledger entry in the SAME transaction: the payment is
            // never approved without it. Any failure here rolls the approval
            // back, so no registration approval, QR or notification happens
            // and the payment stays retryable.
            LedgerService::recordPayment($db, $payment, $gateway + ['checkout_session_id' => $sessionId], $actorUserId, $via);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($sessionId) {
            self::markSession($db, $sessionId, 'paid');
        }

        $regStmt = $db->prepare(
            'SELECT r.id AS registration_id, e.id AS event_id, e.name AS event_name, u.id AS user_id
             FROM registrations r JOIN events e ON e.id = r.event_id
             JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
             WHERE r.id = :rid'
        );
        $regStmt->execute(['rid' => $payment['registration_id']]);
        $registration = $regStmt->fetch();

        if ($registration) {
            approveRegistrationIfNeeded($db, (int) $registration['registration_id'], $actorUserId);
            issueQrIfMissing($db, (int) $registration['registration_id'], $actorUserId);
            // Keep the organizer's event earnings current (never fails the confirmation).
            PayoutRequestService::recalculateQuietly($db, (int) $registration['event_id']);
            notify((int) $registration['user_id'], 'Payment Confirmed', 'Your online payment for "' . $registration['event_name'] . '" was confirmed by PayMongo! Your QR code is ready.', 'payment_status',
                ['category' => 'payment', 'notice_type' => 'status', 'reference_type' => 'payment', 'reference_id' => $paymentId]);
            self::notifyOrganizerOfPayment($db, (int) $registration['event_id'], $registration['event_name'], (int) $registration['registration_id'], (string) $payment['amount']);
        }

        Logger::log($actorUserId, 'paymongo_payment_confirmed', 'Payment #' . $paymentId . ' confirmed via ' . $via . ' (' . ($gatewayPaymentId ?? 'n/a') . ')');

        return true;
    }

    /**
     * Quietly re-checks a participant's own pending PayMongo payments (used by
     * My Registrations), so a payment finished without returning to EVORA
     * still gets confirmed. Throttled per payment and capped per page load;
     * never throws.
     */
    public static function autoCheckForParticipant(PDO $db, int $participantId, int $actorUserId): void
    {
        if (!PAYMONGO_ENABLED || $participantId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare(
                'SELECT pay.* FROM payments pay
                 JOIN registrations r ON r.id = pay.registration_id
                 WHERE r.participant_id = :pid AND pay.payment_channel = "paymongo"
                   AND pay.status = "pending" AND pay.checkout_session_id IS NOT NULL
                 ORDER BY pay.id DESC'
            );
            $stmt->execute(['pid' => $participantId]);

            $checked = 0;
            $now = time();
            foreach ($stmt->fetchAll() as $payment) {
                if ($checked >= self::AUTOCHECK_LIMIT) {
                    break;
                }
                $lastChecked = $_SESSION['paymongo_autocheck'][$payment['id']] ?? 0;
                if ($now - $lastChecked < self::AUTOCHECK_INTERVAL) {
                    continue;
                }
                $_SESSION['paymongo_autocheck'][$payment['id']] = $now;
                $checked++;

                self::verifyWithGateway($db, $payment, 'autocheck', $actorUserId, 8);
            }
        } catch (Throwable $e) {
            Logger::log($actorUserId, 'paymongo_autocheck_error', $e->getMessage());
        }
    }

    /**
     * A second paid PayMongo payment for an already-approved registration
     * (e.g. participant paid both an old and a new session). Logged once for a
     * manual refund; never auto-refunded.
     */
    private static function flagDuplicateCharge(PDO $db, array $payment, ?string $gatewayPaymentId, ?string $sessionId): void
    {
        if (!$gatewayPaymentId || !$payment['gateway_payment_id'] || $gatewayPaymentId === $payment['gateway_payment_id']) {
            return;
        }

        $remarks = 'Second PayMongo charge ' . $gatewayPaymentId . ($sessionId ? ' (session ' . $sessionId . ')' : '') . ' — refund manually';
        $exists = $db->prepare('SELECT 1 FROM payment_history WHERE payment_id = :pid AND action = "paymongo_duplicate_charge" AND remarks = :remarks');
        $exists->execute(['pid' => $payment['id'], 'remarks' => $remarks]);
        if ($exists->fetch()) {
            return;
        }

        $history = $db->prepare('INSERT INTO payment_history (payment_id, action, remarks) VALUES (:pid, "paymongo_duplicate_charge", :remarks)');
        $history->execute(['pid' => $payment['id'], 'remarks' => $remarks]);
        Logger::log(null, 'paymongo_duplicate_charge', 'Payment #' . $payment['id'] . ': ' . $remarks);
        PayoutRequestService::notifyAdmins($db, 'Duplicate Payment Detected',
            'Payment #' . $payment['id'] . ' was charged twice (' . $gatewayPaymentId . '). Refund the extra charge.',
            ['category' => 'payment', 'notice_type' => 'action_required', 'reference_type' => 'payment', 'reference_id' => (int) $payment['id']], 'payment_issue');
        NotificationService::safely(function () use ($db, $payment) {
            $reg = $db->prepare('SELECT p.user_id, e.id AS event_id, e.name AS event_name FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id WHERE r.id = :rid');
            $reg->execute(['rid' => $payment['registration_id']]);
            if ($row = $reg->fetch()) {
                notify((int) $row['user_id'], 'Duplicate Payment Detected',
                    'You were charged twice for "' . $row['event_name'] . '". Your registration is not affected. The extra charge was flagged for an administrator to refund; you will be notified.',
                    'payment_issue', ['category' => 'payment', 'notice_type' => 'informational', 'reference_type' => 'payment', 'reference_id' => (int) $payment['id']]);
                NotificationService::toEventOrganizer($db, (int) $row['event_id'], 'Duplicate Payment Detected',
                    'A participant of "' . $row['event_name'] . '" was charged twice. The extra charge was flagged for an administrator to refund; your earnings count the registration once.',
                    'payment_issue', ['category' => 'payment', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => (int) $payment['registration_id']]);
            }
        });
    }

    /** Notification only: tells the event's organizer that a participant paid. */
    private static function notifyOrganizerOfPayment(PDO $db, int $eventId, string $eventName, int $registrationId, string $amount): void
    {
        $stmt = $db->prepare('SELECT organizer_id FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        if ($organizerId = (int) $stmt->fetchColumn()) {
            PayoutRequestService::notifyOrganizer($db, $organizerId, 'New Participant Payment',
                'A participant paid ' . currency($amount) . ' and is now registered for "' . $eventName . '".',
                ['category' => 'payment', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => $registrationId], 'payment_received');
        }
    }
}
