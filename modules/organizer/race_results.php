<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

// Staff-only: with no event chosen yet, show a picker instead of erroring.
// Organizer behavior is completely unchanged.
if ($eventId === 0 && $role === 'staff') {
    $eventsStmt = $db->prepare(
        "SELECT e.id, e.name, e.event_date FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_finish_results' AND c.slug = 'fun-run'
         ORDER BY e.event_date DESC"
    );
    $eventsStmt->execute(['uid' => $userId]);
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a fun run to record finish times.';
    $pageTitle = 'Finish Line Results';
    $activeRoute = 'organizer-race-results';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Finish Line Results']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-flag-fill', 'No fun runs are assigned to you for finish-line recording yet. Ask the organizer to grant the finish results permission.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('organizer-race-results', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
                    <div class="fw-semibold"><?= e($ev['name']) ?></div>
                    <div class="small text-muted"><?= date('M d, Y', strtotime($ev['event_date'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
    <?php
    exit;
}

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_finish_results'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have finish-line recording access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$search = trim($_GET['search'] ?? '');

$pendingStmt = $db->prepare(
    "SELECT r.id AS registration_id, u.full_name, r.distance_category, r.bib_number
     FROM registrations r
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN race_results rr ON rr.registration_id = r.id
     WHERE r.event_id = :eid AND r.status = 'approved' AND rr.id IS NULL
     " . ($search !== '' ? 'AND (u.full_name LIKE :search OR r.bib_number LIKE :search)' : '') . "
     ORDER BY u.full_name"
);
$params = ['eid' => $eventId];
if ($search !== '') {
    $params['search'] = '%' . $search . '%';
}
$pendingStmt->execute($params);
$pendingParticipants = $pendingStmt->fetchAll();

$resultsStmt = $db->prepare(
    "SELECT rr.*, r.distance_category, r.bib_number, u.full_name
     FROM race_results rr
     JOIN registrations r ON r.id = rr.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid
     ORDER BY r.distance_category, rr.finish_time"
);
$resultsStmt->execute(['eid' => $eventId]);
$allResults = $resultsStmt->fetchAll();

// Duration = finish time minus the event's single gun-start time (events.event_time).
// Fun runs don't have per-runner chip timing in this system, so this is the
// one shared start every finisher's duration is measured against.
$gunStart = strtotime($event['event_date'] . ' ' . $event['event_time']);
foreach ($allResults as &$r) {
    $finishTs = strtotime($r['finish_time']);
    $r['duration_seconds'] = $finishTs >= $gunStart ? $finishTs - $gunStart : null;
}
unset($r);

$resultsByCategory = [];
foreach ($allResults as $r) {
    $cat = $r['distance_category'] ?: 'Uncategorized';
    $resultsByCategory[$cat][] = $r;
}

$overallResults = $allResults;
usort($overallResults, fn ($a, $b) => strtotime($a['finish_time']) <=> strtotime($b['finish_time']));

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

// Display helpers only (no new data): overall rank, rank within category,
// and the list of categories for the filter.
$overallRank = [];
foreach ($overallResults as $i => $r) {
    $overallRank[$r['registration_id']] = $i + 1;
}
$categoryRank = [];
foreach ($resultsByCategory as $category => $results) {
    foreach ($results as $i => $r) {
        $categoryRank[$r['registration_id']] = $i + 1;
    }
}
$categoryNames = array_keys($resultsByCategory);
foreach ($pendingParticipants as $p) {
    $categoryNames[] = $p['distance_category'] ?: 'Uncategorized';
}
$categoryNames = array_values(array_unique($categoryNames));
sort($categoryNames);
$podium = array_slice($overallResults, 0, 3);

$pageDescription = 'Record finish times at the line and see champions, top finishers and full timing results.';
$pageActions = $role === 'organizer'
    ? '<a href="' . url('organizer-race-results-dashboard', ['event_id' => $eventId]) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> Results Dashboard</a>'
    : '';
$pageTitle = 'Finish Line Results';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Finish Line Results'],
] : [
    ['label' => $event['name']],
    ['label' => 'Finish Line Results'],
]); ?>

<?php if ($podium): ?>
<section class="card-evora p-4 mb-3" aria-label="Top finishers">
    <h2 class="h6 fw-semibold mb-3"><i class="bi bi-trophy text-primary me-1" aria-hidden="true"></i> Top Finishers <span class="text-muted fw-normal small">— all categories</span></h2>
    <div class="podium">
        <?php foreach ($podium as $i => $r): ?>
            <div class="podium-place place-<?= $i + 1 ?>">
                <div class="podium-medal"><i class="bi <?= $i === 0 ? 'bi-trophy-fill' : 'bi-award-fill' ?>" aria-hidden="true"></i></div>
                <div class="podium-label"><?= $i === 0 ? 'Champion' : ($i === 1 ? '2nd Place' : '3rd Place') ?></div>
                <div class="podium-name"><?= e($r['full_name']) ?></div>
                <div class="podium-time"><?= formatDuration($r['duration_seconds']) ?></div>
                <div class="podium-meta">Bib <?= e($r['bib_number'] ?: '—') ?> · <?= e($r['distance_category'] ?: 'Uncategorized') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-5">
        <section class="card-evora p-4 h-100" aria-label="Record a finish">
            <h2 class="h6 fw-semibold mb-1"><i class="bi bi-stopwatch text-primary me-1" aria-hidden="true"></i> Record a Finish</h2>
            <p class="small text-muted">Press <strong>Finish Now</strong> as the runner crosses the line, or enter the finish time first.</p>
            <form method="get" action="<?= url('organizer-race-results') ?>" class="mb-3" role="search">
                <input type="hidden" name="route" value="organizer-race-results">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label for="finishSearch" class="visually-hidden">Search runners by name or bib</label>
                <div class="input-group input-group-sm">
                    <input type="search" id="finishSearch" name="search" class="form-control" placeholder="Search by name or bib..." value="<?= e($search) ?>">
                    <button type="submit" class="btn btn-outline-secondary">Search</button>
                </div>
            </form>
            <?php if (empty($pendingParticipants)): ?>
                <?= emptyState('bi-flag-fill', $search !== '' ? 'No runner without a finish time matches “' . $search . '”.' : 'No runners left to record — everyone has a finish time, or no participants are approved yet.') ?>
            <?php else: ?>
            <div class="table-responsive scroll-y-420">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">Runner</th><th scope="col">Record Finish</th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingParticipants as $p): ?>
                        <tr>
                            <td><span class="fw-semibold"><?= e($p['full_name']) ?></span><div class="small text-muted">Bib <?= e($p['bib_number'] ?: '—') ?> · <?= e($p['distance_category'] ?: 'Uncategorized') ?></div></td>
                            <td>
                                <form method="post" action="<?= url('organizer-race-result-save') ?>" class="d-flex gap-1 flex-wrap">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="registration_id" value="<?= $p['registration_id'] ?>">
                                    <input type="time" step="1" name="finish_time_manual" class="form-control form-control-sm w-120px" aria-label="Finish time for <?= e($p['full_name']) ?> (optional, leave blank for now)">
                                    <button type="submit" class="btn btn-sm btn-evora-primary text-nowrap"><i class="bi bi-stopwatch" aria-hidden="true"></i> Finish Now</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="card-evora p-4 h-100" aria-label="Runner results">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-list-ol text-primary me-1" aria-hidden="true"></i> Runner Results</h2>
            <?php if (empty($allResults) && empty($pendingParticipants)): ?>
                <?= emptyState('bi-people', 'No participants found. Runners appear here after their registration is approved.') ?>
            <?php else: ?>
            <div class="filter-bar" data-filter-for="runnerTable">
                <label for="rfSearch" class="visually-hidden">Search runners</label>
                <input type="search" id="rfSearch" class="form-control form-control-sm" placeholder="Search name or bib..." data-filter="search">
                <label for="rfCategory" class="visually-hidden">Category</label>
                <select id="rfCategory" class="form-select form-select-sm" data-filter="category">
                    <option value="">All categories</option>
                    <?php foreach ($categoryNames as $c): ?><option value="<?= e(strtolower($c)) ?>"><?= e($c) ?></option><?php endforeach; ?>
                </select>
                <label for="rfStatus" class="visually-hidden">Status</label>
                <select id="rfStatus" class="form-select form-select-sm" data-filter="status">
                    <option value="">All statuses</option>
                    <option value="finished">Finished</option>
                    <option value="no-finish">No finish yet</option>
                </select>
                <span class="filter-count" aria-live="polite"></span>
            </div>
            <?php if ($search !== ''): ?><p class="small text-muted">Runners without a finish time are limited to your search “<?= e($search) ?>”.</p><?php endif; ?>
            <div class="table-responsive scroll-y-420">
                <table class="table table-sm align-middle mb-0" id="runnerTable">
                    <thead><tr><th scope="col">Rank</th><th scope="col">Bib</th><th scope="col">Name</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col" class="num">Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($overallResults as $r): $rank = $overallRank[$r['registration_id']]; $cat = $r['distance_category'] ?: 'Uncategorized'; ?>
                        <tr data-search="<?= e(strtolower($r['full_name'] . ' ' . $r['bib_number'])) ?>" data-category="<?= e(strtolower($cat)) ?>" data-status="finished">
                            <td><span class="rank-badge<?= $rank <= 3 ? ' rank-' . $rank : '' ?>"><?= $rank ?></span></td>
                            <td class="fw-semibold nowrap"><?= e($r['bib_number'] ?: '—') ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td class="small"><?= e($cat) ?><div class="text-muted nowrap">#<?= $categoryRank[$r['registration_id']] ?? '—' ?> in <?= e($cat) ?></div></td>
                            <td><span class="badge bg-success">Finished</span></td>
                            <td class="num"><span class="fw-bold"><?= formatDuration($r['duration_seconds']) ?></span><div class="small text-muted nowrap"><?= date('h:i:s A', strtotime($r['finish_time'])) ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($pendingParticipants as $p): $cat = $p['distance_category'] ?: 'Uncategorized'; ?>
                        <tr data-search="<?= e(strtolower($p['full_name'] . ' ' . $p['bib_number'])) ?>" data-category="<?= e(strtolower($cat)) ?>" data-status="no-finish">
                            <td><span class="text-muted">—</span></td>
                            <td class="fw-semibold nowrap"><?= e($p['bib_number'] ?: '—') ?></td>
                            <td><?= e($p['full_name']) ?></td>
                            <td class="small"><?= e($cat) ?></td>
                            <td><span class="badge bg-secondary">No finish yet</span></td>
                            <td class="num text-muted">—</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="filter-empty d-none"><td colspan="6" class="text-center text-muted small py-3">No runners match these filters.</td></tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if (!empty($resultsByCategory)): ?>
<h2 class="h6 fw-semibold mt-4 mb-3">Category Rankings</h2>
<div class="row g-3">
    <?php foreach ($resultsByCategory as $category => $results): ?>
    <div class="col-md-6 col-xl-4">
        <section class="card-evora p-4 h-100" aria-label="<?= e($category) ?> ranking">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="h6 fw-semibold mb-0"><?= e($category) ?></h3>
                <span class="small text-muted"><?= count($results) ?> finisher<?= count($results) === 1 ? '' : 's' ?></span>
            </div>
            <ol class="list-unstyled mb-0">
                <?php foreach ($results as $i => $r): ?>
                    <li class="d-flex align-items-center gap-2 py-1 border-bottom">
                        <span class="rank-badge<?= $i < 3 ? ' rank-' . ($i + 1) : '' ?>"><?= $i + 1 ?></span>
                        <span class="flex-grow-1 min-w-0"><?= e($r['full_name']) ?><span class="small text-muted"> · Bib <?= e($r['bib_number'] ?: '—') ?></span><?= $i === 0 ? '<span class="visually-hidden"> (category champion)</span>' : '' ?></span>
                        <span class="fw-bold font-tabular nowrap"><?= formatDuration($r['duration_seconds']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
