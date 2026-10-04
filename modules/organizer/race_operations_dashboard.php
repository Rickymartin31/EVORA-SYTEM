<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

if ($eventId === 0) {
    if ($role === 'organizer') {
        $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
        $orgStmt->execute(['uid' => $userId]);
        $organizer = $orgStmt->fetch();
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_categories c ON c.id = e.category_id
             WHERE e.organizer_id = :oid AND c.slug = 'fun-run' ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
    } else {
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_categories c ON c.id = e.category_id
             JOIN event_staff es ON es.event_id = e.id
             JOIN staff_permissions sp ON sp.event_staff_id = es.id
             WHERE es.user_id = :uid AND sp.permission_key = 'view_race_operations' AND c.slug = 'fun-run'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a fun run to monitor check-ins, runners on the course and finishers.';
    $pageTitle = 'Fun Run Control Center';
    $activeRoute = 'race-operations-dashboard';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Fun Run Control Center']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-speedometer2', $role === 'organizer' ? 'No fun run events yet. Create a fun run to monitor race operations here.' : 'No fun runs are assigned to you for race operations yet.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('race-operations-dashboard', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'view_race_operations'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have race operations access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$registeredStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$registeredStmt->execute(['id' => $eventId]);
$registeredCount = (int) $registeredStmt->fetch()['c'];

$checkedInStmt = $db->prepare(
    'SELECT COUNT(DISTINCT a.registration_id) c FROM attendance a JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :id AND r.status = "approved"'
);
$checkedInStmt->execute(['id' => $eventId]);
$checkedInCount = (int) $checkedInStmt->fetch()['c'];

$startedStmt = $db->prepare(
    'SELECT COUNT(DISTINCT cs.registration_id) c FROM checkpoint_scans cs
     JOIN event_checkpoints cp ON cp.id = cs.checkpoint_id
     WHERE cp.event_id = :id'
);
$startedStmt->execute(['id' => $eventId]);
$startedCount = (int) $startedStmt->fetch()['c'];

$finishedStmt = $db->prepare(
    'SELECT COUNT(*) c FROM race_results rr JOIN registrations r ON r.id = rr.registration_id
     WHERE r.event_id = :id AND r.status = "approved"'
);
$finishedStmt->execute(['id' => $eventId]);
$finishedCount = (int) $finishedStmt->fetch()['c'];

$currentlyRunningCount = max(0, $startedCount - $finishedCount);
$dnfCount = max(0, $checkedInCount - $finishedCount);

$activeCheckpointsStmt = $db->prepare("SELECT COUNT(*) c FROM event_checkpoints WHERE event_id = :id AND status = 'active'");
$activeCheckpointsStmt->execute(['id' => $eventId]);
$activeCheckpointsCount = (int) $activeCheckpointsStmt->fetch()['c'];

$medicalOpenStmt = $db->prepare("SELECT COUNT(*) c FROM race_medical_incidents WHERE event_id = :id AND status = 'open'");
$medicalOpenStmt->execute(['id' => $eventId]);
$medicalOpenCount = (int) $medicalOpenStmt->fetch()['c'];

$alertsStmt = $db->prepare("SELECT message, created_at FROM event_announcements WHERE event_id = :id AND phase = 'during' ORDER BY created_at DESC LIMIT 5");
$alertsStmt->execute(['id' => $eventId]);
$routeAlerts = $alertsStmt->fetchAll();

// "Missing" is a heuristic, not a guarantee: checked in over an hour ago with
// no checkpoint progress and no finish recorded yet.
$missingStmt = $db->prepare(
    "SELECT u.full_name, r.bib_number, a.scanned_at
     FROM attendance a
     JOIN registrations r ON r.id = a.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :id AND r.status = 'approved'
       AND a.scanned_at <= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
       AND NOT EXISTS (SELECT 1 FROM checkpoint_scans cs WHERE cs.registration_id = r.id)
       AND NOT EXISTS (SELECT 1 FROM race_results rr WHERE rr.registration_id = r.id)
     ORDER BY a.scanned_at LIMIT 20"
);
$missingStmt->execute(['id' => $eventId]);
$missingRunners = $missingStmt->fetchAll();

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$checkInPct = $registeredCount > 0 ? (int) round($checkedInCount / $registeredCount * 100) : 0;
$finishPct = $checkedInCount > 0 ? (int) round($finishedCount / $checkedInCount * 100) : 0;
$pageDescription = 'Live race status: check-ins, runners on the course, finishers and incidents.';
$isOrganizer = $role === 'organizer';
$pageActions = !$isOrganizer ? '' : '<a href="' . url('organizer-race-results', ['event_id' => $eventId]) . '" class="btn btn-evora-primary btn-sm"><i class="bi bi-stopwatch" aria-hidden="true"></i> Finish Line</a>';
$pageTitle = 'Fun Run Control Center';
$activeRoute = 'race-operations-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Fun Run Control Center', 'route' => 'race-operations-dashboard'],
    ['label' => $event['name']],
]); ?>

