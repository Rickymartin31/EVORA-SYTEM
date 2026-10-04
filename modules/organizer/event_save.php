<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

if (!$organizer) {
    flash('error', 'Your organizer account must be approved before creating events.');
    redirect('organizer-dashboard');
}

$eventId = (int) ($_POST['event_id'] ?? 0);

// Fetch the current row (and lock status) up front for edits — needed before
// we derive category slug / fun-run flags below, since locked fields must be
// overridden with their current DB values before any of that logic runs.
$currentEvent = null;
$hasRegistrations = false;
if ($eventId) {
    $currentEventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $currentEventStmt->execute(['id' => $eventId, 'oid' => $organizer['id']]);
    $currentEvent = $currentEventStmt->fetch();
    if (!$currentEvent) {
        flash('error', 'Event not found.');
        redirect('organizer-events');
    }
    $regCountStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id');
    $regCountStmt->execute(['id' => $eventId]);
    $hasRegistrations = (int) $regCountStmt->fetch()['c'] > 0;
}

$name = trim($_POST['name'] ?? '');
$categoryId = (int) ($_POST['category_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$eventDate = $_POST['event_date'] ?? '';
$eventTime = $_POST['event_time'] ?? '';
$registrationDeadline = $_POST['registration_deadline'] ?? '';
$venue = trim($_POST['venue'] ?? '');
$fee = (float) ($_POST['fee'] ?? 0);
// Payment type is an explicit organizer choice at event setup, applied the
// same way regardless of event category — Free forces every fee on this
// event (and, for Fun Run, every race category) to 0, skipping PayMongo
// entirely for that registration; Paid keeps whatever fee(s) were entered.
$paymentType = ($_POST['payment_type'] ?? 'paid') === 'free' ? 'free' : 'paid';
if ($paymentType === 'free') {
    $fee = 0;
}
$maxParticipants = (int) ($_POST['max_participants'] ?? 0);
$requirements = trim($_POST['requirements'] ?? '');
$rules = trim($_POST['rules'] ?? '');
$contactInfo = trim($_POST['contact_info'] ?? '');

// Once an event has registrations, critical fields are locked server-side —
// the edit form already disables them, but a crafted request must not be
// able to bypass that. Silently keep the current DB values regardless of
// what was posted, rather than trusting the client.
if ($hasRegistrations) {
    $categoryId = (int) $currentEvent['category_id'];
    $eventDate = $currentEvent['event_date'];
    $fee = (float) $currentEvent['fee'];
}

$catSlugStmt = $db->prepare('SELECT slug FROM event_categories WHERE id = :id');
$catSlugStmt->execute(['id' => $categoryId]);
$categorySlug = $catSlugStmt->fetch()['slug'] ?? null;
$isSportsTournament = $categorySlug === 'sports-tournament';
$isFunRun = $categorySlug === 'fun-run';

// The event-type wizard only offers these 3 categories for new events; enforce
// the same restriction here so a crafted request can't create an event in one
// of the categories that was intentionally hidden from the wizard. Existing
// events already created under another category (e.g. before this restriction)
// remain fully editable — this only blocks brand-new events.
$allowedNewCategorySlugs = ['fun-run', 'sports-tournament', 'seminar-workshop'];
if (!$eventId && !in_array($categorySlug, $allowedNewCategorySlugs, true)) {
    flash('error', 'That event type is not available for new events right now.');
    redirect('organizer-event-type');
}

$sportId = null;
$feeMode = 'individual';
$maxTeams = null;
$tournamentFormat = null;
$periodsCount = null;
$gameDurationMinutes = null;
if ($isSportsTournament) {
    $sportId = (int) ($_POST['sport_id'] ?? 0) ?: null;
    $feeMode = in_array($_POST['fee_mode'] ?? '', ['individual', 'team'], true) ? $_POST['fee_mode'] : 'individual';
    $maxTeams = (int) ($_POST['max_teams'] ?? 0) ?: null;
    $tournamentFormat = in_array($_POST['tournament_format'] ?? '', ['single_elim', 'double_elim', 'round_robin', 'league'], true)
        ? $_POST['tournament_format'] : null;
    $periodsCount = (int) ($_POST['periods_count'] ?? 0) ?: null;
    $gameDurationMinutes = (int) ($_POST['game_duration_minutes'] ?? 0) ?: null;
}

$raceCatNames = [];
if ($isFunRun) {
    $fee = 0;
    $rawNames = $_POST['race_cat_name'] ?? [];
    $rawFees = $_POST['race_cat_fee'] ?? [];
    $rawMaxes = $_POST['race_cat_max'] ?? [];
    $rawIds = $_POST['race_cat_id'] ?? [];
    $seenNames = [];
    foreach ($rawNames as $i => $rawName) {
        $catName = trim($rawName);
        if ($catName === '') {
            continue;
        }
        $raceCatNames[] = [
            'id' => (int) ($rawIds[$i] ?? 0),
            'name' => $catName,
            'fee' => $paymentType === 'free' ? 0.0 : (float) ($rawFees[$i] ?? 0),
            'max' => max(1, (int) ($rawMaxes[$i] ?? 0)),
        ];
        $seenNames[strtolower($catName)] = ($seenNames[strtolower($catName)] ?? 0) + 1;
    }
    $hasDuplicateCatNames = !empty(array_filter($seenNames, fn ($count) => $count > 1));
}

$errors = [];
if ($name === '' || $categoryId <= 0 || $eventDate === '' || $eventTime === '' || $venue === '' || $maxParticipants <= 0) {
    $errors[] = 'Please fill in all required fields.';
}
if ($fee < 0) {
    $errors[] = 'Fee cannot be negative.';
}
if ($registrationDeadline !== '' && $registrationDeadline > $eventDate) {
    $errors[] = 'Registration deadline cannot be after the event date.';
}
if ($isFunRun && empty($raceCatNames)) {
    $errors[] = 'Please add at least one race category with its fee and slot cap.';
}
if ($isFunRun && !empty($raceCatNames) && $hasDuplicateCatNames) {
    $errors[] = 'Race category names must be unique (e.g. only one "5KM").';
}
// A new paid event takes PayPal, paid directly to the organizer's confirmed PayPal Business
// account (PayMongo is legacy only; no PayMongo setup, payout account or payout agreement needed).
$usesPaidRegistration = $paymentType === 'paid' || $fee > 0 || (isset($raceCatRows) && array_filter($raceCatRows, fn ($row) => (float) $row['fee'] > 0));
$wasAlreadyPaid = false;
if ($eventId) {
    $paidCheck = $db->prepare('SELECT (e.fee > 0 OR EXISTS (SELECT 1 FROM race_categories rc WHERE rc.event_id = e.id AND rc.fee > 0)) FROM events e WHERE e.id = :id AND e.organizer_id = :oid');
    $paidCheck->execute(['id' => $eventId, 'oid' => $organizer['id']]);
    $wasAlreadyPaid = (bool) $paidCheck->fetchColumn();
}
if ($usesPaidRegistration && !$wasAlreadyPaid) {
    if (!PAYPAL_ENABLED) {
        $errors[] = 'This event cannot be set to Paid Event — online payment (PayPal) is temporarily unavailable. Please set it to Free Event for now, or try again later.';
    } elseif (!PayPalAccountService::current($db, (int) $organizer['id'])) {
        $errors[] = 'Paid events need your PayPal Business account — connect it on your PayPal Account page (Finance → PayPal Account). Please set it to Free Event for now.';
    }
}

if (!empty($errors)) {
    flash('error', implode(' ', $errors));
    redirect($eventId ? 'organizer-event-form' : 'organizer-event-form', $eventId ? ['id' => $eventId] : []);
}

function syncRaceCategories(PDO $db, int $eventId, array $raceCatRows, bool $hasRegistrations): void
{
    $currentFees = [];
    if ($hasRegistrations) {
        // Once the event has registrations, existing race categories can't be
        // removed or have their fee changed — only their fee is looked up
        // here (server-side truth) and re-applied below regardless of what
        // was posted; new categories can still be added.
        $feeStmt = $db->prepare('SELECT id, fee FROM race_categories WHERE event_id = :eid');
        $feeStmt->execute(['eid' => $eventId]);
        foreach ($feeStmt->fetchAll() as $row) {
            $currentFees[(int) $row['id']] = (float) $row['fee'];
        }
    } else {
        // Delete stale rows first so a rename can't collide with a category
        // that's being removed in this same submission (unique key is checked
        // per-statement, not deferred until the end of the batch).
        $keepIds = array_values(array_filter(array_map(fn ($row) => $row['id'], $raceCatRows), fn ($id) => $id > 0));
        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $stmt = $db->prepare("DELETE FROM race_categories WHERE event_id = ? AND id NOT IN ($placeholders)");
            $stmt->execute(array_merge([$eventId], $keepIds));
        } else {
            $db->prepare('DELETE FROM race_categories WHERE event_id = :eid')->execute(['eid' => $eventId]);
        }
    }

    foreach ($raceCatRows as $row) {
        $fee = ($hasRegistrations && $row['id'] > 0 && isset($currentFees[$row['id']])) ? $currentFees[$row['id']] : $row['fee'];

        if ($row['id'] > 0) {
            $stmt = $db->prepare('UPDATE race_categories SET name = :name, fee = :fee, max_participants = :max WHERE id = :id AND event_id = :eid');
            $stmt->execute(['name' => $row['name'], 'fee' => $fee, 'max' => $row['max'], 'id' => $row['id'], 'eid' => $eventId]);
        } else {
            $stmt = $db->prepare('INSERT INTO race_categories (event_id, name, fee, max_participants) VALUES (:eid, :name, :fee, :max)');
            $stmt->execute(['eid' => $eventId, 'name' => $row['name'], 'fee' => $fee, 'max' => $row['max']]);
        }
    }
}

$bannerPath = null;
if (!empty($_FILES['banner']['name'])) {
    $bannerPath = Upload::handle($_FILES['banner'], 'banners', UPLOAD_ALLOWED_IMAGE);
}

if ($eventId) {
    $sql = 'UPDATE events SET name=:name, category_id=:cat, sport_id=:sport, description=:desc, event_date=:date, event_time=:time,
            registration_deadline=:deadline, venue=:venue, fee=:fee, fee_mode=:fee_mode, max_participants=:max, max_teams=:max_teams,
            tournament_format=:tformat, periods_count=:periods, game_duration_minutes=:gamedur, requirements=:req, rules=:rules, contact_info=:contact';
    $params = [
        'name' => $name, 'cat' => $categoryId, 'sport' => $sportId, 'desc' => $description ?: null, 'date' => $eventDate, 'time' => $eventTime,
        'deadline' => $registrationDeadline ?: null, 'venue' => $venue, 'fee' => $fee, 'fee_mode' => $feeMode, 'max' => $maxParticipants,
        'max_teams' => $maxTeams, 'tformat' => $tournamentFormat, 'periods' => $periodsCount, 'gamedur' => $gameDurationMinutes,
        'req' => $requirements ?: null, 'rules' => $rules ?: null, 'contact' => $contactInfo ?: null, 'id' => $eventId,
    ];
    if ($bannerPath) {
        $sql .= ', banner_path=:banner';
        $params['banner'] = $bannerPath;
    }
    $sql .= ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    if ($isFunRun) {
        syncRaceCategories($db, $eventId, $raceCatNames, $hasRegistrations);
    }

    Logger::log($userId, 'event_updated', 'Updated event #' . $eventId . ' (' . $name . ')' . ($hasRegistrations ? ' [critical fields locked — event has registrations]' : ''));

    // Registered participants (and assigned staff) learn about schedule/venue changes.
    $changes = [];
    if ((string) $currentEvent['event_date'] !== (string) $eventDate) {
        $changes[] = 'date: ' . date('M d, Y', strtotime($eventDate));
    }
    if (substr((string) $currentEvent['event_time'], 0, 5) !== substr((string) $eventTime, 0, 5) && $eventTime !== '') {
        $changes[] = 'time: ' . date('g:i A', strtotime($eventTime));
    }
    if (trim((string) $currentEvent['venue']) !== $venue) {
        $changes[] = 'venue: ' . $venue;
    }
    if ($changes) {
        $scheduleMessage = '"' . $name . '" was updated — new ' . implode(', ', $changes) . '.';
        NotificationService::safely(function () use ($db, $eventId, $scheduleMessage) {
            $people = $db->prepare('SELECT DISTINCT p.user_id FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE r.event_id = :id AND r.status IN ("pending", "approved")');
            $people->execute(['id' => $eventId]);
            foreach ($people->fetchAll(PDO::FETCH_COLUMN) as $participantUserId) {
                notify((int) $participantUserId, 'Event Schedule Updated', $scheduleMessage, 'event_update', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]);
            }
        });
        NotificationService::toEventStaff($db, $eventId, 'Event Schedule Updated', $scheduleMessage, 'staff_update', ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]);
    }
    flash('success', 'Event updated successfully.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$stmt = $db->prepare(
    'INSERT INTO events (organizer_id, category_id, sport_id, name, description, banner_path, event_date, event_time, registration_deadline, venue, fee, fee_mode, max_participants, max_teams, tournament_format, periods_count, game_duration_minutes, requirements, rules, contact_info, status)
     VALUES (:oid, :cat, :sport, :name, :desc, :banner, :date, :time, :deadline, :venue, :fee, :fee_mode, :max, :max_teams, :tformat, :periods, :gamedur, :req, :rules, :contact, "draft")'
);
$stmt->execute([
    'oid' => $organizer['id'], 'cat' => $categoryId, 'sport' => $sportId, 'name' => $name, 'desc' => $description ?: null,
    'banner' => $bannerPath, 'date' => $eventDate, 'time' => $eventTime, 'deadline' => $registrationDeadline ?: null,
    'venue' => $venue, 'fee' => $fee, 'fee_mode' => $feeMode, 'max' => $maxParticipants, 'max_teams' => $maxTeams,
    'tformat' => $tournamentFormat, 'periods' => $periodsCount, 'gamedur' => $gameDurationMinutes,
    'req' => $requirements ?: null, 'rules' => $rules ?: null,
    'contact' => $contactInfo ?: null,
]);
$newEventId = (int) $db->lastInsertId();

if ($isFunRun) {
    syncRaceCategories($db, $newEventId, $raceCatNames, false);
}

Logger::log($userId, 'event_created', 'Created event #' . $newEventId . ' (' . $name . ')');
flash('success', 'Event created as draft. Open registration when ready.');
redirect('organizer-event-manage', ['id' => $newEventId]);
