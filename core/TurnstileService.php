<?php

/**
 * Cloudflare Turnstile CAPTCHA (v43) on sign-in, sign-up and "Forgot password" — stops bots from
 * creating fake accounts or guessing passwords once EVORA is on the internet.
 *
 * Keys come from <secrets folder>/turnstile.ini (TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY), made in
 * the Cloudflare dashboard (free) for the site's domain. No file => the widget is not shown and
 * nothing is checked (fail closed only once configured). If Cloudflare itself cannot be reached,
 * the form is allowed through and the event is logged, so an outage never locks everyone out.
 */
class TurnstileService
{
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        $dir = defined('EVORA_SECRETS_DIR') ? EVORA_SECRETS_DIR : dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_secrets';
        $file = defined('TURNSTILE_SECRETS_FILE') ? TURNSTILE_SECRETS_FILE : $dir . DIRECTORY_SEPARATOR . 'turnstile.ini';
        $ini = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
        $site = trim((string) ($ini['TURNSTILE_SITE_KEY'] ?? ''));
        $secret = trim((string) ($ini['TURNSTILE_SECRET_KEY'] ?? ''));

        return self::$config = ['site_key' => $site, 'secret_key' => $secret, 'enabled' => $site !== '' && $secret !== ''];
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'];
    }

    /** The widget to put inside a form (empty when Turnstile is not configured). */
    public static function widget(): string
    {
        if (!self::enabled()) {
            return '';
        }

        return '<div class="cf-turnstile mb-3" data-sitekey="' . htmlspecialchars(self::config()['site_key'], ENT_QUOTES, 'UTF-8') . '" data-theme="light"></div>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    /** True when the submitted form passed the check (always true when Turnstile is not configured). */
    public static function verify(): bool
    {
        if (!self::enabled()) {
            return true;
        }
        $token = (string) ($_POST['cf-turnstile-response'] ?? '');
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => self::config()['secret_key'], 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '']),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body === false) {
            if (class_exists('Logger', false)) {
                Logger::log(null, 'captcha_unreachable', 'Cloudflare Turnstile could not be reached; form allowed');
            }

            return true;
        }
        $result = json_decode((string) $body, true);

        return !empty($result['success']);
    }

    public const FAILED_MESSAGE = 'Please complete the security check ("I am human") and try again.';
}
