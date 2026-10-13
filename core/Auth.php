<?php

class Auth
{
    private static ?string $lastError = null;

    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    /**
     * $passive: a background request (the bell's live refresh) — it is still subject to the
     * idle timeout but does not count as activity, so polling never keeps an idle session alive.
     */
    public static function start(bool $passive = false): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // PHP's own session garbage collector must not reap a session file
            // before our app-level idle timeout below gets a chance to — the
            // default php.ini gc_maxlifetime (1440s) is shorter than our
            // SESSION_TIMEOUT, which silently logged people out early.
            ini_set('session.gc_maxlifetime', (string) SESSION_TIMEOUT);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                // HTTPS hosting: the session cookie is never sent over plain http (v42)
                'secure' => defined('APP_HTTPS') && APP_HTTPS,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        self::enforceIdleTimeout($passive);
    }

    private static function enforceIdleTimeout(bool $passive = false): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $lastActivity = $_SESSION['last_activity'] ?? time();
        if (time() - $lastActivity > SESSION_TIMEOUT) {
            self::logout();
            if ($passive) {
                http_response_code(401);
                exit;
            }
            header('Location: ' . BASE_URL . '/index.php?route=login&expired=1');
            exit;
        }

        if (!$passive) {
            $_SESSION['last_activity'] = time();
        }
    }

    public static function attempt(string $email, string $password): ?array
    {
        $user = self::verifyCredentials($email, $password);
        if ($user) {
            self::login($user);
        }

        return $user;
    }

    /**
     * Checks email + password (lockout, status) WITHOUT signing in, so a second step
     * (the administrator login code, v37) can run before Auth::login().
     */
    public static function verifyCredentials(string $email, string $password): ?array
    {
        self::$lastError = null;
        self::$unverifiedEmail = false;

        $stmt = Database::connection()->prepare(
            'SELECT u.*, r.name AS role_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            self::$lastError = 'Invalid credentials or inactive account.';
            return null;
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $remainingMinutes = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            self::$lastError = 'Too many failed login attempts. Please try again in ' . $remainingMinutes . ' minute(s).';
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailedAttempt((int) $user['id'], (int) $user['failed_login_attempts']);
            self::$lastError = 'Invalid credentials or inactive account.';
            return null;
        }

        if ($user['status'] !== 'active') {
            self::$lastError = 'Invalid credentials or inactive account.';
            return null;
        }

        // Self-registered accounts must open the emailed verification link first (v38).
        // Checked only after the correct password, so it reveals nothing to a guesser.
        if (array_key_exists('email_verified_at', $user) && $user['email_verified_at'] === null) {
            self::$unverifiedEmail = true;
            self::$lastError = 'Please verify your email address first: open the link we emailed you when you signed up.';
            return null;
        }

        self::clearFailedAttempts((int) $user['id']);

        return $user;
    }

    /** True when the last verifyCredentials() failed only because the email is not verified yet. */
    public static bool $unverifiedEmail = false;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    private static function registerFailedAttempt(int $userId, int $currentCount): void
    {
        $newCount = $currentCount + 1;
        $lockedUntil = $newCount >= self::MAX_FAILED_ATTEMPTS
            ? date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60)
            : null;

        $stmt = Database::connection()->prepare(
            'UPDATE users SET failed_login_attempts = :cnt, locked_until = :locked WHERE id = :id'
        );
        $stmt->execute(['cnt' => $newCount, 'locked' => $lockedUntil, 'id' => $userId]);

        // Security notice once per lockout (not on every failed attempt).
        if ($newCount === self::MAX_FAILED_ATTEMPTS && class_exists('NotificationService')) {
            $db = Database::connection();
            NotificationService::safely(function () use ($db, $userId) {
                $who = $db->prepare('SELECT email FROM users WHERE id = :id');
                $who->execute(['id' => $userId]);
                $login = (string) $who->fetchColumn();
                notify($userId, 'Account Temporarily Locked',
                    'Your EVORA account was locked for ' . self::LOCKOUT_MINUTES . ' minutes after ' . self::MAX_FAILED_ATTEMPTS . ' failed login attempts on ' . date('M d, Y g:i A') . '. If this was not you, change your password after logging in.',
                    'account_security', ['category' => 'system', 'notice_type' => 'action_required']);
                NotificationService::toAdmins($db, 'Failed Login Attempts',
                    'Account "' . $login . '" (user #' . $userId . ') was locked for ' . self::LOCKOUT_MINUTES . ' minutes after ' . self::MAX_FAILED_ATTEMPTS . ' failed login attempts.',
                    'account_security', ['category' => 'system', 'notice_type' => 'informational', 'reference_type' => 'user', 'reference_id' => $userId]);
            });
        }
    }

    private static function clearFailedAttempts(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['last_activity'] = time();

        Logger::log((int) $user['id'], 'login', 'User logged in');
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            Logger::log((int) $_SESSION['user_id'], 'logout', 'User logged out');
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $params = $_GET;
                $route = (string) ($params['route'] ?? '');
                unset($params['route']);
                self::rememberNext($route, $params);
            }
            header('Location: ' . BASE_URL . '/index.php?route=login');
            exit;
        }
    }

    /** Pages that must never be the "after sign-in" destination. */
    private const NEXT_BLOCKED = ['', 'home', 'login', 'login.post', 'login-otp', 'logout', 'google-login', 'google-callback', 'google-complete',
        'register-participant', 'register-organizer', 'forgot-password', 'reset-password', 'verify-email', 'verify-email-resend'];

    /**
     * Remembers the page a signed-out visitor wanted (v65), so signing in brings them back to it.
     * Only an EVORA route with plain query values is kept; it expires after 30 minutes.
     */
    public static function rememberNext(string $route, array $params = []): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,60}$/', $route) || in_array($route, self::NEXT_BLOCKED, true)) {
            return;
        }
        $clean = [];
        foreach ($params as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z0-9_]{1,30}$/i', $k) && is_scalar($v) && strlen((string) $v) <= 120) {
                $clean[$k] = (string) $v;
            }
        }
        $_SESSION['login_next'] = ['route' => $route, 'params' => $clean, 'at' => time()];
    }

    /** "route=event-view&id=3" from a sign-in link (?return=...). */
    public static function rememberNextFromQuery(string $query): void
    {
        parse_str(ltrim($query, '?'), $p);
        $route = (string) ($p['route'] ?? '');
        unset($p['route']);
        self::rememberNext($route, $p);
    }

    /** After a sign-in: go to the remembered page when it fits the user's role, otherwise to $fallback. */
    public static function redirectIntended(string $fallback): void
    {
        $next = $_SESSION['login_next'] ?? null;
        unset($_SESSION['login_next']);
        if (is_array($next) && time() - (int) ($next['at'] ?? 0) <= 1800) {
            $route = (string) $next['route'];
            $prefix = explode('-', $route)[0];
            $otherRole = in_array($prefix, ['admin', 'organizer', 'staff', 'participant'], true) && $prefix !== self::role();
            if (!$otherRole) {
                redirect($route, (array) ($next['params'] ?? []));
            }
        }
        redirect($fallback);
    }

    public static function requireRole(string|array $roles): void
    {
        self::requireLogin();
        $roles = is_array($roles) ? $roles : [$roles];

        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            if (function_exists('renderErrorPage')) {
                renderErrorPage(403, 'Access denied', 'Your account does not have access to this page. If you think this is a mistake, contact the EVORA administrator.');
            }
            die('403 Forbidden — you do not have access to this page.');
        }
    }
}
