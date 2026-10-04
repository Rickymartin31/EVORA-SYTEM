<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$eventId = (int) ($_POST['event_id'] ?? 0);

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$stmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

if ($event['status'] !== 'draft') {
    flash('error', 'Only draft events without registrations can be deleted.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$regCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id');
$regCount->execute(['id' => $eventId]);
if ((int) $regCount->fetch()['c'] > 0) {
    flash('error', 'Cannot delete an event that already has registrations.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$del = $db->prepare('DELETE FROM events WHERE id = :id');
$del->execute(['id' => $eventId]);

Logger::log($userId, 'event_deleted', 'Deleted draft event #' . $eventId . ' (' . $event['name'] . ')');
flash('success', 'Event deleted.');
redirect('organizer-events');
