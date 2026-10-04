<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('medical-incidents');
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_medical'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found or you do not have medical logging access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$action = $_POST['medical_action'] ?? '';

if ($action === 'create') {
    $registrationId = (int) ($_POST['registration_id'] ?? 0) ?: null;
    $checkpointId = (int) ($_POST['checkpoint_id'] ?? 0) ?: null;
    $incidentType = $_POST['incident_type'] ?? '';
    $severity = $_POST['severity'] ?? '';
    $description = trim((string) ($_POST['description'] ?? ''));

    $allowedTypes = ['injury', 'dehydration', 'fatigue', 'cardiac', 'fall', 'other'];
    $allowedSeverities = ['low', 'medium', 'high', 'critical'];

    if (!in_array($incidentType, $allowedTypes, true) || !in_array($severity, $allowedSeverities, true) || $description === '') {
        flash('error', 'Please fill in all required fields.');
        redirect('medical-incidents', ['event_id' => $eventId]);
    }

    // Never trust posted registration_id/checkpoint_id beyond this: both must belong to this exact event.
    if ($registrationId !== null) {
        $chk = $db->prepare('SELECT id FROM registrations WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $registrationId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $registrationId = null; }
    }
    if ($checkpointId !== null) {
        $chk = $db->prepare('SELECT id FROM event_checkpoints WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $checkpointId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $checkpointId = null; }
    }

    $ins = $db->prepare(
        'INSERT INTO race_medical_incidents (event_id, registration_id, checkpoint_id, incident_type, severity, description, reported_by)
         VALUES (:eid, :rid, :cid, :type, :sev, :desc, :by)'
    );
    $ins->execute([
        'eid' => $eventId, 'rid' => $registrationId, 'cid' => $checkpointId, 'type' => $incidentType,
        'sev' => $severity, 'desc' => $description, 'by' => $userId,
    ]);
    $incidentId = (int) $db->lastInsertId();

    Logger::log($userId, 'medical_case_logged', 'Medical case #' . $incidentId . ' logged for event #' . $eventId . ' (' . $incidentType . ', ' . $severity . ')');
    $medicalMeta = ['category' => 'event', 'notice_type' => 'action_required', 'reference_type' => 'medical_incident', 'reference_id' => $incidentId];
    $medicalText = 'Medical case (' . str_replace('_', ' ', $incidentType) . ', ' . $severity . ') logged by ' . ($_SESSION['full_name'] ?? 'a team member') . '.';
    NotificationService::toEventOrganizer($db, $eventId, 'Medical Case Logged', $medicalText, 'incident', $medicalMeta, (int) $userId);
    NotificationService::toEventStaff($db, $eventId, 'Medical Case Logged', $medicalText, 'incident', ['notice_type' => 'informational'] + $medicalMeta, (int) $userId);
    flash('success', 'Case logged.');
    redirect('medical-incident-view', ['id' => $incidentId]);
}

if ($role !== 'organizer') {
    flash('error', 'Only the organizer can resolve a medical case.');
    redirect('medical-incidents', ['event_id' => $eventId]);
}

$incidentId = (int) ($_POST['incident_id'] ?? 0);
$incStmt = $db->prepare('SELECT * FROM race_medical_incidents WHERE id = :id AND event_id = :eid');
$incStmt->execute(['id' => $incidentId, 'eid' => $eventId]);
$incident = $incStmt->fetch();

if (!$incident) {
    flash('error', 'Case not found.');
    redirect('medical-incidents', ['event_id' => $eventId]);
}

if ($action === 'resolve') {
    if ($incident['status'] !== 'open') {
        flash('error', 'This case is already resolved.');
        redirect('medical-incident-view', ['id' => $incidentId]);
    }
    $actionTaken = trim((string) ($_POST['action_taken'] ?? ''));
    if ($actionTaken === '') {
        flash('error', 'Please describe the action taken.');
        redirect('medical-incident-view', ['id' => $incidentId]);
    }
    $stmt = $db->prepare(
        "UPDATE race_medical_incidents SET status = 'resolved', resolved_by = :by, action_taken = :action WHERE id = :id AND event_id = :eid"
    );
    $stmt->execute(['by' => $userId, 'action' => $actionTaken, 'id' => $incidentId, 'eid' => $eventId]);
    Logger::log($userId, 'medical_case_resolved', 'Medical case #' . $incidentId . ' resolved: ' . $actionTaken);
    flash('success', 'Case marked resolved.');
} else {
    flash('error', 'Invalid action.');
}

redirect('medical-incident-view', ['id' => $incidentId]);
