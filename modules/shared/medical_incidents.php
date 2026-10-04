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
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_medical' AND c.slug = 'fun-run'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a fun run to open its medical and emergency log.';
    $pageTitle = 'Medical & Emergency Log';
    $activeRoute = 'medical-incidents';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Medical & Emergency Log']]); ?>
    <?php if ($role === 'staff'): ?>
    <a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
    <?php endif; ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-heart-pulse', 'No fun run events available to you for medical logging.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('medical-incidents', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_medical'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have medical logging access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$checkpointsStmt = $db->prepare('SELECT id, label FROM event_checkpoints WHERE event_id = :eid ORDER BY sequence_no');
$checkpointsStmt->execute(['eid' => $eventId]);
$checkpoints = $checkpointsStmt->fetchAll();

$runnersStmt = $db->prepare(
    "SELECT r.id AS registration_id, r.bib_number, u.full_name
     FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
     WHERE r.event_id = :eid AND r.status = 'approved' ORDER BY u.full_name"
);
$runnersStmt->execute(['eid' => $eventId]);
$runners = $runnersStmt->fetchAll();

$statusFilter = $_GET['status'] ?? '';
$sql = "SELECT mi.*, u.full_name AS runner_name, r.bib_number, cp.label AS checkpoint_label, ru.full_name AS reporter_name
     FROM race_medical_incidents mi
     LEFT JOIN registrations r ON r.id = mi.registration_id
     LEFT JOIN participants p ON p.id = r.participant_id
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN event_checkpoints cp ON cp.id = mi.checkpoint_id
     LEFT JOIN users ru ON ru.id = mi.reported_by
     WHERE mi.event_id = :eid";
$params = ['eid' => $eventId];
if (in_array($statusFilter, ['open', 'resolved'], true)) {
    $sql .= ' AND mi.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY mi.created_at DESC LIMIT 100';
$incidentsStmt = $db->prepare($sql);
$incidentsStmt->execute($params);
$incidents = $incidentsStmt->fetchAll();

$countsStmt = $db->prepare('SELECT status, COUNT(*) c FROM race_medical_incidents WHERE event_id = :eid GROUP BY status');
$countsStmt->execute(['eid' => $eventId]);
$counts = ['open' => 0, 'resolved' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['c'];
}

$typeLabels = [
    'injury' => 'Injury', 'dehydration' => 'Dehydration', 'fatigue' => 'Fatigue',
    'cardiac' => 'Cardiac', 'fall' => 'Fall', 'other' => 'Other',
];
$severityBadge = ['low' => 'bg-secondary', 'medium' => 'bg-info text-dark', 'high' => 'bg-warning text-dark', 'critical' => 'bg-danger'];
$statusBadge = ['open' => 'bg-danger', 'resolved' => 'bg-success'];
$statusLabel = ['open' => 'Open', 'resolved' => 'Resolved'];

$pageDescription = 'Log medical and emergency cases on the route and track them until resolved.';
$pageTitle = 'Medical & Emergency Log';
$activeRoute = 'medical-incidents';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Medical & Emergency Log', 'route' => 'medical-incidents'],
    ['label' => $event['name']],
]); ?>
<?php if ($role === 'staff'): ?>
<a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-danger"><?= $counts['open'] ?></div><div class="small text-muted">Open Cases</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-success"><?= $counts['resolved'] ?></div><div class="small text-muted">Resolved</div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-semibold mb-0">Cases</h6>
                <div class="btn-group btn-group-sm">
                    <a href="<?= url('medical-incidents', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
                    <a href="<?= url('medical-incidents', ['event_id' => $eventId, 'status' => 'open']) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'open' ? 'active' : '' ?>">Open</a>
                    <a href="<?= url('medical-incidents', ['event_id' => $eventId, 'status' => 'resolved']) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'resolved' ? 'active' : '' ?>">Resolved</a>
                </div>
            </div>
            <?php if (empty($incidents)): ?>
                <?= emptyState('bi-heart-pulse', 'No medical cases logged yet.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-sm">
                    <thead><tr><th>Runner</th><th>Type</th><th>Severity</th><th>Location</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($incidents as $inc): ?>
                        <tr>
                            <td class="small"><?= e($inc['runner_name'] ?? 'Unspecified') ?><?= $inc['bib_number'] ? ' (' . e($inc['bib_number']) . ')' : '' ?></td>
                            <td class="small"><?= e($typeLabels[$inc['incident_type']] ?? 'Other') ?></td>
                            <td><span class="badge <?= $severityBadge[$inc['severity']] ?>"><?= e(ucfirst($inc['severity'])) ?></span></td>
                            <td class="small"><?= e($inc['checkpoint_label'] ?? '—') ?></td>
                            <td><span class="badge <?= $statusBadge[$inc['status']] ?>"><?= e($statusLabel[$inc['status']]) ?></span></td>
                            <td><a href="<?= url('medical-incident-view', ['id' => $inc['id']]) ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Log a Case</h6>
            <form method="post" action="<?= url('medical-incident-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="medical_action" value="create">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-2">
                    <label class="form-label small">Runner (optional)</label>
                    <select name="registration_id" class="form-select form-select-sm">
                        <option value="">Unspecified / bystander</option>
                        <?php foreach ($runners as $r): ?><option value="<?= $r['registration_id'] ?>"><?= e($r['full_name']) ?><?= $r['bib_number'] ? ' (' . e($r['bib_number']) . ')' : '' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Location (optional)</label>
                    <select name="checkpoint_id" class="form-select form-select-sm">
                        <option value="">Not specified</option>
                        <?php foreach ($checkpoints as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Incident Type *</label>
                    <select name="incident_type" class="form-select form-select-sm" required>
                        <?php foreach ($typeLabels as $key => $label): ?><option value="<?= $key ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Severity *</label>
                    <select name="severity" class="form-select form-select-sm" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Description *</label>
                    <textarea name="description" class="form-control form-control-sm" rows="3" required placeholder="What happened..."></textarea>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100">Log Case</button>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
