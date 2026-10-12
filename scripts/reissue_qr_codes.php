<?php
/**
 * Re-signs every active QR code with the current QR_SECRET (v62).
 *
 * Run it ONCE after moving existing data to a server with a new QR_SECRET (for example the first
 * deploy to hosting): QR codes signed with the old secret would no longer scan. Each active code
 * gets a new token and a new image; participants simply open My QR / their e-ticket again.
 * Invalidated (cancelled) codes are left untouched, as the database requires.
 *
 *   php scripts/reissue_qr_codes.php            show how many codes would be re-issued
 *   php scripts/reissue_qr_codes.php --apply    re-issue them
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/QrService.php';

$db = Database::connection();
$rows = $db->query('SELECT id, registration_id, code_token FROM qr_codes WHERE invalidated_at IS NULL ORDER BY id')->fetchAll();
$stale = array_values(array_filter($rows, fn ($r) => QrService::verifyToken((string) $r['code_token']) !== (int) $r['registration_id']));
echo count($rows) . ' active QR codes, ' . count($stale) . " signed with a different secret.\n";

if (!in_array('--apply', $argv, true)) {
    echo $stale ? "Run again with --apply to re-issue them.\n" : "Nothing to do.\n";
    exit(0);
}

$update = $db->prepare('UPDATE qr_codes SET code_token = :t, qr_image_path = :p, generated_at = NOW() WHERE id = :id AND invalidated_at IS NULL');
$done = 0;
foreach ($stale as $r) {
    [$path, $token] = QrService::generate((int) $r['registration_id']);
    $update->execute(['t' => $token, 'p' => $path, 'id' => $r['id']]);
    $done += $update->rowCount();
}
Logger::log(null, 'qr_codes_reissued', 'Re-issued ' . $done . ' QR codes with the current QR_SECRET');
echo "Re-issued $done QR codes.\n";
