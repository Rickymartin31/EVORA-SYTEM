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

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$eventId = (int) ($_POST['event_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT r.id, c.slug AS category_slug FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN event_categories c ON c.id = e.category_id
     WHERE r.id = :rid AND e.id = :eid AND e.organizer_id = :oid AND r.status = "approved"'
);
$stmt->execute(['rid' => $registrationId, 'eid' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$reg = $stmt->fetch();
if (!$reg) {
    flash('error', 'Registration not found.');
    redirect('organizer-certificates', ['event_id' => $eventId]);
}

// Fun-run and seminar/workshop events may issue a certificate type other than
// the default "Certificate of Participation" — same one-certificate-per-registration
// rule still applies, this only changes the wording on that one certificate.
$certTemplatesByCategory = [
    'fun-run' => [
        'participation' => ['Certificate of Participation', 'has successfully participated in'],
        'finisher' => ['Certificate of Completion', 'has successfully completed'],
        'winner' => ['Certificate of Achievement', 'achieved a top finish in'],
    ],
    'seminar-workshop' => [
        'participation' => ['Certificate of Participation', 'has successfully participated in'],
        'attendance' => ['Certificate of Attendance', 'attended'],
        'completion' => ['Certificate of Completion', 'has successfully completed'],
    ],
];
$certTemplates = $certTemplatesByCategory[$reg['category_slug']] ?? ['participation' => ['Certificate of Participation', 'has successfully participated in']];
$certType = $_POST['cert_type'] ?? 'participation';
if (!isset($certTemplates[$certType])) {
    $certType = 'participation';
}
[$certTitle, $certVerb] = $certTemplates[$certType];

if (CertificateService::issue($db, $registrationId, $certTitle, $certVerb, $userId)) {
    Logger::log($userId, 'certificate_issued', 'Issued certificate for registration #' . $registrationId);
    flash('success', 'Certificate issued.');
} else {
    flash('error', 'A certificate has already been issued for this participant.');
}

redirect('organizer-certificates', ['event_id' => $eventId]);
