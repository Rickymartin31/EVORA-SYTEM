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
    'SELECT e.*, c.slug AS category_slug FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$permissions = $_POST['permissions'] ?? [];
$assignmentArea = trim($_POST['assignment_area'] ?? '');
$taskNote = trim($_POST['task_note'] ?? '');

// Only General permissions plus this event's own category group can ever be
// granted here — even a forged POST naming a mismatched-category permission
// (e.g. manage_bibs on a sports-tournament event) is silently stripped.
$generalPermissions = ['scan_qr', 'verify_participant', 'record_attendance', 'manage_claims', 'view_announcements', 'manage_walkin'];
$categoryPermissions = [
    'sports-tournament' => ['manage_live_scores', 'manage_incidents'],
    'fun-run' => ['manage_bibs', 'manage_finish_results', 'manage_checkpoints', 'manage_medical', 'view_race_operations'],
    'seminar-workshop' => ['manage_sessions', 'manage_speakers', 'manage_feedback'],
];
$allowedPermissions = array_merge($generalPermissions, $categoryPermissions[$event['category_slug']] ?? []);
$permissions = array_values(array_intersect($permissions, $allowedPermissions));

if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    flash('error', 'Please provide a valid name, email, and password (min 8 characters).');
    redirect('organizer-staff', ['event_id' => $eventId]);
}

$db->beginTransaction();
try {
    $userStmt = $db->prepare('SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = :email');
    $userStmt->execute(['email' => $email]);
    $existingUser = $userStmt->fetch();

    if ($existingUser) {
        if ($existingUser['role_name'] !== 'staff') {
            $db->rollBack();
            flash('error', 'This email belongs to an existing non-staff account and cannot be used.');
            redirect('organizer-staff', ['event_id' => $eventId]);
        }
        $staffUserId = (int) $existingUser['id'];
    } else {
        $roleStmt = $db->query('SELECT id FROM roles WHERE name = "staff"');
        $roleId = $roleStmt->fetch()['id'];

        $insertUser = $db->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash, phone, status)
             VALUES (:role_id, :name, :email, :hash, :phone, "active")'
        );
        $insertUser->execute([
            'role_id' => $roleId, 'name' => $fullName, 'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT), 'phone' => $phone ?: null,
        ]);
        $staffUserId = (int) $db->lastInsertId();
    }

    $dupCheck = $db->prepare('SELECT id FROM event_staff WHERE event_id = :eid AND user_id = :uid');
    $dupCheck->execute(['eid' => $eventId, 'uid' => $staffUserId]);
    if ($dupCheck->fetch()) {
        $db->rollBack();
        flash('error', 'This person is already assigned to this event.');
        redirect('organizer-staff', ['event_id' => $eventId]);
    }

    $insertStaff = $db->prepare(
        'INSERT INTO event_staff (event_id, user_id, assignment_area, task_note, assigned_by) VALUES (:eid, :uid, :area, :note, :by)'
    );
    $insertStaff->execute([
        'eid' => $eventId, 'uid' => $staffUserId, 'area' => $assignmentArea ?: null, 'note' => $taskNote ?: null, 'by' => $userId,
    ]);
    $eventStaffId = (int) $db->lastInsertId();

    $permStmt = $db->prepare('INSERT INTO staff_permissions (event_staff_id, permission_key) VALUES (:id, :perm)');
    foreach ($permissions as $perm) {
        $permStmt->execute(['id' => $eventStaffId, 'perm' => $perm]);
    }

    $db->commit();

    Logger::log($userId, 'staff_assigned', 'Assigned staff ' . $email . ' to event #' . $eventId);
    notify($staffUserId, 'Event Assignment', 'You have been assigned as staff for "' . $event['name'] . '".', 'staff_assignment');

    flash('success', 'Staff member added to event.');
} catch (Throwable $e) {
    $db->rollBack();
    flash('error', 'Something went wrong while adding staff.');
}

redirect('organizer-staff', ['event_id' => $eventId]);
