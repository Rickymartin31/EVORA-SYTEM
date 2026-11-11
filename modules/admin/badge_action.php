<?php
// Admin: Verified badge actions (v78) — validate a document, decide on an application, revoke a badge.
Auth::requireRole('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-badges');
}
Csrf::requireValid();

$db = Database::connection();
$adminId = (int) Auth::id();
$applicationId = (int) ($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';

$error = match ($action) {
    'review_document' => OrganizerBadgeService::reviewDocument($db, (int) ($_POST['document_id'] ?? 0), $adminId,
        (array) ($_POST['checks'] ?? []), (string) ($_POST['decision'] ?? ''), (string) ($_POST['reason'] ?? '')),
    'approve', 'request_resubmission', 'reject' => OrganizerBadgeService::decide($db, $applicationId, $adminId, $action, (string) ($_POST['remarks'] ?? '')),
    'revoke' => OrganizerBadgeService::revoke($db, (int) ($_POST['organizer_id'] ?? 0), $adminId, (string) ($_POST['reason'] ?? '')),
    default => 'Invalid action.',
};

if ($error) {
    flash('error', $error);
} else {
    flash('success', match ($action) {
        'review_document' => 'Document ' . (($_POST['decision'] ?? '') === 'accepted' ? 'accepted.' : 'rejected.'),
        'approve' => 'Verified badge approved. The organizer was notified.',
        'request_resubmission' => 'The organizer was asked to replace the rejected documents.',
        'reject' => 'Application rejected. The organizer was notified.',
        'revoke' => 'Badge revoked.',
    });
}
redirect('admin-badge-review', ['id' => $applicationId]);
