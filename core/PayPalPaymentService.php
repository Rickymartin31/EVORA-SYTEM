<?php

/**
 * Direct PayPal payment for a registration (Phase 1, sandbox first).
 *
 *   participant clicks PayPal -> startCheckout(): EVORA creates the order on its server
 *       (amount from the database, payee = organizer's linked PayPal account)
 *   buyer approves in PayPal  -> capture(): EVORA captures on its server and VERIFIES
 *       order ID, COMPLETED, amount, currency, payee and registration before anything else
 *   verified                  -> payment approved, registration approved, QR, notifications
 *   cancelled / declined      -> nothing approved, no QR; the participant can try again
 *
 * The money goes straight to the organizer: these payments are never written to the
 * organizer ledger, so no EVORA payout request can ever be created for them.
 */
class PayPalPaymentService
{
    private const OPEN_ORDER_TTL = 3 * 3600; // reuse an unpaid order for 3 hours instead of creating another

    public const LEGACY_PAUSED_MESSAGE = 'This event was set up for the previous online payment method (PayMongo), which no longer accepts new payments. New online payments for this event are paused — please contact the organizer. Payments already made are not affected.';

    /**
     * PayMongo is legacy/historical only: a legacy (non-PayPal) event never starts a new
     * PayMongo payment — whether PayPal is on or off (PayPal off does NOT fall back to
     * PayMongo) — so no event can mix PayMongo and PayPal money. Existing PayMongo
     * records keep their legacy handling (in-flight confirmation, payouts, refunds).
     */
    public static function legacyOnlinePaymentPaused(?string $paymentProvider): bool
    {
        return $paymentProvider !== 'paypal';
    }

    /** The registration (owned by $userId) with its event, fee and the event's PayPal payee. */
    public static function checkoutContext(PDO $db, int $registrationId, int $userId): array
    {
        $stmt = $db->prepare(
            'SELECT r.id AS registration_id, r.status AS registration_status, r.event_id, e.name AS event_name, e.status AS event_status,
                    e.payment_provider, e.organizer_paypal_account_id, e.organizer_id, COALESCE(rc.fee, e.fee) AS fee, p.user_id
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             JOIN participants p ON p.id = r.participant_id
             LEFT JOIN race_categories rc ON rc.id = r.race_category_id
             WHERE r.id = :id AND p.user_id = :uid'
        );
        $stmt->execute(['id' => $registrationId, 'uid' => $userId]);
        $ctx = $stmt->fetch();
        if (!$ctx || in_array($ctx['registration_status'], ['rejected', 'cancelled'], true)) {
            throw new DomainException('Registration not found.');
        }
        if ((float) $ctx['fee'] <= 0) {
            throw new DomainException('No payment is required for this registration.');
        }
        if ($ctx['payment_provider'] !== 'paypal' || !$ctx['organizer_paypal_account_id']) {
            throw new DomainException('This event does not take PayPal payments.');
        }
        $account = PayPalAccountService::find($db, (int) $ctx['organizer_paypal_account_id']);
        if (!$account || $account['status'] !== 'connected' || $account['environment'] !== PAYPAL_ENV || (int) $account['organizer_id'] !== (int) $ctx['organizer_id']) {
            throw new DomainException('The organizer\'s PayPal account is not available right now. Please contact the organizer.');
        }
        $ctx['account'] = $account;

        return $ctx;
    }

