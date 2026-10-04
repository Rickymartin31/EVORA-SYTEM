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
    'SELECT e.*, s.sport_type, s.team_size, s.scoring_method FROM events e
     LEFT JOIN sports s ON s.id = e.sport_id WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();
if (!$event || empty($event['sport_id'])) {
    flash('error', 'Event not found or has no sport selected.');
    redirect('organizer-events');
}

$sportId = (int) $event['sport_id'];
$usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;
$lowerWins = $event['scoring_method'] === 'time_rank';

$action = $_POST['match_action'] ?? '';

function insertMatch(PDO $db, int $eventId, int $sportId, int $roundNo, ?int $teamA, ?int $teamB, ?int $partA, ?int $partB, ?string $scheduledAt, ?string $venue, ?int $referee): int
{
    $stmt = $db->prepare(
        'INSERT INTO matches (event_id, sport_id, team_a_id, team_b_id, participant_a_id, participant_b_id, scheduled_at, venue, referee_staff_id, round_no)
         VALUES (:eid, :sid, :ta, :tb, :pa, :pb, :sched, :venue, :ref, :round)'
    );
    $stmt->execute([
        'eid' => $eventId, 'sid' => $sportId, 'ta' => $teamA, 'tb' => $teamB, 'pa' => $partA, 'pb' => $partB,
        'sched' => $scheduledAt, 'venue' => $venue, 'ref' => $referee, 'round' => $roundNo,
    ]);
    $matchId = (int) $db->lastInsertId();

    $posStmt = $db->prepare('SELECT COUNT(*) c FROM tournament_brackets WHERE event_id = :eid AND round_no = :round');
    $posStmt->execute(['eid' => $eventId, 'round' => $roundNo]);
    $position = (int) $posStmt->fetch()['c'] + 1;

    $bracketStmt = $db->prepare('INSERT INTO tournament_brackets (event_id, round_no, match_id, position) VALUES (:eid, :round, :mid, :pos)');
    $bracketStmt->execute(['eid' => $eventId, 'round' => $roundNo, 'mid' => $matchId, 'pos' => $position]);

    return $matchId;
}

