<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$incidentId = (int) ($_GET['id'] ?? 0);

$incStmt = $db->prepare('SELECT * FROM race_medical_incidents WHERE id = :id');
$incStmt->execute(['id' => $incidentId]);
$incident = $incStmt->fetch();

if (!$incident) {
    flash('error', 'Case not found.');
    redirect('medical-incidents');
}

$eventId = (int) $incident['event_id'];

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
    redirect('medical-incidents');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', 'medical-incidents');

$runner = null;
if ($incident['registration_id']) {
    $runnerStmt = $db->prepare(
        "SELECT r.bib_number, r.distance_category, u.full_name
         FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
         WHERE r.id = :id"
    );
    $runnerStmt->execute(['id' => $incident['registration_id']]);
    $runner = $runnerStmt->fetch();
}

$checkpoint = null;
if ($incident['checkpoint_id']) {
    $cpStmt = $db->prepare('SELECT label FROM event_checkpoints WHERE id = :id');
    $cpStmt->execute(['id' => $incident['checkpoint_id']]);
    $checkpoint = $cpStmt->fetch();
}

$reporterStmt = $db->prepare('SELECT full_name FROM users WHERE id = :id');
$reporterStmt->execute(['id' => $incident['reported_by']]);
$reporterName = $reporterStmt->fetch()['full_name'] ?? 'Unknown';

$resolverName = null;
if ($incident['resolved_by']) {
    $resolverStmt = $db->prepare('SELECT full_name FROM users WHERE id = :id');
    $resolverStmt->execute(['id' => $incident['resolved_by']]);
    $resolverName = $resolverStmt->fetch()['full_name'] ?? null;
}

$typeLabels = [
    'injury' => 'Injury', 'dehydration' => 'Dehydration', 'fatigue' => 'Fatigue',
    'cardiac' => 'Cardiac', 'fall' => 'Fall', 'other' => 'Other',
];
$severityBadge = ['low' => 'bg-light text-dark border', 'medium' => 'bg-info text-dark', 'high' => 'bg-warning text-dark', 'critical' => 'bg-danger'];
$statusBadge = ['open' => 'bg-danger', 'resolved' => 'bg-success'];
$statusLabel = ['open' => 'Open', 'resolved' => 'Resolved'];

$pageDescription = 'Case details, responders and resolution.';
$pageTitle = 'Medical Case #' . $incidentId;
$activeRoute = 'medical-incidents';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Medical & Emergency Log', 'route' => 'medical-incidents'],
    ['label' => $event['name'], 'route' => 'medical-incidents', 'params' => ['event_id' => $eventId]],
    ['label' => 'Case #' . $incidentId],
]); ?>
<?php if ($role === 'staff'): ?>
<a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4 mb-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-semibold mb-0"><?= e($typeLabels[$incident['incident_type']] ?? 'Other') ?></h6>
                <span class="badge <?= $statusBadge[$incident['status']] ?>"><?= e($statusLabel[$incident['status']]) ?></span>
            </div>
            <p class="mb-2"><span class="badge <?= $severityBadge[$incident['severity']] ?>"><?= e(ucfirst($incident['severity'])) ?> severity</span></p>
            <p class="mb-1"><strong>Runner:</strong> <?= $runner ? e($runner['full_name']) . ($runner['bib_number'] ? ' (Bib ' . e($runner['bib_number']) . ')' : '') : 'Unspecified' ?></p>
            <p class="mb-1"><strong>Location:</strong> <?= $checkpoint ? e($checkpoint['label']) : 'Not specified' ?></p>
            <p class="mb-2"><?= nl2br(e($incident['description'])) ?></p>
            <p class="small text-muted mb-0">Reported by <?= e($reporterName) ?> on <?= date('M d, Y h:i A', strtotime($incident['created_at'])) ?></p>
        </div>

        <?php if ($incident['status'] === 'resolved'): ?>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Action Taken</h6>
            <p class="mb-1"><?= nl2br(e($incident['action_taken'] ?? '—')) ?></p>
            <p class="small text-muted mb-0">By <?= e($resolverName ?? 'Unknown') ?> on <?= date('M d, Y h:i A', strtotime($incident['updated_at'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Resolve Case</h6>
            <?php if ($role !== 'organizer'): ?>
                <p class="text-muted small mb-0">Only the organizer can resolve a medical case.</p>
            <?php elseif ($incident['status'] === 'open'): ?>
                <form method="post" action="<?= url('medical-incident-action') ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="medical_action" value="resolve">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="incident_id" value="<?= $incidentId ?>">
                    <label class="form-label small">Action Taken *</label>
                    <textarea name="action_taken" class="form-control form-control-sm mb-2" rows="3" required placeholder="e.g. Transferred to medical tent, rehydrated, cleared to continue."></textarea>
                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check-circle"></i> Mark Resolved</button>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">This case is resolved.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
