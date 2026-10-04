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
$message = trim($_POST['message'] ?? '');
$phase = in_array($_POST['phase'] ?? '', ['before', 'during', 'after'], true) ? $_POST['phase'] : null;
// Communication Center redirects back to itself instead of the default event
// management page; every other existing caller is unaffected (falls through
// to the original redirect target below).
$returnToCommCenter = ($_POST['return_to'] ?? '') === 'communication_center';

$stmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

if ($message === '') {
    flash('error', 'Please write a message before sending.');
    redirect($returnToCommCenter ? 'communication-center' : 'organizer-event-manage', $returnToCommCenter ? ['event_id' => $eventId] : ['id' => $eventId]);
}

$logStmt = $db->prepare('INSERT INTO event_announcements (event_id, sent_by, message, phase) VALUES (:eid, :uid, :msg, :phase)');
$logStmt->execute(['eid' => $eventId, 'uid' => $userId, 'msg' => $message, 'phase' => $phase]);

$participantsStmt = $db->prepare(
    'SELECT u.id FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid AND r.status = "approved"'
);
$participantsStmt->execute(['eid' => $eventId]);
$participants = $participantsStmt->fetchAll();

foreach ($participants as $p) {
    notify((int) $p['id'], 'Announcement: ' . $event['name'], $message, 'announcement');
}

$staffStmt = $db->prepare(
    'SELECT DISTINCT u.id FROM event_staff es
     JOIN staff_permissions sp ON sp.event_staff_id = es.id
     JOIN users u ON u.id = es.user_id
     WHERE es.event_id = :eid AND sp.permission_key = "view_announcements"'
);
$staffStmt->execute(['eid' => $eventId]);
$staff = $staffStmt->fetchAll();

foreach ($staff as $s) {
    notify((int) $s['id'], 'Announcement: ' . $event['name'], $message, 'announcement');
}

Logger::log($userId, 'announcement_sent', 'Sent announcement to ' . count($participants) . ' participant(s) and ' . count($staff) . ' staff for event #' . $eventId);
flash('success', 'Announcement sent to ' . count($participants) . ' participant(s) and ' . count($staff) . ' staff member(s).');
redirect($returnToCommCenter ? 'communication-center' : 'organizer-event-manage', $returnToCommCenter ? ['event_id' => $eventId] : ['id' => $eventId]);
