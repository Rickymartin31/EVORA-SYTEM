<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_POST['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.id, c.slug AS category_slug FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

// Same default title/verb per category as the manual "Issue Certificate"
// dropdown's "Participation" option — bulk generation always uses the
// participation-level wording; a straggler needing a different wording
// (e.g. Winner) is still issued individually from the table below.
$defaultTitlesByCategory = [
    'fun-run' => ['Certificate of Participation', 'has successfully participated in'],
    'seminar-workshop' => ['Certificate of Participation', 'has successfully participated in'],
];
[$defaultTitle, $defaultVerb] = $defaultTitlesByCategory[$event['category_slug']] ?? ['Certificate of Participation', 'has successfully participated in'];

$settingsStmt = $db->prepare('SELECT certificate_title FROM event_certificate_settings WHERE event_id = :eid');
$settingsStmt->execute(['eid' => $eventId]);
$settings = $settingsStmt->fetch();
$title = (!empty($settings['certificate_title'])) ? $settings['certificate_title'] : $defaultTitle;

// Eligibility unchanged from the existing manual flow: approved registrations
// with no certificate yet. Attendance stays informational, not a gate.
$eligibleStmt = $db->prepare(
    'SELECT r.id FROM registrations r
     LEFT JOIN certificates c ON c.registration_id = r.id
     WHERE r.event_id = :eid AND r.status = "approved" AND c.id IS NULL'
);
$eligibleStmt->execute(['eid' => $eventId]);
$eligible = $eligibleStmt->fetchAll();

$issuedCount = 0;
foreach ($eligible as $row) {
    if (CertificateService::issue($db, (int) $row['id'], $title, $defaultVerb, $userId)) {
        $issuedCount++;
    }
}

Logger::log($userId, 'certificates_bulk_generated', 'Bulk-generated ' . $issuedCount . ' certificate(s) for event #' . $eventId);
flash('success', $issuedCount > 0 ? "Generated $issuedCount certificate(s)." : 'Everyone already has a certificate.');
redirect('organizer-certificates', ['event_id' => $eventId]);
