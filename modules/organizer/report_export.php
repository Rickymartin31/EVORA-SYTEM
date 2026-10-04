<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$type = $_GET['type'] ?? 'event_summary';
$format = $_GET['format'] ?? 'pdf';

// Same optional filters as reports.php — exported output must match what was
// previewed, so both files read the identical $_GET keys.
$statusFilter = trim($_GET['reg_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filters = array_filter([
    'status' => $statusFilter ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
]);

$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-reports');
}

$reportTitles = [
    'event_summary' => 'Event Summary',
    'participant_list' => 'Participant List',
    'payment_summary' => 'Payment Summary',
    'attendance_report' => 'Attendance Report',
    'staff_activity' => 'Staff Activity',
    'completion_report' => 'Completion Report',
];
if (!isset($reportTitles[$type])) {
    $type = 'event_summary';
}
$title = $reportTitles[$type] . ' — ' . $event['name'];

$data = ReportQueries::run($db, $type, $eventId, $filters);

$logStmt = $db->prepare('INSERT INTO reports (generated_by, report_type, scope, related_id) VALUES (:by, :type, "event", :eid)');
$logStmt->execute(['by' => $userId, 'type' => $type, 'eid' => $eventId]);
Logger::log($userId, 'report_generated', $title . ' (' . strtoupper($format) . ')');

if ($format === 'xlsx') {
    ReportExporter::excel($title, $data['columns'], $data['rows']);
} else {
    ReportExporter::pdf($title, $data['columns'], $data['rows']);
}
