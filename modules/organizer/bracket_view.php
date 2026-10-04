<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, s.scoring_method FROM events e LEFT JOIN sports s ON s.id = e.sport_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$bracketStmt = $db->prepare(
    'SELECT b.round_no, b.position, m.*,
        ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
        sc.team_a_score, sc.team_b_score, sc.winner_team_id, sc.winner_participant_id
     FROM tournament_brackets b
     JOIN matches m ON m.id = b.match_id
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     LEFT JOIN match_scores sc ON sc.match_id = m.id
     WHERE b.event_id = :eid
     ORDER BY b.round_no, b.position'
);
$bracketStmt->execute(['eid' => $eventId]);
$rows = $bracketStmt->fetchAll();

$rounds = [];
$standings = [];
foreach ($rows as $row) {
    $rounds[$row['round_no']][] = $row;

    // Key standings by a namespaced ID (team/participant), not display name —
    // two entrants with the same name would otherwise have their records
    // silently merged into one row.
    $aKey = $row['team_a_id'] ? 't' . $row['team_a_id'] : ($row['participant_a_id'] ? 'p' . $row['participant_a_id'] : null);
    $bKey = $row['team_b_id'] ? 't' . $row['team_b_id'] : ($row['participant_b_id'] ? 'p' . $row['participant_b_id'] : null);
    $aName = $row['team_a_name'] ?? $row['participant_a_name'];
    $bName = $row['team_b_name'] ?? $row['participant_b_name'];
    foreach ([[$aKey, $aName], [$bKey, $bName]] as [$key, $name]) {
        if ($key && !isset($standings[$key])) {
            $standings[$key] = ['name' => $name, 'wins' => 0, 'losses' => 0, 'draws' => 0];
        }
    }
    if ($row['status'] === 'completed' && $aKey && $bKey) {
        if ($row['winner_team_id'] || $row['winner_participant_id']) {
            $winnerKey = null;
            $loserKey = null;
            if ($row['winner_team_id']) {
                $winnerKey = $row['winner_team_id'] == $row['team_a_id'] ? $aKey : $bKey;
                $loserKey = $row['winner_team_id'] == $row['team_a_id'] ? $bKey : $aKey;
            } elseif ($row['winner_participant_id']) {
                $winnerKey = $row['winner_participant_id'] == $row['participant_a_id'] ? $aKey : $bKey;
                $loserKey = $row['winner_participant_id'] == $row['participant_a_id'] ? $bKey : $aKey;
            }
            if ($winnerKey) {
                $standings[$winnerKey]['wins']++;
            }
            if ($loserKey) {
                $standings[$loserKey]['losses']++;
            }
        } elseif ($row['team_a_score'] !== null) {
            // Completed with no winner set — a genuine draw (common for
            // win/draw/loss sports like Chess), not just an unplayed match.
            $standings[$aKey]['draws']++;
            $standings[$bKey]['draws']++;
        }
    }
}
ksort($rounds);
uasort($standings, fn ($a, $b) => ($b['wins'] * 2 + $b['draws']) <=> ($a['wins'] * 2 + $a['draws']));

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$roundLabels = bracketRoundLabels($rounds, $event['tournament_format'] ?? null);
$statusCounts = array_count_values(array_column($rows, 'status'));
$formatNames = ['single_elim' => 'Single Elimination', 'double_elim' => 'Double Elimination', 'round_robin' => 'Round Robin', 'league' => 'League / Ranking'];

$pageDescription = 'Follow every round, see who advanced and check the standings.';
$pageActions = '<a href="' . url('organizer-matches', ['event_id' => $eventId]) . '" class="btn btn-evora-primary btn-sm"><i class="bi bi-trophy" aria-hidden="true"></i> Matches &amp; Scores</a>';
$pageTitle = 'Tournament Bracket';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Bracket'],
]); ?>


<?= moduleOverview('bi-trophy', $event['name'], [
    $formatNames[$event['tournament_format'] ?? ''] ?? null,
    $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null,
    $event['venue'] ?? null,
], [
    ['label' => 'Entrants', 'value' => count($standings)],
    ['label' => 'Matches', 'value' => count($rows)],
    ['label' => 'Completed', 'value' => $statusCounts['completed'] ?? 0, 'tone' => 'success'],
    ['label' => 'Live', 'value' => ($statusCounts['ongoing'] ?? 0) + ($statusCounts['paused'] ?? 0), 'tone' => 'danger'],
    ['label' => 'Upcoming', 'value' => $statusCounts['scheduled'] ?? 0, 'tone' => 'info'],
], '<span class="badge badge-status-' . e($event['status']) . '">' . e(ucfirst($event['status'])) . '</span>') ?>

<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-diagram-3 text-primary me-1" aria-hidden="true"></i> Tournament Bracket</h6>
        <?php if (!empty($rounds)): ?><span class="small text-muted"><i class="bi bi-trophy-fill match-winner-icon" aria-hidden="true"></i> marks the recorded winner</span><?php endif; ?>
    </div>
    <?php if (empty($rounds)): ?>
        <?= emptyState('bi-diagram-3', 'No matches scheduled yet. The bracket fills in as you schedule matches for your teams or players.', 'Schedule Matches', url('organizer-matches', ['event_id' => $eventId])) ?>
    <?php else: ?>
    <div class="bracket" role="list" aria-label="Tournament rounds (scroll sideways for later rounds)" tabindex="0">
        <?php $lastRound = max(array_keys($rounds)); ?>
        <?php foreach ($rounds as $roundNo => $matches): ?>
            <section class="bracket-round<?= $roundNo === $lastRound ? ' is-final' : '' ?>" role="listitem" aria-label="<?= e($roundLabels[$roundNo]) ?>">
                <div class="bracket-round-head">
                    <div class="bracket-round-name"><?= e($roundLabels[$roundNo]) ?></div>
                    <div class="bracket-round-count"><?= count($matches) ?> match<?= count($matches) === 1 ? '' : 'es' ?></div>
                </div>
                <div class="bracket-round-body">
                    <?php foreach ($matches as $m): ?>
                        <?= matchCard($m, ['showMeta' => false]) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($standings)): ?>
<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-bar-chart-steps text-primary me-1" aria-hidden="true"></i> Standings</h6>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th scope="col">Rank</th><th scope="col">Team / Player</th>
                <th scope="col" class="num">Played</th><th scope="col" class="num">Wins</th><th scope="col" class="num">Draws</th><th scope="col" class="num">Losses</th>
                <th scope="col" class="num"><abbr title="Ranking points: 2 per win, 1 per draw" data-bs-toggle="tooltip">Pts</abbr></th>
            </tr></thead>
            <tbody>
            <?php $rank = 1; foreach ($standings as $rec): ?>
                <tr>
                    <td><span class="rank-badge<?= $rank <= 3 ? ' rank-' . $rank : '' ?>"><?= $rank ?></span></td>
                    <td class="fw-semibold"><?= e($rec['name']) ?></td>
                    <td class="num"><?= $rec['wins'] + $rec['draws'] + $rec['losses'] ?></td>
                    <td class="num"><?= $rec['wins'] ?></td>
                    <td class="num"><?= $rec['draws'] ?></td>
                    <td class="num"><?= $rec['losses'] ?></td>
                    <td class="num num-strong"><?= $rec['wins'] * 2 + $rec['draws'] ?></td>
                </tr>
            <?php $rank++; endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
