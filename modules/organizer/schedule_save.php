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

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.id FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_sessions'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found or you do not have session management access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$allowedTypes = ['keynote', 'lecture', 'workshop', 'breakout', 'panel', 'hands_on'];
$allowedStatuses = ['scheduled', 'ongoing', 'completed', 'cancelled'];

$action = $_POST['schedule_action'] ?? 'create';

if ($action === 'delete') {
    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM event_schedule WHERE id = :id AND event_id = :eid');
    $stmt->execute(['id' => $scheduleId, 'eid' => $eventId]);
    Logger::log($userId, 'session_removed', 'Removed session #' . $scheduleId . ' from event #' . $eventId);
    flash('success', 'Session removed.');
    redirect('organizer-schedule', ['event_id' => $eventId]);
}

if ($action === 'update_status') {
    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, $allowedStatuses, true)) {
        flash('error', 'Invalid status.');
        redirect('organizer-schedule', ['event_id' => $eventId]);
    }
    $stmt = $db->prepare('UPDATE event_schedule SET status = :status WHERE id = :id AND event_id = :eid');
    $stmt->execute(['status' => $status, 'id' => $scheduleId, 'eid' => $eventId]);
    Logger::log($userId, 'session_status_changed', 'Session #' . $scheduleId . ' set to ' . $status . ' for event #' . $eventId);
    flash('success', 'Session status updated.');
    redirect('organizer-schedule', ['event_id' => $eventId]);
}

$sessionTitle = trim($_POST['session_title'] ?? '');
$description = trim($_POST['description'] ?? '');
$sessionDate = $_POST['session_date'] ?? '';
$startTime = $_POST['start_time'] ?? '';
$endTime = $_POST['end_time'] ?? '';
$venue = trim($_POST['venue'] ?? '');
$sessionType = $_POST['session_type'] ?? 'lecture';
$speakerId = (int) ($_POST['speaker_id'] ?? 0) ?: null;

if (!in_array($sessionType, $allowedTypes, true)) {
    $sessionType = 'lecture';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
    $sessionDate = null;
}

if ($sessionTitle === '' || $startTime === '' || $endTime === '') {
    flash('error', 'Please provide a session title, start time, and end time.');
    redirect('organizer-schedule', ['event_id' => $eventId]);
}

// Never trust posted speaker_id: it must belong to this exact event.
if ($speakerId !== null) {
    $chk = $db->prepare('SELECT id FROM event_speakers WHERE id = :id AND event_id = :eid');
    $chk->execute(['id' => $speakerId, 'eid' => $eventId]);
    if (!$chk->fetch()) { $speakerId = null; }
}

if ($action === 'update') {
    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $stmt = $db->prepare(
        'UPDATE event_schedule SET session_date = :date, session_title = :title, description = :desc,
            start_time = :start, end_time = :end, venue = :venue, session_type = :type, speaker_id = :sid
         WHERE id = :id AND event_id = :eid'
    );
    $stmt->execute([
        'date' => $sessionDate, 'title' => $sessionTitle, 'desc' => $description ?: null,
        'start' => $startTime, 'end' => $endTime, 'venue' => $venue ?: null, 'type' => $sessionType,
        'sid' => $speakerId, 'id' => $scheduleId, 'eid' => $eventId,
    ]);
    Logger::log($userId, 'session_updated', 'Updated session #' . $scheduleId . ' for event #' . $eventId);
    flash('success', 'Session updated.');
} else {
    $stmt = $db->prepare(
        'INSERT INTO event_schedule (event_id, session_date, session_title, description, start_time, end_time, venue, session_type, speaker_id)
         VALUES (:eid, :date, :title, :desc, :start, :end, :venue, :type, :sid)'
    );
    $stmt->execute([
        'eid' => $eventId, 'date' => $sessionDate, 'title' => $sessionTitle, 'desc' => $description ?: null,
        'start' => $startTime, 'end' => $endTime, 'venue' => $venue ?: null, 'type' => $sessionType, 'sid' => $speakerId,
    ]);
    Logger::log($userId, 'session_added', 'Added session "' . $sessionTitle . '" to event #' . $eventId);
    flash('success', 'Session added.');
}

redirect('organizer-schedule', ['event_id' => $eventId]);
