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
$sessionId = (int) ($_POST['session_id'] ?? 0);
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_sessions'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
if (!$eventStmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'You do not have session attendance access to this event.']);
    exit;
}

if (!EventAccess::isCorrectCategory($db, $eventId, 'seminar-workshop')) {
    echo json_encode(['success' => false, 'message' => 'This tool is not available for this event type.']);
    exit;
}

// Never trust the posted session_id beyond this: it must belong to this event.
$sessStmt = $db->prepare("SELECT * FROM event_schedule WHERE id = :id AND event_id = :eid AND status != 'cancelled'");
$sessStmt->execute(['id' => $sessionId, 'eid' => $eventId]);
$session = $sessStmt->fetch();
if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session not found or cancelled.']);
    exit;
}

if ($value === '') {
    echo json_encode(['success' => false, 'message' => 'No QR value provided.']);
    exit;
}

// Reuses the existing, untouched QrService — no new QR logic.
$registrationId = QrService::verifyToken($value);
if ($registrationId === null) {
    echo json_encode(['success' => false, 'message' => 'QR code signature is invalid or has been tampered with.']);
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
    echo json_encode(['success' => false, 'message' => 'This QR code does not belong to this event.']);
    exit;
}

if ($registration['status'] !== 'approved') {
    echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' is not an approved registrant.']);
    exit;
}

$dupCheck = $db->prepare('SELECT id FROM session_attendance WHERE session_id = :sid AND registration_id = :rid');
$dupCheck->execute(['sid' => $sessionId, 'rid' => $registrationId]);
if ($dupCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' is already marked present for "' . $session['session_title'] . '".']);
    exit;
}

try {
    $insert = $db->prepare('INSERT INTO session_attendance (session_id, registration_id, scanned_by) VALUES (:sid, :rid, :by)');
    $insert->execute(['sid' => $sessionId, 'rid' => $registrationId, 'by' => $userId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => $registration['full_name'] . ' is already marked present for "' . $session['session_title'] . '".']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Something went wrong while recording attendance.']);
    exit;
}

Logger::log($userId, 'session_attendance_recorded', $registration['full_name'] . ' marked present for session "' . $session['session_title'] . '" in event #' . $eventId);

echo json_encode([
    'success' => true,
    'message' => $registration['full_name'] . ' marked present for "' . $session['session_title'] . '".',
]);
