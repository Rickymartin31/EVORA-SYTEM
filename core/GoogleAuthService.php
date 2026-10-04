<?php

/**
 * "Continue with Google" for participants (v41) — OAuth 2.0 / OpenID Connect, authorization-code flow.
 *
 * - Credentials come from C:\xampp\evora_secrets\google.ini (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET);
 *   missing => the button is simply not shown (fail closed).
 * - The Return URL is fixed in config (GOOGLE_REDIRECT_URI, default the real forwarder
 *   http://127.0.0.1/evora_google_callback.php) and is never built from the request.
 * - Only participant accounts may use Google. An email that belongs to an admin/organizer/staff
 *   account is refused (those sign in with their password; admins also need their login code).
 * - Only Google-verified emails are accepted; scopes are openid, email and profile.
 */
class GoogleAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const STATE_TTL = 600;

    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $secretsDir = defined('EVORA_SECRETS_DIR') ? EVORA_SECRETS_DIR : dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_secrets';
        $file = getenv('EVORA_GOOGLE_SECRETS_FILE') ?: $secretsDir . DIRECTORY_SEPARATOR . 'google.ini';
        $ini = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
        $id = trim((string) ($ini['GOOGLE_CLIENT_ID'] ?? ''));
        $secret = trim((string) ($ini['GOOGLE_CLIENT_SECRET'] ?? ''));

        return self::$config = [
            'client_id' => $id,
            'client_secret' => $secret,
            'redirect_uri' => self::redirectUri($ini),
            'enabled' => $id !== '' && $secret !== '' && ($ini['GOOGLE_ENABLED'] ?? '1') !== '0',
        ];
    }

    /**
     * The Return URL registered in the Google client, in this order: GOOGLE_REDIRECT_URI from config
     * (staging), GOOGLE_REDIRECT_URI in google.ini, the space-free forwarder on a local 127.0.0.1
     * install, else APP_URL/index.php?route=google-callback on a real domain (v42).
     */
    private static function redirectUri(array $ini): string
    {
        if (defined('GOOGLE_REDIRECT_URI')) {
            return GOOGLE_REDIRECT_URI;
        }
        if (trim((string) ($ini['GOOGLE_REDIRECT_URI'] ?? '')) !== '') {
            return trim((string) $ini['GOOGLE_REDIRECT_URI']);
        }
        $host = defined('APP_URL') ? strtolower((string) parse_url(APP_URL, PHP_URL_HOST)) : '127.0.0.1';
        if (!defined('APP_URL') || in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return 'http://127.0.0.1/evora_google_callback.php';
        }

        return APP_URL . '/index.php?route=google-callback';
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    /** Host the Return URL lives on (the session cookie only exists there). */
    public static function returnHost(): string
    {
        return strtolower((string) parse_url(self::config()['redirect_uri'], PHP_URL_HOST));
    }

    /** Google's sign-in page for a new one-time state bound to this browser session. */
    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(24));
        $_SESSION['google_oauth'] = ['state' => $state, 'at' => time()];

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => self::config()['client_id'],
            'redirect_uri' => self::config()['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            'access_type' => 'online',
        ]);
    }

    /** Exchanges the code and returns ['sub', 'email', 'name']. Throws DomainException with a safe message. */
    public static function identityFromCallback(string $code, string $state): array
    {
        $saved = $_SESSION['google_oauth'] ?? null;
        unset($_SESSION['google_oauth']); // one-time
        if (!$saved || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['at'] > self::STATE_TTL) {
            throw new DomainException('Your Google sign-in expired or did not start here. Please try again.');
        }
        if ($code === '') {
            throw new DomainException('Google did not return a sign-in code. Please try again.');
        }
        $cfg = self::config();
        $token = self::http(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (empty($token['access_token'])) {
            throw new DomainException('Google sign-in could not be completed (' . ($token['error'] ?? 'no token') . '). Please try again.');
        }
        $info = self::http(self::USERINFO_URL, null, $token['access_token']);
        $sub = (string) ($info['sub'] ?? '');
        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Google did not share your account details. Please try again.');
        }
        if (empty($info['email_verified'])) {
            throw new DomainException('Your Google email address is not verified by Google, so it cannot be used here.');
        }

        return ['sub' => $sub, 'email' => $email, 'name' => trim((string) ($info['name'] ?? '')) ?: strstr($email, '@', true)];
    }

    /**
     * Finds or links the participant for this Google identity.
     * Returns ['user' => row] to sign in, or ['new' => identity] when an account must be created.
     */
    public static function resolve(PDO $db, array $id): array
    {
        $bySub = $db->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.google_sub = :s');
        $bySub->execute(['s' => $id['sub']]);
        $user = $bySub->fetch();
        if (!$user) {
            $byEmail = $db->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = :e');
            $byEmail->execute(['e' => $id['email']]);
            $user = $byEmail->fetch();
            if ($user && $user['role_name'] === 'participant') {
                if (!empty($user['google_sub']) && $user['google_sub'] !== $id['sub']) {
                    throw new DomainException('This EVORA account is already connected to a different Google account. Sign in with your password.');
                }
                // Same Google-verified email: connect Google to the existing participant account.
                $db->prepare('UPDATE users SET google_sub = :s, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id')
                    ->execute(['s' => $id['sub'], 'id' => $user['id']]);
                Logger::log((int) $user['id'], 'google_connected', 'Continue with Google connected to this participant account');
            }
        }
        if (!$user) {
            return ['new' => $id];
        }
        if ($user['role_name'] !== 'participant') {
            throw new DomainException('Continue with Google is for participant accounts only. Please sign in with your email and password.');
        }
        if ($user['status'] !== 'active') {
            throw new DomainException('This account is inactive. Please contact the EVORA administrator.');
        }
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            throw new DomainException('This account is temporarily locked after failed sign-ins. Please try again later.');
        }

        return ['user' => $user];
    }

    /** Creates a participant account for a Google identity (after the person accepted the terms). */
    public static function createParticipant(PDO $db, array $id): array
    {
        $db->beginTransaction();
        try {
            $role = (int) $db->query('SELECT id FROM roles WHERE name = "participant"')->fetchColumn();
            // No usable password: the person signs in with Google (or sets one later with Forgot password).
            $db->prepare('INSERT INTO users (role_id, full_name, email, email_verified_at, google_sub, password_hash, status, terms_accepted_at, terms_version)
                VALUES (:role, :name, :email, NOW(), :sub, :hash, "active", NOW(), :ver)')->execute([
                'role' => $role, 'name' => mb_substr($id['name'], 0, 150), 'email' => $id['email'], 'sub' => $id['sub'],
                'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'ver' => EVORA_LEGAL_VERSION,
            ]);
            $userId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO participants (user_id) VALUES (:uid)')->execute(['uid' => $userId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Logger::log($userId, 'register', 'Participant account created with Continue with Google');
        $stmt = $db->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetch();
    }

    private static function http(string $url, ?array $form, ?string $bearer = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new DomainException('Google could not be reached (' . mb_substr($err, 0, 80) . '). Please try again.');
        }

        return json_decode((string) $body, true) ?: [];
    }
}
