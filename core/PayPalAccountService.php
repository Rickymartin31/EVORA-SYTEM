<?php

/**
 * Organizer's linked PayPal account. The organizer signs in with PayPal
 * ("Log in with PayPal"); EVORA keeps only the PayPal account ID that PayPal
 * returns (merchant ID), a masked email and whether PayPal marks the account
 * verified. It never asks for or stores a PayPal password, token or secret, and
 * the merchant ID cannot be typed in — so it belongs to whoever signed in.
 */
class PayPalAccountService
{
    private const STATE_TTL = 600;

    /**
     * Organizer self-service "Log in with PayPal" is offered only when the PayPal ini turns it
     * on (PAYPAL_OAUTH_ENABLED=1) with the exact Return URL registered in the PayPal app
     * (PAYPAL_OAUTH_REDIRECT_URI). Only the organizer connects or disconnects their own PayPal
     * account — administrators can see the status but cannot link, approve or change it.
     * Account linking is separate from payments: participant PayPal payments are always
     * verified and approved automatically.
     */
    public static function oauthAvailable(): bool
    {
        return defined('PAYPAL_OAUTH_ENABLED') && PAYPAL_OAUTH_ENABLED;
    }

    /** The organizer's connected PayPal account (the one new paid events are paid to). */
    public static function current(PDO $db, int $organizerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_paypal_accounts WHERE organizer_id = :o AND environment = :env AND status = "connected" ORDER BY id DESC LIMIT 1');
        $stmt->execute(['o' => $organizerId, 'env' => PAYPAL_ENV]);

        return $stmt->fetch() ?: null;
    }

    public static function find(PDO $db, int $accountId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_paypal_accounts WHERE id = :id');
        $stmt->execute(['id' => $accountId]);

        return $stmt->fetch() ?: null;
    }

    public static function history(PDO $db, int $organizerId): array
    {
        $stmt = $db->prepare('SELECT * FROM organizer_paypal_accounts WHERE organizer_id = :o ORDER BY id DESC');
        $stmt->execute(['o' => $organizerId]);

        return $stmt->fetchAll();
    }

    /** Starts "Log in with PayPal": a one-time state bound to this organizer and session. */
    public static function startConnect(int $organizerId): string
    {
        if (!self::oauthAvailable()) {
            throw new DomainException('Connecting PayPal is not available yet. Please try again later.');
        }
        $state = bin2hex(random_bytes(24));
        $_SESSION['paypal_connect'] = ['state' => $state, 'organizer_id' => $organizerId, 'at' => time()];

        return PayPalGatewayService::connectUrl($state, self::redirectUri());
    }

    /** The exact Return URL registered in the PayPal app (from config, never from the request's Host header). */
    public static function redirectUri(): string
    {
        return defined('PAYPAL_OAUTH_REDIRECT_URI') ? (string) PAYPAL_OAUTH_REDIRECT_URI : '';
    }

    /**
     * PayPal returns to the host in the registered Return URL, and the EVORA sign-in cookie is
     * host-bound. When the organizer is on another host (e.g. localhost instead of 127.0.0.1),
     * returns the EVORA sign-in page on the right host, which then opens the PayPal Account page;
     * null when they are already on it.
     */
    public static function hostSwitchUrl(): ?string
    {
        $parts = parse_url(self::redirectUri());
        $returnHost = strtolower((string) ($parts['host'] ?? ''));
        $currentHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($returnHost === '' || $returnHost === $currentHost) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return ($parts['scheme'] ?? 'http') . '://' . $returnHost . $port . url('login', ['next' => 'organizer-paypal-account']);
    }

