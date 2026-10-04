<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$format = $_GET['format'] ?? 'pdf';

// Ownership check — event_id is never trusted on its own, must belong to the
// logged-in organizer. No admin/staff route exists for this file at all.
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-payment-dashboard');
}

$sections = ReportQueries::eventFinancialReportSections($db, $eventId);

Logger::log($userId, 'financial_report_exported', 'Exported financial report for event #' . $eventId . ' (' . $event['name'] . ') as ' . strtoupper($format));

if ($format === 'xlsx') {
    ReportExporter::sectionedExcel('Event Financial Report — ' . $event['name'], $event['name'], $sections);
} else {
    ReportExporter::sectionedPdf('Event Financial Report — ' . $event['name'], $event['name'], $sections);
}
