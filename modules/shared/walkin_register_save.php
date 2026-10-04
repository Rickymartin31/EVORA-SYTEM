<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('walkin-register');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);

// Scope check: organizer must own the event; staff must be assigned to it
// with the manage_walkin permission. Same pattern used by every other
// staff-scoped feature (scan.php, claims.php, etc).
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare(
        "SELECT e.*, c.slug AS category_slug FROM events e JOIN event_categories c ON c.id = e.category_id
         WHERE e.id = :id AND e.organizer_id = :oid AND e.status IN ('open','ongoing')"
    );
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.*, c.slug AS category_slug FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_walkin' AND e.status IN ('open','ongoing')"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have walk-in registration access to it.');
    redirect('walkin-register');
}

$isFunRun = $event['category_slug'] === 'fun-run';

// One slot-taker at a time per event (v47): the capacity counts below and the insert run inside
// the event's seat lock, which ends with this request.
if (!EventRegistrationService::seatLock($db, $eventId)) {
    flash('error', 'Another registration or payment for this event is being saved. Please try again in a moment.');
    redirect('walkin-register');
}

// Fee is always looked up server-side from the DB — never trusted from POST.
$raceCategoryId = null;
$distanceCategory = null;
$fee = (float) $event['fee'];
if ($isFunRun) {
    $raceCategoryId = (int) ($_POST['race_category_id'] ?? 0);
    $rcStmt = $db->prepare(
        "SELECT rc.*, (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved_count
         FROM race_categories rc WHERE rc.id = :id AND rc.event_id = :eid"
    );
    $rcStmt->execute(['id' => $raceCategoryId, 'eid' => $eventId]);
    $raceCategory = $rcStmt->fetch();
    if (!$raceCategory) {
        flash('error', 'Please select a valid race category.');
        redirect('walkin-register', ['event_id' => $eventId]);
    }
    if ((int) $raceCategory['max_participants'] > 0 && (int) $raceCategory['approved_count'] >= (int) $raceCategory['max_participants']) {
        flash('error', 'That race category is already full.');
        redirect('walkin-register');
    }
    $fee = (float) $raceCategory['fee'];
    $distanceCategory = $raceCategory['name'];
}

$approvedCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$approvedCount->execute(['id' => $eventId]);
if ((int) $event['max_participants'] > 0 && (int) $approvedCount->fetch()['c'] >= (int) $event['max_participants']) {
    flash('error', 'This event has reached maximum capacity.');
    redirect('walkin-register');
}

