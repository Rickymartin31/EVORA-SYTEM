<?php

/**
 * PayPal REST API client (Orders v2 + Log in with PayPal) for direct organizer payment.
 *
 *   createOrder()  — EVORA's server creates the order: amount from the database,
 *                    payee = the organizer's linked PayPal account (merchant ID)
 *   captureOrder() — after the buyer approves in PayPal, EVORA's server captures it
 *   verifyCapture()— the ONLY thing that can turn a registration into "paid":
 *                    order ID, COMPLETED, amount, currency, payee, invoice/registration
 *
 * Credentials come from config (environment / secrets file outside the web root).
 * The access token is kept in memory for the request only and is never logged.
 */
class PayPalGatewayService
{
    private static ?array $token = null;

    /** Test seam: callable(string $method, string $url, array $headers, ?string $body): array{status:int, body:array}. */
    private static $transport = null;

    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
        self::$token = null;
    }

    public static function enabled(): bool
    {
        return PAYPAL_ENABLED;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private static function send(string $method, string $path, array $headers, ?string $body, int $timeout = 30): array
    {
        $url = PAYPAL_API_BASE . $path;
        if (self::$transport) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        if (PAYPAL_CLIENT_ID === '' || PAYPAL_CLIENT_SECRET === '') {
            throw new RuntimeException('PayPal is not configured.');
        }
        $ch = curl_init($url);
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('PayPal could not be reached (' . $error . ').');
        }

        return ['status' => $status, 'body' => json_decode((string) $raw, true) ?: []];
    }

    private static function accessToken(): string
    {
        if (self::$token && self::$token['expires'] > time() + 60) {
            return self::$token['value'];
        }
        $res = self::send('POST', '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials', 20);
        if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
            throw new RuntimeException('PayPal authentication failed (HTTP ' . $res['status'] . ').');
        }
        self::$token = ['value' => (string) $res['body']['access_token'], 'expires' => time() + (int) ($res['body']['expires_in'] ?? 300)];

        return self::$token['value'];
    }

    private static function api(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . self::accessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ] + $extraHeaders;

        return self::send($method, $path, $headers, $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    /**
     * $tx: amount, currency, merchant_id, invoice_id, registration_id, request_id, description.
     * Returns [orderId, status, httpStatus].
     */
    public static function createOrder(array $tx): array
    {
        $res = self::api('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'REG-' . $tx['registration_id'],
                'custom_id' => 'REG-' . $tx['registration_id'],
                'invoice_id' => $tx['invoice_id'],
                'description' => mb_substr((string) $tx['description'], 0, 127),
                'amount' => ['currency_code' => $tx['currency'], 'value' => number_format((float) $tx['amount'], 2, '.', '')],
                'payee' => ['merchant_id' => $tx['merchant_id']],
            ]],
            'application_context' => array_filter([
                'brand_name' => 'EVORA',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                // Redirect fallback (popup blocked): PayPal returns the buyer to EVORA, which captures server-side.
                'return_url' => $tx['return_url'] ?? null,
                'cancel_url' => $tx['cancel_url'] ?? null,
            ]),
        ], ['PayPal-Request-Id' => $tx['request_id'], 'Prefer' => 'return=representation']);

        return [(string) ($res['body']['id'] ?? ''), (string) ($res['body']['status'] ?? ''), $res['status'], self::issue($res['body'])];
    }

    /** $mockCode: sandbox negative testing only (e.g. INSTRUMENT_DECLINED) — ignored outside sandbox. */
    public static function captureOrder(string $orderId, string $requestId, ?string $mockCode = null): array
    {
        $headers = ['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'];
        if ($mockCode !== null && PAYPAL_ENV === 'sandbox' && preg_match('/^[A-Z_]{3,60}$/', $mockCode)) {
            $headers['PayPal-Mock-Response'] = json_encode(['mock_application_codes' => $mockCode]);
        }
        $res = self::api('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', null, $headers);

        return ['status' => $res['status'], 'body' => $res['body'], 'issue' => self::issue($res['body'])];
    }

    public static function getOrder(string $orderId): array
    {
        $res = self::api('GET', '/v2/checkout/orders/' . rawurlencode($orderId));

        return ['status' => $res['status'], 'body' => $res['body']];
    }

    /** PayPal's error code (e.g. INSTRUMENT_DECLINED, ORDER_ALREADY_CAPTURED) or null. */
    public static function issue(array $body): ?string
    {
        return $body['details'][0]['issue'] ?? ($body['name'] ?? null) ?: null;
    }

    /**
     * Server-side proof of payment. Never trust the browser: every fact is taken from
     * PayPal's capture response and compared with what EVORA stored when it created the order.
     * $expected: order_id, amount, currency, merchant_id, invoice_id, registration_id.
     * Returns ['ok' => bool, 'reason' => string|null, 'capture_id', 'fee', 'net', 'status'].
     */
    public static function verifyCapture(array $order, array $expected): array
    {
        $fail = fn (string $reason, ?string $status = null) => ['ok' => false, 'reason' => $reason, 'capture_id' => null, 'fee' => null, 'net' => null, 'status' => $status];
        if (($order['id'] ?? '') !== $expected['order_id']) {
            return $fail('Order ID does not match.');
        }
        $unit = $order['purchase_units'][0] ?? null;
        $capture = $unit['payments']['captures'][0] ?? null;
        if (!$unit || !$capture) {
            return $fail('No capture in the PayPal response.', (string) ($order['status'] ?? ''));
        }
        if (($capture['status'] ?? '') !== 'COMPLETED') {
            return $fail('Capture status is ' . ($capture['status'] ?? 'unknown') . '.', (string) ($capture['status'] ?? ''));
        }
        $value = (string) ($capture['amount']['value'] ?? '');
        if (($capture['amount']['currency_code'] ?? '') !== $expected['currency']
            || bccomp_safe($value, number_format((float) $expected['amount'], 2, '.', '')) !== 0) {
            return $fail('Captured amount does not match the registration fee.', 'COMPLETED');
        }
        $payee = $unit['payee']['merchant_id'] ?? null;
        if ($payee !== $expected['merchant_id']) {
            return $fail('Payment did not go to the organizer\'s linked PayPal account.', 'COMPLETED');
        }
        $invoice = $capture['invoice_id'] ?? ($unit['invoice_id'] ?? null);
        $custom = $capture['custom_id'] ?? ($unit['custom_id'] ?? null);
        if ($invoice !== $expected['invoice_id'] || $custom !== 'REG-' . $expected['registration_id']) {
            return $fail('Payment reference does not match this registration.', 'COMPLETED');
        }
        $breakdown = $capture['seller_receivable_breakdown'] ?? [];

        return [
            'ok' => true,
            'reason' => null,
            'capture_id' => (string) $capture['id'],
            'fee' => isset($breakdown['paypal_fee']['value']) ? (string) $breakdown['paypal_fee']['value'] : null,
            'net' => isset($breakdown['net_amount']['value']) ? (string) $breakdown['net_amount']['value'] : null,
            'status' => 'COMPLETED',
        ];
    }

    /**
     * Asks PayPal whether a merchant/account ID can receive payments, by creating a ₱1 order
     * with that payee. Nobody approves it, so no money moves; PayPal discards it. Returns
     * ['ok' => bool, 'issue' => PayPal error code|null, 'http' => int].
     */
    public static function verifyPayee(string $merchantId, string $currency): array
    {
        $res = self::api('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'description' => 'EVORA payee verification (never charged)',
                'amount' => ['currency_code' => $currency, 'value' => '1.00'],
                'payee' => ['merchant_id' => $merchantId],
            ]],
        ], ['PayPal-Request-Id' => 'verify-' . bin2hex(random_bytes(12))]);

        return ['ok' => $res['status'] >= 200 && $res['status'] < 300 && !empty($res['body']['id']), 'issue' => self::issue($res['body']), 'http' => $res['status']];
    }

    // ------------------------------------------------------------------
    // Log in with PayPal — proves the organizer owns the account they link
    // ------------------------------------------------------------------

    public static function connectUrl(string $state, string $redirectUri): string
    {
        $web = PAYPAL_ENV === 'live' ? 'https://www.paypal.com' : 'https://www.sandbox.paypal.com';

        return $web . '/connect?' . http_build_query([
            'flowEntry' => 'static',
            'client_id' => PAYPAL_CLIENT_ID,
            'response_type' => 'code',
            'scope' => 'openid https://uri.paypal.com/services/paypalattributes',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /** Exchanges the one-time code and returns the account's identity (payer_id = PayPal account ID). */
    public static function identityFromCode(string $code): array
    {
        $tokenRes = self::send('POST', '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query(['grant_type' => 'authorization_code', 'code' => $code]), 20);
        $userToken = $tokenRes['body']['access_token'] ?? null;
        if ($tokenRes['status'] !== 200 || !$userToken) {
            throw new RuntimeException('PayPal did not accept the sign-in (HTTP ' . $tokenRes['status'] . ').');
        }
        $info = self::send('GET', '/v1/identity/oauth2/userinfo?schema=paypalv1.1', [
            'Authorization' => 'Bearer ' . $userToken,
            'Accept' => 'application/json',
        ], null, 20);
        if ($info['status'] !== 200) {
            // Safe diagnostics only: PayPal's error name/description and the scope names granted (never the token or code).
            $err = trim(($info['body']['error'] ?? $info['body']['name'] ?? '') . ' ' . ($info['body']['error_description'] ?? $info['body']['message'] ?? ''));
            $identityScopes = array_values(array_filter(explode(' ', (string) ($tokenRes['body']['scope'] ?? '')), fn ($s) => preg_match('~openid|email|profile|address|paypalattributes|phone~i', $s)));
            throw new RuntimeException('PayPal account details could not be read (userinfo HTTP ' . $info['status'] . ($err !== '' ? ', ' . mb_substr($err, 0, 80) : '')
                . '; scopes: ' . (implode(' ', $identityScopes) ?: 'none') . ').');
        }
        $email = null;
        foreach ($info['body']['emails'] ?? [] as $e) {
            if (!empty($e['primary']) || $email === null) {
                $email = (string) ($e['value'] ?? '');
            }
        }

        // account_type is kept only when PayPal states it; null means "not reported" (never guessed).
        $accountType = $info['body']['account_type'] ?? null;

        return [
            'payer_id' => (string) ($info['body']['payer_id'] ?? ''),
            'email' => $email,
            'verified_account' => in_array($info['body']['verified_account'] ?? false, [true, 'true'], true),
            'account_type' => is_string($accountType) && $accountType !== '' ? $accountType : null,
        ];
    }
}

/** Exact decimal comparison for money strings ("500.00" vs "500.0"); bcmath may be missing on XAMPP. */
function bccomp_safe(string $a, string $b): int
{
    $toCents = static fn (string $v) => (int) round(((float) $v) * 100);

    return $toCents($a) <=> $toCents($b);
}
