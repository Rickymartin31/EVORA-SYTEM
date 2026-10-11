<?php
Auth::requireRole('organizer');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403); // not 419: Apache turns unknown codes into an empty 500
    echo json_encode(['error' => 'Invalid or expired security token. Refresh the page.']);
    exit;
}

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$matchId = (int) ($_POST['match_id'] ?? 0);
$stmt = $db->prepare(
    'SELECT m.*, e.periods_count, e.game_duration_minutes FROM matches m
     JOIN events e ON e.id = m.event_id
     WHERE m.id = :id AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $matchId, 'oid' => $organizer['id'] ?? 0]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo json_encode(['error' => 'Match not found']);
    exit;
}

$periodsCount = (int) ($match['periods_count'] ?: 4);
$periodSeconds = ((int) ($match['game_duration_minutes'] ?: 12)) * 60;

$scoreStmt = $db->prepare('SELECT * FROM match_scores WHERE match_id = :mid');
$scoreStmt->execute(['mid' => $matchId]);
$scoreRow = $scoreStmt->fetch();

$defaultState = [
    'period' => 1, 'clock_base_seconds' => $periodSeconds, 'clock_running' => false, 'clock_started_at' => null,
    'fouls_a' => 0, 'fouls_b' => 0, 'timeouts_a' => 0, 'timeouts_b' => 0,
];
$state = $defaultState;
$teamAScore = (int) ($scoreRow['team_a_score'] ?? 0);
$teamBScore = (int) ($scoreRow['team_b_score'] ?? 0);
if ($scoreRow && $scoreRow['score_detail']) {
    $decoded = json_decode($scoreRow['score_detail'], true);
    if (is_array($decoded)) {
        $state = array_merge($defaultState, $decoded);
    }
}

function currentRemainingSeconds(array $state): int
{
    $base = (int) $state['clock_base_seconds'];
    if ($state['clock_running'] && $state['clock_started_at']) {
        $elapsed = time() - (int) $state['clock_started_at'];
        return max(0, $base - $elapsed);
    }
    return max(0, $base);
}

$action = $_POST['action'] ?? '';
$team = $_POST['team'] ?? '';

switch ($action) {
    case 'add_points':
        $points = (int) ($_POST['points'] ?? 0);
        if ($team === 'a') {
            $teamAScore += $points;
        } elseif ($team === 'b') {
            $teamBScore += $points;
        }
        break;
    case 'add_foul':
        if ($team === 'a') {
            $state['fouls_a']++;
        } elseif ($team === 'b') {
            $state['fouls_b']++;
        }
        break;
    case 'use_timeout':
        if ($team === 'a') {
            $state['timeouts_a']++;
        } elseif ($team === 'b') {
            $state['timeouts_b']++;
        }
        break;
    case 'start_clock':
        if (!$state['clock_running']) {
            $state['clock_running'] = true;
            $state['clock_started_at'] = time();
        }
        break;
    case 'pause_clock':
        if ($state['clock_running']) {
            $state['clock_base_seconds'] = currentRemainingSeconds($state);
            $state['clock_running'] = false;
            $state['clock_started_at'] = null;
        }
        break;
    case 'reset_clock':
        $state['clock_base_seconds'] = $periodSeconds;
        $state['clock_running'] = false;
        $state['clock_started_at'] = null;
        break;
    case 'next_period':
        $state['period'] = min($periodsCount, $state['period'] + 1);
        $state['clock_base_seconds'] = $periodSeconds;
        $state['clock_running'] = false;
        $state['clock_started_at'] = null;
        break;
    case 'end_game':
        $stmt = $db->prepare('UPDATE matches SET status = "completed" WHERE id = :id');
        $stmt->execute(['id' => $matchId]);
        break;
    case 'refresh':
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
}

if ($action !== 'refresh') {
    // Only end_game actually decides a winner — computing it from the
    // in-progress score on every click would persist a "final" winner while
    // the game is still being played, and any future code reading this
    // column directly (without also checking matches.status) could be misled.
    $winnerTeamId = $scoreRow['winner_team_id'] ?? null;
    if ($action === 'end_game') {
        if ($teamAScore > $teamBScore) {
            $winnerTeamId = $match['team_a_id'];
        } elseif ($teamBScore > $teamAScore) {
            $winnerTeamId = $match['team_b_id'];
        } else {
            $winnerTeamId = null;
        }
    }

    $existing = $db->prepare('SELECT id FROM match_scores WHERE match_id = :mid');
    $existing->execute(['mid' => $matchId]);
    $stateJson = json_encode($state);

    if ($existing->fetch()) {
        $stmt = $db->prepare('UPDATE match_scores SET team_a_score = :a, team_b_score = :b, winner_team_id = :winner, score_detail = :detail, recorded_by = :by, recorded_at = NOW() WHERE match_id = :mid');
    } else {
        $stmt = $db->prepare('INSERT INTO match_scores (match_id, team_a_score, team_b_score, winner_team_id, score_detail, recorded_by) VALUES (:mid, :a, :b, :winner, :detail, :by)');
    }
    $stmt->execute(['a' => $teamAScore, 'b' => $teamBScore, 'winner' => $winnerTeamId, 'detail' => $stateJson, 'by' => $userId, 'mid' => $matchId]);

    Logger::log($userId, 'game_state_updated', 'Live game action "' . $action . '" on match #' . $matchId);
}

echo json_encode([
    'team_a_score' => $teamAScore,
    'team_b_score' => $teamBScore,
    'period' => $state['period'],
    'periods_count' => $periodsCount,
    'fouls_a' => $state['fouls_a'],
    'fouls_b' => $state['fouls_b'],
    'timeouts_a' => $state['timeouts_a'],
    'timeouts_b' => $state['timeouts_b'],
    'clock_running' => $state['clock_running'],
    'remaining_seconds' => currentRemainingSeconds($state),
]);
