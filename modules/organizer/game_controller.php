<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$matchId = (int) ($_GET['match_id'] ?? 0);
$stmt = $db->prepare(
    'SELECT m.*, e.id AS event_id, e.name AS event_name, e.periods_count, e.game_duration_minutes,
        s.name AS sport_name, s.scoring_method, s.sport_type,
        ta.team_name AS team_a_name, tb.team_name AS team_b_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     JOIN sports s ON s.id = m.sport_id
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     WHERE m.id = :id AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $matchId, 'oid' => $organizer['id'] ?? 0]);
$match = $stmt->fetch();

if (!$match) {
    flash('error', 'Match not found.');
    redirect('organizer-events');
}

if ($match['sport_type'] !== 'team' || $match['scoring_method'] !== 'points') {
    flash('error', 'The Live Game Controller is only available for team sports scored by points.');
    redirect('organizer-matches', ['event_id' => $match['event_id']]);
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
if ($scoreRow && $scoreRow['score_detail']) {
    $decoded = json_decode($scoreRow['score_detail'], true);
    if (is_array($decoded)) {
        $state = array_merge($defaultState, $decoded);
    }
}

$teamAScore = (int) ($scoreRow['team_a_score'] ?? 0);
$teamBScore = (int) ($scoreRow['team_b_score'] ?? 0);

$pageDescription = 'Run the clock, periods, fouls and timeouts while the score updates live.';
$pageActions = '<a href="' . url('live-scoreboard', ['match_id' => $matchId]) . '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-broadcast" aria-hidden="true"></i> Public Scoreboard<span class="visually-hidden"> (opens in a new tab)</span></a>';
$pageTitle = 'Live Game Controller';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $match['event_name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $match['event_id']]],
    ['label' => 'Matches & Scores', 'route' => 'organizer-matches', 'params' => ['event_id' => $match['event_id']]],
    ['label' => 'Live Game Controller'],
]); ?>

<section class="card-evora p-4 mb-3" id="gcRoot" aria-label="Live game"
     data-match-id="<?= $matchId ?>" data-event-id="<?= $match['event_id'] ?>"
     data-periods-count="<?= $periodsCount ?>" data-period-seconds="<?= $periodSeconds ?>"
     data-csrf="<?= e(Csrf::token()) ?>" data-action-url="<?= url('organizer-game-state') ?>"
     data-matches-url="<?= url('organizer-matches', ['event_id' => $match['event_id']]) ?>">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <span class="match-no">Match #<?= (int) $matchId ?> · <?= e($match['sport_name']) ?> · Round <?= (int) $match['round_no'] ?></span>
        <span class="badge badge-status-<?= $match['status'] === 'ongoing' ? 'live' : ($match['status'] === 'paused' ? 'paused' : ($match['status'] === 'completed' ? 'completed' : 'scheduled')) ?>"><?= e(['ongoing' => 'Live', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$match['status']] ?? 'Scheduled') ?></span>
    </div>
    <div class="scoreboard scoreboard-lg<?= $match['status'] === 'ongoing' ? ' is-live' : '' ?>">
        <div class="scoreboard-team">
            <div class="scoreboard-name"><?= e($match['team_a_name'] ?? 'Team A') ?></div>
            <div class="scoreboard-score" id="gcScoreA" aria-live="polite"><?= $teamAScore ?></div>
            <div class="scoreboard-sub">Fouls <span id="gcFoulsA" class="fw-semibold"><?= $state['fouls_a'] ?></span> · Timeouts <span id="gcTimeoutsA" class="fw-semibold"><?= $state['timeouts_a'] ?></span></div>
        </div>
        <div class="text-center align-self-center">
            <div class="fs-2 fw-bold font-tabular" id="gcClock" aria-label="Game clock">--:--</div>
            <div class="text-muted small">Period <span id="gcPeriod"><?= $state['period'] ?> / <?= $periodsCount ?></span></div>
        </div>
        <div class="scoreboard-team">
            <div class="scoreboard-name"><?= e($match['team_b_name'] ?? 'Team B') ?></div>
            <div class="scoreboard-score" id="gcScoreB" aria-live="polite"><?= $teamBScore ?></div>
            <div class="scoreboard-sub">Fouls <span id="gcFoulsB" class="fw-semibold"><?= $state['fouls_b'] ?></span> · Timeouts <span id="gcTimeoutsB" class="fw-semibold"><?= $state['timeouts_b'] ?></span></div>
        </div>
    </div>
    <div class="action-group justify-content-center mt-3" aria-label="Clock controls">
        <button type="button" class="btn btn-sm btn-evora-primary" id="gcStart"><i class="bi bi-play-fill" aria-hidden="true"></i> Start Clock</button>
        <button type="button" class="btn btn-sm btn-outline-warning" id="gcPause"><i class="bi bi-pause-fill" aria-hidden="true"></i> Pause</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="gcNextPeriod">Next Period <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
        <span class="action-divider" aria-hidden="true"></span>
        <button type="button" class="btn btn-sm btn-outline-danger" id="gcReset"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset Clock</button>
    </div>
</section>

<div class="row g-3">
    <?php foreach (['a' => $match['team_a_name'] ?? 'Team A', 'b' => $match['team_b_name'] ?? 'Team B'] as $side => $teamName): ?>
    <div class="col-md-6">
        <section class="card-evora p-4 text-center h-100" aria-label="<?= e($teamName) ?> controls">
            <h2 class="h6 fw-semibold mb-3"><?= e($teamName) ?></h2>
            <div class="d-flex gap-2 justify-content-center mb-2" role="group" aria-label="Add points for <?= e($teamName) ?>">
                <?php foreach ([1, 2, 3] as $pts): ?>
                    <button type="button" class="btn btn-evora-primary gc-points" data-team="<?= $side ?>" data-points="<?= $pts ?>" aria-label="Add <?= $pts ?> point<?= $pts > 1 ? 's' : '' ?> to <?= e($teamName) ?>">+<?= $pts ?></button>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-sm btn-outline-secondary gc-foul" data-team="<?= $side ?>" aria-label="Add a foul for <?= e($teamName) ?>">+ Foul</button>
                <button type="button" class="btn btn-sm btn-outline-secondary gc-timeout" data-team="<?= $side ?>" aria-label="Use a timeout for <?= e($teamName) ?>">Timeout</button>
            </div>
        </section>
    </div>
    <?php endforeach; ?>
</div>

<div class="card-evora p-4 mt-3">
    <div class="action-group justify-content-between">
        <a href="<?= url('organizer-matches', ['event_id' => $match['event_id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Matches</a>
        <form method="post" action="<?= url('organizer-matches') ?>" data-no-spinner>
            <button type="button" class="btn btn-evora-primary btn-sm" id="gcEndGame"><i class="bi bi-flag-fill" aria-hidden="true"></i> End Game &amp; Record Final Score</button>
        </form>
    </div>
</div>

<script src="<?= assetUrl('js/game_controller.js') ?>"></script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
