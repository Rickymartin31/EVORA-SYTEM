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
$eventStmt = $db->prepare('SELECT id, sport_id FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$eventRow = $eventStmt->fetch();
if (!$eventRow) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'organizer-events');

$action = $_POST['team_action'] ?? '';

if ($action === 'create_team') {
    $sportId = (int) $eventRow['sport_id'];
    $teamName = trim($_POST['team_name'] ?? '');
    $coachName = trim($_POST['coach_name'] ?? '');
    if ($sportId <= 0 || $teamName === '') {
        flash('error', 'This event has no sport selected, or the team name is missing.');
    } else {
        $stmt = $db->prepare('INSERT INTO teams (event_id, sport_id, team_name, coach_name) VALUES (:eid, :sid, :name, :coach)');
        $stmt->execute(['eid' => $eventId, 'sid' => $sportId, 'name' => $teamName, 'coach' => $coachName ?: null]);
        Logger::log($userId, 'team_created', 'Created team "' . $teamName . '" for event #' . $eventId);
        flash('success', 'Team created.');
    }
} elseif ($action === 'update_team') {
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $teamName = trim($_POST['team_name'] ?? '');
    $coachName = trim($_POST['coach_name'] ?? '');

    $teamCheck = $db->prepare('SELECT id FROM teams WHERE id = :id AND event_id = :eid');
    $teamCheck->execute(['id' => $teamId, 'eid' => $eventId]);
    if (!$teamCheck->fetch()) {
        flash('error', 'Team not found.');
    } elseif ($teamName === '') {
        flash('error', 'Team name is required.');
    } else {
        $stmt = $db->prepare('UPDATE teams SET team_name = :name, coach_name = :coach WHERE id = :id AND event_id = :eid');
        $stmt->execute(['name' => $teamName, 'coach' => $coachName ?: null, 'id' => $teamId, 'eid' => $eventId]);
        Logger::log($userId, 'team_updated', 'Updated team "' . $teamName . '" for event #' . $eventId);
        flash('success', 'Team updated.');
    }
} elseif ($action === 'add_member') {
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $participantId = (int) ($_POST['participant_id'] ?? 0);

    $teamCheck = $db->prepare('SELECT id FROM teams WHERE id = :id AND event_id = :eid');
    $teamCheck->execute(['id' => $teamId, 'eid' => $eventId]);
    $participantCheck = $db->prepare(
        'SELECT p.id FROM registrations r JOIN participants p ON p.id = r.participant_id
         WHERE p.id = :pid AND r.event_id = :eid AND r.status = "approved"'
    );
    $participantCheck->execute(['pid' => $participantId, 'eid' => $eventId]);
    if (!$teamCheck->fetch() || $participantId <= 0 || !$participantCheck->fetch()) {
        flash('error', 'Invalid team or participant.');
    } else {
        try {
            $stmt = $db->prepare('INSERT INTO team_members (team_id, participant_id) VALUES (:tid, :pid)');
            $stmt->execute(['tid' => $teamId, 'pid' => $participantId]);
            flash('success', 'Member added to team.');
        } catch (PDOException $e) {
            flash('error', 'This participant is already on the team.');
        }
    }
} elseif ($action === 'remove_member') {
    $teamMemberId = (int) ($_POST['team_member_id'] ?? 0);
    $stmt = $db->prepare(
        'DELETE tm FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.id = :id AND t.event_id = :eid'
    );
    $stmt->execute(['id' => $teamMemberId, 'eid' => $eventId]);
    flash('success', 'Member removed from team.');
}

redirect('organizer-teams', ['event_id' => $eventId]);
