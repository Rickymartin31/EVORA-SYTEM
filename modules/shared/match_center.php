<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

if ($eventId === 0) {
    // Event picker: organizer sees own sports-tournament events; staff sees
    // only events where they've been granted manage_live_scores.
    if ($role === 'organizer') {
        $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
        $orgStmt->execute(['uid' => $userId]);
        $organizer = $orgStmt->fetch();
        $eventsStmt = $db->prepare(
            "SELECT e.*, c.slug AS category_slug FROM events e
             JOIN event_categories c ON c.id = e.category_id
             WHERE e.organizer_id = :oid AND c.slug = 'sports-tournament' ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
    } else {
        $eventsStmt = $db->prepare(
            "SELECT e.*, c.slug AS category_slug FROM events e
             JOIN event_categories c ON c.id = e.category_id
             JOIN event_staff es ON es.event_id = e.id
             JOIN staff_permissions sp ON sp.event_staff_id = es.id
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_live_scores' AND c.slug = 'sports-tournament'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a sports tournament to run live matches and scores.';
    $pageTitle = 'Match Center';
    $activeRoute = 'match-center';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Match Center']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-broadcast', $role === 'organizer' ? 'No sports tournaments yet. Create a sports tournament event to run live matches here.' : 'No sports tournaments are assigned to you for live match control yet. Ask the organizer to grant the live scores permission.', $role === 'organizer' ? 'Create Event' : null, $role === 'organizer' ? url('organizer-event-type') : null) ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('match-center', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                    <span class="min-w-0"><span class="fw-semibold d-block"><?= e($ev['name']) ?></span>
                    <span class="small text-muted"><i class="bi bi-calendar-event" aria-hidden="true"></i> <?= date('M d, Y', strtotime($ev['event_date'])) ?></span></span>
                    <span class="badge badge-status-<?= e($ev['status']) ?>"><?= e(ucfirst($ev['status'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
    <?php
    exit;
}

// Scope check: organizer must own the event; staff must be assigned to it
// with the manage_live_scores permission.
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare(
        "SELECT e.*, s.name AS sport_name, s.scoring_method, s.sport_type FROM events e
         LEFT JOIN sports s ON s.id = e.sport_id
         WHERE e.id = :id AND e.organizer_id = :oid"
    );
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.*, s.name AS sport_name, s.scoring_method, s.sport_type FROM events e
         LEFT JOIN sports s ON s.id = e.sport_id
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

$matchesStmt = $db->prepare(
    'SELECT m.*, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
        sc.team_a_score, sc.team_b_score, sc.score_detail
     FROM matches m
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     LEFT JOIN match_scores sc ON sc.match_id = m.id
     WHERE m.event_id = :eid ORDER BY m.round_no, m.scheduled_at IS NULL, m.scheduled_at, m.id'
);
$matchesStmt->execute(['eid' => $eventId]);
$matches = $matchesStmt->fetchAll();

function opponentLabel(array $m, string $side): string
{
    if ($side === 'a') {
        return $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    }
    return $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
}

$liveMatches = array_values(array_filter($matches, fn ($m) => in_array($m['status'], ['ongoing', 'paused'], true)));
$upcomingMatches = array_values(array_filter($matches, fn ($m) => $m['status'] === 'scheduled'));
$finishedMatches = array_values(array_filter($matches, fn ($m) => $m['status'] === 'completed'));

$selectedMatch = null;
$matchId = (int) ($_GET['match_id'] ?? 0);
if ($matchId > 0) {
    foreach ($matches as $m) {
        if ((int) $m['id'] === $matchId) {
            $selectedMatch = $m;
            break;
        }
    }
}
$selectedDetail = [];
if ($selectedMatch && $selectedMatch['score_detail']) {
    $decoded = json_decode($selectedMatch['score_detail'], true);
    if (is_array($decoded)) {
        $selectedDetail = $decoded;
    }
}
$isSets = $event['scoring_method'] === 'sets';
$isTeamPoints = $event['sport_type'] === 'team' && $event['scoring_method'] === 'points';

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$statusLabels = ['scheduled' => 'Scheduled', 'ongoing' => 'Live', 'paused' => 'Paused', 'completed' => 'Finished', 'cancelled' => 'Cancelled'];

$pageDescription = 'Start, pause and finish matches, and keep live scores up to date.';
$pageActions = '<a href="' . url('match-hub', ['event_id' => $eventId]) . '" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-broadcast" aria-hidden="true"></i> Public Match Hub<span class="visually-hidden"> (opens in a new tab)</span></a>';
$pageTitle = 'Match Center';
$activeRoute = 'match-center';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Match Center', 'route' => 'match-center'],
    ['label' => $event['name']],
]); ?>

<?= moduleOverview('bi-broadcast', $event['name'], [$event['sport_name'] ?? 'Sport', $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null], [
    ['label' => 'Live Now', 'value' => count($liveMatches), 'tone' => 'danger'],
    ['label' => 'Upcoming', 'value' => count($upcomingMatches), 'tone' => 'info'],
    ['label' => 'Finished', 'value' => count($finishedMatches), 'tone' => 'success'],
    ['label' => 'Total Matches', 'value' => count($matches)],
]) ?>

<div class="row g-3">
    <div class="col-lg-<?= $selectedMatch ? '7' : '12' ?>">
        <?php if (empty($matches)): ?>
            <div class="card-evora p-4"><?= emptyState('bi-calendar-x', 'No matches scheduled yet. Matches appear here once the organizer schedules them on Matches & Scores.', $role === 'organizer' ? 'Schedule Matches' : null, $role === 'organizer' ? url('organizer-matches', ['event_id' => $eventId]) : null) ?></div>
        <?php else: ?>
        <?php foreach ([
            'Live Now' => [$liveMatches, 'No matches are live right now.', 'bi-broadcast'],
            'Upcoming' => [$upcomingMatches, 'No upcoming matches.', 'bi-clock'],
            'Finished' => [$finishedMatches, 'No finished matches yet.', 'bi-flag'],
        ] as $groupLabel => [$group, $emptyText, $groupIcon]): ?>
            <section class="card-evora p-4 mb-3" aria-label="<?= e($groupLabel) ?>">
                <h2 class="h6 fw-semibold mb-3">
                    <?php if ($groupLabel === 'Live Now' && $group): ?><span class="live-dot" aria-hidden="true"></span><?php else: ?><i class="bi <?= $groupIcon ?> text-primary me-1" aria-hidden="true"></i><?php endif; ?>
                    <?= e($groupLabel) ?> <span class="text-muted fw-normal small">(<?= count($group) ?>)</span>
                </h2>
                <?php if (empty($group)): ?>
                    <p class="text-muted small mb-0"><?= e($emptyText) ?></p>
                <?php else: ?>
                <div class="match-grid">
                    <?php foreach ($group as $m): ?>
                        <?= matchCard($m, [
                            'label' => 'Round ' . (int) $m['round_no'],
                            'labels' => $statusLabels,
                            'href' => url('match-center', ['event_id' => $eventId, 'match_id' => $m['id']]),
                            'current' => $selectedMatch && (int) $selectedMatch['id'] === (int) $m['id'],
                        ]) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($selectedMatch): ?>
    <?php $isLive = in_array($selectedMatch['status'], ['ongoing', 'paused'], true); ?>
    <div class="col-lg-5 order-first order-lg-last" id="match-control">
        <section class="card-evora p-4" aria-label="Match control">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <span class="match-no">Match #<?= (int) $selectedMatch['id'] ?> · Round <?= (int) $selectedMatch['round_no'] ?></span>
                <?= matchStatusBadge($selectedMatch['status'], $statusLabels) ?>
            </div>
            <div class="scoreboard mb-2<?= $selectedMatch['status'] === 'ongoing' ? ' is-live' : '' ?>">
                <div class="scoreboard-team">
                    <div class="scoreboard-name"><?= e(opponentLabel($selectedMatch, 'a')) ?></div>
                    <div class="scoreboard-score"><?= $selectedMatch['team_a_score'] !== null ? (int) $selectedMatch['team_a_score'] : '–' ?></div>
                </div>
                <div class="scoreboard-vs" aria-hidden="true">VS</div>
                <div class="scoreboard-team">
                    <div class="scoreboard-name"><?= e(opponentLabel($selectedMatch, 'b')) ?></div>
                    <div class="scoreboard-score"><?= $selectedMatch['team_b_score'] !== null ? (int) $selectedMatch['team_b_score'] : '–' ?></div>
                </div>
            </div>
            <p class="small text-muted mb-3"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= $selectedMatch['venue'] ? e($selectedMatch['venue']) : 'No venue set' ?><?= $selectedMatch['scheduled_at'] ? ' · ' . date('M d, h:i A', strtotime($selectedMatch['scheduled_at'])) : '' ?></p>

            <div class="action-group mb-3">
                <?php if ($selectedMatch['status'] === 'scheduled'): ?>
                    <form method="post" action="<?= url('match-center-action') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-play-fill" aria-hidden="true"></i> Start Match</button>
                    </form>
                <?php elseif ($selectedMatch['status'] === 'ongoing'): ?>
                    <form method="post" action="<?= url('match-center-action') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="pause">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-pause-fill" aria-hidden="true"></i> Pause</button>
                    </form>
                    <form method="post" action="<?= url('match-center-action') ?>" data-confirm="This records the current score as the final result and marks the match finished." data-confirm-title="Finish this match?" data-confirm-button="Finish Match" data-confirm-variant="success">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="finish">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-flag-fill" aria-hidden="true"></i> Finish</button>
                    </form>
                <?php elseif ($selectedMatch['status'] === 'paused'): ?>
                    <form method="post" action="<?= url('match-center-action') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="resume">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-play-fill" aria-hidden="true"></i> Resume</button>
                    </form>
                    <form method="post" action="<?= url('match-center-action') ?>" data-confirm="This records the current score as the final result and marks the match finished." data-confirm-title="Finish this match?" data-confirm-button="Finish Match" data-confirm-variant="success">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="finish">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-flag-fill" aria-hidden="true"></i> Finish</button>
                    </form>
                <?php else: ?>
                    <span class="text-muted small"><i class="bi bi-info-circle" aria-hidden="true"></i> This match is <?= e(strtolower($statusLabels[$selectedMatch['status']] ?? $selectedMatch['status'])) ?> — no further status changes.</span>
                <?php endif; ?>
            </div>

            <?php if ($isLive): ?>
                <?php if ($isSets): ?>
                    <h3 class="small fw-semibold text-uppercase text-muted">Record Set</h3>
                    <?php if (!empty($selectedDetail['sets'])): ?>
                        <p class="small">Sets so far:
                        <?php foreach ($selectedDetail['sets'] as $i => $s): ?>
                            <span class="badge bg-secondary font-tabular"><?= $s['a'] ?>-<?= $s['b'] ?></span>
                        <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <form method="post" action="<?= url('match-center-action') ?>" class="d-flex gap-2 align-items-end flex-wrap mb-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="record_set">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <div>
                            <label class="form-label small mb-0" for="setA"><?= e(opponentLabel($selectedMatch, 'a')) ?></label>
                            <input type="number" min="0" id="setA" name="set_score_a" class="form-control form-control-sm score-input" required>
                        </div>
                        <div>
                            <label class="form-label small mb-0" for="setB"><?= e(opponentLabel($selectedMatch, 'b')) ?></label>
                            <input type="number" min="0" id="setB" name="set_score_b" class="form-control form-control-sm score-input" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-evora-primary">Record Set</button>
                    </form>
                    <p class="small text-muted">Current sets won: <?= (int) $selectedMatch['team_a_score'] ?> - <?= (int) $selectedMatch['team_b_score'] ?></p>
                <?php else: ?>
                    <h3 class="small fw-semibold text-uppercase text-muted">Update Score</h3>
                    <form method="post" action="<?= url('match-center-action') ?>" class="d-flex gap-2 align-items-end flex-wrap mb-2">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="update_score">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                        <div>
                            <label class="form-label small mb-0" for="scoreA"><?= e(opponentLabel($selectedMatch, 'a')) ?></label>
                            <input type="number" id="scoreA" name="team_a_score" class="form-control form-control-sm score-input" value="<?= (int) $selectedMatch['team_a_score'] ?>" required>
                        </div>
                        <div>
                            <label class="form-label small mb-0" for="scoreB"><?= e(opponentLabel($selectedMatch, 'b')) ?></label>
                            <input type="number" id="scoreB" name="team_b_score" class="form-control form-control-sm score-input" value="<?= (int) $selectedMatch['team_b_score'] ?>" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-evora-primary">Update Score</button>
                    </form>
                    <?php if ($isTeamPoints && $role === 'organizer'): ?>
                        <a href="<?= url('organizer-game-controller', ['match_id' => $selectedMatch['id']]) ?>" class="small"><i class="bi bi-controller" aria-hidden="true"></i> Open Live Game Controller for clock/foul/period control</a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <h3 class="small fw-semibold text-uppercase text-muted mt-3">Match Notes</h3>
            <form method="post" action="<?= url('match-center-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_notes">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="match_id" value="<?= $selectedMatch['id'] ?>">
                <textarea name="notes" class="form-control form-control-sm mb-2" rows="2" aria-label="Match notes" placeholder="Optional notes (injuries, incidents, remarks)..."><?= e($selectedDetail['notes'] ?? '') ?></textarea>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Save Notes</button>
            </form>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
