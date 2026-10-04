<?php
// Staff check-in tools (v40), JSON:
//   staff-checkin-search (GET event_id, q)   — find a participant without their QR
//   staff-checkin-stats  (GET event_id)      — live counter, not-yet-arrived list, my undoable check-ins
//   staff-checkin-undo   (POST attendance_id, reason, csrf_token)
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!Auth::check() || Auth::role() !== 'staff') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit;
}
$db = Database::connection();
$staffId = (int) Auth::id();
$route = $_GET['route'] ?? '';

if ($route === 'staff-checkin-undo') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? null)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }
    try {
        $name = CheckInToolsService::undo($db, (int) ($_POST['attendance_id'] ?? 0), $staffId, (string) ($_POST['reason'] ?? ''));
        echo json_encode(['ok' => true, 'message' => 'Check-in of ' . $name . ' was undone. They can be checked in again.']);
    } catch (DomainException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$eventId = (int) ($_GET['event_id'] ?? 0);
if (!CheckInToolsService::canScan($db, $staffId, $eventId)) {
    echo json_encode(['ok' => false, 'message' => 'You do not have scanning permission for this event.']);
    exit;
}
if ($route === 'staff-checkin-search') {
    echo json_encode(['ok' => true, 'results' => CheckInToolsService::search($db, $eventId, (string) ($_GET['q'] ?? ''))]);
    exit;
}
echo json_encode(['ok' => true] + CheckInToolsService::stats($db, $eventId, $staffId));
