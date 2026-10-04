<?php

/**
 * Email OTP for administrator sign-in (v37).
 *
 * After the correct password, an administrator with a real email address is NOT signed in
 * yet: a 6-digit code is emailed and must be entered within 10 minutes. Only a hash of the
 * code is stored; 5 wrong tries end the attempt (sign in again for a new code). Codes can be
 * re-sent at most once a minute and 5 times per 15 minutes.
 *
 * The pending sign-in lives in $_SESSION['otp_pending'] = [user_id, started_at, next]; the
 * session gets no user_id until the code is verified.
 */
class LoginOtpService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    private const PENDING_MINUTES = 15;

    /** Whether this user must enter an emailed code after the password. */
    public static function required(array $user): bool
    {
        return ($user['role_name'] ?? '') === 'admin'
            && filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false
            && MailService::enabled();
    }

    /** Puts the sign-in on hold and emails the first code. Returns null, or an error to show. */
    public static function begin(PDO $db, array $user, string $next = ''): ?string
    {
        $_SESSION['otp_pending'] = ['user_id' => (int) $user['id'], 'started_at' => time(), 'next' => $next, 'last_sent' => 0];

        return self::send($db);
    }

    /** The user waiting for a code, or null when there is no (unexpired) pending sign-in. */
    public static function pendingUser(PDO $db): ?array
    {
        $p = $_SESSION['otp_pending'] ?? null;
        if (!$p || time() - (int) $p['started_at'] > self::PENDING_MINUTES * 60) {
            unset($_SESSION['otp_pending']);

            return null;
        }
        $stmt = $db->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND u.status = "active"');
        $stmt->execute(['id' => (int) $p['user_id']]);

        return $stmt->fetch() ?: null;
    }

    /** Emails a new code to the pending user (replacing older ones). Returns null, or an error. */
    public static function send(PDO $db): ?string
    {
        $user = self::pendingUser($db);
        if (!$user) {
            return 'Your sign-in expired. Please log in again.';
        }
        if (time() - (int) ($_SESSION['otp_pending']['last_sent'] ?? 0) < 60) {
            return 'Please wait a minute before asking for another code.';
        }
        $recent = $db->prepare('SELECT COUNT(*) FROM login_otps WHERE user_id = :uid AND created_at > NOW() - INTERVAL 15 MINUTE');
        $recent->execute(['uid' => $user['id']]);
        if ((int) $recent->fetchColumn() >= 5) {
            return 'Too many codes were requested. Please wait 15 minutes and log in again.';
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE user_id = :uid AND consumed_at IS NULL')->execute(['uid' => $user['id']]);
        $db->prepare('INSERT INTO login_otps (user_id, code_hash, expires_at, requested_ip) VALUES (:uid, :hash, NOW() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE, :ip)')
            ->execute(['uid' => $user['id'], 'hash' => password_hash($code, PASSWORD_DEFAULT), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        $_SESSION['otp_pending']['last_sent'] = time();

        $error = MailService::send($user['email'], $user['full_name'], 'Your EVORA login code: ' . $code,
            "Hi " . $user['full_name'] . ",\n\nYour EVORA administrator login code is:\n\n    " . $code . "\n\nIt expires in " . self::TTL_MINUTES . " minutes and works once.\n"
            . "Requested from IP " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " on " . date('M d, Y g:i A') . ".\n\n"
            . "If you did not just try to log in, someone knows your password: change it now (Forgot password) and do not share this code.");
        Logger::log((int) $user['id'], $error === null ? 'login_otp_sent' : 'login_otp_email_failed', $error === null ? 'Login code emailed' : $error);

        return $error === null ? null : 'The login code could not be emailed. Please try again in a moment.';
    }

    /** Checks the code. Returns the user to sign in on success, or throws DomainException. */
    public static function verify(PDO $db, string $code): array
    {
        $user = self::pendingUser($db);
        if (!$user) {
            throw new DomainException('Your sign-in expired. Please log in again.');
        }
        $stmt = $db->prepare('SELECT * FROM login_otps WHERE user_id = :uid AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute(['uid' => $user['id']]);
        $otp = $stmt->fetch();
        if (!$otp || strtotime($otp['expires_at']) < time()) {
            throw new DomainException('The code expired. Click "Send a new code".');
        }
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) === 6 && password_verify($code, $otp['code_hash'])) {
            $claim = $db->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = :id AND consumed_at IS NULL');
            $claim->execute(['id' => $otp['id']]);
            if ($claim->rowCount() !== 1) {
                throw new DomainException('This code was already used. Please log in again.');
            }
            unset($_SESSION['otp_pending']['last_sent']);

            return $user;
        }

        $attempts = (int) $otp['attempts'] + 1;
        $db->prepare('UPDATE login_otps SET attempts = :a, consumed_at = IF(:a2 >= ' . self::MAX_ATTEMPTS . ', NOW(), NULL) WHERE id = :id')
            ->execute(['a' => $attempts, 'a2' => $attempts, 'id' => $otp['id']]);
        Logger::log((int) $user['id'], 'login_otp_failed', 'Wrong login code (attempt ' . $attempts . ' of ' . self::MAX_ATTEMPTS . ')');
        if ($attempts >= self::MAX_ATTEMPTS) {
            unset($_SESSION['otp_pending']);
            NotificationService::safely(fn() => notify((int) $user['id'], 'Login Code Blocked',
                'Someone entered a wrong EVORA login code ' . self::MAX_ATTEMPTS . ' times on ' . date('M d, Y g:i A') . ' after typing your correct password. If this was not you, change your password now.',
                'account_security', ['category' => 'system', 'notice_type' => 'action_required']));
            throw new DomainException('Too many wrong codes. Please log in again to get a new code.');
        }

        throw new DomainException('Wrong code. ' . (self::MAX_ATTEMPTS - $attempts) . ' attempt(s) left.');
    }

    /** The pending sign-in's continue-to page (validated in login_post). */
    public static function next(): string
    {
        return (string) ($_SESSION['otp_pending']['next'] ?? '');
    }

    public static function clear(): void
    {
        unset($_SESSION['otp_pending']);
    }
}