if ($action === 'create_match') {
    $roundNo = max(1, (int) ($_POST['round_no'] ?? 1));
    $teamAId = $usesTeams ? ((int) ($_POST['team_a_id'] ?? 0) ?: null) : null;
    $teamBId = $usesTeams ? ((int) ($_POST['team_b_id'] ?? 0) ?: null) : null;
    $partAId = !$usesTeams ? ((int) ($_POST['participant_a_id'] ?? 0) ?: null) : null;
    $partBId = !$usesTeams ? ((int) ($_POST['participant_b_id'] ?? 0) ?: null) : null;
    $refereeId = (int) ($_POST['referee_staff_id'] ?? 0) ?: null;
    $scheduledAt = $_POST['scheduled_at'] ?? '';
    $venue = trim($_POST['venue'] ?? '');

    // Verify any referenced team/participant actually belongs to this event —
    // a tampered POST must not be able to wire in a team/participant from a
    // different event (or a different organizer's event) into this bracket.
    if ($teamAId !== null) {
        $chk = $db->prepare('SELECT id FROM teams WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $teamAId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $teamAId = null; }
    }
    if ($teamBId !== null) {
        $chk = $db->prepare('SELECT id FROM teams WHERE id = :id AND event_id = :eid');
        $chk->execute(['id' => $teamBId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $teamBId = null; }
    }
    if ($partAId !== null) {
        $chk = $db->prepare('SELECT p.id FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE p.id = :id AND r.event_id = :eid AND r.status = "approved"');
        $chk->execute(['id' => $partAId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $partAId = null; }
    }
    if ($partBId !== null) {
        $chk = $db->prepare('SELECT p.id FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE p.id = :id AND r.event_id = :eid AND r.status = "approved"');
        $chk->execute(['id' => $partBId, 'eid' => $eventId]);
        if (!$chk->fetch()) { $partBId = null; }
    }

    $matchId = insertMatch($db, $eventId, $sportId, $roundNo, $teamAId, $teamBId, $partAId, $partBId, $scheduledAt ?: null, $venue ?: null, $refereeId);

    Logger::log($userId, 'match_scheduled', 'Scheduled match #' . $matchId . ' for event #' . $eventId);
    flash('success', 'Match scheduled.');
} elseif ($action === 'record_score') {
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $teamAScore = (float) ($_POST['team_a_score'] ?? 0);
    $teamBScore = (float) ($_POST['team_b_score'] ?? 0);

    $matchCheck = $db->prepare('SELECT * FROM matches WHERE id = :id AND event_id = :eid');
    $matchCheck->execute(['id' => $matchId, 'eid' => $eventId]);
    $match = $matchCheck->fetch();
    if (!$match) {
        flash('error', 'Match not found.');
        redirect('organizer-matches', ['event_id' => $eventId]);
    }

    $aWins = $lowerWins ? $teamAScore < $teamBScore : $teamAScore > $teamBScore;
    $bWins = $lowerWins ? $teamBScore < $teamAScore : $teamBScore > $teamAScore;
    $winnerTeamId = null;
    $winnerParticipantId = null;
    if ($match['team_a_id'] || $match['team_b_id']) {
        $winnerTeamId = $aWins ? $match['team_a_id'] : ($bWins ? $match['team_b_id'] : null);
    } else {
        $winnerParticipantId = $aWins ? $match['participant_a_id'] : ($bWins ? $match['participant_b_id'] : null);
    }

    $existing = $db->prepare('SELECT id FROM match_scores WHERE match_id = :mid');
    $existing->execute(['mid' => $matchId]);

    if ($existing->fetch()) {
        $stmt = $db->prepare(
            'UPDATE match_scores SET team_a_score = :a, team_b_score = :b, winner_team_id = :wt, winner_participant_id = :wp, recorded_by = :by, recorded_at = NOW() WHERE match_id = :mid'
        );
    } else {
        $stmt = $db->prepare(
            'INSERT INTO match_scores (match_id, team_a_score, team_b_score, winner_team_id, winner_participant_id, recorded_by) VALUES (:mid, :a, :b, :wt, :wp, :by)'
        );
    }
    $stmt->execute(['a' => $teamAScore, 'b' => $teamBScore, 'wt' => $winnerTeamId, 'wp' => $winnerParticipantId, 'by' => $userId, 'mid' => $matchId]);

    $statusUpdate = $db->prepare('UPDATE matches SET status = "completed" WHERE id = :id');
    $statusUpdate->execute(['id' => $matchId]);

    Logger::log($userId, 'match_score_recorded', 'Recorded score for match #' . $matchId);
    flash('success', 'Score updated.');
} elseif ($action === 'auto_generate_round1') {
    $existingCheck = $db->prepare('SELECT COUNT(*) c FROM matches WHERE event_id = :eid');
    $existingCheck->execute(['eid' => $eventId]);
    if ((int) $existingCheck->fetch()['c'] > 0) {
        flash('error', 'Matches already exist for this event.');
        redirect('organizer-matches', ['event_id' => $eventId]);
    }

    if ($usesTeams) {
        $poolStmt = $db->prepare('SELECT id FROM teams WHERE event_id = :eid ORDER BY created_at');
        $poolStmt->execute(['eid' => $eventId]);
    } else {
        $poolStmt = $db->prepare(
            'SELECT p.id FROM registrations r JOIN participants p ON p.id = r.participant_id
             WHERE r.event_id = :eid AND r.status = "approved" ORDER BY r.registered_at'
        );
        $poolStmt->execute(['eid' => $eventId]);
    }
    $pool = array_column($poolStmt->fetchAll(), 'id');

    if (count($pool) < 2) {
        flash('error', 'Need at least 2 ' . ($usesTeams ? 'teams' : 'approved participants') . ' to generate matches.');
        redirect('organizer-matches', ['event_id' => $eventId]);
    }

    $created = 0;
    for ($i = 0; $i < count($pool); $i += 2) {
        $a = (int) $pool[$i];
        $b = isset($pool[$i + 1]) ? (int) $pool[$i + 1] : null;
        if ($usesTeams) {
            $matchId = insertMatch($db, $eventId, $sportId, 1, $a, $b, null, null, null, $event['venue'], null);
        } else {
            $matchId = insertMatch($db, $eventId, $sportId, 1, null, null, $a, $b, null, $event['venue'], null);
        }
        if ($b === null) {
            // Bye: no opponent to play, so auto-complete with the sole entrant
            // as winner — otherwise this match sits "scheduled" forever and
            // blocks "Advance Winners" (which requires every match in the
            // round to be completed).
            $db->prepare('UPDATE matches SET status = "completed" WHERE id = :id')->execute(['id' => $matchId]);
            $scoreStmt = $db->prepare(
                'INSERT INTO match_scores (match_id, team_a_score, team_b_score, winner_team_id, winner_participant_id) VALUES (:mid, 0, 0, :wt, :wp)'
            );
            $scoreStmt->execute(['mid' => $matchId, 'wt' => $usesTeams ? $a : null, 'wp' => !$usesTeams ? $a : null]);
        }
        $created++;
    }

    Logger::log($userId, 'round_auto_generated', 'Auto-generated ' . $created . ' Round 1 match(es) for event #' . $eventId);
    flash('success', $created . ' Round 1 match(es) generated' . (count($pool) % 2 ? ' (one entry left as a bye).' : '.'));
} elseif ($action === 'advance_round') {
    $fromRound = max(1, (int) ($_POST['from_round'] ?? 0));

    $roundStmt = $db->prepare(
        'SELECT m.id, sc.winner_team_id, sc.winner_participant_id FROM matches m
         LEFT JOIN match_scores sc ON sc.match_id = m.id
         WHERE m.event_id = :eid AND m.round_no = :round ORDER BY m.id'
    );
    $roundStmt->execute(['eid' => $eventId, 'round' => $fromRound]);
    $roundMatches = $roundStmt->fetchAll();

    if (count($roundMatches) < 2) {
        flash('error', 'Round ' . $fromRound . ' needs at least 2 matches to advance.');
        redirect('organizer-matches', ['event_id' => $eventId]);
    }

    $winners = [];
    foreach ($roundMatches as $rm) {
        $winners[] = ['team' => $rm['winner_team_id'], 'participant' => $rm['winner_participant_id'], 'match_id' => $rm['id']];
    }

    $nextRound = $fromRound + 1;
    $created = 0;
    for ($i = 0; $i < count($winners); $i += 2) {
        $a = $winners[$i];
        $b = $winners[$i + 1] ?? ['team' => null, 'participant' => null, 'match_id' => null];
        if (!$a['team'] && !$a['participant'] && !$b['team'] && !$b['participant']) {
            continue;
        }
        $newMatchId = insertMatch(
            $db, $eventId, $sportId, $nextRound,
            $usesTeams ? $a['team'] : null, $usesTeams ? $b['team'] : null,
            !$usesTeams ? $a['participant'] : null, !$usesTeams ? $b['participant'] : null,
            null, $event['venue'], null
        );
        $m1 = $a['match_id']; $m2 = $b['match_id'] ?? $a['match_id'];
        $db->prepare('UPDATE matches SET next_match_id = :next WHERE id = :id')->execute(['next' => $newMatchId, 'id' => $m1]);
        if ($m2 && $m2 !== $m1) {
            $db->prepare('UPDATE matches SET next_match_id = :next WHERE id = :id')->execute(['next' => $newMatchId, 'id' => $m2]);
        }
        $created++;
    }

    Logger::log($userId, 'round_advanced', 'Advanced ' . $created . ' match(es) to round ' . $nextRound . ' for event #' . $eventId);
    flash('success', 'Round ' . $nextRound . ' created with ' . $created . ' match(es).');
}

redirect('organizer-matches', ['event_id' => $eventId]);