<?= moduleOverview('bi-speedometer2', $event['name'], [
    $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null,
    $event['event_time'] ? 'Gun start ' . date('h:i A', strtotime($event['event_time'])) : null,
    $event['venue'] ?? null,
], [
    ['label' => 'Participants', 'value' => $registeredCount, 'hint' => 'Approved runners'],
    ['label' => 'Checked In', 'value' => $checkedInCount, 'tone' => 'info', 'hint' => $checkInPct . '% of participants'],
    ['label' => 'Started', 'value' => $startedCount, 'tone' => 'primary', 'hint' => 'Passed a checkpoint'],
    ['label' => 'On Course', 'value' => $currentlyRunningCount, 'tone' => 'warning', 'hint' => 'Started, not finished'],
    ['label' => 'Completed', 'value' => $finishedCount, 'tone' => 'success', 'hint' => $finishPct . '% of checked in'],
    ['label' => 'DNF', 'value' => $dnfCount, 'tone' => 'danger', 'hint' => 'Checked in, no finish'],
], '<span class="badge badge-status-' . e($event['status']) . '">' . e(ucfirst($event['status'])) . '</span>') ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <?php $tile = '<div class="stat-card"><i class="bi bi-geo-alt fs-4 text-muted" aria-hidden="true"></i><div class="stat-value">' . $activeCheckpointsCount . '</div><div class="stat-label">Active Checkpoints</div><div class="stat-desc">' . ($isOrganizer ? 'Manage checkpoints &rarr;' : 'Along the route') . '</div></div>'; ?>
        <?= $isOrganizer ? '<a href="' . url('organizer-checkpoints', ['event_id' => $eventId]) . '" class="text-decoration-none">' . $tile . '</a>' : $tile ?>
    </div>
    <div class="col-6 col-lg-3">
        <a href="<?= url('medical-incidents', ['event_id' => $eventId]) ?>" class="text-decoration-none">
            <div class="stat-card <?= $medicalOpenCount > 0 ? 'accent-danger' : 'accent-success' ?>"><i class="bi bi-heart-pulse fs-4 text-muted" aria-hidden="true"></i><div class="stat-value"><?= $medicalOpenCount ?></div><div class="stat-label">Open Medical Cases</div><div class="stat-desc"><?= $medicalOpenCount > 0 ? 'Needs attention — view cases' : 'All clear — view log' ?> &rarr;</div></div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <?php $tile = '<div class="stat-card accent-success"><i class="bi bi-flag fs-4 text-muted" aria-hidden="true"></i><div class="stat-value">' . $finishedCount . '</div><div class="stat-label">Finishers</div><div class="stat-desc">' . ($isOrganizer ? 'Record finishes &rarr;' : 'Finish times recorded') . '</div></div>'; ?>
        <?= $isOrganizer ? '<a href="' . url('organizer-race-results', ['event_id' => $eventId]) . '" class="text-decoration-none">' . $tile . '</a>' : $tile ?>
    </div>
    <div class="col-6 col-lg-3">
        <?php $tile = '<div class="stat-card accent-info"><i class="bi bi-bar-chart-line fs-4 text-muted" aria-hidden="true"></i><div class="stat-value">' . $finishPct . '%</div><div class="stat-label">Completion Rate</div><div class="stat-desc">' . ($isOrganizer ? 'Results &amp; rankings &rarr;' : 'Finishers of checked-in runners') . '</div></div>'; ?>
        <?= $isOrganizer ? '<a href="' . url('organizer-race-results-dashboard', ['event_id' => $eventId]) . '" class="text-decoration-none">' . $tile . '</a>' : $tile ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <section class="card-evora p-4 h-100" aria-label="Route alerts">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-megaphone text-primary me-1" aria-hidden="true"></i> Route Alerts</h2>
            <?php if (empty($routeAlerts)): ?>
                <?= emptyState('bi-check2-circle', 'No route alerts sent during the race.') ?>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($routeAlerts as $a): ?>
                        <li class="border-bottom py-2 d-flex justify-content-between gap-3"><span><?= e($a['message']) ?></span><span class="text-muted nowrap"><?= date('h:i A', strtotime($a['created_at'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="card-evora p-4 h-100" aria-label="Possibly missing runners">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h6 fw-semibold mb-0"><i class="bi bi-exclamation-triangle text-warning me-1" aria-hidden="true"></i> Possibly Missing Runners</h2>
                <?php if ($missingRunners): ?><span class="badge bg-warning"><?= count($missingRunners) ?> flagged</span><?php endif; ?>
            </div>
            <?php if (empty($missingRunners)): ?>
                <?= emptyState('bi-person-check', 'No runners flagged. Runners appear here if they checked in over an hour ago with no checkpoint or finish recorded.') ?>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($missingRunners as $m): ?>
                        <li class="border-bottom py-2 d-flex justify-content-between gap-3">
                            <span><span class="fw-semibold"><?= e($m['full_name']) ?></span><?= $m['bib_number'] ? ' <span class="text-muted">· Bib ' . e($m['bib_number']) . '</span>' : '' ?></span>
                            <span class="text-muted nowrap">Checked in <?= date('h:i A', strtotime($m['scanned_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="small text-muted mt-2 mb-0">Checked in over an hour ago with no checkpoint progress or finish yet.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
