<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$participantStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$participantStmt->execute(['uid' => $userId]);
$participant = $participantStmt->fetch();
$participantId = $participant['id'] ?? 0;

$regCounts = $db->prepare(
    "SELECT status, COUNT(*) c FROM registrations WHERE participant_id = :pid GROUP BY status"
);
$regCounts->execute(['pid' => $participantId]);
$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($regCounts->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['c'];
}

$upcoming = $db->prepare(
    'SELECT e.*, r.status AS reg_status, r.id AS registration_id
     FROM registrations r JOIN events e ON e.id = r.event_id
     WHERE r.participant_id = :pid AND e.event_date >= CURDATE()
     ORDER BY e.event_date ASC LIMIT 5'
);
$upcoming->execute(['pid' => $participantId]);
$upcomingEvents = $upcoming->fetchAll();

$totalRegs = array_sum($statusCounts);

// The participant's next event (v52): approved or still-pending, not yet over.
$nextStmt = $db->prepare(
    'SELECT e.id, e.name, e.event_date, e.event_time, e.venue, e.banner_path, r.id AS registration_id, r.status AS reg_status,
            COALESCE(rc.fee, e.fee) AS fee, pay.status AS pay_status, q.qr_image_path
     FROM registrations r JOIN events e ON e.id = r.event_id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     LEFT JOIN payments pay ON pay.registration_id = r.id
     LEFT JOIN qr_codes q ON q.registration_id = r.id AND q.invalidated_at IS NULL
     WHERE r.participant_id = :pid AND r.status IN ("approved", "pending") AND e.event_date >= CURDATE()
       AND e.status NOT IN ("cancelled", "removed", "archived", "completed")
     ORDER BY e.event_date, e.event_time LIMIT 1'
);
$nextStmt->execute(['pid' => $participantId]);
$nextEvent = $nextStmt->fetch() ?: null;

$attendedStmt = $db->prepare(
    'SELECT COUNT(*) c FROM attendance a JOIN registrations r ON r.id = a.registration_id WHERE r.participant_id = :pid'
);
$attendedStmt->execute(['pid' => $participantId]);
$attendedCount = (int) $attendedStmt->fetch()['c'];

$certCountStmt = $db->prepare(
    'SELECT COUNT(*) c FROM certificates cert JOIN registrations r ON r.id = cert.registration_id WHERE r.participant_id = :pid'
);
$certCountStmt->execute(['pid' => $participantId]);
$certCount = (int) $certCountStmt->fetch()['c'];

$qrReadyStmt = $db->prepare(
    'SELECT COUNT(*) c FROM qr_codes q JOIN registrations r ON r.id = q.registration_id WHERE r.participant_id = :pid'
);
$qrReadyStmt->execute(['pid' => $participantId]);
$qrReadyCount = (int) $qrReadyStmt->fetch()['c'];

$pageDescription = 'Your upcoming events, registrations and QR codes.';
$pageTitle = 'My Dashboard';
$activeRoute = 'participant-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if ($nextEvent): ?>
<?php
$neStart = strtotime($nextEvent['event_date'] . ' ' . ($nextEvent['event_time'] ?: '08:00:00'));
$neNeedsPay = (float) $nextEvent['fee'] > 0 && $nextEvent['pay_status'] !== 'approved';
?>
<section class="card-evora next-event mb-3" aria-labelledby="nextEventName">
    <?php if ($nextEvent['banner_path']): ?>
        <img src="<?= uploadUrl($nextEvent['banner_path']) ?>" alt="" class="next-event-thumb" onerror="this.outerHTML='&lt;div class=&quot;next-event-thumb&quot; aria-hidden=&quot;true&quot;&gt;&lt;i class=&quot;bi bi-calendar-event&quot;&gt;&lt;/i&gt;&lt;/div&gt;'">
    <?php else: ?>
        <div class="next-event-thumb" aria-hidden="true"><i class="bi bi-calendar-event"></i></div>
    <?php endif; ?>
    <div class="next-event-body">
        <div class="next-event-label">Your next event</div>
        <h2 class="next-event-name" id="nextEventName"><?= e($nextEvent['name']) ?></h2>
        <div class="next-event-meta">
            <span><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?= date('D, M d, Y', $neStart) ?><?= $nextEvent['event_time'] ? ' · ' . date('g:i A', $neStart) : '' ?></span>
            <span><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e($nextEvent['venue']) ?></span>
        </div>
        <span class="countdown-chip" data-countdown="<?= date('Y-m-d\TH:i:s', $neStart) ?>"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span class="countdown-text"><?= date('M d', $neStart) ?></span></span>
        <?php if ($nextEvent['reg_status'] === 'pending' && !$neNeedsPay): ?><span class="badge badge-status-pending ms-1">Waiting for organizer approval</span><?php endif; ?>
    </div>
    <div class="next-event-actions">
        <?php if ($nextEvent['qr_image_path']): ?>
            <button type="button" class="btn btn-evora-primary" data-qr-fullscreen="<?= e(uploadUrl($nextEvent['qr_image_path'])) ?>" data-qr-title="<?= e($nextEvent['name']) ?>"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Show my QR</button>
        <?php elseif ($neNeedsPay): ?>
            <a href="<?= url('payment-upload', ['registration_id' => $nextEvent['registration_id']]) ?>" class="btn btn-evora-primary"><i class="bi bi-credit-card me-1" aria-hidden="true"></i>Complete payment</a>
        <?php endif; ?>
        <a href="<?= url('event-view', ['id' => $nextEvent['id']]) ?>" class="btn btn-outline-secondary">View event</a>
        <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($nextEvent['venue']) ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Map</a>
    </div>
