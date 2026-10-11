<?php
/**
 * Participant background jobs (v39): waitlist offers/expiry and automatic reminders
 * ("your event is tomorrow", "a saved event's registration closes soon").
 * v45: past events are completed automatically, unpaid registrations get one payment reminder
 * after 24 hours, and unpaid registrations of an event that is over are closed (EventLifecycleService).
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe scripts\participant_jobs.php
 *
 * Safe to run repeatedly (Task Scheduler, e.g. every 15 minutes): each reminder is sent once
 * (participant_reminders_sent) and each waitlist offer is claimed atomically.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Logger.php';
require_once EVORA_ROOT . '/core/FinanceHistory.php';
require_once EVORA_ROOT . '/core/LedgerService.php';
require_once EVORA_ROOT . '/core/EventStatusService.php';
require_once EVORA_ROOT . '/core/PayoutService.php';
require_once EVORA_ROOT . '/core/EventRegistrationService.php';
require_once EVORA_ROOT . '/core/PayoutRequestService.php';
require_once EVORA_ROOT . '/core/PayoutAccountabilityService.php';
require_once EVORA_ROOT . '/core/QrService.php';
require_once EVORA_ROOT . '/core/CertificateService.php';
require_once EVORA_ROOT . '/core/EvaluationService.php';
require_once EVORA_ROOT . '/core/EventLifecycleService.php';
require_once EVORA_ROOT . '/core/NotificationService.php';
require_once EVORA_ROOT . '/core/MailService.php';
require_once EVORA_ROOT . '/core/WaitlistService.php';
require_once EVORA_ROOT . '/core/ParticipantToolsService.php';
require_once EVORA_ROOT . '/core/helpers.php';
require_once EVORA_ROOT . '/core/MaintenanceService.php';

$db = Database::connection();
$completed = EventLifecycleService::autoCompletePastEvents($db);
// v60: while EVORA is under maintenance participants cannot act, so nothing that needs them runs.
if (MaintenanceService::isActive()) {
    echo 'Maintenance mode is on: waitlist offers, reminders and unpaid closing are paused. Events completed: ' . count($completed) . "\n";
    exit(0);
}
$expired = EventLifecycleService::expireUnpaidAfterEvent($db);
$unpaidReminders = EventLifecycleService::remindUnpaid($db);
$offers = WaitlistService::process($db);
$sent = ParticipantToolsService::sendReminders($db);
echo 'Waitlist offers: ' . $offers . ', reminders: ' . $sent['event_tomorrow'] . ' event-tomorrow, ' . $sent['saved_deadline_soon'] . " saved-deadline\n";
echo 'Events completed: ' . count($completed) . ($completed ? ' (#' . implode(', #', $completed) . ')' : '') . ', unpaid closed after event: ' . $expired . ', unpaid reminders: ' . $unpaidReminders . "\n";
