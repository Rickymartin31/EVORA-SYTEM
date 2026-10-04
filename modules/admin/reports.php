<?php
Auth::requireRole('admin');
$db = Database::connection();

$reportTypes = [
    'platform_statistics' => ['label' => 'Platform Statistics', 'icon' => 'bi-bar-chart-line', 'desc' => 'Users, organizers, events, and revenue across the whole platform.'],
    'organizer_statistics' => ['label' => 'Organizer Statistics', 'icon' => 'bi-building', 'desc' => 'Per-organizer breakdown of events run and participants reached.'],
    'event_statistics' => ['label' => 'Event Statistics', 'icon' => 'bi-calendar-event', 'desc' => 'Every event across the platform with status, approvals, and attendance.'],
    'certificate_activity' => ['label' => 'Certificate Activity', 'icon' => 'bi-award', 'desc' => 'Every certificate issued platform-wide, who generated it, and its status.'],
    'payment_summary' => ['label' => 'Payment Summary', 'icon' => 'bi-pie-chart', 'desc' => 'Who received the money: PayPal paid directly to organizers vs. legacy PayMongo collected by EVORA.'],
];
// The old "Payment Reports" link (admin-payment-reports) still opens this page on the Payment Summary.
$isPaymentReports = ($_GET['route'] ?? '') === 'admin-payment-reports';
$type = $_GET['type'] ?? ($isPaymentReports ? 'payment_summary' : 'platform_statistics');

// Shared date-range filter (Platform + Organizer Statistics); Event Statistics
// additionally takes category + status. Both filter inputs are optional —
// leaving them blank reproduces the exact unfiltered report.
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$categoryId = (int) ($_GET['category_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$dateFilters = ($dateFrom !== '' && $dateTo !== '') ? ['date_from' => $dateFrom, 'date_to' => $dateTo] : [];

$data = match ($type) {
    'organizer_statistics' => ReportQueries::organizerStatistics($db, $dateFilters),
    'event_statistics' => ReportQueries::eventStatistics($db, array_filter([
        'category_id' => $categoryId ?: null,
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'status' => $statusFilter ?: null,
    ])),
    'certificate_activity' => ReportQueries::certificateActivity($db),
    'payment_summary' => ReportQueries::paymentProviderSummary($db, $dateFilters),
    default => ReportQueries::platformStatistics($db, $dateFilters),
};

$categories = $db->query('SELECT id, name FROM event_categories ORDER BY name')->fetchAll();
$eventStatuses = ['draft', 'pending_approval', 'approved', 'open', 'closed', 'ongoing', 'completed', 'cancelled'];

$pageDescription = 'Generate and export platform reports.';
$pageTitle = 'Reports';
$activeRoute = 'admin-reports';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3 mb-3">
    <?php foreach ($reportTypes as $key => $info): ?>
    <?php $tileParams = array_filter(['type' => $key, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'category_id' => $categoryId ?: null, 'status' => $statusFilter]); ?>
    <div class="col-md-4">
        <div class="card-evora report-launcher-card p-4 h-100 <?= $key === $type ? 'active' : '' ?>">
            <div class="d-flex align-items-start gap-3 mb-3">
                <i class="bi <?= e($info['icon']) ?> fs-3 text-primary"></i>
                <div>
                    <h6 class="fw-semibold mb-1"><?= e($info['label']) ?></h6>
                    <p class="text-muted small mb-0"><?= e($info['desc']) ?></p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= url('admin-reports', $tileParams) ?>" class="btn btn-sm btn-outline-primary flex-fill">Preview</a>
                <a href="<?= url('admin-report-export', array_merge($tileParams, ['format' => 'pdf'])) ?>" class="btn btn-sm btn-outline-danger" title="Download PDF" aria-label="Download PDF" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a>
                <a href="<?= url('admin-report-export', array_merge($tileParams, ['format' => 'xlsx'])) ?>" class="btn btn-sm btn-outline-success" title="Export Excel" aria-label="Export Excel" data-bs-toggle="tooltip"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-md-4">
        <div class="card-evora report-launcher-card p-4 h-100">
            <div class="d-flex align-items-start gap-3 mb-3">
                <i class="bi bi-star-half fs-3 text-warning"></i>
                <div>
                    <h6 class="fw-semibold mb-1">System Feedback</h6>
                    <p class="text-muted small mb-0">EVORA satisfaction ratings from participants, organizers and staff — by role, rating and date.</p>
                </div>
            </div>
            <a href="<?= url('admin-evaluations') ?>" class="btn btn-sm btn-outline-primary w-100">Open Analytics</a>
        </div>
    </div>
</div>

<?php if ($type !== 'certificate_activity'): ?>
<div class="card-evora p-3 mb-3">
    <form method="get" action="<?= url('admin-reports') ?>" class="row g-2 align-items-end">
        <input type="hidden" name="route" value="admin-reports">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php if ($type === 'event_statistics'): ?>
        <div class="col-md-3">
            <label class="form-label small">Event Category</label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Event Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach ($eventStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
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
            <a href="<?= url('admin-reports', ['type' => $type]) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
        <?php if ($type !== 'event_statistics'): ?>
        <div class="col-12">
            <div class="form-text">Date range applies only to metrics with an activity date (Events, Registrations, Payments). Total Users, Organizer counts, and Total Participants always reflect the current platform total regardless of this filter.</div>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.report-launcher-card').forEach(function (card) {
    var previewLink = card.querySelector('a.btn-outline-primary');
    if (!previewLink) return;
    card.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        window.location.href = previewLink.href;
    });
});
</script>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><?= e($reportTypes[$type]['label'] ?? 'Report') ?></h6>
    <?php if (empty($data['rows'])): ?>
        <?= emptyState('bi-bar-chart', 'No data available.') ?>
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
