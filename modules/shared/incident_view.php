<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$incidentId = (int) ($_GET['id'] ?? 0);

// Never trust the posted/queried incident id alone: resolve its event, then
// re-run the exact same ownership/permission check as incidents_list.php.
$incStmt = $db->prepare('SELECT * FROM event_incidents WHERE id = :id');
$incStmt->execute(['id' => $incidentId]);
$incident = $incStmt->fetch();

if (!$incident) {
    flash('error', 'Incident not found.');
    redirect('incidents');
}

$eventId = (int) $incident['event_id'];

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT e.*, s.name AS sport_name FROM events e LEFT JOIN sports s ON s.id = e.sport_id WHERE e.id = :id AND e.organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.*, s.name AS sport_name FROM events e
         LEFT JOIN sports s ON s.id = e.sport_id
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

$match = null;
if ($incident['match_id']) {
    $matchStmt = $db->prepare(
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
         WHERE m.id = :id'
    );
    $matchStmt->execute(['id' => $incident['match_id']]);
    $match = $matchStmt->fetch();
}

$venue = null;
if ($incident['venue_id']) {
    $venueStmt = $db->prepare('SELECT * FROM venues WHERE id = :id');
    $venueStmt->execute(['id' => $incident['venue_id']]);
    $venue = $venueStmt->fetch();
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

// Activity log entries mentioning this incident, for the audit trail — same
// "embed the id in the description text" convention used across the app.
$logStmt = $db->prepare(
    "SELECT l.*, u.full_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
     WHERE l.description LIKE :needle ORDER BY l.created_at ASC"
);
$logStmt->execute(['needle' => '%Incident #' . $incidentId . ' %']);
$history = $logStmt->fetchAll();

function opponentLabel(array $m, string $side): string
{
    if ($side === 'a') {
        return $m['team_a_name'] ?? $m['participant_a_name'] ?? 'TBD';
    }
    return $m['team_b_name'] ?? $m['participant_b_name'] ?? 'TBD';
}

$typeLabels = [
    'player_misconduct' => 'Player Misconduct', 'team_complaint' => 'Team Complaint',
    'referee_issue' => 'Referee Issue', 'schedule_delay' => 'Schedule Delay',
    'venue_problem' => 'Venue Problem', 'safety_issue' => 'Safety Issue',
    'equipment_issue' => 'Equipment Issue', 'other' => 'Other',
];
$severityBadge = ['low' => 'bg-light text-dark border', 'medium' => 'bg-info text-dark', 'high' => 'bg-warning text-dark', 'critical' => 'bg-danger'];
$statusBadge = ['open' => 'bg-danger', 'under_review' => 'bg-warning text-dark', 'resolved' => 'bg-success', 'dismissed' => 'bg-secondary'];
$statusLabel = ['open' => 'Open', 'under_review' => 'Under Review', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];

$pageDescription = 'Incident details, updates and resolution.';
$pageTitle = 'Incident #' . $incidentId;
$activeRoute = 'incidents';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Incident Reports', 'route' => 'incidents'],
    ['label' => $event['name'], 'route' => 'incidents', 'params' => ['event_id' => $eventId]],
    ['label' => 'Incident #' . $incidentId],
]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4 mb-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-semibold mb-0"><?= e($typeLabels[$incident['incident_type']] ?? 'Other') ?></h6>
                <span class="badge <?= $statusBadge[$incident['status']] ?>"><?= e($statusLabel[$incident['status']]) ?></span>
            </div>
            <p class="mb-2"><span class="badge <?= $severityBadge[$incident['severity']] ?>"><?= e(ucfirst($incident['severity'])) ?> severity</span></p>
            <p class="mb-2"><?= nl2br(e($incident['description'])) ?></p>
            <p class="small text-muted mb-0">Reported by <?= e($reporterName) ?> on <?= date('M d, Y h:i A', strtotime($incident['created_at'])) ?></p>
            <?php if ($incident['attachment_path']): ?>
                <p class="mt-2 mb-0"><a href="<?= uploadUrl($incident['attachment_path']) ?>" target="_blank"><i class="bi bi-paperclip"></i> View Evidence Attachment</a></p>
            <?php endif; ?>
        </div>

        <?php if ($match): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Related Match</h6>
            <p class="mb-1"><strong>Game #<?= $match['id'] ?></strong> &bull; Round <?= (int) $match['round_no'] ?></p>
            <p class="mb-1"><?= e(opponentLabel($match, 'a')) ?> vs <?= e(opponentLabel($match, 'b')) ?></p>
            <p class="mb-1 small text-muted"><?= $match['venue'] ? e($match['venue']) : 'Venue not set' ?><?= $match['scheduled_at'] ? ' &bull; ' . date('M d, h:i A', strtotime($match['scheduled_at'])) : '' ?></p>
            <p class="mb-0 small">Score status: <?= $match['team_a_score'] !== null ? (int) $match['team_a_score'] . ' - ' . (int) $match['team_b_score'] : 'Not yet recorded' ?> (<?= e(ucfirst($match['status'])) ?>)</p>
        </div>
        <?php endif; ?>

        <?php if ($venue): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Related Venue</h6>
            <p class="mb-0"><?= e($venue['name']) ?><?= $venue['code'] ? ' (' . e($venue['code']) . ')' : '' ?> — <?= e($venue['venue_type']) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($incident['status'] === 'resolved' || $incident['status'] === 'dismissed'): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Resolution</h6>
            <p class="mb-1"><?= nl2br(e($incident['resolution_notes'] ?? '—')) ?></p>
            <p class="small text-muted mb-0">By <?= e($resolverName ?? 'Unknown') ?> on <?= date('M d, Y h:i A', strtotime($incident['updated_at'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($history)): ?>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">History</h6>
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($history as $h): ?>
                    <li class="border-bottom py-2"><?= e($h['full_name'] ?? 'System') ?> — <?= e($h['description']) ?><br><span class="text-muted"><?= date('M d, Y h:i A', strtotime($h['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Review &amp; Resolution</h6>
            <?php if ($role !== 'organizer'): ?>
                <p class="text-muted small mb-0">Only the organizer can update status or add a resolution.</p>
            <?php elseif ($incident['status'] === 'open'): ?>
                <form method="post" action="<?= url('incident-action') ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="incident_action" value="start_review">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="incident_id" value="<?= $incidentId ?>">
                    <button type="submit" class="btn btn-sm btn-warning w-100"><i class="bi bi-search"></i> Start Review</button>
                </form>
            <?php elseif ($incident['status'] === 'under_review'): ?>
                <form method="post" action="<?= url('incident-action') ?>" class="mb-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="incident_action" value="resolve">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="incident_id" value="<?= $incidentId ?>">
                    <label class="form-label small">Resolution Notes *</label>
                    <textarea name="resolution_notes" class="form-control form-control-sm mb-2" rows="3" required placeholder="e.g. Team received warning, player suspended for next game..."></textarea>
                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check-circle"></i> Resolve</button>
                </form>
                <form method="post" action="<?= url('incident-action') ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="incident_action" value="dismiss">
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <input type="hidden" name="incident_id" value="<?= $incidentId ?>">
                    <label class="form-label small">Dismissal Reason *</label>
                    <textarea name="resolution_notes" class="form-control form-control-sm mb-2" rows="2" required placeholder="e.g. Complaint dismissed — insufficient evidence."></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-x-circle"></i> Dismiss</button>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">This incident is <?= e(strtolower($statusLabel[$incident['status']])) ?>. If a new issue occurs, file a new incident report.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
