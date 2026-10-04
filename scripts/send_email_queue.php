<?php
/**
 * Sends queued notification emails (v35).
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe scripts\send_email_queue.php [--limit=20]
 *
 * The bell's live refresh already sends a few emails at a time while someone is signed
 * in; run this from Windows Task Scheduler (e.g. every 5 minutes) so emails also go out
 * when nobody is using EVORA. Safe to run repeatedly: each email is claimed once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/MailService.php';
// queued emails may carry a receipt / e-ticket / calendar file (v43)
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/helpers.php';
require_once EVORA_ROOT . '/core/EventDocumentsService.php';
require_once EVORA_ROOT . '/core/ParticipantToolsService.php';

$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

if (!MailService::enabled()) {
    echo "Email is not configured (mail.ini missing or MAIL_ENABLED=0). Nothing sent.\n";
    exit(0);
}
[$sent, $failed] = MailService::processQueue(Database::connection(), $limit);
echo "Sent: $sent, failed: $failed\n";
