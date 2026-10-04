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
$action = $_POST['action'] ?? '';

// The page the organizer was actually viewing (0 = "All Events") — separate
// from $eventId above, which is the registration's real event and must stay
// that way for the ownership check below. Redirecting with $eventId instead
// would bounce the organizer into a single-event view even when they were
// browsing "All Events".
$returnParams = ['event_id' => (int) ($_POST['return_event_id'] ?? $eventId)];
if (!empty($_POST['return_status'])) {
    $returnParams['status'] = $_POST['return_status'];
}

$stmt = $db->prepare(
    'SELECT r.*, e.name AS event_name, e.payment_provider, COALESCE(rc.fee, e.fee) AS fee, e.organizer_id, u.id AS user_id
     FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     WHERE r.id = :id AND e.id = :eid AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $registrationId, 'eid' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$registration = $stmt->fetch();

if (!$registration) {
    flash('error', 'Registration not found.');
    redirect('organizer-registrations', $returnParams);
}

if (!in_array($action, ['approve', 'reject'], true)) {
    flash('error', 'Invalid action.');
    redirect('organizer-registrations', $returnParams);
}

// A refunded (cancelled) registration is final; the database refuses it too.
if ($registration['status'] === 'cancelled') {
    flash('error', 'This registration was cancelled after a refund and cannot be changed.');
    redirect('organizer-registrations', $returnParams);
}

if ($action === 'reject') {
    // A registration paid online is in the organizer ledger; rejecting it would
    // leave collected money attached to a rejected registration. Refunds and
    // reversals go through the admin finance correction flow instead.
    $paidStmt = $db->prepare('SELECT 1 FROM payments WHERE registration_id = :id AND payment_channel IN ("paymongo", "paypal") AND status = "approved"');
    $paidStmt->execute(['id' => $registrationId]);
    if ($paidStmt->fetch()) {
        flash('error', 'This registration was already paid online and cannot be rejected. Refund the participant first (PayPal payments are refunded from your PayPal account).');
        redirect('organizer-registrations', $returnParams);
    }

    $update = $db->prepare('UPDATE registrations SET status = "rejected", reviewed_by = :by, reviewed_at = NOW() WHERE id = :id');
    $update->execute(['by' => $userId, 'id' => $registrationId]);

    Logger::log($userId, 'registration_rejected', 'Rejected registration #' . $registrationId);
    notify((int) $registration['user_id'], 'Registration Update', 'Your registration for "' . $registration['event_name'] . '" was rejected.', 'registration_status', ['reference_type' => 'event', 'reference_id' => $eventId]);

    WaitlistService::process($db, $eventId); // a freed slot goes to the next person on the waitlist (v39)
    flash('success', 'Registration rejected.');
    redirect('organizer-registrations', $returnParams);
}

// approve — never over capacity (v47): checked and saved inside the event's seat lock.
if (!EventRegistrationService::seatLock($db, $eventId)) {
    flash('error', 'Another approval or payment for this event is being saved. Please try again in a moment.');
    redirect('organizer-registrations', $returnParams);
}
if (EventRegistrationService::wouldOverfill($db, $registrationId)) {
    EventRegistrationService::seatUnlock($db, $eventId);
    flash('error', 'This event (or its category) is already full. Increase the capacity in the event settings, or reject this registration.');
    redirect('organizer-registrations', $returnParams);
}
$update = $db->prepare('UPDATE registrations SET status = "approved", reviewed_by = :by, reviewed_at = NOW() WHERE id = :id');
$update->execute(['by' => $userId, 'id' => $registrationId]);
EventRegistrationService::seatUnlock($db, $eventId);

Logger::log($userId, 'registration_approved', 'Approved registration #' . $registrationId);

if ((float) $registration['fee'] <= 0) {
    // Free event: auto-generate QR immediately
    issueQrIfMissing($db, $registrationId, $userId);
    notify((int) $registration['user_id'], 'Registration Approved', 'Your registration for "' . $registration['event_name'] . '" was approved! Your QR code is ready.', 'registration_status', ['reference_type' => 'event', 'reference_id' => $eventId]);
} elseif ($registration['payment_provider'] === 'paypal') {
    // Approving the registration never approves a PayPal payment or issues a QR: only a
    // verified PayPal capture does that (PayPalPaymentService::recordCompleted).
    $paidStmt = $db->prepare('SELECT 1 FROM payments WHERE registration_id = :id AND payment_channel = "paypal" AND status = "approved"');
    $paidStmt->execute(['id' => $registrationId]);
    notify((int) $registration['user_id'], 'Registration Approved', $paidStmt->fetch()
        ? 'Your registration for "' . $registration['event_name'] . '" was approved! Your PayPal payment is confirmed and your QR code is ready.'
        : 'Your registration for "' . $registration['event_name'] . '" was approved! Please complete your payment through PayPal to receive your QR code.',
        'registration_status', ['reference_type' => 'event', 'reference_id' => $eventId]);
} else {
    notify((int) $registration['user_id'], 'Registration Approved', 'Your registration for "' . $registration['event_name'] . '" was approved! Please submit your payment proof to receive your QR code.', 'registration_status', ['reference_type' => 'event', 'reference_id' => $eventId]);
}

flash('success', 'Registration approved.');
redirect('organizer-registrations', $returnParams);
