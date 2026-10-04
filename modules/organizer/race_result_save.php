<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$manualTime = trim($_POST['finish_time_manual'] ?? '');

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventCheckStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
    $eventCheckStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventCheckStmt = $db->prepare(
        "SELECT e.id FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_finish_results'"
    );
    $eventCheckStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventCheckStmt->fetch()) {
    flash('error', 'Event not found or you do not have finish-line recording access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$regStmt = $db->prepare(
    'SELECT r.id, r.event_id FROM registrations r WHERE r.id = :rid AND r.event_id = :eid AND r.status = "approved"'
);
$regStmt->execute(['rid' => $registrationId, 'eid' => $eventId]);
if (!$regStmt->fetch()) {
    flash('error', 'Registration not found or not approved.');
    redirect('organizer-race-results', ['event_id' => $eventId]);
}

$finishTime = $manualTime !== '' ? date('Y-m-d') . ' ' . $manualTime : date('Y-m-d H:i:s');

$existing = $db->prepare('SELECT id FROM race_results WHERE registration_id = :rid');
$existing->execute(['rid' => $registrationId]);

if ($existing->fetch()) {
    $stmt = $db->prepare('UPDATE race_results SET finish_time = :time, recorded_by = :by, recorded_at = NOW() WHERE registration_id = :rid');
} else {
    $stmt = $db->prepare('INSERT INTO race_results (registration_id, finish_time, recorded_by) VALUES (:rid, :time, :by)');
}
$stmt->execute(['time' => $finishTime, 'by' => $userId, 'rid' => $registrationId]);

Logger::log($userId, 'race_finish_recorded', 'Recorded finish time for registration #' . $registrationId . ' on event #' . $eventId);
flash('success', 'Finish time recorded.');
redirect('organizer-race-results', ['event_id' => $eventId]);
