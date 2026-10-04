<?php
Auth::requireRole('admin');
$db = Database::connection();
$userId = Auth::id();

$type = $_GET['type'] ?? 'platform_statistics';
$format = $_GET['format'] ?? 'pdf';

// Same optional filters as reports.php — exported output must match what was
// previewed, so both files read the identical $_GET keys.
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$dateFilters = ($dateFrom !== '' && $dateTo !== '') ? ['date_from' => $dateFrom, 'date_to' => $dateTo] : [];

$reportTitles = [
    'platform_statistics' => 'Platform Statistics',
    'organizer_statistics' => 'Organizer Statistics',
    'event_statistics' => 'Event Statistics',
    'certificate_activity' => 'Certificate Activity',
    'payment_summary' => 'Payment Summary',
];
$title = $reportTitles[$type] ?? 'Report';

$data = match ($type) {
    'organizer_statistics' => ReportQueries::organizerStatistics($db, $dateFilters),
    'event_statistics' => ReportQueries::eventStatistics($db, array_filter([
        'category_id' => $categoryId ?: null,
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'status' => $statusFilter ?: null,
    ])),
    'certificate_activity' => ReportQueries::certificateActivity($db),
    'payment_summary' => ReportQueries::paymentProviderSummary($db, $dateFilters),
    default => ReportQueries::platformStatistics($db, $dateFilters),
};

$logStmt = $db->prepare('INSERT INTO reports (generated_by, report_type, scope) VALUES (:by, :type, "platform")');
$logStmt->execute(['by' => $userId, 'type' => $type]);
Logger::log($userId, 'report_generated', $title . ' (' . strtoupper($format) . ')');

if ($format === 'xlsx') {
    ReportExporter::excel($title, $data['columns'], $data['rows']);
} else {
    ReportExporter::pdf($title, $data['columns'], $data['rows']);
}
