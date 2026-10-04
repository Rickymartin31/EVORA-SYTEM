<?php
// Public, read-only match hub for one event. No Auth check by design (same
// precedent as certificate_verify.php / event_view.php) — no POST accepted.
$db = Database::connection();

$eventId = (int) ($_GET['event_id'] ?? 0);

$eventStmt = $db->prepare('SELECT e.*, s.name AS sport_name FROM events e LEFT JOIN sports s ON s.id = e.sport_id WHERE e.id = :id');
$eventStmt->execute(['id' => $eventId]);
$event = $eventStmt->fetch();

$matches = [];
if ($event) {
    $matchesStmt = $db->prepare(
        'SELECT m.*, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
            pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
            sc.team_a_score, sc.team_b_score
         FROM matches m
         LEFT JOIN teams ta ON ta.id = m.team_a_id
         LEFT JOIN teams tb ON tb.id = m.team_b_id
         LEFT JOIN participants pa ON pa.id = m.participant_a_id
         LEFT JOIN users pau ON pau.id = pa.user_id
         LEFT JOIN participants pb ON pb.id = m.participant_b_id
         LEFT JOIN users pbu ON pbu.id = pb.user_id
         LEFT JOIN match_scores sc ON sc.match_id = m.id
         WHERE m.event_id = :eid ORDER BY m.round_no, m.scheduled_at IS NULL, m.scheduled_at, m.id
         LIMIT 200'
    );
    $matchesStmt->execute(['eid' => $eventId]);
    $matches = $matchesStmt->fetchAll();
}

function opponentLabel(array $m, string $side): string
{
    if ($side === 'a') {
        return $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    }
    return $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
}

$liveMatches = array_values(array_filter($matches, fn ($m) => in_array($m['status'], ['ongoing', 'paused'], true)));
$upcomingMatches = array_slice(array_values(array_filter($matches, fn ($m) => $m['status'] === 'scheduled')), 0, 20);
$finishedMatches = array_slice(array_reverse(array_values(array_filter($matches, fn ($m) => $m['status'] === 'completed'))), 0, 20);

$pageTitle = $event ? $event['name'] . ' — Match Hub' : 'Match Hub';
require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$hubLabels = ['scheduled' => 'Upcoming', 'ongoing' => 'Live', 'paused' => 'Paused', 'completed' => 'Final', 'cancelled' => 'Cancelled'];
// Same rule the hub always used for final results: the higher score is shown as the winner.
$scoreWinner = function (array $m): ?string {
    $aScore = $m['team_a_score'] !== null ? (int) $m['team_a_score'] : 0;
    $bScore = $m['team_b_score'] !== null ? (int) $m['team_b_score'] : 0;
    return $aScore > $bScore ? 'a' : ($bScore > $aScore ? 'b' : null);
};
include EVORA_ROOT . '/modules/shared/partials/public_head.php';
?>
<?php if (!$event): ?>
    <div class="card-evora p-4 text-center">
        <i class="bi bi-broadcast text-muted icon-xl" aria-hidden="true"></i>
        <h1 class="h5 fw-bold mt-2">Event Not Found</h1>
        <p class="text-muted mb-0">This match hub link doesn't match any event.</p>
    </div>
<?php else: ?>
    <?= moduleOverview('bi-broadcast', $event['name'], [$event['sport_name'] ?? 'Sports Tournament', 'Match Hub · updates every 20 seconds'], [
        ['label' => 'Live Now', 'value' => count($liveMatches), 'tone' => 'danger'],
        ['label' => 'Next Games', 'value' => count($upcomingMatches), 'tone' => 'info'],
        ['label' => 'Final Results', 'value' => count($finishedMatches), 'tone' => 'success'],
    ]) ?>

    <section class="card-evora p-4 mb-3" aria-label="Now playing">
        <h2 class="h6 fw-semibold mb-3"><?php if ($liveMatches): ?><span class="live-dot" aria-hidden="true"></span><?php else: ?><i class="bi bi-broadcast text-primary me-1" aria-hidden="true"></i><?php endif; ?>Now Playing</h2>
        <?php if (empty($liveMatches)): ?>
            <div class="empty-state empty-state-sm"><i class="bi bi-broadcast" aria-hidden="true"></i><p>No matches are live right now. Check the next games below.</p></div>
        <?php else: ?>
        <div class="match-grid">
            <?php foreach ($liveMatches as $m): ?>
                <?= matchCard($m, ['label' => 'Round ' . (int) $m['round_no'], 'labels' => $hubLabels, 'href' => url('live-scoreboard', ['match_id' => $m['id']])]) ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="row g-3">
        <div class="col-md-6">
            <section class="card-evora p-4 h-100" aria-label="Next games">
                <h2 class="h6 fw-semibold mb-3"><i class="bi bi-clock text-primary me-1" aria-hidden="true"></i> Next Games</h2>
                <?php if (empty($upcomingMatches)): ?>
                    <div class="empty-state empty-state-sm"><i class="bi bi-calendar2-week" aria-hidden="true"></i><p>No upcoming matches scheduled.</p></div>
                <?php else: ?>
                    <div class="d-grid gap-2">
                    <?php foreach ($upcomingMatches as $m): ?>
                        <?= matchCard($m, ['label' => 'Round ' . (int) $m['round_no'], 'labels' => $hubLabels, 'href' => url('live-scoreboard', ['match_id' => $m['id']])]) ?>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-md-6">
            <section class="card-evora p-4 h-100" aria-label="Final results">
                <h2 class="h6 fw-semibold mb-3"><i class="bi bi-check-circle text-primary me-1" aria-hidden="true"></i> Final Results</h2>
                <?php if (empty($finishedMatches)): ?>
                    <div class="empty-state empty-state-sm"><i class="bi bi-flag" aria-hidden="true"></i><p>No finished matches yet.</p></div>
                <?php else: ?>
                    <div class="d-grid gap-2">
                    <?php foreach ($finishedMatches as $m): ?>
                        <?= matchCard($m, ['label' => 'Round ' . (int) $m['round_no'], 'labels' => $hubLabels, 'winner' => $scoreWinner($m), 'showMeta' => false, 'href' => url('live-scoreboard', ['match_id' => $m['id']])]) ?>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
<script>setTimeout(function () { window.location.reload(); }, 20000);</script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/public_foot.php'; ?>