$db->beginTransaction();
try {
    $participantId = (int) ($_POST['participant_id'] ?? 0);
    $participantName = null;

    if ($participantId) {
        $pStmt = $db->prepare('SELECT p.id, u.full_name FROM participants p JOIN users u ON u.id = p.user_id WHERE p.id = :id');
        $pStmt->execute(['id' => $participantId]);
        $participant = $pStmt->fetch();
        if (!$participant) {
            throw new RuntimeException('Selected participant not found.');
        }
        $participantName = $participant['full_name'];
    } else {
        // Lookup-first was already done in the UI; this is the server-side
        // safety net against a duplicate account being created in a race
        // or via a direct POST.
        $fullName = trim($_POST['full_name'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($fullName === '' || $mobile === '') {
            throw new RuntimeException('Full name and mobile number are required.');
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email, or leave it blank.');
            }
            $dupStmt = $db->prepare('SELECT id FROM users WHERE email = :email');
            $dupStmt->execute(['email' => $email]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('An account with that email already exists — search for the participant instead of creating a new one.');
            }
        } else {
            // Clearly marked as walk-in-generated via the dedicated domain —
            // greppable/reportable without needing an extra DB column.
            do {
                $email = 'walkin.' . bin2hex(random_bytes(6)) . '@walkin.evora.local';
                $dupStmt = $db->prepare('SELECT id FROM users WHERE email = :email');
                $dupStmt->execute(['email' => $email]);
            } while ($dupStmt->fetch());
        }

        $roleStmt = $db->query('SELECT id FROM roles WHERE name = "participant"');
        $participantRoleId = $roleStmt->fetch()['id'];

        $randomPassword = bin2hex(random_bytes(16));
        $insertUser = $db->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash, phone, status) VALUES (:role_id, :name, :email, :hash, :phone, "active")'
        );
        $insertUser->execute([
            'role_id' => $participantRoleId, 'name' => $fullName, 'email' => $email,
            'hash' => password_hash($randomPassword, PASSWORD_DEFAULT), 'phone' => $mobile,
        ]);
        $newUserId = (int) $db->lastInsertId();

        $insertParticipant = $db->prepare('INSERT INTO participants (user_id) VALUES (:uid)');
        $insertParticipant->execute(['uid' => $newUserId]);
        $participantId = (int) $db->lastInsertId();
        $participantName = $fullName;
    }

    $dupRegStmt = $db->prepare('SELECT id FROM registrations WHERE event_id = :eid AND participant_id = :pid AND status <> "cancelled"'); // a cancelled one may register again (v47)
    $dupRegStmt->execute(['eid' => $eventId, 'pid' => $participantId]);
    if ($dupRegStmt->fetch()) {
        throw new RuntimeException('This participant is already registered for this event.');
    }

    $insertReg = $db->prepare(
        'INSERT INTO registrations (event_id, participant_id, distance_category, race_category_id, status, reviewed_by, reviewed_at)
         VALUES (:eid, :pid, :dist, :rcid, "approved", :by, NOW())'
    );
    $insertReg->execute([
        'eid' => $eventId, 'pid' => $participantId, 'dist' => $distanceCategory, 'rcid' => $raceCategoryId, 'by' => $userId,
    ]);
    $registrationId = (int) $db->lastInsertId();

    if ($fee > 0) {
        $insertPay = $db->prepare(
            'INSERT INTO payments (registration_id, amount, payment_method, payment_channel, status, reviewed_by, reviewed_at)
             VALUES (:rid, :amount, "cash", "cash_walkin", "approved", :by, NOW())'
        );
        $insertPay->execute(['rid' => $registrationId, 'amount' => $fee, 'by' => $userId]);
        $paymentId = (int) $db->lastInsertId();

        $operatorName = $_SESSION['full_name'] ?? 'Staff';
        $historyStmt = $db->prepare('INSERT INTO payment_history (payment_id, action, performed_by, remarks) VALUES (:pid, "cash_walkin_recorded", :by, :remarks)');
        $historyStmt->execute([
            'pid' => $paymentId, 'by' => $userId,
            'remarks' => 'Cash walk-in payment of ' . currency($fee) . ' recorded by ' . $operatorName,
        ]);
    }

    issueQrIfMissing($db, $registrationId, $userId);

    Logger::log($userId, 'walkin_registration_created', 'Walk-in registration for ' . $participantName . ' on event #' . $eventId . ($fee > 0 ? ' (cash ' . currency($fee) . ')' : ' (free)'));

    $db->commit();

    NotificationService::toEventOrganizer($db, $eventId, 'New Walk-in Registration',
        $participantName . ' was registered on-site for "' . $event['name'] . '" by ' . ($_SESSION['full_name'] ?? 'staff') . ($fee > 0 ? ' (cash ' . currency($fee) . ').' : '.'),
        'new_registration', ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'registration', 'reference_id' => $registrationId], (int) $userId);
    NotificationService::safely(function () use ($db, $participantId, $event, $registrationId) {
        $owner = $db->prepare('SELECT user_id FROM participants WHERE id = :id');
        $owner->execute(['id' => $participantId]);
        if ($participantUserId = (int) $owner->fetchColumn()) {
            notify($participantUserId, 'Registration Confirmed', 'You were registered on-site for "' . $event['name'] . '". Your QR code is ready in My Registrations.', 'registration_status',
                ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'registration', 'reference_id' => $registrationId]);
        }
    });

    flash('success', 'Walk-in registration complete for ' . $participantName . '. QR code is ready.');
} catch (Throwable $e) {
    $db->rollBack();
    flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong while processing the walk-in registration.');
    redirect('walkin-register');
}

redirect('walkin-register');
