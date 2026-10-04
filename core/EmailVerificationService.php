<?php

/**
 * Email verification for self-registered accounts (v38).
 *
 * Accounts made through the public sign-up forms start with users.email_verified_at = NULL
 * (only while email is configured) and cannot log in until the emailed link is opened.
 * Every other account (existing ones, staff added by organizers, walk-ins) is verified by
 * default. Tokens: 32 random bytes, only the sha256 stored, valid 24 hours, single use.
 */
class EmailVerificationService
{
    public const TTL_HOURS = 24;

    /** Called right after a public sign-up (after the transaction commits). */
    public static function startForNewAccount(PDO $db, int $userId): bool
    {
        if (!MailService::enabled()) {
            return false; // email off: the account stays verified so the person can log in
        }
        $db->prepare('UPDATE users SET email_verified_at = NULL WHERE id = :id')->execute(['id' => $userId]);
        self::send($db, $userId);

        return true;
    }

    /** Emails a fresh link (older unused links stop working). Returns null or a short error. */
    public static function send(PDO $db, int $userId): ?string
    {
        $stmt = $db->prepare('SELECT id, full_name, email, email_verified_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || $user['email_verified_at'] !== null) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')->execute(['uid' => $userId]);
        $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (:uid, :hash, NOW() + INTERVAL ' . self::TTL_HOURS . ' HOUR)')
            ->execute(['uid' => $userId, 'hash' => hash('sha256', $token)]);
        $error = MailService::send($user['email'], $user['full_name'], 'Verify your EVORA email address',
            "Hi " . $user['full_name'] . ",\n\nWelcome to EVORA! Please confirm that this is your email address by opening this link:\n\n"
            . MailService::link('verify-email', ['token' => $token])
            . "\n\nThe link works once and expires in " . self::TTL_HOURS . " hours. If you did not create an EVORA account, you can ignore this email.");
        Logger::log($userId, $error === null ? 'email_verification_sent' : 'email_verification_failed', $error ?? 'Verification link emailed');

        return $error;
    }

    /** "Resend" from the login page: same answer whether or not the address exists. */
    public static function resend(PDO $db, string $email): void
    {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :e AND email_verified_at IS NULL AND status = "active"');
        $stmt->execute(['e' => trim($email)]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if (!$userId || !MailService::enabled()) {
            return;
        }
        $recent = $db->prepare('SELECT COUNT(*) FROM email_verifications WHERE user_id = :uid AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute(['uid' => $userId]);
        if ((int) $recent->fetchColumn() < 3) {
            self::send($db, $userId);
        }
    }

    /** Opens the emailed link. Returns the verified user's name, or null when the link is not valid. */
    public static function verify(PDO $db, string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $db->prepare('SELECT ev.id, u.id AS user_id, u.full_name FROM email_verifications ev JOIN users u ON u.id = ev.user_id
            WHERE ev.token_hash = :h AND ev.used_at IS NULL AND ev.expires_at > NOW()');
        $stmt->execute(['h' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $claim = $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $claim->execute(['id' => $row['id']]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        $db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL')->execute(['id' => $row['user_id']]);
        Logger::log((int) $row['user_id'], 'email_verified', 'Email address verified');

        return $row['full_name'];
    }
}
