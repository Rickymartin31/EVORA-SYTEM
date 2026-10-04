<?php
/**
 * EVORA global configuration (hosting-ready, v42).
 *
 * Every environment-specific value lives in ONE file OUTSIDE the website folder:
 *     <secrets folder>/evora.ini        (template: config/evora.ini.example, guide: docs/HOSTING.md)
 * The secrets folder is the first one that exists of:
 *     1. the EVORA_SECRETS_DIR environment variable
 *     2. "evora_secrets" next to the project folder      (hosting: /home/<user>/evora_secrets)
 *     3. "evora_secrets" two levels up                    (XAMPP:   C:\xampp\evora_secrets)
 * Keys missing from evora.ini fall back to the local XAMPP values below, so a local install
 * keeps working without the file. An environment variable EVORA_<KEY> overrides any key.
 */

define('EVORA_ROOT', dirname(__DIR__));

$secretsDir = dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_secrets';
foreach ([getenv('EVORA_SECRETS_DIR') ?: '', dirname(EVORA_ROOT) . DIRECTORY_SEPARATOR . 'evora_secrets', $secretsDir] as $candidate) {
    if ($candidate !== '' && is_dir($candidate)) {
        $secretsDir = $candidate;
        break;
    }
}
define('EVORA_SECRETS_DIR', rtrim($secretsDir, '/\\'));
unset($secretsDir, $candidate);

$appSettings = [];
$appSettingsFile = EVORA_SECRETS_DIR . DIRECTORY_SEPARATOR . 'evora.ini';
if (is_file($appSettingsFile) && is_readable($appSettingsFile)) {
    $appSettings = parse_ini_file($appSettingsFile, false, INI_SCANNER_RAW) ?: [];
}
$appSetting = function (string $key, string $default = '') use (&$appSettings): string {
    $env = getenv('EVORA_' . $key);
    if ($env !== false && $env !== '') {
        return (string) $env;
    }

    return array_key_exists($key, $appSettings) ? trim((string) $appSettings[$key], '"') : $default;
};

// "local" (XAMPP) or "production" (hosting). Production refuses to run with unsafe defaults.
define('APP_ENV', $appSetting('APP_ENV', 'local') === 'production' ? 'production' : 'local');

