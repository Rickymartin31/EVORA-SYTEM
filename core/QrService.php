<?php

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

/**
 * Generates and verifies HMAC-signed QR tokens for registrations.
 * The QR image never encodes the raw registration ID alone — it encodes
 * "{registrationId}.{signature}" so a scanned code cannot be forged or
 * guessed by incrementing IDs.
 */
class QrService
{
    public static function makeToken(int $registrationId): string
    {
        $signature = hash_hmac('sha256', (string) $registrationId, QR_SECRET);

        return $registrationId . '.' . $signature;
    }

    /**
     * Returns the registration ID if the token signature is valid, else null.
     */
    public static function verifyToken(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$registrationId, $signature] = $parts;
        if (!ctype_digit($registrationId)) {
            return null;
        }

        $expected = hash_hmac('sha256', $registrationId, QR_SECRET);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return (int) $registrationId;
    }

    /**
     * Generates a PNG QR image for the given registration and saves it under
     * storage/uploads/qr. Returns [relativePath, token].
     */
    public static function generate(int $registrationId): array
    {
        $token = self::makeToken($registrationId);

        $result = Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($token)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(320)
            ->margin(10)
            ->build();

        $filename = 'qr_' . $registrationId . '_' . bin2hex(random_bytes(6)) . '.png';
        $targetDir = STORAGE_PATH . DIRECTORY_SEPARATOR . 'qr';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $relativePath = 'qr/' . $filename;
        $result->saveToFile($targetDir . DIRECTORY_SEPARATOR . $filename);

        return [$relativePath, $token];
    }
}
