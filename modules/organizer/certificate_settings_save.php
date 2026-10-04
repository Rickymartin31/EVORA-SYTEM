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
$eventStmt = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
if (!$eventStmt->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$certificateTitle = trim((string) ($_POST['certificate_title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$venueOverride = trim((string) ($_POST['venue_override'] ?? ''));
$orgNameOverride = trim((string) ($_POST['organization_name_override'] ?? ''));
$organizerNameOverride = trim((string) ($_POST['organizer_name_override'] ?? ''));

$existingStmt = $db->prepare('SELECT * FROM event_certificate_settings WHERE event_id = :eid');
$existingStmt->execute(['eid' => $eventId]);
$existing = $existingStmt->fetch();

// Uploads are optional on every save — keep the previously stored file
// unless a new one is provided.
$logoPath = $existing['logo_path'] ?? null;
if (!empty($_FILES['logo']['name'])) {
    $uploaded = Upload::handle($_FILES['logo'], 'certificate_assets', UPLOAD_ALLOWED_IMAGE);
    if ($uploaded) {
        $logoPath = $uploaded;
    }
}

$signatoryPaths = ['signatory1_signature_path' => $existing['signatory1_signature_path'] ?? null, 'signatory2_signature_path' => $existing['signatory2_signature_path'] ?? null];
foreach ([1, 2] as $n) {
    $field = 'signatory' . $n . '_signature';
    if (!empty($_FILES[$field]['name'])) {
        $uploaded = Upload::handle($_FILES[$field], 'certificate_assets', UPLOAD_ALLOWED_IMAGE);
        if ($uploaded) {
            $signatoryPaths['signatory' . $n . '_signature_path'] = $uploaded;
        }
    }
}

$signatory1Name = trim((string) ($_POST['signatory1_name'] ?? ''));
$signatory1Title = trim((string) ($_POST['signatory1_title'] ?? ''));
$signatory2Name = trim((string) ($_POST['signatory2_name'] ?? ''));
$signatory2Title = trim((string) ($_POST['signatory2_title'] ?? ''));

$autoIssueEnabled = isset($_POST['auto_issue_enabled']) ? 1 : 0;
$attendanceRequired = isset($_POST['attendance_required']) ? 1 : 0;

$params = [
    'eid' => $eventId,
    'title' => $certificateTitle ?: null,
    'desc' => $description ?: null,
    'venue' => $venueOverride ?: null,
    'org' => $orgNameOverride ?: null,
    'organizer_name' => $organizerNameOverride ?: null,
    'logo' => $logoPath,
    'sig1_name' => $signatory1Name ?: null,
    'sig1_title' => $signatory1Title ?: null,
    'sig1_path' => $signatoryPaths['signatory1_signature_path'],
    'sig2_name' => $signatory2Name ?: null,
    'sig2_title' => $signatory2Title ?: null,
    'sig2_path' => $signatoryPaths['signatory2_signature_path'],
    'auto_issue' => $autoIssueEnabled,
    'attendance_required' => $attendanceRequired,
    'updated_by' => $userId,
];

if ($existing) {
    $stmt = $db->prepare(
        'UPDATE event_certificate_settings SET
            certificate_title = :title, description = :desc, venue_override = :venue,
            organization_name_override = :org, organizer_name_override = :organizer_name, logo_path = :logo,
            signatory1_name = :sig1_name, signatory1_title = :sig1_title, signatory1_signature_path = :sig1_path,
            signatory2_name = :sig2_name, signatory2_title = :sig2_title, signatory2_signature_path = :sig2_path,
            auto_issue_enabled = :auto_issue, attendance_required = :attendance_required,
            updated_by = :updated_by
         WHERE event_id = :eid'
    );
} else {
    $stmt = $db->prepare(
        'INSERT INTO event_certificate_settings
            (event_id, certificate_title, description, venue_override, organization_name_override, organizer_name_override, logo_path,
             signatory1_name, signatory1_title, signatory1_signature_path, signatory2_name, signatory2_title, signatory2_signature_path,
             auto_issue_enabled, attendance_required, updated_by)
         VALUES
            (:eid, :title, :desc, :venue, :org, :organizer_name, :logo,
             :sig1_name, :sig1_title, :sig1_path, :sig2_name, :sig2_title, :sig2_path,
             :auto_issue, :attendance_required, :updated_by)'
    );
}
$stmt->execute($params);

Logger::log($userId, 'certificate_settings_saved', 'Updated certificate settings for event #' . $eventId);
flash('success', 'Certificate settings saved.');
redirect('organizer-certificates', ['event_id' => $eventId]);
