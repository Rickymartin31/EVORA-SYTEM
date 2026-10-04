<?php
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
$stmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$participantsStmt = $db->prepare(
    'SELECT u.id FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid AND r.status = "approved"'
);
$participantsStmt->execute(['eid' => $eventId]);
$participants = $participantsStmt->fetchAll();

$message = 'Reminder: "' . $event['name'] . '" is on ' . date('F d, Y', strtotime($event['event_date']))
    . ' at ' . date('h:i A', strtotime($event['event_time'])) . ', ' . $event['venue'] . '.';

foreach ($participants as $p) {
    notify((int) $p['id'], 'Event Reminder', $message, 'event_reminder');
}

Logger::log($userId, 'reminder_sent', 'Sent reminder to ' . count($participants) . ' participant(s) for event #' . $eventId);
flash('success', 'Reminder sent to ' . count($participants) . ' participant(s).');
redirect('organizer-event-manage', ['id' => $eventId]);
