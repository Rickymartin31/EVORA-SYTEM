<?php
Auth::requireRole('participant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$eventId = (int) ($_POST['event_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT e.*, c.slug AS category_slug FROM events e JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.status = 'open' AND e.event_date >= CURDATE()" // a past event is never open for registration
);
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'This event is not open for registration.');
    redirect('events');
}

$pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$pStmt->execute(['uid' => $userId]);
$participant = $pStmt->fetch();

// A cancelled registration does not count: the participant may register again (v47).
$existing = $db->prepare('SELECT id FROM registrations WHERE event_id = :eid AND participant_id = :pid AND status <> "cancelled"');
$existing->execute(['eid' => $eventId, 'pid' => $participant['id']]);
if ($existing->fetch()) {
    flash('error', 'You have already registered for this event.');
    redirect('event-view', ['id' => $eventId]);
}

// PayPal switched off: refuse before anything is saved (no registration, no payment row).
if (($event['payment_provider'] ?? null) === 'paypal' && !PAYPAL_ENABLED) {
    flash('error', 'Online payment for this event is paused right now, so new registrations are not accepted. Please try again later or contact the organizer.');
    redirect('event-view', ['id' => $eventId]);
}
// PayPal is the active provider: a legacy (PayMongo) paid event takes no new online payments
// (same "requires payment" rule as the registration page).
if (($event['category_slug'] === 'fun-run' || (float) $event['fee'] > 0) && PayPalPaymentService::legacyOnlinePaymentPaused($event['payment_provider'] ?? null)) {
    flash('error', PayPalPaymentService::LEGACY_PAUSED_MESSAGE);
    redirect('event-view', ['id' => $eventId]);
}

if (!empty($event['registration_deadline']) && $event['registration_deadline'] < date('Y-m-d')) {
    flash('error', 'The registration deadline for this event has passed.');
    redirect('event-view', ['id' => $eventId]);
}

// Full = approved registrations + slots held for someone else on the waitlist (v39).
if (WaitlistService::isFull($db, $event, null, (int) $participant['id'])) {
    flash('error', 'This event has reached maximum capacity. You can join the waitlist on the event page.');
    redirect('event-view', ['id' => $eventId]);
}

$distanceCategory = null;
$raceCategoryId = null;
if ($event['category_slug'] === 'fun-run') {
    $raceCategoryId = (int) ($_POST['race_category_id'] ?? 0);
    $catStmt = $db->prepare(
        "SELECT rc.*, (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved_count
         FROM race_categories rc WHERE rc.id = :id AND rc.event_id = :eid"
    );
    $catStmt->execute(['id' => $raceCategoryId, 'eid' => $eventId]);
    $raceCategory = $catStmt->fetch();

    if (!$raceCategory) {
        flash('error', 'Please select a valid race category.');
        redirect('event-register', ['id' => $eventId]);
    }
    if (WaitlistService::isFull($db, $event, $raceCategoryId, (int) $participant['id'])) {
        flash('error', 'That race category is already full. Please choose another, or join the waitlist on the event page.');
        redirect('event-register', ['id' => $eventId]);
    }
    $distanceCategory = $raceCategory['name'];
}

if ($event['rules'] && empty($_POST['agree_rules'])) {
    flash('error', 'You must agree to the event rules to register.');
    redirect('event-register', ['id' => $eventId]);
}

$requirementsPath = null;
if (!empty($_FILES['requirements_file']['name'])) {
    $requirementsPath = Upload::handle($_FILES['requirements_file'], 'docs', UPLOAD_ALLOWED_DOC);
    if (!$requirementsPath) {
        flash('error', 'Requirements file must be JPG, PNG, or PDF under 5MB.');
        redirect('event-register', ['id' => $eventId]);
    }
}

$organization = null;
$profession = null;
$dietaryPreference = null;
$specialRequirements = null;
if ($event['category_slug'] === 'seminar-workshop') {
    $organization = trim($_POST['organization'] ?? '') ?: null;
    $profession = trim($_POST['profession'] ?? '') ?: null;
    $dietaryPreference = trim($_POST['dietary_preference'] ?? '') ?: null;
    $specialRequirements = trim($_POST['special_requirements'] ?? '') ?: null;
}

$stmt = $db->prepare(
    'INSERT INTO registrations (event_id, participant_id, distance_category, race_category_id, requirements_upload_path, organization, profession, dietary_preference, special_requirements)
     VALUES (:eid, :pid, :dist, :cat, :path, :org, :prof, :diet, :special)'
);
$stmt->execute([
    'eid' => $eventId, 'pid' => $participant['id'], 'dist' => $distanceCategory, 'cat' => $raceCategoryId, 'path' => $requirementsPath,
    'org' => $organization, 'prof' => $profession, 'diet' => $dietaryPreference, 'special' => $specialRequirements,
]);
$registrationId = (int) $db->lastInsertId();
WaitlistService::markRegistered($db, $eventId, (int) $participant['id']);

Logger::log($userId, 'registration_submitted', 'Registered for event #' . $eventId);

$organizerUserStmt = $db->prepare('SELECT o.user_id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id');
$organizerUserStmt->execute(['id' => $eventId]);
$organizerUser = $organizerUserStmt->fetch();
if ($organizerUser) {
    notify((int) $organizerUser['user_id'], 'New Registration', 'A new participant registered for "' . $event['name'] . '".', 'new_registration',
        ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => $registrationId]);
}

$fee = $raceCategoryId ? (float) $raceCategory['fee'] : (float) $event['fee'];

// Participant receipt of the registration, and a one-time "almost full" warning to the organizer.
NotificationService::safely(fn () => notify($userId, 'Registration Submitted',
    $fee > 0
        ? 'Your registration for "' . $event['name'] . '" was received. Complete the online payment to confirm your slot.'
        : 'Your registration for "' . $event['name'] . '" was received and is waiting for the organizer\'s approval.',
    'registration_status', ['category' => 'event', 'notice_type' => $fee > 0 ? 'action_required' : 'status', 'reference_type' => 'registration', 'reference_id' => $registrationId]));
if ($organizerUser && (int) $event['max_participants'] > 0) {
    NotificationService::safely(function () use ($db, $event, $eventId, $organizerUser) {
        $count = $db->prepare('SELECT COUNT(*) FROM registrations WHERE event_id = :id AND status IN ("pending", "approved")');
        $count->execute(['id' => $eventId]);
        $taken = (int) $count->fetchColumn();
        if ($taken >= (int) ceil((int) $event['max_participants'] * 0.9)) {
            NotificationService::once($db, (int) $organizerUser['user_id'], 'Registration Almost Full',
                '"' . $event['name'] . '" has ' . $taken . ' of ' . (int) $event['max_participants'] . ' slots taken (90% or more).', 'capacity_warning',
                ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]);
        }
    });
}

if ($fee <= 0) {
    flash('success', 'Registration submitted! Await organizer approval.');
    redirect('event-view', ['id' => $eventId]);
}

flash('success', 'Registration submitted! Please complete your payment to confirm your slot.');
redirect('paypal-checkout', ['registration_id' => $registrationId]);
