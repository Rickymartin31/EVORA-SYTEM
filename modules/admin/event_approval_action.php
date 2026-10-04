<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-event-approvals');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$eventId = (int) ($_POST['event_id'] ?? 0);
$action = $_POST['action'] ?? '';
$remarks = trim($_POST['remarks'] ?? '');

$stmt = $db->prepare(
    'SELECT e.*, o.user_id AS organizer_user_id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id'
);
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event || $event['status'] !== 'pending_approval') {
    flash('error', 'Event not found or not awaiting approval.');
    redirect('admin-event-approvals');
}

if ($action === 'approve') {
    EventStatusService::changeStatus($db, $eventId, 'approved', $userId);
    Logger::log($userId, 'event_approved', 'Approved event #' . $eventId . ' (' . $event['name'] . ')');
    notify((int) $event['organizer_user_id'], 'Event Approved', '"' . $event['name'] . '" has been approved. You can now open registration.', 'event_approved', ['reference_type' => 'event', 'reference_id' => $eventId]);
    NotificationService::toOtherAdmins($db, (int) $userId, 'Event Approved', '"' . $event['name'] . '" was approved by another administrator.', 'event_approved', ['notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]);
    flash('success', 'Event approved.');
} elseif ($action === 'reject') {
    EventStatusService::changeStatus($db, $eventId, 'draft', $userId, $remarks ?: 'No reason provided.');
    Logger::log($userId, 'event_rejected', 'Rejected event #' . $eventId . ' (' . $event['name'] . ')');
    notify((int) $event['organizer_user_id'], 'Event Rejected', '"' . $event['name'] . '" was sent back to draft.' . ($remarks ? ' Reason: ' . $remarks : ''), 'event_rejected', ['reference_type' => 'event', 'reference_id' => $eventId]);
    NotificationService::toOtherAdmins($db, (int) $userId, 'Event Returned for Revision', '"' . $event['name'] . '" was sent back to draft for changes.' . ($remarks ? ' Reason: ' . $remarks : ''), 'event_rejected', ['notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]);
    flash('success', 'Event rejected and returned to draft.');
} else {
    flash('error', 'Invalid action.');
}

redirect('admin-event-approvals');