// Full address of the public folder as the browser sees it, without a trailing slash, e.g.
// https://evora.example.com   or   http://127.0.0.1/EVORA%20SYSTEM/public
define('APP_URL', rtrim($appSetting('APP_URL', 'http://127.0.0.1/EVORA%20SYSTEM/public'), '/'));
// Path part of APP_URL (what every in-app link starts with): "" at a domain root.
define('BASE_URL', rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/'));
define('APP_HTTPS', strtolower((string) parse_url(APP_URL, PHP_URL_SCHEME)) === 'https');

define('DB_HOST', $appSetting('DB_HOST', 'localhost'));
define('DB_NAME', $appSetting('DB_NAME', 'evora_db'));
define('DB_USER', $appSetting('DB_USER', 'root'));
define('DB_PASS', $appSetting('DB_PASS', ''));

// Secret used to HMAC-sign QR code tokens. Changing it makes every existing QR code invalid,
// so set it once, before the first real event. The fallback is a known placeholder for local use only.
$qrSecretPlaceholder = 'evora-capstone-qr-secret-change-me-2026';
define('QR_SECRET', getenv('QR_SECRET') ?: $appSetting('QR_SECRET', $qrSecretPlaceholder));

// Where scripts/backup_database.php keeps its automatic backups, and (optional) the mysqldump binary.
define('BACKUP_DIR', rtrim($appSetting('BACKUP_DIR', dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_backups'), '/\\'));
define('MYSQLDUMP_PATH', $appSetting('MYSQLDUMP_PATH', ''));

if (APP_ENV === 'production' && (QR_SECRET === $qrSecretPlaceholder || strlen(QR_SECRET) < 32 || DB_PASS === '' || !APP_HTTPS)) {
    http_response_code(503);
    exit('EVORA is not configured for production yet: evora.ini needs a long random QR_SECRET, a database password and an https APP_URL.');
}
unset($appSettings, $appSettingsFile, $appSetting, $qrSecretPlaceholder);

// Session idle timeout in seconds (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Upload constraints
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5 MB
define('UPLOAD_ALLOWED_IMAGE', ['jpg', 'jpeg', 'png']);
define('UPLOAD_ALLOWED_DOC', ['jpg', 'jpeg', 'png', 'pdf']);

define('STORAGE_PATH', EVORA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads');

// PayMongo — LEGACY only (historical / in-flight PayMongo records; PayPal is the active provider).
// Keys are NOT in source code: environment variables win, else <secrets folder>/paymongo_real.ini
// (override with EVORA_PAYMONGO_SECRETS_FILE). No file / no key  =>  PAYMONGO_ENABLED is false.
$paymongoSecrets = [];
$paymongoSecretsFile = getenv('EVORA_PAYMONGO_SECRETS_FILE') ?: EVORA_SECRETS_DIR . DIRECTORY_SEPARATOR . 'paymongo_real.ini';
if (is_file($paymongoSecretsFile) && is_readable($paymongoSecretsFile)) {
    $paymongoSecrets = parse_ini_file($paymongoSecretsFile, false, INI_SCANNER_RAW) ?: [];
}
$paymongoSetting = fn (string $key): string => (string) (getenv($key) ?: trim((string) ($paymongoSecrets[$key] ?? ''), '"'));
define('PAYMONGO_SECRET_KEY', $paymongoSetting('PAYMONGO_SECRET_KEY'));
define('PAYMONGO_PUBLIC_KEY', $paymongoSetting('PAYMONGO_PUBLIC_KEY'));
define('PAYMONGO_WEBHOOK_SECRET', $paymongoSetting('PAYMONGO_WEBHOOK_SECRET'));
unset($paymongoSecrets, $paymongoSecretsFile, $paymongoSetting);
define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');
define('PAYMONGO_ENABLED', PAYMONGO_SECRET_KEY !== '');

// PayPal direct payment (participant pays the organizer's own PayPal account).
// Credentials are NEVER written in source code: they come from environment variables, or from
// <secrets folder>/paypal_real.ini (override with EVORA_SECRETS_FILE).
// No file / PAYPAL_ENABLED not 1 / no credentials  =>  PayPal is OFF.
$paypalSecrets = [];
$paypalSecretsFile = getenv('EVORA_SECRETS_FILE') ?: EVORA_SECRETS_DIR . DIRECTORY_SEPARATOR . 'paypal_real.ini';
if (is_file($paypalSecretsFile) && is_readable($paypalSecretsFile)) {
    $paypalSecrets = parse_ini_file($paypalSecretsFile, false, INI_SCANNER_RAW) ?: [];
}
// An environment variable always wins (even "0", so PAYPAL_ENABLED=0 switches PayPal off).
$paypalSetting = function (string $key, string $default = '') use (&$paypalSecrets): string {
    $env = getenv($key);
    return (string) (($env !== false && $env !== '') ? $env : ($paypalSecrets[$key] ?? $default));
};
define('PAYPAL_CLIENT_ID', $paypalSetting('PAYPAL_CLIENT_ID'));
define('PAYPAL_CLIENT_SECRET', $paypalSetting('PAYPAL_CLIENT_SECRET'));
define('PAYPAL_ENV', $paypalSetting('PAYPAL_ENV', 'sandbox') === 'live' ? 'live' : 'sandbox');
define('PAYPAL_CURRENCY', strtoupper($paypalSetting('PAYPAL_CURRENCY', 'PHP')));
define('PAYPAL_API_BASE', PAYPAL_ENV === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com');
// Master switch: PayPal is offered only when enabled AND both credentials are present.
define('PAYPAL_ENABLED', $paypalSetting('PAYPAL_ENABLED', '0') === '1' && PAYPAL_CLIENT_ID !== '' && PAYPAL_CLIENT_SECRET !== '');
// Organizer self-service "Log in with PayPal" (account linking only — payments are unaffected).
// Off unless the ini sets PAYPAL_OAUTH_ENABLED=1 AND PAYPAL_OAUTH_REDIRECT_URI, the exact Return URL
// registered in the PayPal app. The URL is never built from the request's Host header.
define('PAYPAL_OAUTH_REDIRECT_URI', $paypalSetting('PAYPAL_OAUTH_REDIRECT_URI'));
define('PAYPAL_OAUTH_ENABLED', PAYPAL_ENABLED && $paypalSetting('PAYPAL_OAUTH_ENABLED', '0') === '1' && PAYPAL_OAUTH_REDIRECT_URI !== '');
unset($paypalSecrets, $paypalSecretsFile, $paypalSetting);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Manila');
