<?php

/**
 * Thin wrapper around the PayMongo REST API (Checkout Sessions).
 * https://docs.paymongo.com/reference/create_checkout_sessions
 */
class PaymentGatewayService
{
    private static function request(string $method, string $path, array $body = [], int $timeout = 20): array
    {
        if (PAYMONGO_SECRET_KEY === '') {
            throw new RuntimeException('PayMongo is not configured (PAYMONGO_SECRET_KEY is empty).');
        }

        $ch = curl_init(PAYMONGO_API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => PAYMONGO_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $body]]));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('PayMongo request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $message = $decoded['errors'][0]['detail'] ?? ('HTTP ' . $httpCode);
            throw new RuntimeException('PayMongo API error: ' . $message);
        }

        return $decoded ?? [];
    }

    /**
     * Creates a hosted Checkout Session for one payment. Amount is in pesos;
     * PayMongo expects centavos (integer), so it's multiplied by 100 here.
     */
    public static function createCheckoutSession(string $description, float $amountPesos, string $referenceNumber, string $successUrl, string $cancelUrl, array $metadata = []): array
    {
        $attributes = [
            'line_items' => [[
                'name' => $description,
                'amount' => (int) round($amountPesos * 100),
                'quantity' => 1,
                'currency' => 'PHP',
            ]],
            'payment_method_types' => ['gcash', 'paymaya', 'card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'reference_number' => $referenceNumber,
            'description' => $description,
            'send_email_receipt' => false,
            'show_line_items' => true,
        ];
        if (!empty($metadata)) {
            // Lets a webhook be matched back to EVORA's payment even if the
            // session id was replaced by a retry. Values must be strings.
            $attributes['metadata'] = array_map('strval', $metadata);
        }

        $result = self::request('POST', '/checkout_sessions', $attributes);

        return [
            'id' => $result['data']['id'] ?? null,
            'checkout_url' => $result['data']['attributes']['checkout_url'] ?? null,
        ];
    }

    public static function retrieveCheckoutSession(string $checkoutSessionId, int $timeout = 20): array
    {
        $result = self::request('GET', '/checkout_sessions/' . urlencode($checkoutSessionId), [], $timeout);
        $attributes = $result['data']['attributes'] ?? [];
        $paid = self::findPaidPayment($attributes['payments'] ?? []);

        return [
            'id' => $result['data']['id'] ?? null,
            'status' => $attributes['status'] ?? null,
            'paid' => $paid !== null,
            'payment_id' => $paid['payment_id'] ?? null,
            'payment_method' => $paid['payment_method'] ?? null,
            'fee' => $paid['fee'] ?? null,
            'net_amount' => $paid['net_amount'] ?? null,
            'amount' => $paid['amount'] ?? null,
        ];
    }

    /**
     * Read-only lookup of one PayMongo payment (GET /payments/{id}). Used by
     * scripts/ledger_backfill.php to recover historical fees.
     */
    public static function retrievePayment(string $paymentId, int $timeout = 20): array
    {
        $result = self::request('GET', '/payments/' . urlencode($paymentId), [], $timeout);

        return self::summarizePayment($result['data'] ?? []);
    }

    /**
     * Expires an open Checkout Session so it can no longer be paid (used before
     * a retry creates a new one). Throws on API error; callers treat it as
     * non-fatal because the old session stays in payment_checkout_sessions.
     */
    public static function expireCheckoutSession(string $checkoutSessionId): void
    {
        self::request('POST', '/checkout_sessions/' . urlencode($checkoutSessionId) . '/expire');
    }

    /**
     * Registers a webhook endpoint and returns its id and signing secret.
     * Used only by scripts/paymongo_webhook_setup.php.
     */
    public static function createWebhook(string $url, array $events): array
    {
        $result = self::request('POST', '/webhooks', ['url' => $url, 'events' => $events]);

        return [
            'id' => $result['data']['id'] ?? null,
            'secret_key' => $result['data']['attributes']['secret_key'] ?? null,
            'status' => $result['data']['attributes']['status'] ?? null,
        ];
    }

    /**
     * Picks the payment with status "paid" out of a list of PayMongo payment
     * resources (a checkout session can hold failed attempts before the paid one).
     */
    public static function findPaidPayment(array $payments): ?array
    {
        foreach ($payments as $payment) {
            if (($payment['attributes']['status'] ?? null) === 'paid') {
                return self::summarizePayment($payment);
            }
        }

        return null;
    }

    /**
     * Normalizes a PayMongo payment resource. PayMongo amounts are in
     * centavos; fee/net are returned in pesos, or null when not present.
     */
    public static function summarizePayment(array $payment): array
    {
        $attributes = $payment['attributes'] ?? [];

        return [
            'payment_id' => $payment['id'] ?? null,
            'status' => $attributes['status'] ?? null,
            'payment_method' => $attributes['source']['type'] ?? null,
            'amount' => isset($attributes['amount']) ? round($attributes['amount'] / 100, 2) : null,
            'fee' => isset($attributes['fee']) ? round($attributes['fee'] / 100, 2) : null,
            'net_amount' => isset($attributes['net_amount']) ? round($attributes['net_amount'] / 100, 2) : null,
            'metadata' => $attributes['metadata'] ?? [],
        ];
    }

    /**
     * Verifies the "Paymongo-Signature" header (Stripe-style t=/te=/li= scheme):
     * HMAC-SHA256 of "{timestamp}.{raw_body}" using the webhook's own signing
     * secret, compared against whichever signature this event actually sent —
     * "li" for a live-mode webhook, "te" for a sandbox/test one.
     *
     * NOTE: implemented per PayMongo's documented pattern — reconfirm exact
     * field names against your PayMongo dashboard's webhook resource once a
     * real webhook is created there. A wrong field name here just means the
     * webhook gets rejected (400), never a false "duplicate payment applied",
     * since the handler's own webhook_event_id uniqueness check is independent
     * of this signature check.
     */
    public static function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (PAYMONGO_WEBHOOK_SECRET === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? null;
        // The header carries both keys ("t=…,te=…,li=…") with the unused one
        // empty: live events fill "li", sandbox/test events fill "te". Take
        // whichever is non-empty — "??" alone would pick the empty "li".
        $signature = ($parts['li'] ?? '') !== '' ? $parts['li'] : (($parts['te'] ?? '') !== '' ? $parts['te'] : null);
        if ($timestamp === null || $signature === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, PAYMONGO_WEBHOOK_SECRET);

        return hash_equals($expected, $signature);
    }
}
