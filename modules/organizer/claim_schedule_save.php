<?php
// v71: organizer adds or removes a race kit / item claiming schedule (POST + CSRF).
Auth::requireRole('organizer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_POST['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();
if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

if (($_POST['schedule_action'] ?? 'add') === 'delete') {
    if (ClaimScheduleService::deleteSlot($db, $eventId, (int) ($_POST['schedule_id'] ?? 0))) {
        Logger::log($userId, 'claim_schedule_removed', 'Removed a claiming schedule of event #' . $eventId);
        flash('success', 'Claiming schedule removed.');
    } else {
        flash('error', 'Claiming schedule not found.');
    }
    redirect('organizer-items', ['event_id' => $eventId]);
}

try {
    ClaimScheduleService::addSlot($db, $event, $userId, (string) ($_POST['claim_date'] ?? ''), (string) ($_POST['start_time'] ?? ''),
        (string) ($_POST['end_time'] ?? ''), (string) ($_POST['location'] ?? ''), (string) ($_POST['note'] ?? ''));
    Logger::log($userId, 'claim_schedule_added', 'Added a claiming schedule (' . ($_POST['claim_date'] ?? '') . ') to event #' . $eventId);
    flash('success', 'Claiming schedule added.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-items', ['event_id' => $eventId]);
