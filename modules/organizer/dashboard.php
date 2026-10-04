<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$pageDescription = 'Your events, participants and earnings at a glance.';
$pageTitle = 'Organizer Dashboard';
$activeRoute = 'organizer-dashboard';

if (!$organizer) {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    echo evoraAlert('action', 'No organizer profile found for your account.', ['icon' => 'bi-x-octagon']);
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
    exit;
}

if ($organizer['status'] !== 'approved') {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    $statusMessages = [
        'pending' => ['warning', 'Your organizer application is pending admin review. You will be notified once a decision is made.'],
        'rejected' => ['danger', 'Your organizer application was rejected.' . ($organizer['remarks'] ? ' Reason: ' . e($organizer['remarks']) : '')],
        'suspended' => ['danger', 'Your organizer account has been suspended.' . ($organizer['remarks'] ? ' Reason: ' . e($organizer['remarks']) : '')],
    ];
    
    [$type, $msg] = $statusMessages[$organizer['status']] ?? ['secondary', 'Your account status is unclear. Please contact support.'];
    echo '<div class="card-evora p-5 text-center">';
    echo '<i class="bi bi-hourglass-split fs-1 text-' . $type . ' mb-3"></i>';
    echo '<h5>Account Status: <span class="badge badge-status-' . e($organizer['status']) . '">' . ucfirst(e($organizer['status'])) . '</span></h5>';
    echo '<p class="text-muted">' . $msg . '</p>';
    echo '</div>';
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
    exit;
}

$organizerId = (int) $organizer['id'];

$myEvents = $db->prepare('SELECT COUNT(*) c FROM events WHERE organizer_id = :oid');
$myEvents->execute(['oid' => $organizerId]);
$myEventsCount = $myEvents->fetch()['c'];

$totalParticipants = $db->prepare(
    'SELECT COUNT(*) c FROM registrations r JOIN events e ON e.id = r.event_id WHERE e.organizer_id = :oid AND r.status = "approved"'
);
$totalParticipants->execute(['oid' => $organizerId]);
$totalParticipantsCount = $totalParticipants->fetch()['c'];

$staffCount = $db->prepare(
    'SELECT COUNT(DISTINCT es.user_id) c FROM event_staff es JOIN events e ON e.id = es.event_id WHERE e.organizer_id = :oid'
);
$staffCount->execute(['oid' => $organizerId]);
$staffCountVal = $staffCount->fetch()['c'];

$pendingReg = $db->prepare(
    'SELECT COUNT(*) c FROM registrations r JOIN events e ON e.id = r.event_id WHERE e.organizer_id = :oid AND r.status = "pending"'
);
$pendingReg->execute(['oid' => $organizerId]);
$pendingRegCount = $pendingReg->fetch()['c'];

$pendingPay = $db->prepare(
    'SELECT COUNT(*) c FROM payments p
     JOIN registrations r ON r.id = p.registration_id
     JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid AND p.status = "pending"'
);
$pendingPay->execute(['oid' => $organizerId]);
$pendingPayCount = $pendingPay->fetch()['c'];

$attendanceCount = $db->prepare(
    'SELECT COUNT(*) c FROM attendance a JOIN events e ON e.id = a.event_id WHERE e.organizer_id = :oid'
);
$attendanceCount->execute(['oid' => $organizerId]);
$attendanceCountVal = $attendanceCount->fetch()['c'];

$myEventsList = $db->prepare(
    'SELECT e.*, c.name AS category_name,
        (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = "approved") AS approved_count
     FROM events e JOIN event_categories c ON c.id = e.category_id
     WHERE e.organizer_id = :oid ORDER BY e.event_date DESC LIMIT 5'
);
$myEventsList->execute(['oid' => $organizerId]);
$eventsList = $myEventsList->fetchAll();

