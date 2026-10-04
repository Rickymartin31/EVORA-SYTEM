<?php
/**
 * Confirms PayMongo payments that were paid but never reported back (the
 * participant closed the tab and no webhook reached this server).
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe scripts\paymongo_reconcile.php [--minutes=10] [--limit=50]
 *
 * Checks PayMongo payments still "pending" whose latest checkout session is
 * older than --minutes. Safe to run repeatedly or from Windows Task Scheduler:
 * confirmation goes through PaymentConfirmationService, which approves each
 * payment at most once.
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
require_once EVORA_ROOT . '/core/QrService.php';
require_once EVORA_ROOT . '/core/PaymentGatewayService.php';
require_once EVORA_ROOT . '/core/PaymentConfirmationService.php';
require_once EVORA_ROOT . '/core/FinanceHistory.php';
require_once EVORA_ROOT . '/core/LedgerService.php';
require_once EVORA_ROOT . '/core/EventStatusService.php';
require_once EVORA_ROOT . '/core/PayoutService.php';
require_once EVORA_ROOT . '/core/EventRegistrationService.php';
require_once EVORA_ROOT . '/core/PayoutRequestService.php';
require_once EVORA_ROOT . '/core/helpers.php';

$options = getopt('', ['minutes::', 'limit::']);
$minutes = max(0, (int) ($options['minutes'] ?? 10));
$limit = max(1, (int) ($options['limit'] ?? 50));

if (!PAYMONGO_ENABLED) {
    fwrite(STDERR, "PayMongo is not configured.\n");
    exit(1);
}

$db = Database::connection();
$stmt = $db->prepare(
    'SELECT pay.* FROM payments pay
     WHERE pay.payment_channel = "paymongo" AND pay.status = "pending" AND pay.checkout_session_id IS NOT NULL
       AND COALESCE(
             (SELECT MAX(s.created_at) FROM payment_checkout_sessions s WHERE s.payment_id = pay.id),
             pay.submitted_at
           ) <= NOW() - INTERVAL ' . $minutes . ' MINUTE
     ORDER BY pay.id
     LIMIT ' . $limit
);
$stmt->execute();
$pending = $stmt->fetchAll();

$counts = ['paid' => 0, 'unpaid' => 0, 'error' => 0];
foreach ($pending as $payment) {
    $result = PaymentConfirmationService::verifyWithGateway($db, $payment, 'reconcile');
    $counts[$result]++;
    echo 'Payment #' . $payment['id'] . ' (registration #' . $payment['registration_id'] . '): ' . $result . PHP_EOL;
}

$summary = sprintf('Checked %d pending PayMongo payment(s): %d confirmed, %d still unpaid, %d errors.', count($pending), $counts['paid'], $counts['unpaid'], $counts['error']);
echo $summary . PHP_EOL;
if (count($pending) > 0) {
    Logger::log(null, 'paymongo_reconcile_run', $summary);
}
