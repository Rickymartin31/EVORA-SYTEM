<?php
Auth::requireRole('admin');
$db = Database::connection();

$totalUsers = $db->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
$totalOrganizers = $db->query('SELECT COUNT(*) c FROM organizers')->fetch()['c'];
$totalEvents = $db->query('SELECT COUNT(*) c FROM events')->fetch()['c'];
$activeEvents = $db->query("SELECT COUNT(*) c FROM events WHERE status IN ('open','ongoing')")->fetch()['c'];
$completedEvents = $db->query("SELECT COUNT(*) c FROM events WHERE status = 'completed'")->fetch()['c'];
$totalParticipants = $db->query('SELECT COUNT(*) c FROM participants')->fetch()['c'];
$pendingOrganizers = $db->query("SELECT COUNT(*) c FROM organizers WHERE status = 'pending'")->fetch()['c'];
$pendingEventApprovals = $db->query("SELECT COUNT(*) c FROM events WHERE status = 'pending_approval'")->fetch()['c'];
// PayPal payments go straight to the organizer, so they are shown apart and never counted as EVORA revenue.
$revenueSplit = ReportQueries::revenueSplit($db);
$totalRevenue = $revenueSplit['total'];

$recentActivity = $db->query(
    'SELECT a.*, u.full_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 10'
)->fetchAll();

$eventsByCategory = $db->query(
    'SELECT c.name, COUNT(e.id) total FROM event_categories c LEFT JOIN events e ON e.category_id = c.id GROUP BY c.id, c.name'
)->fetchAll();

/** Builds ['2026-03' => 'Mar 2026', ...] for the last $n months, oldest first. */
function lastNMonths(int $n): array
{
    $months = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("-$i months"))] = date('M Y', strtotime("-$i months"));
    }
    return $months;
}

$months = lastNMonths(6);

