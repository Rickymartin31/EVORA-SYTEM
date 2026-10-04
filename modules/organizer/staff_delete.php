<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$eventStaffId = (int) ($_POST['event_staff_id'] ?? 0);
$eventId = (int) ($_POST['event_id'] ?? 0);

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$stmt = $db->prepare(
    'SELECT es.* FROM event_staff es JOIN events e ON e.id = es.event_id
     WHERE es.id = :id AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $eventStaffId, 'oid' => $organizer['id'] ?? 0]);
$staffRow = $stmt->fetch();

if (!$staffRow) {
    flash('error', 'Staff assignment not found.');
    redirect('organizer-staff', ['event_id' => $eventId]);
}

$del = $db->prepare('DELETE FROM event_staff WHERE id = :id');
$del->execute(['id' => $eventStaffId]);

NotificationService::safely(function () use ($db, $staffRow) {
    $evName = $db->prepare('SELECT name FROM events WHERE id = :id'); $evName->execute(['id' => (int) $staffRow['event_id']]); $evName = (string) $evName->fetchColumn();
    notify((int) $staffRow['user_id'], 'Removed From Event', 'You are no longer assigned as staff for "' . $evName . '".', 'staff_assignment',
        ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => (int) $staffRow['event_id']]);
});
Logger::log($userId, 'staff_removed', 'Removed staff assignment #' . $eventStaffId . ' from event #' . $eventId);
flash('success', 'Staff member removed from event.');
redirect('organizer-staff', ['event_id' => $eventId]);
