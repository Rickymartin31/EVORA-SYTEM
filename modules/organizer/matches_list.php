<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, s.name AS sport_name, s.sport_type, s.team_size, s.scoring_method, s.default_tournament_format
     FROM events e LEFT JOIN sports s ON s.id = e.sport_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

if (empty($event['sport_id'])) {
    flash('error', 'This event has no sport selected. Edit the event and choose a sport first.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

$usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;
$isLiveGameSport = $event['sport_type'] === 'team' && $event['scoring_method'] === 'points';

$scoreLabels = [
    'points' => 'Points', 'sets' => 'Sets Won', 'rounds' => 'Rounds Won',
    'win_draw_loss' => 'Points (Win=1, Draw=0.5, Loss=0)', 'time_rank' => 'Time (seconds, lower wins)',
];
$scoreLabel = $scoreLabels[$event['scoring_method']] ?? 'Score';
$lowerWins = $event['scoring_method'] === 'time_rank';

$teamsStmt = $db->prepare('SELECT * FROM teams WHERE event_id = :eid ORDER BY team_name');
$teamsStmt->execute(['eid' => $eventId]);
$teams = $teamsStmt->fetchAll();

$participantsStmt = $db->prepare(
    'SELECT p.id, u.full_name FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid AND r.status = "approved" ORDER BY u.full_name'
);
$participantsStmt->execute(['eid' => $eventId]);
$participants = $participantsStmt->fetchAll();

$staffStmt = $db->prepare(
    'SELECT es.id, u.full_name FROM event_staff es JOIN users u ON u.id = es.user_id WHERE es.event_id = :eid ORDER BY u.full_name'
);
$staffStmt->execute(['eid' => $eventId]);
$staffPool = $staffStmt->fetchAll();

$matchesStmt = $db->prepare(
    'SELECT m.*, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
        ref.id AS referee_id, refu.full_name AS referee_name,
        sc.team_a_score, sc.team_b_score, sc.winner_team_id, sc.winner_participant_id
     FROM matches m
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     LEFT JOIN event_staff ref ON ref.id = m.referee_staff_id
     LEFT JOIN users refu ON refu.id = ref.user_id
     LEFT JOIN match_scores sc ON sc.match_id = m.id
     WHERE m.event_id = :eid ORDER BY m.round_no, m.scheduled_at'
);
$matchesStmt->execute(['eid' => $eventId]);
$matches = $matchesStmt->fetchAll();

$rounds = [];
foreach ($matches as $m) {
    $rounds[(int) $m['round_no']][] = $m;
}
$maxRound = $rounds ? max(array_keys($rounds)) : 0;
$maxRoundComplete = $maxRound > 0 && !array_filter($rounds[$maxRound], fn ($m) => $m['status'] !== 'completed');

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$statusCounts = array_count_values(array_column($matches, 'status'));
$formatNames = ['single_elim' => 'Single Elimination', 'double_elim' => 'Double Elimination', 'round_robin' => 'Round Robin', 'league' => 'League / Ranking'];

$pageDescription = 'Schedule matches, record scores and advance winners to the next round.';
$scheduleOpen = empty($matches);
$pageActions = '<a href="' . url('organizer-bracket', ['event_id' => $eventId]) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-diagram-3" aria-hidden="true"></i> View Bracket</a>'
    . '<button type="button" class="btn btn-evora-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#scheduleMatchPanel" aria-expanded="' . ($scheduleOpen ? 'true' : 'false') . '" aria-controls="scheduleMatchPanel"><i class="bi bi-plus-lg" aria-hidden="true"></i> Schedule Match</button>';
$pageTitle = 'Matches & Scores';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';

function opponentLabel(array $m, string $side): string
{
    if ($side === 'a') {
        return $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    }
    return $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
}
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Matches & Scores'],
]); ?>

