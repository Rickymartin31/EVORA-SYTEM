<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);

// Ownership check — event_id is never trusted alone, must belong to the
// logged-in organizer, same pattern as payment_dashboard.php/financial_report_export.php.
$eventCheck = $db->prepare('SELECT id FROM events WHERE id = :id AND organizer_id = :oid');
$eventCheck->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
if (!$eventCheck->fetch()) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$data = ReportQueries::eventAnalytics($db, $eventId);
$event = $data['event'];

$pageDescription = 'Registrations, attendance and revenue for this event.';
$pageTitle = 'Event Analytics';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Analytics'],
]); ?>

<div class="card-evora p-4 mb-3">
    <h5 class="fw-bold mb-1"><?= e($event['name']) ?></h5>
    <p class="text-muted small mb-0"><?= date('F d, Y', strtotime($event['event_date'])) ?> &bull; <?= e($event['category_name']) ?></p>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-2">Event Insights</h6>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-info"><i class="bi bi-star fs-4 text-muted"></i><div class="stat-value" style="font-size:1.15rem;"><?= e($data['most_popular_category'] ?? 'N/A') ?></div><div class="stat-label">Most Popular Category</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-warning"><i class="bi bi-graph-up-arrow fs-4 text-muted"></i><div class="stat-value" style="font-size:1.15rem;"><?= $data['peak_date'] ? date('M d, Y', strtotime($data['peak_date'])) : 'N/A' ?></div><div class="stat-label">Registration Peak</div><div class="stat-desc"><?= $data['peak_count'] ?> in one day</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card accent-success"><i class="bi bi-cash-stack fs-4 text-muted"></i><div class="stat-value"><?= currency($data['total_revenue']) ?></div><div class="stat-label">Revenue</div></div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card"><i class="bi bi-clipboard-check fs-4 text-muted"></i><div class="stat-value"><?= $data['attendance_rate_pct'] ?>%</div><div class="stat-label">Attendance</div><div class="stat-desc">Capacity used: <?= $data['capacity_usage_pct'] ?>%</div></div>
    </div>
</div>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-speedometer2 text-primary me-1"></i> Event Performance Summary</h6>
    <div class="row g-3 text-center">
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-4"><?= $data['registered_count'] ?> / <?= $data['max_participants'] ?></div><div class="text-muted small">Registered / Capacity</div></div>
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-4 text-success"><?= $data['paid_count'] ?></div><div class="text-muted small">Paid Participants</div></div>
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-4 text-warning"><?= $data['pending_reg_count'] ?></div><div class="text-muted small">Pending Registrations</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4"><?= $data['completion_rate_pct'] ?>%</div><div class="text-muted small">Registration Completion Rate</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4"><?= $data['capacity_usage_pct'] ?>%</div><div class="text-muted small">Maximum Capacity Usage</div></div>
    </div>
</div>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-graph-up text-primary me-1"></i> Registration Analytics</h6>
    <?php if (empty($data['registration_trend'])): ?>
        <?= emptyState('bi-bar-chart', 'No registrations yet.') ?>
    <?php else: ?>
    <canvas id="regTrendChart" height="90"></canvas>
    <div class="row g-2 text-center mt-3 pt-2 border-top">
        <div class="col"><div class="fw-bold"><?= $data['peak_date'] ? date('M d, Y', strtotime($data['peak_date'])) : '—' ?></div><div class="text-muted small">Peak Registration Date (<?= $data['peak_count'] ?> in a day)</div></div>
        <?php if ($data['deadline_rush_pct'] !== null): ?>
        <div class="col"><div class="fw-bold"><?= $data['deadline_rush_pct'] ?>%</div><div class="text-muted small">Registered in the final 3 days before deadline</div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-list-ol text-primary me-1"></i> Category Performance</h6>
    <?php if ($event['category_slug'] !== 'fun-run'): ?>
        <p class="text-muted small mb-0">Not applicable — this event doesn't use selectable race categories.</p>
    <?php elseif (empty($data['categories'])): ?>
        <?= emptyState('bi-list-ol', 'No race categories set up yet.') ?>
    <?php else: ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <canvas id="categoryChart" height="220"></canvas>
        </div>
        <div class="col-lg-7">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Category</th><th>Participants</th><th>Capacity Used</th><th>Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['categories'] as $cat): ?>
                        <tr>
                            <td><?= e($cat['name']) ?> <?php if ($cat['name'] === $data['most_popular_category']): ?><span class="badge bg-primary">Most Popular</span><?php endif; ?></td>
                            <td><?= $cat['participant_count'] ?></td>
                            <td><?= $cat['capacity_pct'] ?>%</td>
                            <td><?= currency($cat['revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3"><i class="bi bi-qr-code-scan text-primary me-1"></i> Attendance Summary</h6>
    <div class="row g-3 text-center mb-3">
        <div class="col-sm-3"><div class="fw-bold fs-4"><?= $data['approved_count'] ?></div><div class="text-muted small">Registered (Approved)</div></div>
        <div class="col-sm-3"><div class="fw-bold fs-4 text-success"><?= $data['checked_in_count'] ?></div><div class="text-muted small">Checked-in</div></div>
        <div class="col-sm-3"><div class="fw-bold fs-4 text-danger"><?= $data['no_show_count'] ?></div><div class="text-muted small">No-show</div></div>
        <div class="col-sm-3"><div class="fw-bold fs-4"><?= $data['attendance_rate_pct'] ?>%</div><div class="text-muted small">Attendance Rate</div></div>
    </div>
    <?php if ($data['finish_rate_pct'] !== null): ?>
        <p class="text-muted small mb-3">Finish Rate: <strong><?= $data['finish_rate_pct'] ?>%</strong> of approved runners have a recorded finish time.</p>
    <?php endif; ?>
    <?php if (!empty($data['checkin_timeline'])): ?>
    <canvas id="checkinTimelineChart" height="80"></canvas>
    <?php else: ?>
        <?= emptyState('bi-qr-code-scan', 'No check-ins recorded yet. They appear here as staff scan participants at the entrance.') ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
<?php if (!empty($data['registration_trend'])): ?>
new Chart(document.getElementById('regTrendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn ($p) => date('M d', strtotime($p['date'])), $data['registration_trend'])) ?>,
        datasets: [{ label: 'Registrations', data: <?= json_encode(array_column($data['registration_trend'], 'count')) ?>, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.15)', tension: .3, fill: true }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
<?php if (!empty($data['categories'])): ?>
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($data['categories'], 'name')) ?>,
        datasets: [{ label: 'Participants', data: <?= json_encode(array_column($data['categories'], 'participant_count')) ?>, backgroundColor: '#4f46e5' }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
<?php if (!empty($data['checkin_timeline'])): ?>
new Chart(document.getElementById('checkinTimelineChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($data['checkin_timeline'], 'hour')) ?>,
        datasets: [{ label: 'Check-ins', data: <?= json_encode(array_column($data['checkin_timeline'], 'count')) ?>, backgroundColor: '#16a34a' }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
