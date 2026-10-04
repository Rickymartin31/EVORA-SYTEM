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
$taskStatus = $_POST['task_status'] ?? '';
$assignmentArea = trim($_POST['assignment_area'] ?? '');
$taskNote = trim($_POST['task_note'] ?? '');

$allowedStatuses = ['not_started', 'in_progress', 'completed'];
if (!in_array($taskStatus, $allowedStatuses, true)) {
    flash('error', 'Invalid task status.');
    redirect('organizer-staff', ['event_id' => $eventId]);
}

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

$update = $db->prepare(
    'UPDATE event_staff SET task_status = :status, assignment_area = :area, task_note = :note WHERE id = :id'
);
$update->execute([
    'status' => $taskStatus, 'area' => $assignmentArea ?: null, 'note' => $taskNote ?: null, 'id' => $eventStaffId,
]);

if ((string) $staffRow['assignment_area'] !== (string) ($assignmentArea ?: '') || (string) $staffRow['task_note'] !== (string) ($taskNote ?: '')) {
    NotificationService::safely(function () use ($db, $staffRow, $assignmentArea, $taskNote) {
        $evName = $db->prepare('SELECT name FROM events WHERE id = :id'); $evName->execute(['id' => (int) $staffRow['event_id']]); $evName = (string) $evName->fetchColumn();
        notify((int) $staffRow['user_id'], 'Assignment Updated', 'Your assignment for "' . $evName . '" was updated' . ($assignmentArea ? ' — area: ' . $assignmentArea : '') . ($taskNote ? ' — note: ' . $taskNote : '') . '.', 'staff_update',
            ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => (int) $staffRow['event_id']]);
    });
}
Logger::log($userId, 'staff_task_updated', 'Updated task for staff assignment #' . $eventStaffId . ' on event #' . $eventId);
flash('success', 'Staff task updated.');
redirect('organizer-staff', ['event_id' => $eventId]);