    /**
     * Why this registration cannot be paid right now, or null (v45). Payment is what makes a
     * registration take a slot, so an event that is over/cancelled or already full takes no money.
     */
    public static function cannotPayReason(PDO $db, int $registrationId): ?string
    {
        $stmt = $db->prepare('SELECT r.status AS reg_status, r.participant_id, r.race_category_id, e.id, e.status, e.event_date, e.max_participants
                               FROM registrations r JOIN events e ON e.id = r.event_id WHERE r.id = :id');
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();
        if (!$row || in_array($row['reg_status'], ['rejected', 'cancelled'], true)) {
            return 'This registration is no longer active.';
        }
        if (in_array($row['status'], ['completed', 'cancelled', 'removed', 'archived'], true) || $row['event_date'] < date('Y-m-d')) {
            return 'This event has ended or was cancelled, so it no longer takes payments.';
        }
        if ($row['reg_status'] !== 'approved' && (int) $row['max_participants'] > 0
            && WaitlistService::isFull($db, ['id' => $row['id'], 'max_participants' => $row['max_participants']], $row['race_category_id'] ? (int) $row['race_category_id'] : null, (int) $row['participant_id'])) {
            return 'Sorry, this event filled up before your payment went through.';
        }

        return null;
    }

    /** Returns the PayPal order ID for the buyer to approve. */
    public static function startCheckout(PDO $db, int $registrationId, int $userId): string
    {
        if (!PayPalGatewayService::enabled()) {
            throw new DomainException('PayPal payment is not available right now.');
        }
        $ctx = self::checkoutContext($db, $registrationId, $userId);
        if ($refusal = self::cannotPayReason($db, $registrationId)) {
            throw new DomainException($refusal);
        }

        $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM registrations WHERE id = :id FOR UPDATE')->execute(['id' => $registrationId]);
            $payStmt = $db->prepare('SELECT * FROM payments WHERE registration_id = :id FOR UPDATE');
            $payStmt->execute(['id' => $registrationId]);
            $payment = $payStmt->fetch();
            if ($payment && in_array($payment['status'], ['approved', 'refunded'], true)) {
                throw new DomainException('This registration is already paid.');
            }

            // Reuse a still-open order so one registration never has two payable orders.
            $open = $db->prepare('SELECT * FROM paypal_transactions WHERE registration_id = :id AND paypal_status = "created" AND paypal_order_id IS NOT NULL
                                  AND merchant_id = :m AND amount = :amt AND created_at > NOW() - INTERVAL ' . self::OPEN_ORDER_TTL . ' SECOND ORDER BY id DESC LIMIT 1');
            $open->execute(['id' => $registrationId, 'm' => $ctx['account']['merchant_id'], 'amt' => $ctx['fee']]);
            if ($existing = $open->fetch()) {
                $db->commit();

                return (string) $existing['paypal_order_id'];
            }
            // Anything older or different is closed before a new order is made.
            $db->prepare('UPDATE paypal_transactions SET paypal_status = "expired", failure_reason = "Replaced by a new checkout" WHERE registration_id = :id AND paypal_status = "created"')
                ->execute(['id' => $registrationId]);

            if ($payment) {
                $db->prepare('UPDATE payments SET amount = :amt, payment_channel = "paypal", payment_method = "paypal", status = "pending", rejection_reason = NULL WHERE id = :id')
                    ->execute(['amt' => $ctx['fee'], 'id' => $payment['id']]);
                $paymentId = (int) $payment['id'];
            } else {
                $db->prepare('INSERT INTO payments (registration_id, amount, payment_channel, payment_method) VALUES (:rid, :amt, "paypal", "paypal")')
                    ->execute(['rid' => $registrationId, 'amt' => $ctx['fee']]);
                $paymentId = (int) $db->lastInsertId();
            }
            $requestId = self::uuid();
            $invoiceId = 'EVORA-REG' . $registrationId . '-' . strtoupper(bin2hex(random_bytes(4)));
            $db->prepare('INSERT INTO paypal_transactions (registration_id, event_id, payment_id, organizer_id, organizer_paypal_account_id, merchant_id, environment, invoice_id, request_id, amount, currency, created_by)
                          VALUES (:r, :e, :p, :o, :a, :m, :env, :inv, :req, :amt, :cur, :by)')
                ->execute(['r' => $registrationId, 'e' => $ctx['event_id'], 'p' => $paymentId, 'o' => $ctx['organizer_id'], 'a' => $ctx['account']['id'], 'm' => $ctx['account']['merchant_id'],
                    'env' => PAYPAL_ENV, 'inv' => $invoiceId, 'req' => $requestId, 'amt' => $ctx['fee'], 'cur' => PAYPAL_CURRENCY, 'by' => $userId]);
            $txId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        try {
            [$orderId, $status, $http, $issue] = PayPalGatewayService::createOrder([
                'registration_id' => $registrationId, 'amount' => $ctx['fee'], 'currency' => PAYPAL_CURRENCY,
                'merchant_id' => $ctx['account']['merchant_id'], 'invoice_id' => $invoiceId, 'request_id' => $requestId,
                'description' => 'Registration — ' . $ctx['event_name'],
                'return_url' => absoluteUrl('paypal-return', ['registration_id' => $registrationId]),
                'cancel_url' => absoluteUrl('paypal-return', ['registration_id' => $registrationId, 'cancel' => 1]),
            ]);
        } catch (RuntimeException $e) {
            self::closeTransaction($db, $txId, 'failed', 'PayPal unavailable: ' . $e->getMessage(), $userId);
            throw new DomainException('PayPal could not be reached. Please try again in a moment.');
        }
        PayPalAccountService::log($db, 'response', 'create_order', $txId, 'Order ' . ($orderId ?: 'none') . ' ' . $status . ($issue ? ' (' . $issue . ')' : ''), $userId, $http);
        if ($http < 200 || $http >= 300 || $orderId === '') {
            self::closeTransaction($db, $txId, 'failed', 'Order not created' . ($issue ? ': ' . $issue : ''), $userId);
            throw new DomainException('PayPal could not start the payment' . ($issue === 'PAYEE_ACCOUNT_INVALID' ? ' — the organizer\'s PayPal account cannot receive payments.' : '. Please try again.'));
        }
        $db->prepare('UPDATE paypal_transactions SET paypal_order_id = :o WHERE id = :id AND paypal_order_id IS NULL')->execute(['o' => $orderId, 'id' => $txId]);
        Logger::log($userId, 'paypal_order_created', 'PayPal order ' . $orderId . ' for registration #' . $registrationId);

        return $orderId;
    }

    /**
     * Captures and verifies the order. Returns ['state' => completed|already_paid|failed, 'message', 'capture_id', 'transaction_id'].
     * $mockCode: sandbox negative testing only (tests).
     */
    public static function capture(PDO $db, string $orderId, int $registrationId, int $userId, ?string $mockCode = null): array
    {
        $tx = self::transactionForBuyer($db, $orderId, $registrationId, $userId);
        if ($tx['paypal_status'] === 'completed') {
            return ['state' => 'already_paid', 'message' => 'This payment was already recorded.', 'capture_id' => $tx['paypal_capture_id'], 'transaction_id' => (int) $tx['id']];
        }
        if (!in_array($tx['paypal_status'], ['created', 'approved'], true)) {
            return ['state' => 'failed', 'message' => 'This checkout is closed. Please start the payment again.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
        }
        // Duplicate guard BEFORE money moves: an order approved in a second tab is never captured
        // once the registration is paid (PayPal only takes the money at capture).
        $paid = $db->prepare('SELECT status FROM payments WHERE id = :id');
        $paid->execute(['id' => $tx['payment_id']]);
        if (in_array($paid->fetchColumn(), ['approved', 'refunded'], true)) {
            self::closeTransaction($db, (int) $tx['id'], 'cancelled', 'Not captured: registration already paid', $userId);

            return ['state' => 'already_paid', 'message' => 'This registration is already paid — the second PayPal approval was not charged.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
        }

        // One slot-taker at a time per event (v47): the capacity check, the capture and the approval
        // happen inside the event's seat lock, so two buyers can never both get the last slot.
        if (!EventRegistrationService::seatLock($db, (int) $tx['event_id'])) {
            return ['state' => 'failed', 'message' => 'Many participants are paying for this event right now. Please press Pay again in a moment. You were not charged.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
        }
        try {
            if ($refusal = self::cannotPayReason($db, $registrationId)) {
                self::closeTransaction($db, (int) $tx['id'], 'cancelled', 'Not captured: ' . $refusal, $userId);

                return ['state' => 'failed', 'message' => $refusal . ' Your PayPal account was not charged.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
            }

            // The buyer came back from PayPal: record the approval before asking PayPal to capture.
            $db->prepare('UPDATE paypal_transactions SET paypal_status = "approved", approved_at = COALESCE(approved_at, NOW()) WHERE id = :id AND paypal_status = "created"')->execute(['id' => $tx['id']]);

            try {
                $res = PayPalGatewayService::captureOrder($orderId, 'cap-' . $tx['request_id'], $mockCode);
            } catch (RuntimeException $e) {
                PayPalAccountService::log($db, 'response', 'capture_error', (int) $tx['id'], mb_substr($e->getMessage(), 0, 200), $userId);
                // Unknown outcome (network): leave the transaction open; the participant can retry and EVORA re-checks with PayPal.
                return ['state' => 'failed', 'message' => 'PayPal could not be reached to confirm the payment. Please try again; you will not be charged twice.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
            }
            PayPalAccountService::log($db, 'response', 'capture', (int) $tx['id'], ($res['body']['status'] ?? 'no status') . ($res['issue'] ? ' (' . $res['issue'] . ')' : ''), $userId, $res['status']);

            $order = $res['body'];
            if ($res['issue'] === 'ORDER_ALREADY_CAPTURED') {
                $order = PayPalGatewayService::getOrder($orderId)['body']; // a retry after a lost response: read the real outcome
            } elseif ($res['status'] < 200 || $res['status'] >= 300) {
                $reason = $res['issue'] ?: ('HTTP ' . $res['status']);
                self::fail($db, $tx, 'Declined by PayPal: ' . $reason, $userId);

                return ['state' => 'failed', 'message' => self::declineMessage($reason), 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
            }

            $check = PayPalGatewayService::verifyCapture($order, [
                'order_id' => $orderId, 'amount' => $tx['amount'], 'currency' => $tx['currency'],
                'merchant_id' => $tx['merchant_id'], 'invoice_id' => $tx['invoice_id'], 'registration_id' => (int) $tx['registration_id'],
            ]);
            if (!$check['ok']) {
                self::fail($db, $tx, 'Verification failed: ' . $check['reason'], $userId);
                if ($check['status'] === 'COMPLETED') {
                    // Money moved but not as EVORA expected — never approve; administrators must look at it.
                    NotificationService::toAdmins($db, 'PayPal Payment Needs Review', 'A PayPal capture for registration #' . $tx['registration_id'] . ' did not match EVORA\'s order (' . $check['reason'] . '). The registration was not approved.',
                        'payment_issue', ['category' => 'payment', 'notice_type' => 'action_required', 'reference_type' => 'registration', 'reference_id' => (int) $tx['registration_id']]);
                }

                return ['state' => 'failed', 'message' => $check['status'] === 'PENDING' ? 'PayPal is still processing this payment. You will be notified when it completes.' : 'The payment could not be confirmed. You were not registered as paid.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
            }

            return self::recordCompleted($db, $tx, $check, $userId);
        } finally {
            EventRegistrationService::seatUnlock($db, (int) $tx['event_id']);
        }
    }

    /** Buyer closed or cancelled the PayPal window: nothing is approved, no QR. */
    public static function cancel(PDO $db, string $orderId, int $registrationId, int $userId): void
    {
        $tx = self::transactionForBuyer($db, $orderId, $registrationId, $userId);
        if (in_array($tx['paypal_status'], ['created', 'approved'], true)) {
            self::closeTransaction($db, (int) $tx['id'], 'cancelled', 'Cancelled by the participant', $userId);
            Logger::log($userId, 'paypal_cancelled', 'PayPal order ' . $orderId . ' cancelled for registration #' . $registrationId);
        }
    }

    public static function transactionsForEvent(PDO $db, int $eventId): array
    {
        $stmt = $db->prepare('SELECT t.*, u.full_name FROM paypal_transactions t JOIN registrations r ON r.id = t.registration_id JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id WHERE t.event_id = :e ORDER BY t.id DESC');
        $stmt->execute(['e' => $eventId]);

        return $stmt->fetchAll();
    }

    public static function latestForRegistration(PDO $db, int $registrationId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM paypal_transactions WHERE registration_id = :r ORDER BY (paypal_status = "completed") DESC, id DESC LIMIT 1');
        $stmt->execute(['r' => $registrationId]);

        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------

    private static function transactionForBuyer(PDO $db, string $orderId, int $registrationId, int $userId): array
    {
        $stmt = $db->prepare('SELECT t.* FROM paypal_transactions t JOIN registrations r ON r.id = t.registration_id JOIN participants p ON p.id = r.participant_id
                              WHERE t.paypal_order_id = :o AND t.registration_id = :r AND p.user_id = :u');
        $stmt->execute(['o' => $orderId, 'r' => $registrationId, 'u' => $userId]);
        $tx = $stmt->fetch();
        if (!$tx) {
            throw new DomainException('Payment not found.');
        }

        return $tx;
    }

    private static function recordCompleted(PDO $db, array $tx, array $check, int $userId): array
    {
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $tx['payment_id']]);
            $payment = $lock->fetch();
            $txNow = $db->prepare('SELECT paypal_status FROM paypal_transactions WHERE id = :id FOR UPDATE');
            $txNow->execute(['id' => $tx['id']]);
            if ($txNow->fetchColumn() === 'completed') {
                $db->commit();

                return ['state' => 'already_paid', 'message' => 'This payment was already recorded.', 'capture_id' => $check['capture_id'], 'transaction_id' => (int) $tx['id']];
            }
            if ($payment['status'] === 'approved') {
                // A second, separate capture for a registration that is already paid: never count it twice.
                $db->prepare('UPDATE paypal_transactions SET paypal_status = "failed", failure_reason = :why, failed_at = NOW() WHERE id = :id')
                    ->execute(['why' => 'Duplicate capture ' . $check['capture_id'] . ' — registration was already paid; organizer must refund it', 'id' => $tx['id']]);
                $db->commit();
                self::notifyDuplicate($db, $tx, $check['capture_id']);

                return ['state' => 'already_paid', 'message' => 'This registration was already paid. The extra PayPal charge was flagged for the organizer to refund.', 'capture_id' => null, 'transaction_id' => (int) $tx['id']];
            }
            $db->prepare('UPDATE paypal_transactions SET paypal_status = "completed", paypal_capture_id = :c, paypal_fee = :fee, net_amount = :net, completed_at = NOW() WHERE id = :id')
                ->execute(['c' => $check['capture_id'], 'fee' => $check['fee'], 'net' => $check['net'], 'id' => $tx['id']]);
            $db->prepare('UPDATE payments SET status = "approved", payment_channel = "paypal", payment_method = "paypal", gateway_payment_id = :c, gateway_payment_method = "paypal",
                                gateway_fee = :fee, net_amount = :net, confirmed_via = "capture", paid_at = COALESCE(paid_at, NOW()), reviewed_at = COALESCE(reviewed_at, NOW()) WHERE id = :id')
                ->execute(['c' => $check['capture_id'], 'fee' => $check['fee'], 'net' => $check['net'], 'id' => $tx['payment_id']]);
            $db->prepare('INSERT INTO payment_history (payment_id, action, performed_by, remarks) VALUES (:p, "paypal_paid", :by, :r)')
                ->execute(['p' => $tx['payment_id'], 'by' => $userId, 'r' => 'PayPal capture ' . $check['capture_id'] . ' (order ' . $tx['paypal_order_id'] . ') paid directly to the organizer']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        approveRegistrationIfNeeded($db, (int) $tx['registration_id'], $userId);
        issueQrIfMissing($db, (int) $tx['registration_id'], $userId);
        Logger::log($userId, 'paypal_payment_completed', 'Registration #' . $tx['registration_id'] . ' paid via PayPal capture ' . $check['capture_id']);

        $event = $db->prepare('SELECT name FROM events WHERE id = :id');
        $event->execute(['id' => $tx['event_id']]);
        $eventName = (string) $event->fetchColumn();
        NotificationService::safely(fn () => notify($userId, 'Payment Confirmed',
            'Your PayPal payment of ' . currency($tx['amount']) . ' for "' . $eventName . '" was confirmed (transaction ' . $check['capture_id'] . '). Your QR code is ready.',
            'payment_status', ['category' => 'payment', 'notice_type' => 'status', 'reference_type' => 'payment', 'reference_id' => (int) $tx['payment_id']]));
        NotificationService::toEventOrganizer($db, (int) $tx['event_id'], 'PayPal Payment Received',
            'A participant paid ' . currency($tx['amount']) . ' for "' . $eventName . '" directly to your PayPal account (transaction ' . $check['capture_id'] . ').',
            'payment_received', ['category' => 'payment', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => (int) $tx['registration_id']]);

        return ['state' => 'completed', 'message' => 'Payment confirmed.', 'capture_id' => $check['capture_id'], 'transaction_id' => (int) $tx['id']];
    }

    private static function fail(PDO $db, array $tx, string $reason, int $userId): void
    {
        self::closeTransaction($db, (int) $tx['id'], 'failed', $reason, $userId);
        $db->prepare('UPDATE payments SET status = "failed" WHERE id = :id AND status = "pending"')->execute(['id' => $tx['payment_id']]);
        Logger::log($userId, 'paypal_payment_failed', 'Registration #' . $tx['registration_id'] . ': ' . $reason);
        NotificationService::safely(fn () => notify($userId, 'Payment Failed', 'Your PayPal payment did not go through. You were not charged; you can try again from My Registrations.', 'payment_status',
            ['category' => 'payment', 'notice_type' => 'action_required', 'reference_type' => 'payment', 'reference_id' => (int) $tx['payment_id']]));
    }

    private static function closeTransaction(PDO $db, int $txId, string $status, string $reason, ?int $userId): void
    {
        $db->prepare('UPDATE paypal_transactions SET paypal_status = :s, failure_reason = :r, failed_at = IF(:s2 = "failed", NOW(), failed_at) WHERE id = :id AND paypal_status IN ("created", "approved")')
            ->execute(['s' => $status, 's2' => $status, 'r' => mb_substr($reason, 0, 255), 'id' => $txId]);
        PayPalAccountService::log($db, 'redirect', $status, $txId, mb_substr($reason, 0, 200), $userId);
    }

    private static function notifyDuplicate(PDO $db, array $tx, string $captureId): void
    {
        Logger::log(null, 'paypal_duplicate_capture', 'Registration #' . $tx['registration_id'] . ' second capture ' . $captureId);
        NotificationService::toEventOrganizer($db, (int) $tx['event_id'], 'Duplicate PayPal Payment',
            'A participant was charged twice for registration #' . $tx['registration_id'] . ' (extra transaction ' . $captureId . '). Please refund the extra payment from your PayPal account.',
            'payment_issue', ['category' => 'payment', 'notice_type' => 'action_required', 'reference_type' => 'registration', 'reference_id' => (int) $tx['registration_id']]);
        NotificationService::toAdmins($db, 'Duplicate PayPal Payment', 'Registration #' . $tx['registration_id'] . ' was captured twice (' . $captureId . '). The organizer was asked to refund the extra payment.',
            'payment_issue', ['category' => 'payment', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => (int) $tx['registration_id']]);
    }

    private static function declineMessage(string $issue): string
    {
        return match ($issue) {
            'INSTRUMENT_DECLINED' => 'Your PayPal funding source was declined. You were not charged — please try again with another card or balance.',
            'PAYER_ACTION_REQUIRED' => 'PayPal needs you to complete an extra step. Please try again.',
            default => 'PayPal could not complete the payment. You were not charged — please try again.',
        };
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
