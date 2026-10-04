<?php
header('Content-Type: application/json');

if (!Auth::check() || Auth::role() !== 'staff') {
    http_response_code(403);
    echo json_encode(['valid' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    echo json_encode(['valid' => false, 'code' => 'session', 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

$db = Database::connection();
$userId = Auth::id();
$token = trim($_POST['token'] ?? '');
$eventId = (int) ($_POST['event_id'] ?? 0);
$stage = $_POST['stage'] ?? 'lookup'; // 'lookup' (no side effect) or 'confirm' (records attendance)

// Manual check-in (v40): staff found the participant by name/bib instead of scanning, so a
// registration id replaces the QR token. Every rule below still applies unchanged.
$manualRegistrationId = (int) ($_POST['registration_id'] ?? 0);
$method = ($token === '' && $manualRegistrationId > 0) ? 'manual' : 'qr';
$registrationId = $method === 'manual' ? $manualRegistrationId : QrService::verifyToken($token);
if ($registrationId === null) {
    echo json_encode(['valid' => false, 'code' => 'invalid_qr', 'message' => 'This QR code is not recognized by EVORA.']);
    exit;
}

$permCheck = $db->prepare(
    'SELECT 1 FROM event_staff es
     JOIN staff_permissions sp ON sp.event_staff_id = es.id
     WHERE es.user_id = :uid AND es.event_id = :eid AND sp.permission_key = "scan_qr"'
);
$permCheck->execute(['uid' => $userId, 'eid' => $eventId]);
if (!$permCheck->fetch()) {
    echo json_encode(['valid' => false, 'code' => 'no_permission', 'message' => 'You do not have scanning permission for this event.']);
    exit;
}

$regStmt = $db->prepare(
    "SELECT r.*, e.id AS reg_event_id, e.name AS event_name, COALESCE(rc.fee, e.fee) AS fee, e.status AS event_status, e.event_date,
        c.slug AS category_slug, u.full_name, u.profile_picture_path
     FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN event_categories c ON c.id = e.category_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     WHERE r.id = :id"
);
$regStmt->execute(['id' => $registrationId]);
$registration = $regStmt->fetch();

if (!$registration) {
    echo json_encode(['valid' => false, 'code' => 'not_found', 'message' => 'Participant registration not found.']);
    exit;
}

// Rule: QR belongs to the correct event being scanned
if ((int) $registration['reg_event_id'] !== $eventId) {
    echo json_encode([
        'valid' => false,
        'code' => 'wrong_event',
        'participant_name' => $registration['full_name'],
        'message' => 'This participant is not registered for the selected event (their QR is for ' . $registration['event_name'] . ').',
    ]);
    exit;
}

// Rule: a QR code invalidated by EVORA (e.g. after a completed refund) is never accepted
$qrStmt = $db->prepare('SELECT invalidated_at FROM qr_codes WHERE registration_id = :rid');
$qrStmt->execute(['rid' => $registrationId]);
if ($qrStmt->fetchColumn()) {
    echo json_encode(['valid' => false, 'code' => 'invalidated', 'participant_name' => $registration['full_name'], 'event_name' => $registration['event_name'], 'message' => 'This QR code is no longer valid.']);
    exit;
}

// Rule: QR is expired once the event is over/cancelled or the event date has fully passed
$expiredStatuses = ['completed', 'cancelled', 'removed', 'archived'];
$isExpired = in_array($registration['event_status'], $expiredStatuses, true) || $registration['event_date'] < date('Y-m-d');

$paymentStatus = 'not_required';
if ((float) $registration['fee'] > 0) {
    $payStmt = $db->prepare('SELECT status FROM payments WHERE registration_id = :id');
    $payStmt->execute(['id' => $registrationId]);
    $payment = $payStmt->fetch();
    $paymentStatus = $payment['status'] ?? 'pending';
}

$attCheck = $db->prepare('SELECT id, scanned_at, staff_id FROM attendance WHERE registration_id = :rid AND event_id = :eid');
$attCheck->execute(['rid' => $registrationId, 'eid' => $eventId]);
$attendanceRow = $attCheck->fetch();
$alreadyCheckedIn = (bool) $attendanceRow;
$checkedInAt = $attendanceRow ? date('g:i A', strtotime($attendanceRow['scanned_at'])) : null;

$canConfirm = !$isExpired
    && $registration['status'] === 'approved'
    && ($paymentStatus === 'approved' || $paymentStatus === 'not_required')
    && !$alreadyCheckedIn;

$card = [
    'registration_id' => $registrationId,
    'participant_name' => $registration['full_name'],
    'participant_photo' => $registration['profile_picture_path'] ? uploadUrl($registration['profile_picture_path']) : null,
    'event_name' => $registration['event_name'],
    'registration_status' => $registration['status'],
    'payment_status' => $paymentStatus,
    'attendance_status' => $alreadyCheckedIn ? 'checked_in' : 'not_checked_in',
    'expired' => $isExpired,
    'can_confirm' => $canConfirm,
    'checked_in_at' => $checkedInAt,
    // v40: the staff member who recorded a check-in may undo it for a few minutes
    'attendance_id' => $attendanceRow ? (int) $attendanceRow['id'] : null,
    'can_undo' => $attendanceRow && (int) $attendanceRow['staff_id'] === (int) $userId && strtotime($attendanceRow['scanned_at']) >= time() - CheckInToolsService::UNDO_MINUTES * 60,
];

if ($stage === 'lookup') {
    $reasons = [];
    if ($isExpired) $reasons[] = 'this QR code has expired';
    if ($registration['status'] !== 'approved') $reasons[] = 'registration is ' . $registration['status'];
    if ($paymentStatus !== 'approved' && $paymentStatus !== 'not_required') $reasons[] = 'payment is ' . $paymentStatus;
    if ($alreadyCheckedIn) $reasons[] = 'already checked in';

    echo json_encode(array_merge($card, [
        'valid' => true,
        'code' => $canConfirm ? 'ready' : ($alreadyCheckedIn ? 'already_checked_in' : 'not_eligible'),
        'message' => $canConfirm ? 'Participant verified — ready to check in.'
            : ($alreadyCheckedIn && count($reasons) === 1 ? 'Participant already checked in at ' . $checkedInAt . '.' : ('Cannot check in: ' . implode(', ', $reasons) . '.')),
    ]));
    exit;
}

// stage === 'confirm': re-validate everything server-side before writing (never trust the lookup round-trip)
if (!$canConfirm) {
    $message = $alreadyCheckedIn
        ? $registration['full_name'] . ' has already been checked in for this event.'
        : 'This registration can no longer be checked in.';
    echo json_encode(array_merge($card, ['valid' => false, 'code' => $alreadyCheckedIn ? 'already_checked_in' : 'not_eligible', 'message' => $message]));
    exit;
}

try {
    $insert = $db->prepare('INSERT INTO attendance (registration_id, event_id, staff_id) VALUES (:rid, :eid, :sid)');
    $insert->execute(['rid' => $registrationId, 'eid' => $eventId, 'sid' => $userId]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Lost the race to a concurrent scan of the same badge — treat as already checked in.
        echo json_encode(array_merge($card, [
            'valid' => false,
            'code' => 'already_checked_in',
            'attendance_status' => 'checked_in',
            'message' => $registration['full_name'] . ' has already been checked in for this event.',
        ]));
        exit;
    }
    throw $e;
}

$newAttendanceId = (int) $db->lastInsertId();
Logger::log($userId, 'attendance_recorded', 'Checked in registration #' . $registrationId . ' for event #' . $eventId . ($method === 'manual' ? ' (manual check-in: participant found by name/bib, no QR)' : ''));
NotificationService::safely(function () use ($db, $registrationId, $registration) {
    $owner = $db->prepare('SELECT p.user_id FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE r.id = :id');
    $owner->execute(['id' => $registrationId]);
    if ($participantUserId = (int) $owner->fetchColumn()) {
        notify($participantUserId, 'Check-in Successful', 'You were checked in to "' . $registration['event_name'] . '" at ' . date('g:i A') . '. Enjoy the event!', 'check_in',
            ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'registration', 'reference_id' => $registrationId]);
    }
});

$certificateIssued = false;
if ($registration['category_slug'] === 'seminar-workshop') {
    $certificateIssued = CertificateService::issue($db, $registrationId);
    if ($certificateIssued) {
        Logger::log($userId, 'certificate_issued', 'Auto-issued certificate for registration #' . $registrationId . ' on check-in');
    }
}

echo json_encode(array_merge($card, [
    'valid' => true,
    'code' => 'checked_in',
    'attendance_status' => 'checked_in',
    'checked_in_at' => date('g:i A'),
    'certificate_issued' => $certificateIssued,
    'attendance_id' => $newAttendanceId,
    'can_undo' => true,
    'message' => 'Attendance recorded successfully.' . ($certificateIssued ? ' Certificate auto-issued.' : ''),
]));
