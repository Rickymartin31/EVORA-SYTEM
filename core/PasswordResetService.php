<?php

/**
 * Forgot password (v35).
 *
 * - The answer to "send me a link" is always the same, whether or not the email exists,
 *   so the page cannot be used to find out who has an account.
 * - The emailed token is 32 random bytes; only its sha256 is stored. It works once,
 *   expires after 60 minutes, and asking again replaces older unused links.
 * - At most 3 links per account per hour, and 10 requests per IP per hour.
 * - Resetting clears a login lockout, and the user is notified (bell + email).
 */
class PasswordResetService
{
    public const TTL_MINUTES = 60;

    /** Returns null when the request was accepted (or silently ignored), or an error to show. */
    public static function request(PDO $db, string $email, string $ip): ?string
    {
        if (!MailService::enabled()) {
            return 'Password reset by email is not available yet. Please contact an administrator.';
        }
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Please enter the email address of your account.';
        }

        $byIp = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE requested_ip = :ip AND created_at > NOW() - INTERVAL 1 HOUR');
        $byIp->execute(['ip' => $ip]);
        if ((int) $byIp->fetchColumn() >= 10) {
            return 'Too many reset requests. Please try again later.';
        }

        $stmt = $db->prepare('SELECT id, full_name, email, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            Logger::log(null, 'password_reset_unknown', 'Reset requested for an unknown or inactive address');

            return null;
        }
        $recent = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :uid AND created_at > NOW() - INTERVAL 1 HOUR');
        $recent->execute(['uid' => $user['id']]);
        if ((int) $recent->fetchColumn() >= 3) {
            Logger::log((int) $user['id'], 'password_reset_throttled', 'Reset link not sent: 3 already sent this hour');

            return null;
        }

        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')->execute(['uid' => $user['id']]);
        $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (:uid, :hash, NOW() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE, :ip)')
            ->execute(['uid' => $user['id'], 'hash' => hash('sha256', $token), 'ip' => $ip]);

        $error = MailService::send($user['email'], $user['full_name'], 'Reset your EVORA password',
            "Hi " . $user['full_name'] . ",\n\nWe received a request to reset your EVORA password. Open this link to choose a new one:\n\n"
            . MailService::link('reset-password', ['token' => $token])
            . "\n\nThe link works once and expires in " . self::TTL_MINUTES . " minutes. If you did not ask for this, ignore this email — your password stays the same.");
        Logger::log((int) $user['id'], $error === null ? 'password_reset_requested' : 'password_reset_email_failed', $error === null ? 'Reset link emailed' : $error);

        return null;
    }

    /** The account a still-valid token belongs to, or null. */
    public static function findValid(PDO $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $db->prepare('SELECT pr.id AS reset_id, u.id, u.full_name, u.email, u.password_hash FROM password_resets pr JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = "active" LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $token)]);

        return $stmt->fetch() ?: null;
    }

    /** Returns null on success, or the reason the new password was refused. */
    public static function reset(PDO $db, string $token, string $new, string $confirm): ?string
    {
        $row = self::findValid($db, $token);
        if (!$row) {
            return 'This reset link is invalid or has expired. Please request a new one.';
        }
        $error = match (true) {
            strlen($new) < 8 => 'New password must be at least 8 characters.',
            strlen($new) > 72 => 'New password can be at most 72 characters.',
            !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new) => 'New password must contain at least one letter and one number.',
            $new !== $confirm => 'New password confirmation does not match.',
            password_verify($new, $row['password_hash']) => 'New password must be different from your current password.',
            default => null,
        };
        if ($error) {
            return $error;
        }

        $db->beginTransaction();
        try {
            $claim = $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
            $claim->execute(['id' => $row['reset_id']]);
            if ($claim->rowCount() !== 1) {
                $db->rollBack();

                return 'This reset link was already used. Please request a new one.';
            }
            $db->prepare('UPDATE users SET password_hash = :hash, failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
                ->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $row['id']]);
            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')->execute(['uid' => $row['id']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Logger::log((int) $row['id'], 'password_reset_completed', 'Password reset with an emailed link');
        notify((int) $row['id'], 'Password Changed', 'Your EVORA password was reset with an emailed link on ' . date('M d, Y g:i A') . '. If this was not you, contact an administrator immediately.', 'account_security',
            ['category' => 'system', 'notice_type' => 'status']);

        return null;
    }
}
