<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

if ($eventId === 0) {
    // Event picker: organizer sees own sports-tournament events; staff sees
    // only events where they've been granted manage_incidents.
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
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_incidents' AND c.slug = 'sports-tournament'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a sports tournament to review its incident reports.';
    $pageTitle = 'Incident Reports';
    $activeRoute = 'incidents';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Incident Reports']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-exclamation-octagon', 'No sports tournament events available to you for incident reporting.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('incidents', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
// with the manage_incidents permission.
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT e.* FROM events e WHERE e.id = :id AND e.organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_incidents'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have incident reporting access to it.');
    redirect('incidents');
}

EventAccess::requireEventType($db, $eventId, 'sports-tournament', 'incidents');

$matchesStmt = $db->prepare(
    'SELECT m.id, m.round_no, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name
     FROM matches m
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     WHERE m.event_id = :eid ORDER BY m.round_no, m.id'
);
$matchesStmt->execute(['eid' => $eventId]);
$matches = $matchesStmt->fetchAll();

$venuesStmt = $db->prepare("SELECT id, name, code FROM venues WHERE event_id = :eid ORDER BY name");
$venuesStmt->execute(['eid' => $eventId]);
$venues = $venuesStmt->fetchAll();

function matchLabel(array $m): string
{
    $a = $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    $b = $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
    return 'Game #' . $m['id'] . ' — ' . $a . ' vs ' . $b;
}

$statusFilter = $_GET['status'] ?? '';
$allowedStatuses = ['open', 'under_review', 'resolved', 'dismissed'];

$incidentsSql = "SELECT i.*, m.id AS m_id, m.round_no, ta.team_name AS team_a_name, tb.team_name AS team_b_name,
        pau.full_name AS participant_a_name, pbu.full_name AS participant_b_name,
        v.name AS venue_name, ru.full_name AS reporter_name
     FROM event_incidents i
     LEFT JOIN matches m ON m.id = i.match_id
     LEFT JOIN teams ta ON ta.id = m.team_a_id
     LEFT JOIN teams tb ON tb.id = m.team_b_id
     LEFT JOIN participants pa ON pa.id = m.participant_a_id
     LEFT JOIN users pau ON pau.id = pa.user_id
     LEFT JOIN participants pb ON pb.id = m.participant_b_id
     LEFT JOIN users pbu ON pbu.id = pb.user_id
     LEFT JOIN venues v ON v.id = i.venue_id
     LEFT JOIN users ru ON ru.id = i.reported_by
     WHERE i.event_id = :eid";
$params = ['eid' => $eventId];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $incidentsSql .= ' AND i.status = :status';
    $params['status'] = $statusFilter;
}
$incidentsSql .= ' ORDER BY i.created_at DESC LIMIT 100';
$incidentsStmt = $db->prepare($incidentsSql);
$incidentsStmt->execute($params);
$incidents = $incidentsStmt->fetchAll();

$countsStmt = $db->prepare('SELECT status, COUNT(*) c FROM event_incidents WHERE event_id = :eid GROUP BY status');
$countsStmt->execute(['eid' => $eventId]);
$counts = ['open' => 0, 'under_review' => 0, 'resolved' => 0, 'dismissed' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['c'];
}

$typeLabels = [
    'player_misconduct' => 'Player Misconduct', 'team_complaint' => 'Team Complaint',
    'referee_issue' => 'Referee Issue', 'schedule_delay' => 'Schedule Delay',
    'venue_problem' => 'Venue Problem', 'safety_issue' => 'Safety Issue',
    'equipment_issue' => 'Equipment Issue', 'other' => 'Other',
];
$severityBadge = ['low' => 'bg-secondary', 'medium' => 'bg-info text-dark', 'high' => 'bg-warning text-dark', 'critical' => 'bg-danger'];
$statusBadge = ['open' => 'bg-danger', 'under_review' => 'bg-warning text-dark', 'resolved' => 'bg-success', 'dismissed' => 'bg-secondary'];
$statusLabel = ['open' => 'Open', 'under_review' => 'Under Review', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];

$pageDescription = 'Report, track and resolve incidents during the tournament.';
$pageTitle = 'Incident Reports';
$activeRoute = 'incidents';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Incident Reports', 'route' => 'incidents'],
    ['label' => $event['name']],
]); ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-danger"><?= $counts['open'] ?></div><div class="small text-muted">Open</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-warning"><?= $counts['under_review'] ?></div><div class="small text-muted">Under Review</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-success"><?= $counts['resolved'] ?></div><div class="small text-muted">Resolved</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-secondary"><?= $counts['dismissed'] ?></div><div class="small text-muted">Dismissed</div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-semibold mb-0">Recent Reports</h6>
                <div class="btn-group btn-group-sm">
                    <a href="<?= url('incidents', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary <?= $statusFilter === '' ? 'active' : '' ?>">All</a>
                    <?php foreach ($allowedStatuses as $s): ?>
                        <a href="<?= url('incidents', ['event_id' => $eventId, 'status' => $s]) ?>" class="btn btn-outline-secondary <?= $statusFilter === $s ? 'active' : '' ?>"><?= e($statusLabel[$s]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (empty($incidents)): ?>
                <?= emptyState('bi-clipboard-check', 'No incidents reported yet.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-sm">
                    <thead><tr><th>Match</th><th>Type</th><th>Severity</th><th>Status</th><th>Reported</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($incidents as $inc): ?>
                        <tr>
                            <td class="small"><?= $inc['m_id'] ? 'Game #' . $inc['m_id'] : '—' ?></td>
                            <td class="small"><?= e($typeLabels[$inc['incident_type']] ?? 'Other') ?></td>
                            <td><span class="badge <?= $severityBadge[$inc['severity']] ?>"><?= e(ucfirst($inc['severity'])) ?></span></td>
                            <td><span class="badge <?= $statusBadge[$inc['status']] ?>"><?= e($statusLabel[$inc['status']]) ?></span></td>
                            <td class="small"><?= e($inc['reporter_name'] ?? '—') ?><br><?= date('M d, h:i A', strtotime($inc['created_at'])) ?></td>
                            <td><a href="<?= url('incident-view', ['id' => $inc['id']]) ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
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
            <h6 class="fw-semibold mb-3">Report an Incident</h6>
            <form method="post" action="<?= url('incident-action') ?>" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <input type="hidden" name="incident_action" value="create">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-2">
                    <label class="form-label small">Related Match (optional)</label>
                    <select name="match_id" class="form-select form-select-sm">
                        <option value="">Not match-specific</option>
                        <?php foreach ($matches as $m): ?><option value="<?= $m['id'] ?>"><?= e(matchLabel($m)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Related Venue (optional)</label>
                    <select name="venue_id" class="form-select form-select-sm">
                        <option value="">Not venue-specific</option>
                        <?php foreach ($venues as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name']) ?><?= $v['code'] ? ' (' . e($v['code']) . ')' : '' ?></option><?php endforeach; ?>
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
                <div class="mb-2">
                    <label class="form-label small">Description *</label>
                    <textarea name="description" class="form-control form-control-sm" rows="4" required placeholder="What happened, who was involved, when/where..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Evidence Attachment (optional)</label>
                    <input type="file" name="attachment" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100">Submit Report</button>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
