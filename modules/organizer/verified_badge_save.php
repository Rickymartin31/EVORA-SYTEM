<?php
// Organizer: submit a Verified badge application, or replace rejected documents (v78).
Auth::requireRole('organizer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-verified-badge');
}
Csrf::requireValid();

$db = Database::connection();
$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => Auth::id()]);
$organizer = $orgStmt->fetch();
if (!$organizer) {
    redirect('organizer-dashboard');
}

$mode = $_POST['mode'] ?? '';
$errors = $mode === 'resubmit'
    ? OrganizerBadgeService::resubmit($db, $organizer, (int) Auth::id(), $_POST, $_FILES)
    : OrganizerBadgeService::submit($db, $organizer, (int) Auth::id(), $_POST, $_FILES);

if ($errors) {
    setOld(array_intersect_key($_POST, array_flip(['organization_type', 'social_link', 'past_events_note',
        'government_id_kind', 'government_id_ref', 'government_id_expires', 'organization_proof_kind', 'organization_proof_ref', 'organization_proof_expires'])));
    flash('error', implode(' • ', $errors));
    redirect('organizer-verified-badge');
}

flash('success', $mode === 'resubmit' ? 'New documents sent. An administrator will review them.' : 'Application submitted. An administrator will validate your documents.');
redirect('organizer-verified-badge');
