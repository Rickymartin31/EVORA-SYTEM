# PayPal — production (real project) configuration strategy

Status: **prepared in staging only. Nothing here has been applied to the real project.**

## Problem this solves

Staging and the real project both live under `C:\xampp\htdocs\`, so the staging PayPal block
(`dirname(EVORA_ROOT, 2) . '\evora_secrets\paypal.ini'`) would make the real project read the
**same** `C:\xampp\evora_secrets\paypal.ini` — which has `PAYPAL_ENABLED=1` for staging tests.
Copying the code would switch PayPal on in the real project immediately.

## Strategy

1. The real project reads its **own** file: `C:\xampp\evora_secrets\paypal_real.ini`.
   Staging keeps `paypal.ini` (`PAYPAL_ENV=sandbox`, `PAYPAL_CURRENCY=PHP`) unchanged.
2. **Fail closed:** if `paypal_real.ini` does not exist, is unreadable, has `PAYPAL_ENABLED` other
   than `1`, or is missing the Client ID / Secret, then `PAYPAL_ENABLED` is `false`.
   So the real project starts with PayPal **off** even before the file is created.
3. The file lives outside the web root and is never committed, printed, logged or put in a URL.
4. Environment variables still override the file (`PAYPAL_ENABLED=0` is the emergency off switch).
   Do **not** set `EVORA_SECRETS_FILE` globally in Apache — it would point staging and the real
   project at the same file again.

## Block to merge into the real `config/config.php` (Step 8 — do not copy the whole staging config)

Identical to the staging block except the default file name (`paypal_real.ini`):

```php
// PayPal direct payment (participant pays the organizer's own PayPal account).
// Credentials are NEVER written in source code: they come from environment
// variables, or from an ini file OUTSIDE the web root (real project:
// C:\xampp\evora_secrets\paypal_real.ini, override with EVORA_SECRETS_FILE).
// No file / PAYPAL_ENABLED not 1 / no credentials  =>  PayPal is OFF.
$paypalSecrets = [];
$paypalSecretsFile = getenv('EVORA_SECRETS_FILE') ?: dirname(EVORA_ROOT, 2) . DIRECTORY_SEPARATOR . 'evora_secrets' . DIRECTORY_SEPARATOR . 'paypal_real.ini';
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
unset($paypalSecrets, $paypalSecretsFile, $paypalSetting);
```

## `paypal_real.ini` (created by the owner at deployment — never by pasting values in chat)

Start **disabled**. Sandbox for the capstone deployment; do not switch to live.

```ini
PAYPAL_ENABLED=0
PAYPAL_ENV=sandbox
PAYPAL_CURRENCY=PHP
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
```

Step 9 (only after Step 8 smoke tests pass and with approval): the owner fills in the Client ID /
Secret locally and sets `PAYPAL_ENABLED=1`, then the admin confirms each organizer's PayPal account.

## Order of operations reminder

- Migration **before** copying code (the new code reads `events.payment_provider` even with PayPal off).
- Rollback: `PAYPAL_ENABLED=0` → restore old code → `paypal_phase1_rollback.sql` only if no PayPal
  payments exist (never with `--force`; export `organizer_paypal_accounts` first) → DB restore last.