$eventsByMonthRaw = $db->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) c FROM events
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$registrationsByMonthRaw = $db->query(
    "SELECT DATE_FORMAT(registered_at, '%Y-%m') ym, COUNT(*) c FROM registrations
     WHERE registered_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$participantsByMonthRaw = $db->query(
    "SELECT DATE_FORMAT(u.created_at, '%Y-%m') ym, COUNT(*) c FROM participants p
     JOIN users u ON u.id = p.user_id
     WHERE u.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$participantsBeforeWindow = $db->prepare(
    "SELECT COUNT(*) c FROM participants p JOIN users u ON u.id = p.user_id WHERE u.created_at < :start"
);
$participantsBeforeWindow->execute(['start' => date('Y-m-01', strtotime('-5 months'))]);
$cumulativeParticipants = (int) $participantsBeforeWindow->fetch()['c'];

$eventsByMonth = [];
$registrationsByMonth = [];
$participantGrowth = [];
foreach ($months as $ym => $label) {
    $eventsByMonth[] = (int) ($eventsByMonthRaw[$ym] ?? 0);
    $registrationsByMonth[] = (int) ($registrationsByMonthRaw[$ym] ?? 0);
    $cumulativeParticipants += (int) ($participantsByMonthRaw[$ym] ?? 0);
    $participantGrowth[] = $cumulativeParticipants;
}

$approvedRegCount = $db->query("SELECT COUNT(*) c FROM registrations WHERE status = 'approved'")->fetch()['c'];
$attendedCount = $db->query(
    "SELECT COUNT(*) c FROM attendance a JOIN registrations r ON r.id = a.registration_id WHERE r.status = 'approved'"
)->fetch()['c'];
$attendanceRate = $approvedRegCount > 0 ? round(($attendedCount / $approvedRegCount) * 100, 1) : 0;

$paymentStatusRaw = $db->query('SELECT status, COUNT(*) c FROM payments GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$paymentApproved = (int) ($paymentStatusRaw['approved'] ?? 0);
$paymentPending = (int) ($paymentStatusRaw['pending'] ?? 0);
$paymentRejected = (int) ($paymentStatusRaw['rejected'] ?? 0);

$pageDescription = 'Platform overview, pending actions and system health at a glance.';
$pageTitle = 'Admin Dashboard';
$activeRoute = 'admin-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if ($pendingOrganizers > 0 || $pendingEventApprovals > 0): ?>
<?= evoraAlert('action',
    ($pendingOrganizers > 0 ? '<strong>' . (int) $pendingOrganizers . '</strong> organizer application(s) awaiting review. ' : '')
    . ($pendingEventApprovals > 0 ? '<strong>' . (int) $pendingEventApprovals . '</strong> event(s) awaiting approval.' : ''), [
    'icon' => 'bi-exclamation-circle', 'class' => 'mb-3',
    'actions' => ($pendingOrganizers > 0 ? '<a href="' . e(url('admin-organizers', ['status' => 'pending'])) . '" class="btn btn-sm btn-outline-dark">Review Organizers</a>' : '')
        . ($pendingEventApprovals > 0 ? '<a href="' . e(url('admin-event-approvals')) . '" class="btn btn-sm btn-outline-dark">Review Events</a>' : ''),
]) ?>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card"><i class="bi bi-people fs-4 text-muted"></i><div class="stat-value"><?= $totalUsers ?></div><div class="stat-label">Total Users</div><div class="stat-desc">Registered platform-wide</div></div>
    </div>
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card accent-info"><i class="bi bi-building fs-4 text-muted"></i><div class="stat-value"><?= $totalOrganizers ?></div><div class="stat-label">Organizers</div><div class="stat-desc">Approved &amp; pending</div></div>
    </div>
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card accent-warning"><i class="bi bi-calendar-event fs-4 text-muted"></i><div class="stat-value"><?= $totalEvents ?></div><div class="stat-label">Total Events</div><div class="stat-desc"><?= trendIndicator($eventsByMonth) ?></div></div>
    </div>
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card accent-success"><i class="bi bi-broadcast fs-4 text-muted"></i><div class="stat-value"><?= $activeEvents ?></div><div class="stat-label">Active Events</div><div class="stat-desc">Open or ongoing now</div></div>
    </div>
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card"><i class="bi bi-person-check fs-4 text-muted"></i><div class="stat-value"><?= $totalParticipants ?></div><div class="stat-label">Participants</div><div class="stat-desc"><?= trendIndicator($participantGrowth) ?></div></div>
    </div>
    <div class="col-6 col-md-4 col-xxl-2">
        <div class="stat-card accent-success"><i class="bi bi-cash-coin fs-4 text-muted"></i><div class="stat-value"><?= currency($totalRevenue) ?></div><div class="stat-label">Total Revenue</div><div class="stat-desc">Collected by EVORA (legacy PayMongo): <?= currency($revenueSplit['evora_collected']) ?></div><div class="stat-desc">Paid directly to organizers (PayPal, not collected by EVORA): <?= currency($revenueSplit['direct_to_organizers']) ?></div></div>
    </div>
</div>

<?php
$pendingActions = [
    ['Organizer applications', 'bi-person-check', (int) $pendingOrganizers, url('admin-organizers', ['status' => 'pending'])],
    ['Events awaiting approval', 'bi-patch-check', (int) $pendingEventApprovals, url('admin-event-approvals')],
];
$pendingTotal = array_sum(array_column($pendingActions, 2));
?>
<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card-evora p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-semibold mb-0">Pending Actions</h6>
                <span class="badge <?= $pendingTotal ? 'bg-warning' : 'bg-success' ?>"><?= $pendingTotal ? $pendingTotal . ' waiting' : 'All clear' ?></span>
            </div>
            <?php foreach ($pendingActions as [$label, $icon, $count, $href]): ?>
                <a href="<?= $href ?>" class="pending-row">
                    <span><i class="bi <?= $icon ?> me-2 text-muted" aria-hidden="true"></i><?= e($label) ?></span>
                    <span class="pending-count <?= $count ? 'has-items' : '' ?>"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3">Quick Actions</h6>
            <div class="row g-2">
                <div class="col-sm-6"><a href="<?= url('admin-event-approvals') ?>" class="quick-action qa-primary"><span class="qa-icon"><i class="bi bi-patch-check" aria-hidden="true"></i></span><span>Review Events<span class="qa-sub">Approve submitted events</span></span></a></div>
                <div class="col-sm-6"><a href="<?= url('admin-organizers') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span><span>Verify Organizers<span class="qa-sub">Applications &amp; documents</span></span></a></div>
                <div class="col-sm-6"><a href="<?= url('admin-paypal-transactions') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-paypal" aria-hidden="true"></i></span><span>PayPal Transactions<span class="qa-sub">Monitor online payments</span></span></a></div>
                <div class="col-sm-6"><a href="<?= url('admin-reports') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-bar-chart" aria-hidden="true"></i></span><span>Reports<span class="qa-sub">Exports &amp; system feedback</span></span></a></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Events per Category</h6>
            <canvas id="categoryChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Completed vs Active Events</h6>
            <canvas id="statusChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Monthly Event Creation</h6>
            <canvas id="monthlyEventsChart" height="220"></canvas>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Registration Trend</h6>
            <canvas id="registrationTrendChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Participant Growth</h6>
            <canvas id="participantGrowthChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4 text-center">
            <h6 class="fw-semibold mb-3">Attendance Rate</h6>
            <canvas id="attendanceChart" height="180"></canvas>
            <p class="mt-2 mb-0 fw-bold fs-4"><?= $attendanceRate ?>%</p>
            <p class="text-muted small mb-0"><?= $attendedCount ?> attended of <?= $approvedRegCount ?> approved</p>
        </div>
    </div>
</div>

<?php $platformFeedback = EvaluationService::platformAnalytics($db, 3); ?>
<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-star-fill text-warning me-1"></i> EVORA System Feedback Analytics</h6>
        <a href="<?= url('admin-evaluations') ?>" class="btn btn-sm btn-outline-primary">View All Feedback</a>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <div class="small text-muted">Overall EVORA Rating</div>
            <div class="fs-4 fw-bold"><span class="rating-stars"><?= EvaluationService::stars($platformFeedback['average']) ?></span> <?= $platformFeedback['responses'] ? number_format($platformFeedback['average'], 1) . ' <span class="fs-6 text-muted fw-normal">/ 5</span>' : '—' ?></div>
            <div class="small text-muted mt-2">Total Responses</div>
            <div class="fw-semibold"><?= $platformFeedback['responses'] ?></div>
            <div class="small text-muted mt-2">User Satisfaction <span title="Share of ratings of 4 or 5 stars">(4–5 ★)</span></div>
            <div class="fw-semibold"><?= $platformFeedback['responses'] ? $platformFeedback['satisfaction'] . '%' : '—' ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted mb-1">Role Breakdown</div>
            <?php foreach ($platformFeedback['by_role'] as $roleName => $roleRow): ?>
                <div class="border-bottom py-1 small">
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold"><?= e($roleName === 'staff' ? 'Staff' : ucfirst($roleName) . 's') ?></span>
                        <span><?php if ($roleRow['responses']): ?><span class="rating-stars"><?= EvaluationService::stars($roleRow['average']) ?></span> <?= number_format($roleRow['average'], 1) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></span>
                    </div>
                    <div class="text-muted"><?= $roleRow['responses'] ?> response<?= $roleRow['responses'] === 1 ? '' : 's' ?><?= $roleRow['satisfaction'] !== null ? ' · ' . $roleRow['satisfaction'] . '% satisfied' : '' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="col-md-5">
            <div class="small text-muted mb-1">Recent Feedback</div>
            <?php if (!$platformFeedback['recent_comments']): ?>
                <p class="small text-muted mb-0">No written feedback yet.</p>
            <?php else: ?>
                <?php foreach ($platformFeedback['recent_comments'] as $fb): ?>
                    <div class="border-start border-3 ps-2 mb-2 small">
                        <strong><?= e(ucfirst($fb['role'])) ?>:</strong> "<?= e(mb_strimwidth($fb['comment'], 0, 160, '…')) ?>"
                        <span class="rating-stars"><?= EvaluationService::stars($fb['rating']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Payment Analytics</h6>
            <canvas id="paymentChart" height="220"></canvas>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Recent System Activity</h6>
            <?php if (empty($recentActivity)): ?>
                <?= emptyState('bi-clock-history', 'No activity recorded yet.') ?>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($recentActivity as $log): ?>
                        <li class="d-flex justify-content-between border-bottom py-2">
                            <span><strong><?= e($log['full_name'] ?? 'System') ?></strong> — <?= e($log['action']) ?></span>
                            <span class="text-muted"><?= date('M d, H:i', strtotime($log['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($eventsByCategory, 'name')) ?>,
        datasets: [{
            label: 'Events',
            data: <?= json_encode(array_map('intval', array_column($eventsByCategory, 'total'))) ?>,
            backgroundColor: EvoraChart.brand
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

// Two values compare better as bars than as a two-slice donut.
new Chart(document.getElementById('statusChart'), {
    type: 'bar',
    data: {
        labels: ['Active', 'Completed'],
        datasets: [{ label: 'Events', data: [<?= $activeEvents ?>, <?= $completedEvents ?>], backgroundColor: EvoraChart.brand }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('monthlyEventsChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_values($months)) ?>,
        datasets: [{ label: 'Events Created', data: <?= json_encode($eventsByMonth) ?>, borderColor: EvoraChart.brand, backgroundColor: EvoraChart.brandFill, fill: true }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('registrationTrendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_values($months)) ?>,
        datasets: [{ label: 'Registrations', data: <?= json_encode($registrationsByMonth) ?>, borderColor: EvoraChart.brand, backgroundColor: EvoraChart.brandFill, fill: true }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('participantGrowthChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_values($months)) ?>,
        datasets: [{ label: 'Total Participants', data: <?= json_encode($participantGrowth) ?>, borderColor: EvoraChart.brand, backgroundColor: EvoraChart.brandFill, fill: true }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('attendanceChart'), {
    type: 'doughnut',
    data: {
        labels: ['Attended', 'Not Attended'],
        datasets: [{ data: [<?= $attendedCount ?>, <?= max(0, $approvedRegCount - $attendedCount) ?>], backgroundColor: [EvoraChart.status.success, EvoraChart.status.track] }]
    },
    options: { plugins: { legend: { display: false } }, cutout: '70%' }
});

new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Pending', 'Rejected'],
        datasets: [{ data: [<?= $paymentApproved ?>, <?= $paymentPending ?>, <?= $paymentRejected ?>], backgroundColor: [EvoraChart.status.success, EvoraChart.status.warning, EvoraChart.status.danger] }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
