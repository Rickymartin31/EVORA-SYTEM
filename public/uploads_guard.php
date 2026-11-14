<?php
/**
 * Serves uploads. Published event banners, speaker photos and the system logo are public;
 * private uploads (valid IDs, supporting docs, payment proofs, QR images) are served
 * only to authorized viewers: the admin, the organizer who owns the related
 * event/verification, the participant who owns the file, or event staff (QR only).
 */

require_once __DIR__ . '/../config/config.php';
require_once EVORA_ROOT . '/core/SecurityHeaders.php';
SecurityHeaders::send();
require_once EVORA_ROOT . '/core/Database.php';
require_once EVORA_ROOT . '/core/Auth.php';

Auth::start();

$rel = $_GET['f'] ?? '';
$rel = str_replace(['..', "\0"], '', $rel);

// Public event content (event banners, speaker photos, the system logo) is shown on
// pages guests can open (Browse Events / Event details), so it is served without a
// login — but only when the exact file belongs to a published event (or is the current
// system logo). Every other upload still requires a login and the checks below.
const PUBLIC_EVENT_STATUSES = ['approved', 'open', 'closed', 'ongoing', 'completed'];
$publicContent = false;
if ($rel !== '' && preg_match('#^(banners|speakers|logo|reviews)/[a-z0-9_.-]+$#i', $rel)) {
    $statuses = "'" . implode("','", PUBLIC_EVENT_STATUSES) . "'";
    $publicStmt = Database::connection()->prepare(match (strtok($rel, '/')) {
        'banners' => "SELECT 1 FROM events WHERE banner_path = :p AND status IN ($statuses)",
        'speakers' => "SELECT 1 FROM event_speakers sp JOIN events e ON e.id = sp.event_id WHERE sp.photo_path = :p AND e.status IN ($statuses)",
        'logo' => 'SELECT 1 FROM system_settings WHERE logo_path = :p',
        // v81: photos attached to a public post-event review
        'reviews' => "SELECT 1 FROM event_feedback_photos ph JOIN event_feedback f ON f.id = ph.feedback_id JOIN events e ON e.id = f.event_id WHERE ph.photo_path = :p AND e.status IN ($statuses, 'archived')",
    });
    $publicStmt->execute(['p' => $rel]);
    $publicContent = (bool) $publicStmt->fetchColumn();
}

if (!$publicContent) {
    Auth::requireLogin();
}

if ($rel === '' || !preg_match('#^[a-z0-9_/.-]+$#i', $rel)) {
    http_response_code(400);
    exit('Invalid file reference.');
}

