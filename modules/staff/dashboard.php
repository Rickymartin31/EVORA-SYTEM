<?php
Auth::requireRole('staff');
$db = Database::connection();
$userId = Auth::id();

$assignments = $db->prepare(
    'SELECT e.*, c.name AS category_name, c.slug AS category_slug, es.id AS event_staff_id
     FROM event_staff es
     JOIN events e ON e.id = es.event_id
     JOIN event_categories c ON c.id = e.category_id
     WHERE es.user_id = :uid
     AND e.status NOT IN ("removed", "archived") -- events an admin removed are not shown to staff
     ORDER BY (e.event_date < CURDATE()), CASE WHEN e.event_date >= CURDATE() THEN e.event_date END, e.event_date DESC'
);
$assignments->execute(['uid' => $userId]);
$events = $assignments->fetchAll();

$permStmt = $db->prepare('SELECT permission_key FROM staff_permissions WHERE event_staff_id = :id');

$assignedCount = count($events);
$activeAssignedCount = count(array_filter($events, fn($ev) => in_array($ev['status'], ['open', 'ongoing'], true)));

$checkInsStmt = $db->prepare('SELECT COUNT(*) c FROM attendance WHERE staff_id = :uid');
$checkInsStmt->execute(['uid' => $userId]);
$checkInsCount = (int) $checkInsStmt->fetch()['c'];

$checkInsTodayStmt = $db->prepare('SELECT COUNT(*) c FROM attendance WHERE staff_id = :uid AND DATE(scanned_at) = CURDATE()');
$checkInsTodayStmt->execute(['uid' => $userId]);
$checkInsTodayCount = (int) $checkInsTodayStmt->fetch()['c'];

// Event-scoped tool catalog: permission => [route, icon, label, category_slug it applies to].
// This is a discovery aid only (matches the organizer event_manage.php tool-tile
// pattern) — every route still independently re-checks ownership/permission.
$toolCatalog = [
    'manage_live_scores' => ['match-center', 'bi-broadcast', 'Match Operations', 'sports-tournament'],
    'manage_incidents' => ['incidents', 'bi-exclamation-octagon', 'Incident Reports', 'sports-tournament'],
    'manage_bibs' => ['organizer-bib-assign', 'bi-upc-scan', 'Bib Management', 'fun-run'],
    'manage_finish_results' => ['organizer-race-results', 'bi-flag-fill', 'Finish Results', 'fun-run'],
    'manage_checkpoints' => ['checkpoint-scan', 'bi-qr-code-scan', 'Checkpoint Monitoring', 'fun-run'],
    'manage_medical' => ['medical-incidents', 'bi-heart-pulse', 'Medical Incidents', 'fun-run'],
    'view_race_operations' => ['race-operations-dashboard', 'bi-speedometer2', 'Race Operations Dashboard', 'fun-run'],
    'manage_sessions' => ['organizer-schedule', 'bi-clock-history', 'Session Management', 'seminar-workshop'],
    'manage_speakers' => ['organizer-speakers', 'bi-mic', 'Speaker Management', 'seminar-workshop'],
    'manage_feedback' => ['feedback-summary', 'bi-star', 'Feedback Summary', 'seminar-workshop'],
];
// Secondary tools surfaced under an existing permission for a specific category
// (kept out of the main catalog since they share a permission with another tool).
$bonusTools = [
    'manage_claims' => ['fun-run' => ['kit-claim-search', 'bi-search', 'Kit Claim Search']],
    'manage_sessions' => ['seminar-workshop' => ['session-attendance-scan', 'bi-qr-code-scan', 'Session Attendance Scan']],
];

$pageDescription = 'Your event assignments and check-in tools.';
$pageTitle = 'Staff Dashboard';
$activeRoute = 'staff-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php include EVORA_ROOT . '/modules/shared/partials/today_panel.php'; ?>
<div class="row g-2 mb-3">
    <div class="col-12 col-lg-4"><a href="<?= url('staff-scan') ?>" class="quick-action qa-primary py-3"><span class="qa-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span><span>Scan QR Code<span class="qa-sub">Check participants in</span></span></a></div>
    <div class="col-6 col-lg"><a href="<?= url('staff-attendance') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span><span>Attendance</span></a></div>
    <div class="col-6 col-lg"><a href="<?= url('staff-claims') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span>Item Claiming</span></a></div>
    <div class="col-12 col-lg"><a href="<?= url('staff-announcements') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span><span>Announcements</span></a></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card"><i class="bi bi-calendar-event fs-4 text-muted"></i><div class="stat-value"><?= $assignedCount ?></div><div class="stat-label">Assigned Events</div><div class="stat-desc">Across all statuses</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-info"><i class="bi bi-broadcast fs-4 text-muted"></i><div class="stat-value"><?= $activeAssignedCount ?></div><div class="stat-label">Active Now</div><div class="stat-desc">Open or ongoing events</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-success"><i class="bi bi-qr-code-scan fs-4 text-muted"></i><div class="stat-value"><?= $checkInsTodayCount ?></div><div class="stat-label">Check-ins Today</div><div class="stat-desc">Scanned by you</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-warning"><i class="bi bi-clipboard-data fs-4 text-muted"></i><div class="stat-value"><?= $checkInsCount ?></div><div class="stat-label">Total Check-ins</div><div class="stat-desc">All-time attendance recorded</div></div>
    </div>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">My Event Assignments</h6>
    <?php if (empty($events)): ?>
        <?= emptyState('bi-person-badge', 'You have not been assigned to any event yet. Your organizer adds you as staff to an event, and its tools appear here.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Event</th><th>Type</th><th>Date</th><th>My Permissions</th><th>Status</th><th>Available Staff Tools</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <?php
                $permStmt->execute(['id' => $ev['event_staff_id']]);
                $perms = array_column($permStmt->fetchAll(), 'permission_key');

                // Resolve which tools this permission set unlocks for this
                // specific event's category — never show a tool the event
                // type doesn't support even if the permission happens to be granted.
                $availableTools = [];
                foreach ($toolCatalog as $permKey => [$route, $icon, $label, $category]) {
                    if (in_array($permKey, $perms, true) && $ev['category_slug'] === $category) {
                        $availableTools[] = [$route, $icon, $label];
                    }
                }
                foreach ($bonusTools as $permKey => $byCategory) {
                    if (in_array($permKey, $perms, true) && isset($byCategory[$ev['category_slug']])) {
                        $availableTools[] = $byCategory[$ev['category_slug']];
                    }
                }
                ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td>
                        <?php foreach ($perms as $p): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1"><?= e(str_replace('_', ' ', $p)) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><span class="badge badge-status-<?= e($ev['status']) ?>"><?= ucfirst(e($ev['status'])) ?></span></td>
                    <td>
                        <?php if (empty($availableTools)): ?>
                            <span class="text-muted small">No event-specific tools granted</span>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($availableTools as [$route, $icon, $label]): ?>
                                <a href="<?= url($route, ['event_id' => $ev['id']]) ?>" class="btn btn-sm btn-outline-primary"><i class="bi <?= $icon ?>"></i> <?= e($label) ?></a>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
