<?php
/**
 * Re-renders every issued certificate PDF with the current one-page template (v72).
 * Certificate numbers, verification tokens (QR), types, issue dates and file paths stay the same.
 *
 * Usage (from the project folder):
 *   php scripts/regenerate_certificates.php            (all issued certificates)
 *   php scripts/regenerate_certificates.php --dry-run  (only lists them)
 *
 * Back up storage/uploads/certificates first.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/NotificationService.php';
require_once EVORA_ROOT . '/core/MailService.php';
require_once EVORA_ROOT . '/core/CertificateService.php';
require_once EVORA_ROOT . '/core/helpers.php';

$dry = in_array('--dry-run', $argv, true);
$db = Database::connection();
$rows = $db->query('SELECT id, certificate_number FROM certificates WHERE status = "issued" ORDER BY id')->fetchAll();
$ok = 0;
$failed = [];
foreach ($rows as $row) {
    if ($dry) {
        echo $row['certificate_number'] . "\n";
        continue;
    }
    try {
        CertificateService::regenerate($db, (int) $row['id']) ? $ok++ : $failed[] = $row['certificate_number'];
    } catch (Throwable $e) {
        $failed[] = $row['certificate_number'] . ' (' . $e->getMessage() . ')';
    }
}
echo ($dry ? count($rows) . ' certificate(s) would be regenerated.' : 'Regenerated: ' . $ok . ' of ' . count($rows)) . "\n";
if ($failed) {
    echo 'Failed: ' . implode(', ', $failed) . "\n";
}
if (!$dry) {
    Logger::log(null, 'certificates_regenerated', 'Re-rendered ' . $ok . ' certificate PDF(s) with the one-page layout');
}
