<?php
header('Content-Type: application/json');

if (!Auth::check() || !in_array(Auth::role(), ['organizer', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Refresh the page.']);
    exit;
}

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);
$checkpointId = (int) ($_POST['checkpoint_id'] ?? 0);
$value = trim($_POST['value'] ?? '');

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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_checkpoints'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'You do not have checkpoint scanning access to this event.']);
    exit;
}

if (!EventAccess::isCorrectCategory($db, $eventId, 'fun-run')) {
    echo json_encode(['success' => false, 'message' => 'This tool is not available for this event type.']);
    exit;
}

// Never trust the posted checkpoint_id beyond this: it must belong to this event and be active.
$cpStmt = $db->prepare("SELECT * FROM event_checkpoints WHERE id = :id AND event_id = :eid AND status = 'active'");
$cpStmt->execute(['id' => $checkpointId, 'eid' => $eventId]);
$checkpoint = $cpStmt->fetch();
if (!$checkpoint) {
    echo json_encode(['success' => false, 'message' => 'Checkpoint not found or inactive.']);
    exit;
}

if ($value === '') {
    echo json_encode(['success' => false, 'message' => 'No BIB or QR value provided.']);
    exit;
}

// Try the signed QR token first (does not touch/modify QrService's own logic).
$registrationId = QrService::verifyToken($value);

if ($registrationId === null) {
    // Fall back to a raw BIB number lookup — a manual-entry convenience,
    // not a security check (BIB numbers are already visibly printed on race bibs).
    $bibStmt = $db->prepare('SELECT id FROM registrations WHERE event_id = :eid AND bib_number = :bib AND status = "approved"');
    $bibStmt->execute(['eid' => $eventId, 'bib' => $value]);
    $bibRow = $bibStmt->fetch();
    $registrationId = $bibRow ? (int) $bibRow['id'] : null;
}

if ($registrationId === null) {
    echo json_encode(['success' => false, 'message' => 'No matching runner found for that BIB/QR.']);
    exit;
}

$regStmt = $db->prepare(
    'SELECT r.*, u.full_name FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.id = :id AND r.event_id = :eid'
);
$regStmt->execute(['id' => $registrationId, 'eid' => $eventId]);
$registration = $regStmt->fetch();

if (!$registration) {
    echo json_encode(['success' => false, 'message' => 'This QR/BIB does not belong to this event.']);
    exit;
}

if ($registration['status'] !== 'approved') {
    echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' is not an approved runner.']);
    exit;
}

$dupCheck = $db->prepare('SELECT id FROM checkpoint_scans WHERE checkpoint_id = :cid AND registration_id = :rid');
$dupCheck->execute(['cid' => $checkpointId, 'rid' => $registrationId]);
if ($dupCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' already passed "' . $checkpoint['label'] . '".']);
    exit;
}

try {
    $insert = $db->prepare('INSERT INTO checkpoint_scans (checkpoint_id, registration_id, scanned_by) VALUES (:cid, :rid, :by)');
    $insert->execute(['cid' => $checkpointId, 'rid' => $registrationId, 'by' => $userId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' already passed "' . $checkpoint['label'] . '".']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Something went wrong while recording the passage.']);
    exit;
}

Logger::log($userId, 'checkpoint_passage_recorded', $registration['full_name'] . ' (bib ' . ($registration['bib_number'] ?: '—') . ') passed checkpoint "' . $checkpoint['label'] . '" for event #' . $eventId);

echo json_encode([
    'success' => true,
    'message' => $registration['full_name'] . ' (bib ' . ($registration['bib_number'] ?: '—') . ') recorded at "' . $checkpoint['label'] . '".',
]);