<?= moduleOverview('bi-trophy', $event['name'], [
    $event['sport_name'],
    $formatNames[$event['tournament_format'] ?? ''] ?? null,
    'Scored by ' . str_replace('_', ' ', $event['scoring_method']),
], [
    ['label' => $usesTeams ? 'Teams' : 'Players', 'value' => $usesTeams ? count($teams) : count($participants)],
    ['label' => 'Matches', 'value' => count($matches)],
    ['label' => 'Completed', 'value' => $statusCounts['completed'] ?? 0, 'tone' => 'success'],
    ['label' => 'Live', 'value' => ($statusCounts['ongoing'] ?? 0) + ($statusCounts['paused'] ?? 0), 'tone' => 'danger'],
    ['label' => 'Upcoming', 'value' => $statusCounts['scheduled'] ?? 0, 'tone' => 'info'],
], '<span class="badge badge-status-' . e($event['status']) . '">' . e(ucfirst($event['status'])) . '</span>') ?>

<div class="card-evora p-3 mb-3">
    <div class="action-group">
        <form method="post" action="<?= url('organizer-match-save') ?>" data-confirm="Pair up all current teams/participants sequentially into Round 1 matches?" data-confirm-title="Generate Round 1?" data-confirm-button="Generate Round 1">
            <?= Csrf::field() ?>
            <input type="hidden" name="match_action" value="auto_generate_round1">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <button type="submit" class="btn btn-sm <?= $maxRound > 0 ? 'btn-outline-secondary' : 'btn-evora-primary' ?>" <?= $maxRound > 0 ? 'disabled title="Round 1 already exists"' : '' ?>><i class="bi bi-magic" aria-hidden="true"></i> Auto-Generate Round 1</button>
        </form>
        <?php if ($maxRound > 0 && $maxRoundComplete && count($rounds[$maxRound]) > 1): ?>
        <form method="post" action="<?= url('organizer-match-save') ?>" data-confirm="Create the next round by pairing up the winners of round <?= $maxRound ?>?" data-confirm-title="Advance winners?" data-confirm-button="Create Round <?= $maxRound + 1 ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="match_action" value="advance_round">
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="from_round" value="<?= $maxRound ?>">
            <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> Advance Winners to Round <?= $maxRound + 1 ?></button>
        </form>
        <?php elseif ($maxRound > 0): ?>
        <span class="small text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Winners can advance once every Round <?= $maxRound ?> match is completed.</span>
        <?php endif; ?>
    </div>
</div>

