<?php
// v71: organizer reminds every participant who still has an item to claim (bell + email).
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

try {
    $count = ClaimScheduleService::sendManualReminder($db, $event, $userId);
    flash('success', 'Reminder sent to ' . $count . ' participant' . ($count === 1 ? '' : 's') . ' (notification and email).');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-items', ['event_id' => $eventId]);
