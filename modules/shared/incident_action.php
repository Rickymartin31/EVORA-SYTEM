<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('incidents');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);

// Scope check: organizer must own the event; staff must be assigned to it
// with the manage_incidents permission. Same pattern as match_center.php.
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT e.* FROM events e WHERE e.id = :id AND e.organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_incidents'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have incident reporting access to it.');
    redirect('incidents');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'incidents');

$action = $_POST['incident_action'] ?? '';

if ($action === 'create') {
    $matchId = (int) ($_POST['match_id'] ?? 0) ?: null;
    $venueId = (int) ($_POST['venue_id'] ?? 0) ?: null;
    $incidentType = $_POST['incident_type'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $description = trim((string) ($_POST['description'] ?? ''));

    $allowedTypes = ['player_misconduct', 'team_complaint', 'referee_issue', 'schedule_delay', 'venue_problem', 'safety_issue', 'equipment_issue', 'other'];
    $allowedSeverities = ['low', 'medium', 'high', 'critical'];

    if (!in_array($incidentType, $allowedTypes, true) || !in_array($severity, $allowedSeverities, true) || $description === '') {
        flash('error', 'Please fill in all required fields.');
        redirect('incidents', ['event_id' => $eventId]);
    }

    // Never trust posted match_id/venue_id: they must belong to this exact event.
    if ($matchId !== null) {
        $chk = $db->prepare('SELECT id FROM matches WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $matchId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $matchId = null; }
    }
    if ($venueId !== null) {
        $chk = $db->prepare('SELECT id FROM venues WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $venueId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $venueId = null; }
    }

    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name'])) {
        $attachmentPath = Upload::handle($_FILES['attachment'], 'incidents', UPLOAD_ALLOWED_DOC);
    }

    $ins = $db->prepare(
        'INSERT INTO event_incidents (event_id, match_id, venue_id, incident_type, severity, description, attachment_path, reported_by)
         VALUES (:eid, :mid, :vid, :type, :sev, :desc, :attach, :by)'
    );
    $ins->execute([
        'eid' => $eventId, 'mid' => $matchId, 'vid' => $venueId, 'type' => $incidentType,
        'sev' => $severity, 'desc' => $description, 'attach' => $attachmentPath, 'by' => $userId,
    ]);
    $incidentId = (int) $db->lastInsertId();

    Logger::log($userId, 'incident_reported', 'Incident #' . $incidentId . ' reported for event #' . $eventId . ' (' . $incidentType . ', ' . $severity . ')');
    $incidentMeta = ['category' => 'event', 'notice_type' => 'action_required', 'reference_type' => 'incident', 'reference_id' => $incidentId];
    $incidentText = ucfirst(str_replace('_', ' ', $incidentType)) . ' incident (' . $severity . ') reported by ' . ($_SESSION['full_name'] ?? 'a team member') . '.';
    NotificationService::toEventOrganizer($db, $eventId, 'Incident Reported', $incidentText, 'incident', $incidentMeta, (int) $userId);
    NotificationService::toEventStaff($db, $eventId, 'Incident Reported', $incidentText, 'incident', ['notice_type' => 'informational'] + $incidentMeta, (int) $userId);
    flash('success', 'Incident reported.');
    redirect('incident-view', ['id' => $incidentId]);
}

// Every action below changes status/resolution — organizer only, even though
// staff without organizer role wouldn't normally reach here via the UI.
if ($role !== 'organizer') {
    flash('error', 'Only the organizer can update incident status.');
    redirect('incidents', ['event_id' => $eventId]);
}

$incidentId = (int) ($_POST['incident_id'] ?? 0);
$incStmt = $db->prepare('SELECT * FROM event_incidents WHERE id = :id AND event_id = :eid');
$incStmt->execute(['id' => $incidentId, 'eid' => $eventId]);
$incident = $incStmt->fetch();

if (!$incident) {
    flash('error', 'Incident not found.');
    redirect('incidents', ['event_id' => $eventId]);
}

$transitions = [
    'start_review' => ['from' => ['open'], 'to' => 'under_review'],
    'resolve' => ['from' => ['under_review'], 'to' => 'resolved'],
    'dismiss' => ['from' => ['under_review'], 'to' => 'dismissed'],
];

if (!isset($transitions[$action])) {
    flash('error', 'Invalid action.');
    redirect('incident-view', ['id' => $incidentId]);
}

$t = $transitions[$action];
if (!in_array($incident['status'], $t['from'], true)) {
    flash('error', 'Cannot ' . str_replace('_', ' ', $action) . ' an incident that is currently "' . $incident['status'] . '".');
    redirect('incident-view', ['id' => $incidentId]);
}

if ($action === 'start_review') {
    $stmt = $db->prepare('UPDATE event_incidents SET status = :status WHERE id = :id AND event_id = :eid');
    $stmt->execute(['status' => $t['to'], 'id' => $incidentId, 'eid' => $eventId]);
    Logger::log($userId, 'incident_review_started', 'Incident #' . $incidentId . ' moved to under review');
    flash('success', 'Incident is now under review.');
} else { // resolve or dismiss
    $resolutionNotes = trim((string) ($_POST['resolution_notes'] ?? ''));
    if ($resolutionNotes === '') {
        flash('error', 'Please provide resolution notes.');
        redirect('incident-view', ['id' => $incidentId]);
    }
    $stmt = $db->prepare(
        'UPDATE event_incidents SET status = :status, resolved_by = :by, resolution_notes = :notes WHERE id = :id AND event_id = :eid'
    );
    $stmt->execute(['status' => $t['to'], 'by' => $userId, 'notes' => $resolutionNotes, 'id' => $incidentId, 'eid' => $eventId]);
    Logger::log($userId, 'incident_' . $action . 'd', 'Incident #' . $incidentId . ' ' . $t['to'] . ': ' . $resolutionNotes);
    flash('success', 'Incident ' . $t['to'] . '.');
}

redirect('incident-view', ['id' => $incidentId]);
