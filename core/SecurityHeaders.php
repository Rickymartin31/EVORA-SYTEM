<?php

/**
 * HTTP security headers (v43), sent on every page and file EVORA serves.
 *
 * - nosniff / SAMEORIGIN / frame-ancestors: no MIME guessing, and EVORA pages cannot be framed by
 *   other sites (clickjacking).
 * - Referrer-Policy: other sites only see our origin, never full URLs (which may carry tokens).
 * - Permissions-Policy: only EVORA itself may use the camera (QR scanner); no microphone/location.
 * - object-src 'none' / base-uri 'self': blocks plugin content and <base> hijacking. Scripts and
 *   styles are not restricted here, because pages use inline handlers and CDNs (PayPal, Bootstrap).
 * - HSTS + upgrade-insecure-requests: only when EVORA runs on https (APP_HTTPS).
 */
class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        $https = defined('APP_HTTPS') && APP_HTTPS;
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), usb=(), interest-cohort=()');
        header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'" . ($https ? '; upgrade-insecure-requests' : ''));
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