</section>
<?php endif; ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3"><a href="<?= url('events') ?>" class="quick-action qa-primary"><span class="qa-icon"><i class="bi bi-search" aria-hidden="true"></i></span><span>Browse Events<span class="qa-sub">Find something to join</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('my-registrations') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span><span>My Registrations<span class="qa-sub">Status &amp; payments</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('my-qr') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-qr-code" aria-hidden="true"></i></span><span>My QR Codes<span class="qa-sub"><?= (int) $qrReadyCount ?> ready for check-in</span></span></a></div>
    <div class="col-6 col-lg-3"><a href="<?= url('notifications') ?>" class="quick-action"><span class="qa-icon"><i class="bi bi-bell" aria-hidden="true"></i></span><span>Notifications<span class="qa-sub"><?= (int) ($unreadCount ?? 0) ?> unread</span></span></a></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card"><i class="bi bi-clipboard-check fs-4 text-muted"></i><div class="stat-value"><?= $totalRegs ?></div><div class="stat-label">Total Registrations</div><div class="stat-desc">Across all your events</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-warning"><i class="bi bi-hourglass-split fs-4 text-muted"></i><div class="stat-value"><?= $statusCounts['pending'] ?></div><div class="stat-label">Pending</div><div class="stat-desc">Awaiting organizer review</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-success"><i class="bi bi-check-circle fs-4 text-muted"></i><div class="stat-value"><?= $statusCounts['approved'] ?></div><div class="stat-label">Approved</div><div class="stat-desc">Ready to attend</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-danger"><i class="bi bi-x-circle fs-4 text-muted"></i><div class="stat-value"><?= $statusCounts['rejected'] ?></div><div class="stat-label">Rejected</div><div class="stat-desc">Not approved</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-info"><i class="bi bi-qr-code-scan fs-4 text-muted"></i><div class="stat-value"><?= $attendedCount ?></div><div class="stat-label">Attended</div><div class="stat-desc">Checked in via QR</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-success"><i class="bi bi-award fs-4 text-muted"></i><div class="stat-value"><?= $certCount ?></div><div class="stat-label">Certificates Earned</div><div class="stat-desc">Available to download</div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="stat-card accent-info"><i class="bi bi-qr-code fs-4 text-muted"></i><div class="stat-value"><?= $qrReadyCount ?></div><div class="stat-label">QR Codes Ready</div><div class="stat-desc">Ready for check-in</div></div>
    </div>
</div>

<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">My Upcoming Events</h6>
        <a href="<?= url('events') ?>" class="btn btn-evora-primary btn-sm"><i class="bi bi-search"></i> Browse Events</a>
    </div>
    <?php if (empty($upcomingEvents)): ?>
        <?= emptyState('bi-calendar-x', 'No upcoming events yet.', 'Browse Events', url('events')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Registration</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($upcomingEvents as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= e($ev['venue']) ?></td>
                    <td><span class="badge badge-status-<?= e($ev['reg_status']) ?>"><?= ucfirst(e($ev['reg_status'])) ?></span></td>
                    <td><a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