<div class="collapse mb-3<?= $scheduleOpen ? ' show' : '' ?>" id="scheduleMatchPanel">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Schedule Match</h6>
            <?php if ($usesTeams && count($teams) < 2): ?>
                <?= emptyState('bi-people', 'Create at least 2 teams before scheduling a match.', 'Manage Teams', url('organizer-teams', ['event_id' => $eventId])) ?>
            <?php elseif (!$usesTeams && count($participants) < 2): ?>
                <?= emptyState('bi-person-check', 'You need at least 2 approved participants before scheduling a match.', 'Review Registrations', url('organizer-registrations')) ?>
            <?php else: ?>
            <form method="post" action="<?= url('organizer-match-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="match_action" value="create_match">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="row g-3">
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mRound">Round Number *</label>
                    <input type="number" id="mRound" name="round_no" min="1" value="<?= $maxRound + 1 ?>" class="form-control form-control-sm" required>
                </div>
                <?php if ($usesTeams): ?>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mTeamA">Team A</label>
                    <select id="mTeamA" name="team_a_id" class="form-select form-select-sm">
                        <option value="">TBD</option>
                        <?php foreach ($teams as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['team_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mTeamB">Team B</label>
                    <select id="mTeamB" name="team_b_id" class="form-select form-select-sm">
                        <option value="">TBD</option>
                        <?php foreach ($teams as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['team_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mPartA">Participant A</label>
                    <select id="mPartA" name="participant_a_id" class="form-select form-select-sm">
                        <option value="">TBD</option>
                        <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mPartB">Participant B</label>
                    <select id="mPartB" name="participant_b_id" class="form-select form-select-sm">
                        <option value="">TBD</option>
                        <?php foreach ($participants as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mReferee">Referee / Staff</label>
                    <select id="mReferee" name="referee_staff_id" class="form-select form-select-sm">
                        <option value="">Unassigned</option>
                        <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mSchedule">Schedule</label>
                    <input type="datetime-local" id="mSchedule" name="scheduled_at" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small" for="mVenue">Venue</label>
                    <input type="text" id="mVenue" name="venue" class="form-control form-control-sm" value="<?= e($event['venue']) ?>">
                </div>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-evora-primary btn-sm">Schedule Match</button></div>
            </form>
            <?php endif; ?>
        </div>
</div>

        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Match Schedule</h6>
            <?php if (empty($matches)): ?>
                <?= emptyState('bi-trophy', 'No matches scheduled yet. Create your first match with Schedule Match, or auto-generate Round 1 from your ' . ($usesTeams ? 'teams' : 'players') . '.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th scope="col">Match</th><th scope="col">Matchup</th><th scope="col">Schedule</th><th scope="col"><?= e($scoreLabel) ?></th><th scope="col">Status</th><?php if ($isLiveGameSport): ?><th scope="col">Live</th><?php endif; ?><th scope="col">Update Score</th></tr></thead>
                    <tbody>
                    <?php foreach ($matches as $m): ?>
                        <?php $winner = matchWinnerSide($m); ?>
                        <tr>
                            <td data-order="<?= (int) $m['round_no'] * 100000 + (int) $m['id'] ?>"><span class="fw-semibold">#<?= (int) $m['id'] ?></span><div class="small text-muted">Round <?= $m['round_no'] ?></div></td>
                            <td>
                                <div class="<?= $winner === 'a' ? 'fw-bold' : '' ?>"><?= e(opponentLabel($m, 'a')) ?><?php if ($winner === 'a'): ?> <i class="bi bi-trophy-fill match-winner-icon" aria-hidden="true"></i><span class="visually-hidden">(winner)</span><?php endif; ?></div>
                                <div class="small text-muted">vs</div>
                                <div class="<?= $winner === 'b' ? 'fw-bold' : '' ?>"><?= e(opponentLabel($m, 'b')) ?><?php if ($winner === 'b'): ?> <i class="bi bi-trophy-fill match-winner-icon" aria-hidden="true"></i><span class="visually-hidden">(winner)</span><?php endif; ?></div>
                            </td>
                            <td class="small"><span class="nowrap"><?= $m['scheduled_at'] ? date('M d, h:i A', strtotime($m['scheduled_at'])) : '—' ?></span><?php if ($m['venue']): ?><div class="text-muted"><?= e($m['venue']) ?></div><?php endif; ?><?php if ($m['referee_name']): ?><div class="text-muted"><i class="bi bi-person-badge" aria-hidden="true"></i> <?= e($m['referee_name']) ?></div><?php endif; ?></td>
                            <td class="fw-bold nowrap font-tabular"><?= $m['team_a_score'] !== null ? $m['team_a_score'] . ' – ' . $m['team_b_score'] : '<span class="text-muted fw-normal">—</span>' ?></td>
                            <td><?= matchStatusBadge($m['status']) ?></td>
                            <?php if ($isLiveGameSport): ?>
                            <td>
                                <?php if (in_array($m['status'], ['scheduled', 'ongoing'], true) && $m['team_a_id'] && $m['team_b_id']): ?>
                                    <a href="<?= url('organizer-game-controller', ['match_id' => $m['id']]) ?>" class="btn btn-sm btn-outline-secondary nowrap"><i class="bi bi-controller" aria-hidden="true"></i> Controller</a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <form method="post" action="<?= url('organizer-match-save') ?>" class="d-flex gap-1 align-items-center">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="match_action" value="record_score">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="match_id" value="<?= $m['id'] ?>">
                                    <input type="number" step="0.01" name="team_a_score" class="form-control form-control-sm score-input" value="<?= $m['team_a_score'] ?? 0 ?>" aria-label="<?= e($scoreLabel . ' for ' . opponentLabel($m, 'a')) ?>">
                                    <input type="number" step="0.01" name="team_b_score" class="form-control form-control-sm score-input" value="<?= $m['team_b_score'] ?? 0 ?>" aria-label="<?= e($scoreLabel . ' for ' . opponentLabel($m, 'b')) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
