<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

// Staff-only: with no event chosen yet, show a picker instead of erroring.
// Organizer behavior is completely unchanged (this branch never fires for
// organizer — they always arrive here via event_manage.php's tool tile,
// which always supplies a real event_id).
if ($eventId === 0 && $role === 'staff') {
    $eventsStmt = $db->prepare(
        "SELECT e.id, e.name, e.event_date FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_bibs' AND c.slug = 'fun-run'
         ORDER BY e.event_date DESC"
    );
    $eventsStmt->execute(['uid' => $userId]);
    $events = $eventsStmt->fetchAll();

    $pageTitle = 'Bib Number Assignment';
    $activeRoute = 'organizer-bib-assign';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Bib Number Assignment']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-upc-scan', 'No fun run events available to you for bib assignment.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('organizer-bib-assign', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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

// Scope check: organizer must own the event; staff must be assigned to it
// with the manage_bibs permission.
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_bibs'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have bib assignment access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$statsStmt = $db->prepare(
    "SELECT COUNT(*) AS approved_count,
        SUM(CASE WHEN bib_number IS NOT NULL AND bib_number != '' THEN 1 ELSE 0 END) AS assigned_count
     FROM registrations WHERE event_id = :eid AND status = 'approved'"
);
$statsStmt->execute(['eid' => $eventId]);
$stats = $statsStmt->fetch();
$approvedCount = (int) $stats['approved_count'];
$assignedCount = (int) $stats['assigned_count'];

$search = trim($_GET['search'] ?? '');

$sql = "SELECT r.id AS registration_id, r.bib_number, r.distance_category, u.full_name
        FROM registrations r
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        WHERE r.event_id = :eid AND r.status = 'approved'";
$params = ['eid' => $eventId];
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE :search OR r.bib_number LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
$sql .= " ORDER BY r.distance_category, u.full_name";

$regStmt = $db->prepare($sql);
$regStmt->execute($params);
$registrations = $regStmt->fetchAll();

$pageDescription = 'Give every approved runner a bib number — automatically or one at a time.';
$pageTitle = 'Bib Number Assignment';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Bib Number Assignment'],
] : [
    ['label' => $event['name']],
    ['label' => 'Bib Number Assignment'],
]); ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="stat-card"><i class="bi bi-people fs-4 text-muted" aria-hidden="true"></i><div class="stat-value"><?= $approvedCount ?></div><div class="stat-label">Approved Runners</div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card accent-success"><i class="bi bi-person-badge fs-4 text-muted" aria-hidden="true"></i><div class="stat-value"><?= $assignedCount ?></div><div class="stat-label">Bibs Assigned</div><div class="stat-desc"><?= $approvedCount > 0 ? (int) round($assignedCount / $approvedCount * 100) : 0 ?>% of runners</div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card accent-warning"><i class="bi bi-hourglass-split fs-4 text-muted" aria-hidden="true"></i><div class="stat-value"><?= max(0, $approvedCount - $assignedCount) ?></div><div class="stat-label">Remaining</div><div class="stat-desc">Runners without a bib</div></div>
    </div>
</div>

<div class="card-evora p-4 mb-3 d-flex flex-row justify-content-between align-items-center flex-wrap gap-2">
    <form method="get" action="<?= url('organizer-bib-assign') ?>" class="d-flex gap-2 flex-wrap" role="search">
        <input type="hidden" name="route" value="organizer-bib-assign">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <label for="bibSearch" class="visually-hidden">Search by name or bib</label>
        <input type="search" id="bibSearch" name="search" class="form-control form-control-sm min-w-200" placeholder="Search by name or bib..." value="<?= e($search) ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
    </form>
    <form method="post" action="<?= url('organizer-bib-assign-save') ?>" data-confirm="Every approved runner without a bib gets the next number in their category. Bibs already assigned are kept." data-confirm-title="Auto-assign bib numbers?" data-confirm-button="Auto-Assign">
        <?= Csrf::field() ?>
        <input type="hidden" name="bib_action" value="auto_assign">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <button type="submit" class="btn btn-sm btn-evora-primary"><i class="bi bi-magic"></i> Auto-Assign All</button>
    </form>
</div>

<div class="card-evora p-4">
    <?php if (empty($registrations)): ?>
        <?= emptyState('bi-person-badge', $search !== '' ? 'No approved runner matches your search.' : 'No participants found. Runners appear here after their registration is approved.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th scope="col">Participant</th><th scope="col">Category</th><th scope="col">Bib Number</th><th scope="col">Assign</th></tr></thead>
            <tbody>
            <?php foreach ($registrations as $r): ?>
                <tr>
                    <td><?= e($r['full_name']) ?></td>
                    <td><?= e($r['distance_category'] ?: '—') ?></td>
                    <td><?= $r['bib_number'] ? '<span class="fw-semibold">' . e($r['bib_number']) . '</span>' : '<span class="badge bg-warning">Not assigned</span>' ?></td>
                    <td>
                        <form method="post" action="<?= url('organizer-bib-assign-save') ?>" class="d-flex gap-1">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="bib_action" value="manual">
                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <input type="hidden" name="registration_id" value="<?= $r['registration_id'] ?>">
                            <input type="text" name="bib_number" class="form-control form-control-sm w-120px" placeholder="e.g. 5KM-001" value="<?= e($r['bib_number'] ?? '') ?>" aria-label="Bib number for <?= e($r['full_name']) ?>">
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