    /** Finishes the sign-in. Throws DomainException with a user-facing message on any problem. */
    public static function completeConnect(PDO $db, int $organizerId, int $userId, string $code, string $state): array
    {
        $pending = $_SESSION['paypal_connect'] ?? null;
        unset($_SESSION['paypal_connect']);
        if (!$pending || !hash_equals((string) $pending['state'], $state) || (int) $pending['organizer_id'] !== $organizerId || time() - (int) $pending['at'] > self::STATE_TTL) {
            throw new DomainException('The PayPal sign-in expired or did not start here. Please click Connect PayPal again.');
        }
        if ($code === '' || strlen($code) > 2000) {
            throw new DomainException('PayPal did not return a sign-in code.');
        }
        try {
            $identity = PayPalGatewayService::identityFromCode($code);
        } catch (RuntimeException $e) {
            self::log($db, 'connect', 'connect_failed', null, mb_substr($e->getMessage(), 0, 200), $userId);
            throw new DomainException('PayPal sign-in could not be completed. Please try again.');
        }
        if (!preg_match('/^[A-Za-z0-9]{8,32}$/', $identity['payer_id'])) {
            throw new DomainException('PayPal did not return an account ID.');
        }
        if (!$identity['verified_account']) {
            throw new DomainException('This PayPal account is not verified by PayPal yet. Verify it in PayPal, then connect again.');
        }
        $masked = self::maskId($identity['payer_id']);
        // Business requirement: enforced only when PayPal itself states the account type.
        // PayPal's sign-in may not report it, and a Personal account also passes the payee
        // check below, so EVORA never guesses — an unreported type is logged, not inferred.
        if ($identity['account_type'] !== null && strtoupper($identity['account_type']) !== 'BUSINESS') {
            self::log($db, 'connect', 'connect_rejected', null, 'PayPal account ' . $masked . ' is type ' . $identity['account_type'] . ', not BUSINESS', $userId);
            throw new DomainException('This is not a PayPal Business account. Sign in with your PayPal Business account, or upgrade it in PayPal first.');
        }
        $existing = self::current($db, $organizerId);
        if ($existing && $existing['merchant_id'] !== $identity['payer_id']) {
            // Switching would disconnect the account open events were saved with; that must be a
            // deliberate Disconnect (which shows the impact) — never a side effect of signing in.
            throw new DomainException('You already have a linked PayPal account. To use a different one, disconnect the current account first (check the warning about open events), then connect again.');
        }
        $taken = $db->prepare('SELECT 1 FROM organizer_paypal_accounts WHERE environment = :env AND merchant_id = :m AND status = "connected" AND organizer_id <> :o');
        $taken->execute(['env' => PAYPAL_ENV, 'm' => $identity['payer_id'], 'o' => $organizerId]);
        if ($taken->fetchColumn()) {
            self::log($db, 'connect', 'connect_rejected', null, 'PayPal account ' . $masked . ' is already linked to another organizer', $userId);
            throw new DomainException('This PayPal account is already linked to another organizer.');
        }
        // Server-side check: PayPal must accept this account as the payee of an order in
        // EVORA's currency before it is linked.
        try {
            $check = PayPalGatewayService::verifyPayee($identity['payer_id'], PAYPAL_CURRENCY);
        } catch (RuntimeException $e) {
            throw new DomainException('PayPal could not be reached to check your account. Please try again.');
        }
        self::log($db, 'connect', 'payee_check', null, 'Payee check for ' . $masked . ' (PayPal sign-in): ' . ($check['ok'] ? 'can receive' : ($check['issue'] ?? 'rejected'))
            . ($identity['account_type'] === null ? '; account type not reported by PayPal' : '; account type ' . $identity['account_type']), $userId, $check['http']);
        if (!$check['ok']) {
            throw new DomainException('PayPal does not accept this account for ' . PAYPAL_CURRENCY . ' payments' . ($check['issue'] ? ' (' . $check['issue'] . ')' : '') . '. Your PayPal account was not connected.');
        }

        $db->beginTransaction();
        try {
            $old = self::current($db, $organizerId);
            if ($old) {
                $db->commit();

                return $old; // same account again (a different one was refused above): nothing to change
            }
            // Active at once: the organizer connected their own account (approved_by stays NULL — no administrator involved).
            $db->prepare('INSERT INTO organizer_paypal_accounts (organizer_id, environment, merchant_id, email_masked, verified_account, verification_method, connected_by, verified_at, approved_at)
                          VALUES (:o, :env, :m, :email, 1, "paypal_login", :by, NOW(), NOW())')
                ->execute(['o' => $organizerId, 'env' => PAYPAL_ENV, 'm' => $identity['payer_id'], 'email' => self::maskEmail($identity['email']), 'by' => $userId]);
            $id = (int) $db->lastInsertId();
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                throw new DomainException('This PayPal account is already linked to another organizer.');
            }
            throw $e;
        }

        self::log($db, 'connect', 'connected', null, 'Linked PayPal account ' . self::maskId($identity['payer_id']) . ' (' . PAYPAL_ENV . ') by PayPal sign-in', $userId);
        Logger::log($userId, 'paypal_connected', 'Organizer #' . $organizerId . ' linked PayPal account ' . self::maskId($identity['payer_id']) . ' by PayPal sign-in');
        NotificationService::safely(fn () => PayoutRequestService::notifyOrganizer($db, $organizerId, 'PayPal Account Connected',
            'Your PayPal account ' . self::maskId($identity['payer_id']) . ' is connected. Participants of your new paid events pay you directly through PayPal.',
            ['category' => 'payment', 'notice_type' => 'status'], 'payment_status'));

        return self::find($db, $id);
    }

    public static function disconnect(PDO $db, int $organizerId, int $userId): void
    {
        $current = self::current($db, $organizerId);
        if (!$current) {
            throw new DomainException('No PayPal account is connected.');
        }
        $db->prepare('UPDATE organizer_paypal_accounts SET status = "disconnected", disconnected_at = NOW(), disconnected_by = :by WHERE id = :id AND status = "connected"')
            ->execute(['by' => $userId, 'id' => $current['id']]);
        self::log($db, 'connect', 'disconnected', null, 'Unlinked PayPal account ' . self::maskId($current['merchant_id']), $userId);
        Logger::log($userId, 'paypal_disconnected', 'Organizer #' . $organizerId . ' unlinked PayPal account ' . self::maskId($current['merchant_id']));
    }

    public static function maskEmail(?string $email): ?string
    {
        if (!$email || !str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($local) - 2))) . '@' . $domain;
    }

    public static function maskId(string $merchantId): string
    {
        return '••••' . substr($merchantId, -4);
    }

    public static function log(PDO $db, string $kind, string $action, ?int $transactionId, ?string $summary, ?int $actorUserId, ?int $httpStatus = null): void
    {
        NotificationService::safely(function () use ($db, $kind, $action, $transactionId, $summary, $actorUserId, $httpStatus) {
            $db->prepare('INSERT INTO paypal_payment_logs (paypal_transaction_id, kind, action, http_status, summary, actor_user_id) VALUES (:t, :k, :a, :h, :s, :u)')
                ->execute(['t' => $transactionId, 'k' => $kind, 'a' => mb_substr($action, 0, 40), 'h' => $httpStatus, 's' => $summary !== null ? mb_substr($summary, 0, 500) : null, 'u' => $actorUserId]);
        });
    }
}
