<?php
Auth::requireRole(['organizer', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('match-center');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_POST['event_id'] ?? 0);

// Scope check: organizer must own the event; staff must be assigned to it
// with the manage_live_scores permission. Same pattern as every other
// staff-scoped feature (scan_verify.php, walkin_register_save.php, etc).
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT e.* FROM events e WHERE e.id = :id AND e.organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_live_scores'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have live match control access to it.');
    redirect('match-center');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'match-center');

// Never trust the posted match_id beyond this: it must belong to this exact event.
$matchId = (int) ($_POST['match_id'] ?? 0);
$matchStmt = $db->prepare(
    'SELECT m.*, s.scoring_method FROM matches m
     JOIN sports s ON s.id = m.sport_id
     WHERE m.id = :id AND m.event_id = :eid'
);
$matchStmt->execute(['id' => $matchId, 'eid' => $eventId]);
$match = $matchStmt->fetch();

if (!$match) {
    flash('error', 'Match not found.');
    redirect('match-center', ['event_id' => $eventId]);
}

$action = $_POST['action'] ?? '';

$transitions = [
    'start' => ['from' => ['scheduled'], 'to' => 'ongoing'],
    'pause' => ['from' => ['ongoing'], 'to' => 'paused'],
    'resume' => ['from' => ['paused'], 'to' => 'ongoing'],
    'finish' => ['from' => ['ongoing', 'paused'], 'to' => 'completed'],
];

function currentState(PDO $db, int $matchId): array
{
    $stmt = $db->prepare('SELECT * FROM match_scores WHERE match_id = :mid');
    $stmt->execute(['mid' => $matchId]);
    $row = $stmt->fetch();
    $detail = [];
    if ($row && $row['score_detail']) {
        $decoded = json_decode($row['score_detail'], true);
        if (is_array($decoded)) {
            $detail = $decoded;
        }
    }
    return [
        'row' => $row,
        'team_a_score' => (int) ($row['team_a_score'] ?? 0),
        'team_b_score' => (int) ($row['team_b_score'] ?? 0),
        'detail' => $detail,
    ];
}

function saveScore(PDO $db, int $matchId, int $userId, int $teamAScore, int $teamBScore, array $detail, ?int $winnerTeamId, ?int $winnerParticipantId): void
{
    $existing = $db->prepare('SELECT id FROM match_scores WHERE match_id = :mid');
    $existing->execute(['mid' => $matchId]);
    $detailJson = json_encode($detail);

    if ($existing->fetch()) {
        $stmt = $db->prepare(
            'UPDATE match_scores SET team_a_score = :a, team_b_score = :b, winner_team_id = :wt, winner_participant_id = :wp, score_detail = :detail, recorded_by = :by, recorded_at = NOW() WHERE match_id = :mid'
        );
    } else {
        $stmt = $db->prepare(
            'INSERT INTO match_scores (match_id, team_a_score, team_b_score, winner_team_id, winner_participant_id, score_detail, recorded_by) VALUES (:mid, :a, :b, :wt, :wp, :detail, :by)'
        );
    }
    $stmt->execute(['a' => $teamAScore, 'b' => $teamBScore, 'wt' => $winnerTeamId, 'wp' => $winnerParticipantId, 'detail' => $detailJson, 'by' => $userId, 'mid' => $matchId]);
}