$revenueStmt = $db->prepare(
    'SELECT COALESCE(SUM(pay.amount),0) c FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid AND pay.status = "approved"'
);
$revenueStmt->execute(['oid' => $organizerId]);
$revenueTotal = $revenueStmt->fetch()['c'];

$paymentStatusStmt = $db->prepare(
    'SELECT pay.status, COUNT(*) c FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid GROUP BY pay.status'
);
$paymentStatusStmt->execute(['oid' => $organizerId]);
$paymentStatusRaw = $paymentStatusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$paymentApproved = (int) ($paymentStatusRaw['approved'] ?? 0);
$paymentPending = (int) ($paymentStatusRaw['pending'] ?? 0);
$paymentRejected = (int) ($paymentStatusRaw['rejected'] ?? 0);
$paymentFailedCancelled = (int) ($paymentStatusRaw['failed'] ?? 0) + (int) ($paymentStatusRaw['cancelled'] ?? 0);

$paymentChannelStmt = $db->prepare(
    'SELECT pay.payment_channel, COUNT(*) c FROM payments pay
     JOIN registrations r ON r.id = pay.registration_id
     JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid AND pay.status = "approved" GROUP BY pay.payment_channel'
);
$paymentChannelStmt->execute(['oid' => $organizerId]);
$paymentChannelRaw = $paymentChannelStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$paidOnlineCount = (int) ($paymentChannelRaw['paymongo'] ?? 0);
$paidPayPalCount = (int) ($paymentChannelRaw['paypal'] ?? 0);
$paidCashWalkinCount = (int) ($paymentChannelRaw['cash_walkin'] ?? 0);
$paidManualCount = (int) ($paymentChannelRaw['manual'] ?? 0);

function organizerLastNMonths(int $n): array
{
    $months = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("-$i months"))] = date('M Y', strtotime("-$i months"));
    }
    return $months;
}
$months = organizerLastNMonths(6);

$regTrendStmt = $db->prepare(
    "SELECT DATE_FORMAT(r.registered_at, '%Y-%m') ym, COUNT(*) c
     FROM registrations r JOIN events e ON e.id = r.event_id
     WHERE e.organizer_id = :oid AND r.registered_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym"
);
$regTrendStmt->execute(['oid' => $organizerId]);
$regTrendRaw = $regTrendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$registrationTrend = [];
foreach ($months as $ym => $label) {
    $registrationTrend[] = (int) ($regTrendRaw[$ym] ?? 0);
}

