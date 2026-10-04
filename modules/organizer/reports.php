<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventsStmt = $db->prepare('SELECT id, name FROM events WHERE organizer_id = :oid ORDER BY event_date DESC');
$eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
$events = $eventsStmt->fetchAll();

$reportTypes = [
    'event_summary' => ['label' => 'Event Summary', 'icon' => 'bi-info-circle', 'desc' => 'Key details and registration counts for this event.'],
    'participant_list' => ['label' => 'Participant List', 'icon' => 'bi-people', 'desc' => 'Full roster of registered participants and their status.'],
    'payment_summary' => ['label' => 'Payment Summary', 'icon' => 'bi-credit-card', 'desc' => 'Payment amounts and review status for this event.'],
    'attendance_report' => ['label' => 'Attendance Report', 'icon' => 'bi-clipboard-check', 'desc' => 'Who checked in, when, and which staff verified them.'],
    'staff_activity' => ['label' => 'Staff Activity', 'icon' => 'bi-person-badge', 'desc' => 'Check-ins and item releases logged by each assigned staff member.'],
    'completion_report' => ['label' => 'Completion Report', 'icon' => 'bi-flag', 'desc' => 'Final attendance rate, funds collected, and items claimed.'],
];

$eventId = (int) ($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$reportType = $_GET['type'] ?? 'event_summary';
if (!isset($reportTypes[$reportType])) {
    $reportType = 'event_summary';
}

// Registration Status applies to Participant List; date range applies to
// whichever report has a natural per-row date (Participant List, Payment
// Summary, Attendance Report) — Event Summary/Staff Activity/Completion
// Report stay all-time snapshots by design, unaffected by these filters.
$statusFilter = trim($_GET['reg_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filters = array_filter([
    'status' => $statusFilter ?: null,
    'date_from' => $dateFrom ?: null,
    'date_to' => $dateTo ?: null,
]);

$data = [];
$event = null;

if ($eventId) {
    $eventCheck = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventCheck->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
    $event = $eventCheck->fetch();

    if ($event) {
        $data = ReportQueries::run($db, $reportType, $eventId, $filters);
    }
}

$filterableTypes = ['participant_list', 'payment_summary', 'attendance_report'];

$pageDescription = 'Generate and export reports for your events.';
$pageTitle = 'Reports';
$activeRoute = 'organizer-reports';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('organizer-reports') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="organizer-reports">
        <input type="hidden" name="type" value="<?= e($reportType) ?>">
        <div class="col-md-8">
            <label class="form-label small">Event</label>
            <select name="event_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>><?= e($ev['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($event): ?>
<div class="row g-3 mb-3">
    <?php foreach ($reportTypes as $key => $info): ?>
    <?php $tileParams = array_filter(array_merge(['event_id' => $eventId, 'type' => $key], $filters)); ?>
    <div class="col-md-4">
        <div class="card-evora report-launcher-card p-4 h-100 <?= $key === $reportType ? 'active' : '' ?>">
            <div class="d-flex align-items-start gap-3 mb-3">
                <i class="bi <?= e($info['icon']) ?> fs-3 text-primary"></i>
                <div>
                    <h6 class="fw-semibold mb-1"><?= e($info['label']) ?></h6>
                    <p class="text-muted small mb-0"><?= e($info['desc']) ?></p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= url('organizer-reports', $tileParams) ?>" class="btn btn-sm btn-outline-primary flex-fill">Preview</a>
                <a href="<?= url('organizer-report-export', array_merge($tileParams, ['format' => 'pdf'])) ?>" class="btn btn-sm btn-outline-danger" title="Download PDF" aria-label="Download PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a>
                <a href="<?= url('organizer-report-export', array_merge($tileParams, ['format' => 'xlsx'])) ?>" class="btn btn-sm btn-outline-success" title="Export Excel" aria-label="Export Excel" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="col-md-4">
        <div class="card-evora report-launcher-card p-4 h-100">
            <div class="d-flex align-items-start gap-3 mb-3">
                <i class="bi bi-cash-stack fs-3 text-primary"></i>
                <div>
                    <h6 class="fw-semibold mb-1">Event Financial Report</h6>
                    <p class="text-muted small mb-0">Full payment breakdown by channel, registration/payment status, and every transaction for this event.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= url('organizer-financial-report-export', ['event_id' => $eventId, 'format' => 'pdf']) ?>" class="btn btn-sm btn-outline-danger flex-fill" title="Download PDF" aria-label="Download PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a>
                <a href="<?= url('organizer-financial-report-export', ['event_id' => $eventId, 'format' => 'xlsx']) ?>" class="btn btn-sm btn-outline-success flex-fill" title="Export Excel" aria-label="Export Excel" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Excel</a>
            </div>
        </div>
    </div>
</div>

<div class="card-evora p-3 mb-3">
    <form method="get" action="<?= url('organizer-reports') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="organizer-reports">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="hidden" name="type" value="<?= e($reportType) ?>">
        <div class="col-md-3">
            <label class="form-label small">Registration Status</label>
            <select name="reg_status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Date From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Date To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-evora-primary btn-sm w-100">Apply Filters</button>
            <a href="<?= url('organizer-reports', ['event_id' => $eventId, 'type' => $reportType]) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
        <div class="col-12">
            <div class="form-text">
                <?php if (in_array($reportType, $filterableTypes, true)): ?>
                    Registration Status filters Participant List only. Date range filters Participant List (registered on), Payment Summary (submitted on), and Attendance Report (checked in on).
                <?php else: ?>
                    These filters don't apply to <?= e($reportTypes[$reportType]['label']) ?> — it's an all-time snapshot. Switch to Participant List, Payment Summary, or Attendance Report to filter.
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.report-launcher-card').forEach(function (card) {
    var previewLink = card.querySelector('a.btn-outline-primary');
    if (!previewLink) return;
    card.addEventListener('click', function (e) {
        if (e.target.closest('a')) return; // let Preview/PDF/Excel links handle their own click
        window.location.href = previewLink.href;
    });
});
</script>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><?= e($reportTypes[$reportType]['label'] ?? 'Report') ?><?= $event ? ' — ' . e($event['name']) : '' ?></h6>
    <?php if (!$event): ?>
        <p class="text-muted small mb-0">Create an event first to generate reports.</p>
    <?php elseif (empty($data['rows'])): ?>
        <?= emptyState('bi-bar-chart', 'No data available for this report yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle table-datatable">
            <thead><tr><?php foreach ($data['columns'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
