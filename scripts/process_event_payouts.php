<?php
/**
 * Scheduled payout processing (registration-closing based payouts).
 *
 * Usage (from the project folder):
 *   C:\xampp\php\php.exe scripts\process_event_payouts.php [--dry-run]
 *
 * Each run:
 *   1. optional automatic closing: closes registration of every "open" event
 *      whose registration deadline has passed (events without a deadline are
 *      closed only by their organizer): status open -> closed,
 *      registration_closed_at stamped;
 *   2. stamps registration_closed_at for events whose registration ended in
 *      another way (organizer closed it / marked the event ongoing);
 *   3. creates ONE pending payout request per closed event from its eligible
 *      ledger entries (amounts come only from the ledger);
 *   4. refreshes every event's earnings totals.
 *
 * The payout trigger is registration closing — the event date is never used.
 *
 * Safe to run repeatedly (e.g. every 15 minutes from Windows Task Scheduler):
 * an event is closed once, the database allows only one open payout request
 * per event, entries already in a request are never requested again, and a
 * second concurrent run is refused.
 *
 * --dry-run only lists what would happen; nothing is changed.
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
require_once EVORA_ROOT . '/core/NotificationService.php';
require_once EVORA_ROOT . '/core/PayoutAccountabilityService.php';
require_once EVORA_ROOT . '/core/helpers.php';

$options = getopt('', ['dry-run']);
$dryRun = isset($options['dry-run']);
$db = Database::connection();

if ($dryRun) {
    echo "DRY RUN — nothing will be changed.\n\n";

    $due = EventRegistrationService::dueForClosing($db);
    echo count($due) . " open event(s) past their registration deadline would be closed:\n";
    foreach ($due as $event) {
        echo '  #' . $event['id'] . ' ' . $event['name'] . ' (deadline ' . $event['registration_deadline'] . ")\n";
    }

    $stmt = $db->query(
        'SELECT x.event_id, x.event_name, COUNT(*) entries, SUM(x.organizer_earnings) net
         FROM (SELECT l.*, e.name AS event_name, ' . LedgerService::STATE_SQL . ' AS state ' . LedgerService::FROM_SQL . ') x
         WHERE x.state = "eligible" GROUP BY x.event_id, x.event_name ORDER BY x.event_id'
    );
    $rows = $stmt->fetchAll();
    echo "\n" . count($rows) . " already-closed event(s) with entries ready for a payout request:\n";
    foreach ($rows as $row) {
        echo '  #' . $row['event_id'] . ' ' . $row['event_name'] . ': ' . $row['entries'] . ' entr' . ((int) $row['entries'] === 1 ? 'y' : 'ies') . ', net ' . $row['net'] . "\n";
    }
    echo "\n(Events closed by this run would also get a request if they have eligible entries.)\n";
    exit(0);
}

try {
    $summary = PayoutRequestService::processDueEvents($db, null);
} catch (DomainException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo count($summary['closed']) . " registration(s) closed automatically\n";
foreach ($summary['closed'] as $label) {
    echo "  $label\n";
}
echo $summary['stamped'] . " event(s) with registration already ended were marked closed\n";
echo count($summary['requests']) . " payout request(s) created" . ($summary['requests'] ? ': #' . implode(', #', $summary['requests']) : '') . "\n";
echo $summary['recalculated'] . " event earnings record(s) refreshed\n";
// Payout deadline / dispute response alerts (the overdue state itself is always derived from timestamps).
echo PayoutAccountabilityService::sweepAlerts($db) . " payout alert notification(s) sent\n";
if ($summary['errors']) {
    echo count($summary['errors']) . " error(s):\n";
    foreach ($summary['errors'] as $error) {
        echo "  $error\n";
    }
    exit(1);
}
exit(0);