$fullPath = realpath(STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
$storageRoot = realpath(STORAGE_PATH);

if ($fullPath === false || $storageRoot === false || strpos($fullPath, $storageRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('File not found.');
}

$db = Database::connection();
$role = Auth::role();
$userId = Auth::id();
$authorized = false;

if ($role === 'admin' || $publicContent) {
    $authorized = true;
} elseif ($role === 'organizer' && (str_starts_with($rel, 'banners/') || str_starts_with($rel, 'speakers/'))) {
    // Unpublished event (draft, pending approval, cancelled, …): only the organizer who owns it.
    $stmt = $db->prepare(str_starts_with($rel, 'banners/')
        ? 'SELECT o.user_id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.banner_path = :p'
        : 'SELECT o.user_id FROM event_speakers sp JOIN events e ON e.id = sp.event_id JOIN organizers o ON o.id = e.organizer_id WHERE sp.photo_path = :p');
    $stmt->execute(['p' => $rel]);
    $authorized = in_array($userId, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
} elseif (str_starts_with($rel, 'ids/') || str_starts_with($rel, 'docs/')) {
    $stmt = $db->prepare(
        'SELECT o.user_id FROM organizer_verifications v
         JOIN organizers o ON o.id = v.organizer_id
         WHERE v.valid_id_path = :p_id OR v.supporting_docs_path = :p_doc'
    );
    // Native prepares do not allow one named placeholder twice, so each column gets its own.
    $stmt->execute(['p_id' => $rel, 'p_doc' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && (int) $row['user_id'] === $userId;
} elseif (str_starts_with($rel, 'verification/')) {
    // Verified badge documents (v78): the admin (handled above) and the organizer who submitted them.
    $stmt = $db->prepare('SELECT o.user_id FROM organizer_badge_documents d JOIN organizer_badge_applications a ON a.id = d.application_id
                          JOIN organizers o ON o.id = a.organizer_id WHERE d.file_path = :p LIMIT 1');
    $stmt->execute(['p' => $rel]);
    $authorized = (int) $stmt->fetchColumn() === $userId;
} elseif (str_starts_with($rel, 'payments/')) {
    $stmt = $db->prepare(
        'SELECT p.user_id AS participant_user_id, o.user_id AS organizer_user_id
         FROM payments pay
         JOIN registrations r ON r.id = pay.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN events e ON e.id = r.event_id
         JOIN organizers o ON o.id = e.organizer_id
         WHERE pay.proof_path = :p'
    );
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && ((int) $row['participant_user_id'] === $userId || (int) $row['organizer_user_id'] === $userId);
} elseif (str_starts_with($rel, 'qr/')) {
    $stmt = $db->prepare(
        'SELECT p.user_id AS participant_user_id, o.user_id AS organizer_user_id, r.event_id
         FROM qr_codes q
         JOIN registrations r ON r.id = q.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN events e ON e.id = r.event_id
         JOIN organizers o ON o.id = e.organizer_id
         WHERE q.qr_image_path = :p'
    );
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    if ($row) {
        if ((int) $row['participant_user_id'] === $userId || (int) $row['organizer_user_id'] === $userId) {
            $authorized = true;
        } elseif ($role === 'staff') {
            $stmt2 = $db->prepare('SELECT 1 FROM event_staff WHERE event_id = :eid AND user_id = :uid');
            $stmt2->execute(['eid' => $row['event_id'], 'uid' => $userId]);
            $authorized = (bool) $stmt2->fetch();
        }
    }
} elseif (str_starts_with($rel, 'certificates/')) {
    $stmt = $db->prepare(
        'SELECT p.user_id AS participant_user_id, o.user_id AS organizer_user_id
         FROM certificates c
         JOIN registrations r ON r.id = c.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN events e ON e.id = r.event_id
         JOIN organizers o ON o.id = e.organizer_id
         WHERE c.certificate_path = :p'
    );
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && ((int) $row['participant_user_id'] === $userId || (int) $row['organizer_user_id'] === $userId);
} elseif (str_starts_with($rel, 'payouts/')) {
    // Payout transfer proof: admin always (handled above). The payout's own organizer may see it
    // once a problem was reported for that paid payout (payout dispute), so they can compare it
    // with their own GCash/Maya/bank records. Each organizer view is logged.
    $stmt = $db->prepare(
        'SELECT o.user_id, pr.request_no FROM organizer_payout_requests pr JOIN organizers o ON o.id = pr.organizer_id
         WHERE pr.proof_path = :p AND pr.status = "paid"
           AND EXISTS (SELECT 1 FROM payout_disputes d WHERE d.payout_request_id = pr.id AND d.type = "not_received_after_paid")'
    );
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && $role === 'organizer' && (int) $row['user_id'] === $userId;
    if ($authorized) {
        require_once EVORA_ROOT . '/core/Logger.php';
        Logger::log($userId, 'payout_proof_viewed', 'Viewed the transfer proof of payout ' . $row['request_no'] . ' (payout dispute on record)');
    }
} elseif (str_starts_with($rel, 'payout_disputes/')) {
    // Dispute attachments: admin (above) or the organizer of that dispute.
    $stmt = $db->prepare('SELECT o.user_id FROM payout_dispute_events ev JOIN payout_disputes d ON d.id = ev.dispute_id JOIN organizers o ON o.id = d.organizer_id WHERE ev.attachment_path = :p');
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && $role === 'organizer' && (int) $row['user_id'] === $userId;
} elseif (str_starts_with($rel, 'refund_proofs/')) {
    // PayPal refund screenshots (v55): the organizer who recorded it and the participant who was refunded.
    $stmt = $db->prepare('SELECT o.user_id AS organizer_user, p.user_id AS participant_user FROM paypal_refund_records rr
        JOIN organizers o ON o.id = rr.organizer_id JOIN registrations r ON r.id = rr.registration_id JOIN participants p ON p.id = r.participant_id WHERE rr.proof_path = :p');
    $stmt->execute(['p' => $rel]);
    $row = $stmt->fetch();
    $authorized = $row && (($role === 'organizer' && (int) $row['organizer_user'] === $userId) || ($role === 'participant' && (int) $row['participant_user'] === $userId));
} elseif (str_starts_with($rel, 'refunds/')) {
    // Refund proof (PayMongo dashboard screenshots): admin-only (handled above).
    // Participants see the refund status and a masked reference instead.
    $authorized = false;
} elseif (str_starts_with($rel, 'avatars/') || str_starts_with($rel, 'gcash_qr/') || str_starts_with($rel, 'certificate_assets/')) {
    $authorized = true; // not sensitive, but only for signed-in users
}

if (!$authorized) {
    http_response_code(403);
    exit('You are not authorized to view this file.');
}

$mime = mime_content_type($fullPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