// PayPal summary: participants pay the organizer's PayPal account directly; EVORA only records verified payments.
$dashPayPalAccount = PayPalAccountService::current($db, $organizerId);
$dashPayPalStmt = $db->prepare("SELECT t.amount, t.net_amount, t.completed_at, e.name AS event_name, u.full_name AS participant_name
    FROM paypal_transactions t JOIN events e ON e.id = t.event_id JOIN registrations r ON r.id = t.registration_id
    JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
    WHERE t.organizer_id = :oid AND t.paypal_status = 'completed' ORDER BY t.completed_at DESC");
$dashPayPalStmt->execute(['oid' => $organizerId]);
$dashPayPalRows = $dashPayPalStmt->fetchAll();
$dashPayPalTotal = array_sum(array_column($dashPayPalRows, 'amount'));
$dashPayPalRecent = array_slice($dashPayPalRows, 0, 5);
// Event Performance (participant evaluations of this organizer's own events).
$dashPerformance = EvaluationService::organizerSummary($db, (int) $organizerId, 5);

// Getting Started (v53): the steps from an approved account to a running event.
$gsCounts = $db->prepare('SELECT COUNT(*) AS events,
        SUM(status <> "draft") AS submitted,
        SUM(status IN ("open", "closed", "ongoing", "completed", "archived")) AS opened,
        (SELECT COUNT(*) FROM event_staff es JOIN events x ON x.id = es.event_id WHERE x.organizer_id = :o2) AS staff
    FROM events WHERE organizer_id = :o1');
$gsCounts->execute(['o1' => $organizerId, 'o2' => $organizerId]);
$gs = $gsCounts->fetch();
$gsSteps = [
    ['done' => true, 'title' => 'Organizer account verified', 'desc' => 'EVORA approved your organization.', 'label' => null, 'url' => null],
    ['done' => !PAYPAL_ENABLED || (bool) PayPalAccountService::current($db, (int) $organizerId), 'title' => 'Connect your PayPal Business account', 'desc' => 'Needed for paid events: participants pay you directly.', 'label' => 'Connect PayPal', 'url' => url('organizer-paypal-account')],
    ['done' => (int) $gs['events'] > 0, 'title' => 'Create your first event', 'desc' => 'Pick the event type, then fill in the step-by-step form.', 'label' => 'Create event', 'url' => url('organizer-event-type')],
    ['done' => (int) $gs['submitted'] > 0, 'title' => 'Submit it for admin approval', 'desc' => 'From the event page: Submit for Approval.', 'label' => 'My events', 'url' => url('organizer-events')],
    ['done' => (int) $gs['opened'] > 0, 'title' => 'Open registration', 'desc' => 'Once approved, open registration so participants can join.', 'label' => 'My events', 'url' => url('organizer-events')],
    ['done' => (int) $gs['staff'] > 0, 'title' => 'Add staff for event day', 'desc' => 'Staff scan QR codes and help at the entrance.', 'label' => 'Add staff', 'url' => url('organizer-staff')],
];
$gsDone = count(array_filter(array_column($gsSteps, 'done')));

include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php
// Presentation only: every value below was already computed above.
?>
<?php include EVORA_ROOT . '/modules/shared/partials/today_panel.php'; ?>
<?php if ($gsDone < count($gsSteps)): ?>
<?php $gsNext = array_search(false, array_column($gsSteps, 'done'), true); ?>
<section class="card-evora p-4 mb-3 getting-started" data-dismiss-key="gs-<?= (int) Auth::id() ?>" aria-labelledby="gsTitle">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
            <h2 class="h6 fw-bold mb-1" id="gsTitle"><i class="bi bi-rocket-takeoff me-1" aria-hidden="true"></i>Getting started</h2>
            <p class="small text-muted mb-2"><?= $gsDone ?> of <?= count($gsSteps) ?> steps done. Finish these to run your first event on EVORA.</p>
        </div>
        <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none" data-dismiss>Hide</button>
    </div>
    <div class="progress gs-progress" role="progressbar" aria-label="Getting started progress" aria-valuenow="<?= $gsDone ?>" aria-valuemin="0" aria-valuemax="<?= count($gsSteps) ?>"><div class="progress-bar" style="width:<?= round($gsDone * 100 / count($gsSteps)) ?>%"></div></div>
    <ol class="gs-steps">
        <?php foreach ($gsSteps as $i => $step): ?>
        <li class="<?= $step['done'] ? 'is-done' : ($i === $gsNext ? 'is-next' : '') ?>">
            <span class="gs-check" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
            <div class="gs-body">
                <div class="gs-title"><?= e($step['title']) ?><span class="visually-hidden"><?= $step['done'] ? ' (done)' : ' (to do)' ?></span></div>
                <div class="gs-desc"><?= e($step['desc']) ?></div>
            </div>
            <?php if (!$step['done'] && $step['url']): ?><a href="<?= e($step['url']) ?>" class="btn btn-sm <?= $i === $gsNext ? 'btn-evora-primary' : 'btn-outline-secondary' ?>"><?= e($step['label']) ?></a><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3"><a href="<?= url('organizer-event-type') ?>" class="quick-action qa-primary"><span class="qa-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span><span>Create Event<span class="qa-sub">Start a new event</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('organizer-registrations') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span>Manage Participants<span class="qa-sub"><?= (int) $pendingRegCount ?> pending review</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('organizer-paypal-account') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-paypal" aria-hidden="true"></i></span><span>PayPal Account<span class="qa-sub">Where participants pay you</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('walkin-register') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span><span>Walk-in Registration<span class="qa-sub">Register on site</span></span></a></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="stat-card"><i class="bi bi-calendar-event fs-4 text-muted"></i><div class="stat-value"><?= $myEventsCount ?></div><div class="stat-label">My Events</div><div class="stat-desc">Across all statuses</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-info"><i class="bi bi-person-check fs-4 text-muted"></i><div class="stat-value"><?= $totalParticipantsCount ?></div><div class="stat-label">Participants</div><div class="stat-desc"><?= trendIndicator($registrationTrend) ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-success"><i class="bi bi-paypal fs-4 text-muted"></i><div class="stat-value"><?= currency($dashPayPalTotal) ?></div><div class="stat-label">PayPal Payments</div><div class="stat-desc"><?= count($dashPayPalRows) ?> paid directly to you</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-warning"><i class="bi bi-shield-check fs-4 text-muted"></i><div class="stat-value"><?= $dashPayPalAccount ? 'Linked' : 'Not connected' ?></div><div class="stat-label">PayPal Account</div><div class="stat-desc"><?= $dashPayPalAccount ? e(PayPalAccountService::maskId($dashPayPalAccount['merchant_id'])) : 'Needed for paid events' ?></div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <a href="<?= url('organizer-registrations', ['status' => 'pending']) ?>" class="text-decoration-none">
            <div class="stat-card accent-warning"><i class="bi bi-hourglass-split fs-4 text-muted"></i><div class="stat-value"><?= $pendingRegCount ?></div><div class="stat-label">Pending Registrations</div><div class="stat-desc">Awaiting your review</div></div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-danger"><i class="bi bi-credit-card fs-4 text-muted"></i><div class="stat-value"><?= $pendingPayCount ?></div><div class="stat-label">Pending Payments</div><div class="stat-desc">Awaiting validation</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card accent-success"><i class="bi bi-clipboard-check fs-4 text-muted"></i><div class="stat-value"><?= $attendanceCountVal ?></div><div class="stat-label">Attendance Records</div><div class="stat-desc">Checked in via QR</div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card"><i class="bi bi-person-badge fs-4 text-muted"></i><div class="stat-value"><?= $staffCountVal ?></div><div class="stat-label">Staff Members</div><div class="stat-desc">Assigned to your events</div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="<?= $dashPerformance ? 'col-xl-7' : 'col-12' ?>">
        <div class="card-evora p-4 h-100">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h6 class="fw-semibold mb-1">PayPal Payments</h6>
                    <div class="small text-muted">
                        <?php if ($dashPayPalAccount): ?>
                            PayPal account: <?= e(PayPalAccountService::maskId($dashPayPalAccount['merchant_id'])) ?> <span class="badge bg-success">Linked</span>
                        <?php else: ?>
                            <span class="text-danger">No PayPal Business account connected yet</span> — <a href="<?= url('organizer-paypal-account') ?>">connect it</a>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?= url('organizer-payment-transactions') ?>" class="btn btn-sm btn-outline-primary">View Payments</a>
            </div>
            <?php if ($dashPayPalRecent): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead><tr><th>Event</th><th>Participant</th><th class="text-end">Amount</th><th>Paid</th></tr></thead>
                    <tbody>
                    <?php foreach ($dashPayPalRecent as $p): ?>
                        <tr>
                            <td><?= e($p['event_name']) ?></td>
                            <td><?= e($p['participant_name']) ?></td>
                            <td class="text-end fw-semibold"><?= currency($p['amount']) ?></td>
                            <td><?= date('M d, Y', strtotime($p['completed_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mt-2">Payments go straight to your PayPal account. EVORA verifies them automatically and issues the QR code.</div>
            <?php else: ?>
                <?= emptyState('bi-paypal', 'No PayPal payments yet. Payments appear here once participants pay for your paid events.') ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($dashPerformance): ?>
    <div class="col-xl-5">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3"><i class="bi bi-star-half text-warning me-1" aria-hidden="true"></i> Event Performance</h6>
            <?php foreach ($dashPerformance as $perf): ?>
                <div class="d-flex justify-content-between align-items-center gap-2 border-bottom py-2">
                    <div class="min-w-0">
                        <div class="fw-semibold small text-truncate" title="<?= e($perf['name']) ?>"><?= e($perf['name']) ?></div>
                        <div class="small"><span class="rating-stars" aria-hidden="true"><?= EvaluationService::stars($perf['average'] ?? 0) ?></span>
                            <strong><?= $perf['reviews'] ? e($perf['average']) : '—' ?></strong>
                            <span class="text-muted">· <?= (int) $perf['reviews'] ?> review<?= (int) $perf['reviews'] === 1 ? '' : 's' ?></span></div>
                    </div>
                    <a href="<?= url('organizer-event-feedback', ['event_id' => $perf['id']]) ?>" class="btn btn-sm btn-outline-primary text-nowrap">View Feedback</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Registration Trend (Last 6 Months)</h6>
            <canvas id="regTrendChart" height="200"></canvas>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Payment Status</h6>
            <canvas id="paymentStatusChart" height="200"></canvas>
            <p class="text-muted small text-center mt-2 mb-0">PayPal: <strong><?= $paidPayPalCount ?></strong> &middot; Cash Walk-in: <strong><?= $paidCashWalkinCount ?></strong> &middot; Manual: <strong><?= $paidManualCount ?></strong><?php if ($paidOnlineCount): ?> &middot; PayMongo (legacy): <strong><?= $paidOnlineCount ?></strong><?php endif; ?></p>
            <p class="text-center mt-1 mb-0"><a href="<?= url('organizer-payment-dashboard') ?>" class="small">View full Payment Dashboard &rarr;</a></p>
        </div>
    </div>
</div>

<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">My Recent Events</h6>
        <a href="<?= url('organizer-event-type') ?>" class="btn btn-evora-primary btn-sm"><i class="bi bi-plus-lg"></i> Create Event</a>
    </div>
    <?php if (empty($eventsList)): ?>
        <?= emptyState('bi-calendar-plus', "You haven't created any events yet.", 'Create Event', url('organizer-event-type')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Event</th><th>Category</th><th>Date</th><th>Registration Progress</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($eventsList as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td style="min-width:140px"><?php $fillPct = (int) $ev['max_participants'] > 0 ? min(100, round(100 * (int) $ev['approved_count'] / (int) $ev['max_participants'])) : 0; ?>
                        <div class="small mb-1"><?= $ev['approved_count'] ?> / <?= $ev['max_participants'] ?></div>
                        <div class="progress" style="height:6px" role="progressbar" aria-label="Registration progress" aria-valuenow="<?= $fillPct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $fillPct ?>%"></div></div></td>
                    <td><span class="badge badge-status-<?= e($ev['status']) ?>"><?= ucfirst(e($ev['status'])) ?></span></td>
                    <td><a href="<?= url('organizer-event-manage', ['id' => $ev['id']]) ?>" class="btn btn-sm btn-outline-secondary">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('regTrendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_values($months)) ?>,
        datasets: [{ label: 'Registrations', data: <?= json_encode($registrationTrend) ?>, borderColor: EvoraChart.brand, backgroundColor: EvoraChart.brandFill, fill: true }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('paymentStatusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Pending', 'Rejected', 'Failed/Cancelled'],
        datasets: [{ data: [<?= $paymentApproved ?>, <?= $paymentPending ?>, <?= $paymentRejected ?>, <?= $paymentFailedCancelled ?>], backgroundColor: [EvoraChart.status.success, EvoraChart.status.warning, EvoraChart.status.danger, EvoraChart.status.neutral] }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