if (isset($transitions[$action])) {
    $t = $transitions[$action];
    if (!in_array($match['status'], $t['from'], true)) {
        flash('error', 'Cannot ' . $action . ' a match that is currently "' . $match['status'] . '".');
        redirect('match-center', ['event_id' => $eventId, 'match_id' => $matchId]);
    }

    if ($action === 'start') {
        // Ensure a score row exists so the control panel and public view have
        // something to render immediately, same convention as game_state_action.php.
        $existing = $db->prepare('SELECT id FROM match_scores WHERE match_id = :mid');
        $existing->execute(['mid' => $matchId]);
        if (!$existing->fetch()) {
            $ins = $db->prepare('INSERT INTO match_scores (match_id, team_a_score, team_b_score) VALUES (:mid, 0, 0)');
            $ins->execute(['mid' => $matchId]);
        }
    }

    $winnerTeamId = $match['winner_team_id'] ?? null;
    $winnerParticipantId = null;
    $scoreStmt = $db->prepare('SELECT * FROM match_scores WHERE match_id = :mid');
    $scoreStmt->execute(['mid' => $matchId]);
    $scoreRow = $scoreStmt->fetch();

    if ($action === 'finish' && $scoreRow) {
        $lowerWins = $match['scoring_method'] === 'time_rank';
        $a = (int) $scoreRow['team_a_score'];
        $b = (int) $scoreRow['team_b_score'];
        $aWins = $lowerWins ? $a < $b : $a > $b;
        $bWins = $lowerWins ? $b < $a : $b > $a;
        if ($match['team_a_id'] || $match['team_b_id']) {
            $winnerTeamId = $aWins ? $match['team_a_id'] : ($bWins ? $match['team_b_id'] : null);
        } else {
            $winnerParticipantId = $aWins ? $match['participant_a_id'] : ($bWins ? $match['participant_b_id'] : null);
        }
        $upd = $db->prepare('UPDATE match_scores SET winner_team_id = :wt, winner_participant_id = :wp WHERE match_id = :mid');
        $upd->execute(['wt' => $winnerTeamId, 'wp' => $winnerParticipantId, 'mid' => $matchId]);
    }

    $stmt = $db->prepare('UPDATE matches SET status = :status WHERE id = :id AND event_id = :eid');
    $stmt->execute(['status' => $t['to'], 'id' => $matchId, 'eid' => $eventId]);

    Logger::log($userId, 'match_' . $action, 'Match #' . $matchId . ' (' . $action . ') for event #' . $eventId);
    flash('success', 'Match ' . $t['to'] . '.');
} elseif ($action === 'update_score') {
    if (!in_array($match['status'], ['ongoing', 'paused'], true)) {
        flash('error', 'The match must be live or paused to update its score.');
        redirect('match-center', ['event_id' => $eventId, 'match_id' => $matchId]);
    }
    $teamAScore = (float) ($_POST['team_a_score'] ?? 0);
    $teamBScore = (float) ($_POST['team_b_score'] ?? 0);
    $state = currentState($db, $matchId);
    saveScore($db, $matchId, $userId, (int) $teamAScore, (int) $teamBScore, $state['detail'], null, null);

    Logger::log($userId, 'match_score_updated', 'Score updated for match #' . $matchId . ' via Match Center');
    flash('success', 'Score updated.');
} elseif ($action === 'record_set') {
    if (!in_array($match['status'], ['ongoing', 'paused'], true)) {
        flash('error', 'The match must be live or paused to record a set.');
        redirect('match-center', ['event_id' => $eventId, 'match_id' => $matchId]);
    }
    $setScoreA = (int) ($_POST['set_score_a'] ?? 0);
    $setScoreB = (int) ($_POST['set_score_b'] ?? 0);
    if ($setScoreA === $setScoreB) {
        flash('error', 'A set cannot end in a tie.');
        redirect('match-center', ['event_id' => $eventId, 'match_id' => $matchId]);
    }
    $state = currentState($db, $matchId);
    $sets = $state['detail']['sets'] ?? [];
    $sets[] = ['a' => $setScoreA, 'b' => $setScoreB];
    $state['detail']['sets'] = $sets;

    $setsWonA = 0;
    $setsWonB = 0;
    foreach ($sets as $s) {
        if ($s['a'] > $s['b']) { $setsWonA++; } else { $setsWonB++; }
    }
    saveScore($db, $matchId, $userId, $setsWonA, $setsWonB, $state['detail'], null, null);

    Logger::log($userId, 'match_set_recorded', 'Set recorded for match #' . $matchId . ' (' . $setScoreA . '-' . $setScoreB . ')');
    flash('success', 'Set recorded.');
} elseif ($action === 'save_notes') {
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $state = currentState($db, $matchId);
    $state['detail']['notes'] = $notes !== '' ? $notes : null;
    saveScore($db, $matchId, $userId, $state['team_a_score'], $state['team_b_score'], $state['detail'], $state['row']['winner_team_id'] ?? null, $state['row']['winner_participant_id'] ?? null);

    Logger::log($userId, 'match_notes_updated', 'Notes updated for match #' . $matchId);
    flash('success', 'Notes saved.');
} else {
    flash('error', 'Invalid action.');
}

redirect('match-center', ['event_id' => $eventId, 'match_id' => $matchId]);
