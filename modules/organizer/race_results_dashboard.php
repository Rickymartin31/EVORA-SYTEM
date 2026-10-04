<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

$approvedStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$approvedStmt->execute(['id' => $eventId]);
$approvedCount = (int) $approvedStmt->fetch()['c'];

$checkedInStmt = $db->prepare(
    'SELECT COUNT(DISTINCT a.registration_id) c FROM attendance a JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :id AND r.status = "approved"'
);
$checkedInStmt->execute(['id' => $eventId]);
$checkedInCount = (int) $checkedInStmt->fetch()['c'];

$finishersStmt = $db->prepare(
    "SELECT rr.finish_time, r.distance_category, r.bib_number, u.full_name
     FROM race_results rr
     JOIN registrations r ON r.id = rr.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :id AND r.status = 'approved'
     ORDER BY rr.finish_time"
);
$finishersStmt->execute(['id' => $eventId]);
$finishers = $finishersStmt->fetchAll();
$finisherCount = count($finishers);

$noShowCount = max(0, $approvedCount - $checkedInCount);
$dnfCount = max(0, $checkedInCount - $finisherCount);

$gunStart = strtotime($event['event_date'] . ' ' . $event['event_time']);
$durations = [];
foreach ($finishers as $f) {
    $finishTs = strtotime($f['finish_time']);
    if ($finishTs >= $gunStart) {
        $durations[] = $finishTs - $gunStart;
    }
}
$avgDurationSeconds = !empty($durations) ? (int) round(array_sum($durations) / count($durations)) : null;
$fastestRunner = $finishers[0] ?? null;

function formatDuration(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

// Per-category breakdown, same reuse pattern as ReportQueries::eventAnalytics().
$categoryStmt = $db->prepare(
    "SELECT rc.id, rc.name,
        COUNT(DISTINCT r.id) AS approved_count,
        COUNT(DISTINCT a.registration_id) AS checked_in_count,
        COUNT(DISTINCT rr.registration_id) AS finisher_count
     FROM race_categories rc
     LEFT JOIN registrations r ON r.race_category_id = rc.id AND r.status = 'approved'
     LEFT JOIN attendance a ON a.registration_id = r.id
     LEFT JOIN race_results rr ON rr.registration_id = r.id
     WHERE rc.event_id = :id
     GROUP BY rc.id, rc.name
     ORDER BY rc.name"
);
$categoryStmt->execute(['id' => $eventId]);
$categoryBreakdown = [];
foreach ($categoryStmt->fetchAll() as $row) {
    $categoryBreakdown[] = [
        'name' => $row['name'],
        'approved' => (int) $row['approved_count'],
        'finishers' => (int) $row['finisher_count'],
        'dnf' => max(0, (int) $row['checked_in_count'] - (int) $row['finisher_count']),
        'no_show' => max(0, (int) $row['approved_count'] - (int) $row['checked_in_count']),
    ];
}

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$pageDescription = 'Race outcome at a glance: finishers, DNF, no-shows, fastest time and per-category results.';
$pageActions = '<a href="' . url('organizer-race-results', ['event_id' => $eventId]) . '" class="btn btn-evora-primary btn-sm"><i class="bi bi-list-ol" aria-hidden="true"></i> Full Results</a>';
$pageTitle = 'Race Results Dashboard';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Race Results Dashboard'],
]); ?>

<?= moduleOverview('bi-flag', $event['name'], [
    $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null,
    $event['venue'] ?? null,
], [
    ['label' => 'Approved Runners', 'value' => $approvedCount],
    ['label' => 'Finishers', 'value' => $finisherCount, 'tone' => 'success'],
    ['label' => 'DNF', 'value' => $dnfCount, 'tone' => 'warning', 'hint' => 'Checked in, no finish'],
    ['label' => 'No Show', 'value' => $noShowCount, 'tone' => 'danger', 'hint' => 'Never checked in'],
    ['label' => 'Average Time', 'value' => formatDuration($avgDurationSeconds)],
], '<span class="badge badge-status-' . e($event['status']) . '">' . e(ucfirst($event['status'])) . '</span>') ?>

<div class="row g-3">
    <div class="col-lg-4">
        <section class="card-evora p-4 h-100" aria-label="Fastest runner">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-lightning-charge text-primary me-1" aria-hidden="true"></i> Fastest Runner</h2>
            <?php if (!$fastestRunner): ?>
                <?= emptyState('bi-stopwatch', 'No finish times recorded yet. The fastest runner appears once finishes are recorded.', 'Record Finishes', url('organizer-race-results', ['event_id' => $eventId])) ?>
            <?php else: ?>
                <div class="podium-place place-1">
                    <div class="podium-medal"><i class="bi bi-trophy-fill" aria-hidden="true"></i></div>
                    <div class="podium-label">Overall Champion</div>
                    <div class="podium-name"><?= e($fastestRunner['full_name']) ?></div>
                    <div class="podium-time"><?= formatDuration($durations[0] ?? null) ?></div>
                    <div class="podium-meta">Bib <?= e($fastestRunner['bib_number'] ?: '—') ?> · <?= e($fastestRunner['distance_category'] ?: 'Uncategorized') ?></div>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="card-evora p-4 h-100" aria-label="Results by race category">
            <h2 class="h6 fw-semibold mb-3">By Race Category</h2>
            <?php if (empty($categoryBreakdown)): ?>
                <?= emptyState('bi-list-ol', 'No race categories defined for this event. Add categories like 3KM or 5KM to see results per distance.', 'Manage Categories', url('organizer-race-categories', ['event_id' => $eventId])) ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">Category</th><th scope="col" class="num">Approved</th><th scope="col" class="num">Finishers</th><th scope="col" class="num">DNF</th><th scope="col" class="num">No Show</th><th scope="col">Completion</th></tr></thead>
                    <tbody>
                    <?php foreach ($categoryBreakdown as $c): $pct = $c['approved'] > 0 ? (int) round($c['finishers'] / $c['approved'] * 100) : 0; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($c['name']) ?></td>
                            <td class="num"><?= $c['approved'] ?></td>
                            <td class="num"><?= $c['finishers'] ?></td>
                            <td class="num"><?= $c['dnf'] ?></td>
                            <td class="num"><?= $c['no_show'] ?></td>
                            <td class="w-120px">
                                <div class="small text-muted"><?= $pct ?>% finished</div>
                                <div class="progress progress-thin" role="progressbar" aria-label="<?= e($c['name']) ?> finishers" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
