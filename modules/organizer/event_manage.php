<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare(
    'SELECT e.*, c.name AS category_name, c.slug AS category_slug,
        s.name AS sport_name, s.sport_type, s.team_size
     FROM events e
     JOIN event_categories c ON c.id = e.category_id
     LEFT JOIN sports s ON s.id = e.sport_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$approvedCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$approvedCount->execute(['id' => $eventId]);
$approvedCountVal = $approvedCount->fetch()['c'];

$pendingCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "pending"');
$pendingCount->execute(['id' => $eventId]);
$pendingCountVal = $pendingCount->fetch()['c'];

$pendingPayCount = $db->prepare(
    'SELECT COUNT(*) c FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = :id AND p.status = "pending"'
);
$pendingPayCount->execute(['id' => $eventId]);
$pendingPayCountVal = $pendingPayCount->fetch()['c'];

$announcementsStmt = $db->prepare('SELECT * FROM event_announcements WHERE event_id = :id ORDER BY created_at DESC LIMIT 10');
$announcementsStmt->execute(['id' => $eventId]);
$announcements = $announcementsStmt->fetchAll();

$staffCount = $db->prepare('SELECT COUNT(*) c FROM event_staff WHERE event_id = :id');
$staffCount->execute(['id' => $eventId]);
$staffCountVal = $staffCount->fetch()['c'];

// v57: the latest participants for the Participant Overview preview
$latestPeopleStmt = $db->prepare('SELECT u.full_name, r.bib_number, r.status, COALESCE(rc.name, r.distance_category) AS category
    FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id
    LEFT JOIN race_categories rc ON rc.id = r.race_category_id
    WHERE r.event_id = :id AND r.status IN ("approved", "pending") ORDER BY r.registered_at DESC, r.id DESC LIMIT 5');
$latestPeopleStmt->execute(['id' => $eventId]);
$latestPeople = $latestPeopleStmt->fetchAll();

$attendanceCount = $db->prepare('SELECT COUNT(*) c FROM attendance WHERE event_id = :id');
$attendanceCount->execute(['id' => $eventId]);
$attendanceCountVal = $attendanceCount->fetch()['c'];

$itemsCount = $db->prepare('SELECT COUNT(*) c FROM event_items WHERE event_id = :id');
$itemsCount->execute(['id' => $eventId]);
$itemsCountVal = $itemsCount->fetch()['c'];

$registrationIsOpenOrLater = in_array($event['status'], ['open', 'closed', 'ongoing', 'completed'], true);

$certificatesCount = $db->prepare(
    'SELECT COUNT(*) c FROM certificates cert JOIN registrations r ON r.id = cert.registration_id WHERE r.event_id = :id'
);
$certificatesCount->execute(['id' => $eventId]);
$certificatesCountVal = $certificatesCount->fetch()['c'];

$setupSteps = [];
$usesTeams = true;
if ($event['category_slug'] === 'sports-tournament') {
    $usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;

    $teamsCount = $db->prepare('SELECT COUNT(*) c FROM teams WHERE event_id = :id');
    $teamsCount->execute(['id' => $eventId]);
    $teamsCountVal = $teamsCount->fetch()['c'];

    $matchesCount = $db->prepare('SELECT COUNT(*) c FROM matches WHERE event_id = :id');
    $matchesCount->execute(['id' => $eventId]);
    $matchesCountVal = $matchesCount->fetch()['c'];

    if (empty($event['sport_id'])) {
        $setupSteps[] = ['label' => 'Choose a sport (edit event)', 'done' => false, 'route' => null, 'icon' => 'bi-exclamation-triangle'];
    } elseif ($usesTeams) {
        $setupSteps[] = ['label' => 'Add teams/pairs', 'done' => $teamsCountVal > 0, 'route' => 'organizer-teams', 'icon' => 'bi-people-fill'];
    }
    $setupSteps[] = ['label' => 'Schedule matches', 'done' => $matchesCountVal > 0, 'route' => 'organizer-matches', 'icon' => 'bi-trophy'];
    $setupSteps[] = ['label' => 'Set up the bracket', 'done' => $matchesCountVal > 0, 'route' => 'organizer-bracket', 'icon' => 'bi-diagram-3'];
    $setupSteps[] = ['label' => 'Assign staff/referees', 'done' => $staffCountVal > 0, 'route' => 'organizer-staff', 'icon' => 'bi-person-badge'];
    $setupSteps[] = ['label' => 'Open registration', 'done' => $registrationIsOpenOrLater, 'route' => null, 'icon' => 'bi-unlock'];
} elseif ($event['category_slug'] === 'seminar-workshop') {
    $speakersCount = $db->prepare('SELECT COUNT(*) c FROM event_speakers WHERE event_id = :id');
    $speakersCount->execute(['id' => $eventId]);
    $speakersCountVal = $speakersCount->fetch()['c'];

    $scheduleCount = $db->prepare('SELECT COUNT(*) c FROM event_schedule WHERE event_id = :id');
    $scheduleCount->execute(['id' => $eventId]);
    $scheduleCountVal = $scheduleCount->fetch()['c'];

    $setupSteps[] = ['label' => 'Add speakers', 'done' => $speakersCountVal > 0, 'route' => 'organizer-speakers', 'icon' => 'bi-mic'];
    $setupSteps[] = ['label' => 'Build the session schedule', 'done' => $scheduleCountVal > 0, 'route' => 'organizer-schedule', 'icon' => 'bi-clock-history'];
    $setupSteps[] = ['label' => 'Assign staff', 'done' => $staffCountVal > 0, 'route' => 'organizer-staff', 'icon' => 'bi-person-badge'];
    $setupSteps[] = ['label' => 'Open registration', 'done' => $registrationIsOpenOrLater, 'route' => null, 'icon' => 'bi-unlock'];
    $setupSteps[] = ['label' => 'Issue certificates once attendees are marked present', 'done' => $certificatesCountVal > 0, 'route' => 'organizer-certificates', 'icon' => 'bi-award'];
} elseif ($event['category_slug'] === 'festival') {
    $scheduleCount = $db->prepare('SELECT COUNT(*) c FROM event_schedule WHERE event_id = :id');
    $scheduleCount->execute(['id' => $eventId]);
    $scheduleCountVal = $scheduleCount->fetch()['c'];

    $setupSteps[] = ['label' => 'Build the activity schedule', 'done' => $scheduleCountVal > 0, 'route' => 'organizer-schedule', 'icon' => 'bi-clock-history'];
    $setupSteps[] = ['label' => 'Add event items (giveaways, kits, tickets), if any', 'done' => $itemsCountVal > 0, 'route' => 'organizer-items', 'icon' => 'bi-box-seam'];
    $setupSteps[] = ['label' => 'Assign staff per activity', 'done' => $staffCountVal > 0, 'route' => 'organizer-staff', 'icon' => 'bi-person-badge'];
    $setupSteps[] = ['label' => 'Open registration', 'done' => $registrationIsOpenOrLater, 'route' => null, 'icon' => 'bi-unlock'];
    $setupSteps[] = ['label' => 'Issue certificates once attendees are marked present', 'done' => $certificatesCountVal > 0, 'route' => 'organizer-certificates', 'icon' => 'bi-award'];
} elseif ($event['category_slug'] === 'fun-run') {
    $checkpointsCount = $db->prepare('SELECT COUNT(*) c FROM event_checkpoints WHERE event_id = :id');
    $checkpointsCount->execute(['id' => $eventId]);
    $checkpointsCountVal = $checkpointsCount->fetch()['c'];

    $raceCategoriesCount = $db->prepare('SELECT COUNT(*) c FROM race_categories WHERE event_id = :id');
    $raceCategoriesCount->execute(['id' => $eventId]);
    $raceCategoriesCountVal = $raceCategoriesCount->fetch()['c'];

    $finishersCount = $db->prepare(
        'SELECT COUNT(*) c FROM race_results rr JOIN registrations r ON r.id = rr.registration_id WHERE r.event_id = :id'
    );
    $finishersCount->execute(['id' => $eventId]);
    $finishersCountVal = $finishersCount->fetch()['c'];

    $bibAssignedCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND bib_number IS NOT NULL');
    $bibAssignedCount->execute(['id' => $eventId]);
    $bibAssignedCountVal = $bibAssignedCount->fetch()['c'];

    $raceCategoryBreakdownStmt = $db->prepare(
        "SELECT rc.name, rc.max_participants,
            (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id) AS registered,
            (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved,
            (SELECT COUNT(*) FROM registrations r JOIN payments p ON p.registration_id = r.id WHERE r.race_category_id = rc.id AND p.status = 'pending') AS pending_payment
         FROM race_categories rc WHERE rc.event_id = :id ORDER BY rc.fee, rc.name"
    );
    $raceCategoryBreakdownStmt->execute(['id' => $eventId]);
    $raceCategoryBreakdown = $raceCategoryBreakdownStmt->fetchAll();

    $setupSteps[] = ['label' => 'Define race categories (distances, fees, slot caps)', 'done' => $raceCategoriesCountVal > 0, 'route' => 'organizer-race-categories', 'icon' => 'bi-list-ol'];
    $setupSteps[] = ['label' => 'Add event items (bibs, race kits, medals), if any', 'done' => $itemsCountVal > 0, 'route' => 'organizer-items', 'icon' => 'bi-box-seam'];
    $setupSteps[] = ['label' => 'Configure the race route', 'done' => $checkpointsCountVal > 0, 'route' => 'organizer-checkpoints', 'icon' => 'bi-signpost-split'];
    $setupSteps[] = ['label' => 'Assign staff', 'done' => $staffCountVal > 0, 'route' => 'organizer-staff', 'icon' => 'bi-person-badge'];
    $setupSteps[] = ['label' => 'Open registration', 'done' => $registrationIsOpenOrLater, 'route' => null, 'icon' => 'bi-unlock'];
    $setupSteps[] = ['label' => 'Assign bib numbers to approved runners', 'done' => $bibAssignedCountVal > 0, 'route' => 'organizer-bib-assign', 'icon' => 'bi-upc-scan'];
    $setupSteps[] = ['label' => 'Record finish line times', 'done' => $finishersCountVal > 0, 'route' => 'organizer-race-results', 'icon' => 'bi-flag-fill'];
    $setupSteps[] = ['label' => 'Issue certificates once attendees are marked present', 'done' => $certificatesCountVal > 0, 'route' => 'organizer-certificates', 'icon' => 'bi-award'];
} else {
    $setupSteps[] = ['label' => 'Add event items (kits, bibs, prizes, etc.), if any', 'done' => $itemsCountVal > 0, 'route' => 'organizer-items', 'icon' => 'bi-box-seam'];
    $setupSteps[] = ['label' => 'Assign staff', 'done' => $staffCountVal > 0, 'route' => 'organizer-staff', 'icon' => 'bi-person-badge'];
    $setupSteps[] = ['label' => 'Open registration', 'done' => $registrationIsOpenOrLater, 'route' => null, 'icon' => 'bi-unlock'];
    $setupSteps[] = ['label' => 'Review registrations and payments as they come in', 'done' => (int) $approvedCountVal > 0, 'route' => 'organizer-registrations', 'icon' => 'bi-clipboard-check'];
    $setupSteps[] = ['label' => 'Issue certificates once attendees are marked present', 'done' => $certificatesCountVal > 0, 'route' => 'organizer-certificates', 'icon' => 'bi-award'];
}
$setupDoneCount = count(array_filter($setupSteps, fn ($s) => $s['done']));

$toolGroups = EventTools::forEvent($db, $event, ['uses_teams' => $usesTeams]);

$totalRegisteredCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id');
$totalRegisteredCount->execute(['id' => $eventId]);
$totalRegisteredCountVal = (int) $totalRegisteredCount->fetch()['c'];

$rejectedCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "rejected"');
$rejectedCount->execute(['id' => $eventId]);
$rejectedCountVal = (int) $rejectedCount->fetch()['c'];

$paySumStmt = $db->prepare(
    'SELECT p.status, COALESCE(SUM(p.amount), 0) total FROM payments p
     JOIN registrations r ON r.id = p.registration_id
     WHERE r.event_id = :id GROUP BY p.status'
);
$paySumStmt->execute(['id' => $eventId]);
$paySumsRaw = $paySumStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$paymentCollected = (float) ($paySumsRaw['approved'] ?? 0);
$paymentPendingSum = (float) ($paySumsRaw['pending'] ?? 0);
$paymentRejectedSum = (float) ($paySumsRaw['rejected'] ?? 0);
$paymentFailedOnlySum = (float) ($paySumsRaw['failed'] ?? 0);
$paymentCancelledSum = (float) ($paySumsRaw['cancelled'] ?? 0);
$paymentFailedSum = $paymentFailedOnlySum + $paymentCancelledSum;

$paidCountStmt = $db->prepare(
    'SELECT COUNT(*) c FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = :id AND p.status = "approved"'
);
$paidCountStmt->execute(['id' => $eventId]);
$paidParticipantCount = (int) $paidCountStmt->fetch()['c'];

$failedCountStmt = $db->prepare(
    'SELECT COUNT(*) c FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = :id AND p.status = "failed"'
);
$failedCountStmt->execute(['id' => $eventId]);
$failedPaymentCount = (int) $failedCountStmt->fetch()['c'];

$cancelledCountStmt = $db->prepare(
    'SELECT COUNT(*) c FROM payments p JOIN registrations r ON r.id = p.registration_id WHERE r.event_id = :id AND p.status = "cancelled"'
);
$cancelledCountStmt->execute(['id' => $eventId]);
$cancelledPaymentCount = (int) $cancelledCountStmt->fetch()['c'];

$channelRevStmt = $db->prepare(
    'SELECT p.payment_channel, COALESCE(SUM(p.amount), 0) total FROM payments p
     JOIN registrations r ON r.id = p.registration_id
     WHERE r.event_id = :id AND p.status = "approved" GROUP BY p.payment_channel'
);
$channelRevStmt->execute(['id' => $eventId]);
$channelRevRaw = $channelRevStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$paymongoRevenue = (float) ($channelRevRaw['paymongo'] ?? 0);
$cashWalkinRevenue = (float) ($channelRevRaw['cash_walkin'] ?? 0);
$manualRevenueHistorical = (float) ($channelRevRaw['manual'] ?? 0);
if ($event['category_slug'] === 'fun-run') {
    $expectedRevenueStmt = $db->prepare(
        "SELECT COALESCE(SUM(COALESCE(rc.fee, e.fee)), 0) total FROM registrations r
         JOIN events e ON e.id = r.event_id
         LEFT JOIN race_categories rc ON rc.id = r.race_category_id
         WHERE r.event_id = :id AND r.status NOT IN ('rejected', 'cancelled')"
    );
    $expectedRevenueStmt->execute(['id' => $eventId]);
    $expectedRevenue = (float) $expectedRevenueStmt->fetch()['total'];
} else {
    $expectedRevenue = (float) $event['fee'] * max(0, $totalRegisteredCountVal - $rejectedCountVal);
}

$eventItemsStmt = $db->prepare('SELECT * FROM event_items WHERE event_id = :id ORDER BY item_name');
$eventItemsStmt->execute(['id' => $eventId]);
$eventItemsList = $eventItemsStmt->fetchAll();

$isEventDay = in_array($event['status'], ['open', 'closed', 'ongoing'], true);
$isCompleted = $event['status'] === 'completed';
$attendanceRate = $approvedCountVal > 0 ? round(($attendanceCountVal / $approvedCountVal) * 100) : 0;
$paymentCompletionDenom = $paymentCollected + $paymentPendingSum + $paymentRejectedSum;
$paymentCompletionPct = $paymentCompletionDenom > 0 ? round(($paymentCollected / $paymentCompletionDenom) * 100) : 0;

$daysUntilEvent = (int) ceil((strtotime($event['event_date']) - strtotime(date('Y-m-d'))) / 86400);

$recommendedActions = [];
if ($pendingPayCountVal > 0) {
    $recommendedActions[] = ['level' => 'danger', 'text' => $pendingPayCountVal . ' payment(s) waiting for your review.', 'route' => 'organizer-registrations', 'label' => 'Review Registrations'];
}
if ($pendingCountVal > 0) {
    $recommendedActions[] = ['level' => 'warning', 'text' => $pendingCountVal . ' registration(s) waiting for your review.', 'route' => 'organizer-registrations', 'label' => 'Review Registrations'];
}
foreach ($eventItemsList as $item) {
    if ((int) $item['total_quantity'] > 0 && ($item['claimed_quantity'] / $item['total_quantity']) >= 0.8) {
        $recommendedActions[] = ['level' => 'warning', 'text' => 'Low stock on "' . $item['item_name'] . '" (' . $item['claimed_quantity'] . '/' . $item['total_quantity'] . ' claimed).', 'route' => 'organizer-items', 'label' => 'Manage Items'];
    }
}
if ((int) $event['max_participants'] > 0 && ($approvedCountVal / $event['max_participants']) >= 0.9) {
    $recommendedActions[] = ['level' => 'info', 'text' => 'Registration is almost full (' . $approvedCountVal . '/' . $event['max_participants'] . ').', 'route' => 'organizer-registrations', 'label' => 'View Registrations'];
}
if ($event['status'] === 'draft' && $daysUntilEvent <= 3 && $daysUntilEvent >= 0) {
    $recommendedActions[] = ['level' => 'danger', 'text' => 'Event is in ' . $daysUntilEvent . ' day(s) but is still a draft — submit it for approval now.', 'route' => null, 'label' => null];
} elseif (in_array($event['status'], ['approved'], true) && $daysUntilEvent <= 3 && $daysUntilEvent >= 0) {
    $recommendedActions[] = ['level' => 'warning', 'text' => 'Event is in ' . $daysUntilEvent . ' day(s) but registration is not open yet.', 'route' => null, 'label' => null];
} elseif ($isEventDay && $daysUntilEvent <= 1 && $daysUntilEvent >= 0) {
    $recommendedActions[] = ['level' => 'info', 'text' => 'Event starts soon — send a reminder to approved participants.', 'route' => null, 'label' => null];
}

$pageDescription = 'Run this event: status, participants, payments and tools.';
$pageTitle = $event['name'];
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name']],
]); ?>

<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div>
            <h5 class="fw-bold mb-1"><?= e($event['name']) ?> <span class="badge badge-status-<?= e($event['status']) ?>"><?= ucfirst(str_replace('_', ' ', e($event['status']))) ?></span></h5>
            <p class="text-muted small mb-0"><?= e($event['category_name']) ?><?= $event['sport_name'] ? ' (' . e($event['sport_name']) . ')' : '' ?> &bull; <?= date('M d, Y', strtotime($event['event_date'])) ?> at <?= date('h:i A', strtotime($event['event_time'])) ?> &bull; <?= e($event['venue']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('organizer-event-form', ['id' => $event['id']]) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
            <a href="<?= url('event-view', ['id' => $event['id']]) ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i> Public Page</a>
        </div>
    </div>

    <?php if ($event['status'] === 'draft' && !empty($event['admin_remarks'])): ?>
        <div class="alert alert-danger small mb-2"><i class="bi bi-exclamation-triangle"></i> Rejected by admin: <?= e($event['admin_remarks']) ?>. Update the event and resubmit.</div>
    <?php endif; ?>

    <?php /* Actions grouped by weight: primary (next lifecycle step) · secondary · danger zone. Same forms and values as before. */ ?>
    <div class="action-group pt-3 border-top">
        <?php if ($event['status'] === 'draft'): ?>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="submit_for_approval">
                <button type="submit" class="btn btn-evora-primary btn-sm"><i class="bi bi-send" aria-hidden="true"></i> Submit for Approval</button>
            </form>
        <?php elseif ($event['status'] === 'pending_approval'): ?>
            <span class="badge badge-status-pending_approval fs-6">Awaiting admin approval</span>
        <?php elseif ($event['status'] === 'approved'): ?>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="open_registration">
                <button type="submit" class="btn btn-evora-primary btn-sm"><i class="bi bi-unlock" aria-hidden="true"></i> Open Registration</button>
            </form>
        <?php elseif ($event['status'] === 'open'): ?>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="close_registration">
                <button type="submit" class="btn btn-evora-primary btn-sm" data-confirm-click="New registrations stop, your earnings are finalized and a payout request is sent to EVORA. Registration cannot be reopened." data-confirm-title="Close registration?" data-confirm-button="Close Registration" data-confirm-variant="primary"><i class="bi bi-lock" aria-hidden="true"></i> Close Registration</button>
            </form>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="mark_ongoing">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-play-circle" aria-hidden="true"></i> Mark Ongoing</button>
            </form>
        <?php elseif ($event['status'] === 'closed'): ?>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="mark_ongoing">
                <button type="submit" class="btn btn-evora-primary btn-sm"><i class="bi bi-play-circle" aria-hidden="true"></i> Mark Ongoing</button>
            </form>
        <?php elseif ($event['status'] === 'ongoing'): ?>
            <form method="post" action="<?= url('organizer-event-status-action') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <input type="hidden" name="action" value="mark_completed">
                <button type="submit" class="btn btn-evora-primary btn-sm"><i class="bi bi-check-circle" aria-hidden="true"></i> Mark Completed</button>
            </form>
        <?php elseif (in_array($event['status'], ['completed', 'cancelled', 'removed', 'archived'], true)): ?>
            <span class="text-muted small">No further status actions available.</span>
        <?php endif; ?>

        <span class="action-divider" aria-hidden="true"></span>
        <a href="<?= url('organizer-participants', ['event_id' => $event['id'], 'status' => 'all']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people" aria-hidden="true"></i> Participants</a>
        <?php if (in_array($event['status'], ['open', 'closed', 'ongoing'], true)): ?>
            <form method="post" action="<?= url('organizer-send-reminder') ?>" data-confirm="All approved participants get a reminder notification." data-confirm-title="Send a reminder?" data-confirm-button="Send Reminder">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bell" aria-hidden="true"></i> Send Reminder</button>
            </form>
        <?php endif; ?>

        <?php if (in_array($event['status'], ['pending_approval', 'approved', 'open', 'closed', 'ongoing'], true)): ?>
            <div class="action-danger-zone">
                <form method="post" action="<?= url('organizer-event-status-action') ?>"
                      data-prompt="Tell the EVORA admin why this event should be cancelled (at least 10 characters). Only an admin can cancel an event." data-prompt-field="reason" data-prompt-min="10" data-prompt-title="Request event cancellation?" data-prompt-button="Send Request">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                    <input type="hidden" name="action" value="request_cancellation">
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-octagon" aria-hidden="true"></i> Request Cancellation</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($recommendedActions)): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-lightbulb text-warning me-1"></i> EVORA Suggests</h6>
    <div class="d-flex flex-column gap-2">
        <?php foreach ($recommendedActions as $action): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border rounded p-2 border-<?= e($action['level']) ?>-subtle bg-<?= e($action['level']) ?>-subtle">
                <span class="small"><i class="bi bi-exclamation-triangle text-<?= e($action['level']) ?> me-1"></i><?= e($action['text']) ?></span>
                <?php if ($action['route']): ?>
                    <a href="<?= url($action['route'], ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-<?= e($action['level']) ?>"><?= e($action['label']) ?></a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-signpost-2 text-primary me-1"></i> Event Readiness &mdash; <?= e($event['category_name']) ?><?= $event['sport_name'] ? ' (' . e($event['sport_name']) . ')' : '' ?></h6>
        <span class="fw-bold fs-5"><?= count($setupSteps) > 0 ? round(($setupDoneCount / count($setupSteps)) * 100) : 0 ?>%</span>
    </div>
    <div class="progress progress-thin mb-3">
        <div class="progress-bar bg-success" style="width: <?= count($setupSteps) > 0 ? round(($setupDoneCount / count($setupSteps)) * 100) : 0 ?>%"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <h6 class="small text-uppercase text-muted fw-bold mb-2">Completed</h6>
            <?php $completedSteps = array_filter($setupSteps, fn ($s) => $s['done']); ?>
            <?php if (empty($completedSteps)): ?>
                <p class="text-muted small mb-0">Nothing completed yet.</p>
            <?php endif; ?>
            <?php foreach ($completedSteps as $step): ?>
                <div class="d-flex align-items-center gap-2 small mb-2"><i class="bi bi-check-circle-fill text-success"></i><?= e($step['label']) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="col-md-6">
            <h6 class="small text-uppercase text-muted fw-bold mb-2">Needs Attention</h6>
            <?php $pendingSteps = array_filter($setupSteps, fn ($s) => !$s['done']); ?>
            <?php if (empty($pendingSteps)): ?>
                <p class="text-success small mb-0"><i class="bi bi-stars"></i> Everything's ready!</p>
            <?php endif; ?>
            <?php foreach ($pendingSteps as $step): ?>
                <div class="d-flex justify-content-between align-items-center gap-2 small mb-2 border-bottom pb-2">
                    <span><i class="bi bi-exclamation-triangle text-warning me-1"></i><?= e($step['label']) ?></span>
                    <?php if ($step['route']): ?>
                        <a href="<?= url($step['route'], ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-outline-primary">Fix Now</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card-evora p-4 h-100">
            <h6 class="fw-semibold mb-3"><i class="bi bi-people text-primary me-1"></i> Participant Overview</h6>
            <div class="row g-2 text-center mb-2">
                <div class="col"><div class="fw-bold fs-5"><?= $event['max_participants'] ?></div><div class="text-muted small">Slots</div></div>
                <div class="col"><a class="stat-link" href="<?= url('organizer-registrations', ['event_id' => $event['id']]) ?>"><div class="fw-bold fs-5"><?= $totalRegisteredCountVal ?></div><div class="text-muted small">Registered</div></a></div>
                <div class="col"><a class="stat-link" href="<?= url('organizer-participants', ['event_id' => $event['id']]) ?>"><div class="fw-bold fs-5 text-success"><?= $approvedCountVal ?></div><div class="text-muted small">Approved</div></a></div>
                <div class="col"><a class="stat-link" href="<?= url('organizer-participants', ['event_id' => $event['id'], 'status' => 'pending']) ?>"><div class="fw-bold fs-5 text-warning"><?= $pendingCountVal ?></div><div class="text-muted small">Pending</div></a></div>
                <div class="col"><a class="stat-link" href="<?= url('organizer-registrations', ['event_id' => $event['id'], 'status' => 'rejected']) ?>"><div class="fw-bold fs-5 text-danger"><?= $rejectedCountVal ?></div><div class="text-muted small">Rejected</div></a></div>
            </div>
            <?php if ($latestPeople): ?>
                <div class="small text-uppercase text-muted fw-bold mt-3 mb-1">Latest participants</div>
                <ul class="list-group list-group-flush small mb-2">
                    <?php foreach ($latestPeople as $lp): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                            <span class="text-truncate"><?php if ($lp['bib_number']): ?><span class="fw-semibold me-1"><?= e($lp['bib_number']) ?></span><?php endif; ?><?= e($lp['full_name']) ?><?php if ($lp['category']): ?> <span class="text-muted">· <?= e($lp['category']) ?></span><?php endif; ?></span>
                            <span class="badge badge-status-<?= e($lp['status']) ?>"><?= ucfirst(e($lp['status'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small text-muted mt-3 mb-2">Nobody has registered yet.</p>
            <?php endif; ?>
            <a href="<?= url('organizer-participants', ['event_id' => $event['id'], 'status' => 'all']) ?>" class="btn btn-outline-primary btn-sm w-100 mb-2"><i class="bi bi-people"></i> See All Participants (<?= (int) $approvedCountVal + (int) $pendingCountVal ?>)</a>
            <?php if ((int) $event['max_participants'] > 0 && ($approvedCountVal / $event['max_participants']) >= 0.9): ?>
                <div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> Registration is almost full.</div>
            <?php elseif ($pendingPayCountVal >= 5): ?>
                <div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle"></i> Many participants are waiting for payment approval.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-evora p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-semibold mb-0"><i class="bi bi-cash-coin text-primary me-1"></i> Payment Monitoring</h6>
                <div class="d-flex gap-2">
                    <a href="<?= url('organizer-registrations', ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-outline-primary">Review Registrations</a>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Export</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= url('organizer-financial-report-export', ['event_id' => $event['id'], 'format' => 'pdf']) ?>"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a></li>
                            <li><a class="dropdown-item" href="<?= url('organizer-financial-report-export', ['event_id' => $event['id'], 'format' => 'xlsx']) ?>"><i class="bi bi-file-earmark-excel me-1"></i> Excel</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="row g-2 text-center mb-2">
                <div class="col"><div class="fw-bold"><?= currency($expectedRevenue) ?></div><div class="text-muted small">Expected</div></div>
                <div class="col"><div class="fw-bold text-success"><?= $paidParticipantCount ?></div><div class="text-muted small">Paid</div></div>
                <div class="col"><div class="fw-bold text-warning"><?= currency($paymentPendingSum) ?></div><div class="text-muted small">Pending</div></div>
                <div class="col"><div class="fw-bold text-danger"><?= currency($paymentRejectedSum) ?></div><div class="text-muted small">Rejected</div></div>
                <div class="col"><div class="fw-bold text-muted"><?= $failedPaymentCount ?></div><div class="text-muted small">Failed</div></div>
                <div class="col"><div class="fw-bold text-muted"><?= $cancelledPaymentCount ?></div><div class="text-muted small">Cancelled</div></div>
            </div>
            <div class="row g-2 text-center pt-2 border-top">
                <div class="col"><div class="fw-bold text-info"><?= currency($paymongoRevenue) ?></div><div class="text-muted small">PayMongo Revenue</div></div>
                <div class="col"><div class="fw-bold text-warning"><?= currency($cashWalkinRevenue) ?></div><div class="text-muted small">Cash Walk-in Revenue</div></div>
                <div class="col"><div class="fw-bold text-success"><?= currency($paymentCollected) ?></div><div class="text-muted small">Total Revenue</div></div>
            </div>
            <?php if ($manualRevenueHistorical > 0): ?>
            <div class="text-muted small mt-2"><i class="bi bi-archive me-1"></i>Includes <?= currency($manualRevenueHistorical) ?> from historical (deprecated) manual payments.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($event['category_slug'] === 'fun-run' && !empty($raceCategoryBreakdown)): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-list-ol text-primary me-1"></i> Participant Preparation Monitoring (per Category)</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Category</th><th>Max Slots</th><th>Registered</th><th>Approved</th><th>Pending Payment</th><th>Available</th></tr></thead>
            <tbody>
            <?php foreach ($raceCategoryBreakdown as $rc): ?>
                <?php $available = max(0, (int) $rc['max_participants'] - (int) $rc['approved']); ?>
                <tr>
                    <td><?= e($rc['name']) ?></td>
                    <td><?= (int) $rc['max_participants'] ?></td>
                    <td><?= (int) $rc['registered'] ?></td>
                    <td><?= (int) $rc['approved'] ?></td>
                    <td><?= (int) $rc['pending_payment'] ?></td>
                    <td>
                        <?= $available ?>
                        <?php if ($available === 0): ?><span class="badge bg-danger ms-1">Full</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($isEventDay): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-broadcast text-danger me-1"></i> Event Day &mdash; Live Status</h6>
    <div class="row g-3 text-center">
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4"><?= $approvedCountVal ?></div><div class="text-muted small">Expected Participants</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4 text-success"><?= $attendanceCountVal ?></div><div class="text-muted small">Checked In</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4 text-warning"><?= max(0, $approvedCountVal - $attendanceCountVal) ?></div><div class="text-muted small">Not Yet Arrived</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4"><?= $staffCountVal ?></div><div class="text-muted small">Active Staff</div></div>
    </div>
    <div class="progress progress-thin mt-3">
        <div class="progress-bar bg-success" style="width: <?= $attendanceRate ?>%"></div>
    </div>
    <p class="text-muted small mt-1 mb-0">Attendance: <?= $attendanceRate ?>%</p>
</div>
<?php endif; ?>

<?php if ($isEventDay && $event['category_slug'] === 'fun-run'): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-flag-fill text-danger me-1"></i> Live Race Dashboard</h6>
    <div class="row g-3 text-center">
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4"><?= $approvedCountVal ?></div><div class="text-muted small">Registered</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4 text-info"><?= $attendanceCountVal ?></div><div class="text-muted small">Checked In</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4 text-warning"><?= max(0, $attendanceCountVal - $finishersCountVal) ?></div><div class="text-muted small">Currently Running</div></div>
        <div class="col-sm-6 col-lg-3"><div class="fw-bold fs-4 text-success"><?= $finishersCountVal ?></div><div class="text-muted small">Finished</div></div>
    </div>
    <div class="mt-3">
        <a href="<?= url('organizer-race-results', ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-outline-primary">Record Finish Times</a>
        <a href="<?= url('organizer-checkpoints', ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-outline-secondary">View Route</a>
    </div>
</div>
<?php endif; ?>

<?php if ($isCompleted): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-graph-up text-primary me-1"></i> Post-Event Analytics</h6>
    <div class="row g-3 text-center">
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-5"><?= $approvedCountVal ?></div><div class="text-muted small">Participants</div></div>
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-5"><?= $attendanceRate ?>%</div><div class="text-muted small">Attendance Rate</div></div>
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-5"><?= $paymentCompletionPct ?>%</div><div class="text-muted small">Payment Completion</div></div>
        <div class="col-sm-6 col-lg-2"><div class="fw-bold fs-5"><?= currency($paymentCollected) ?></div><div class="text-muted small">Revenue</div></div>
        <div class="col-sm-6 col-lg-4"><div class="fw-bold fs-6 text-muted">Not yet collected</div><div class="text-muted small">Feedback Rating (no per-event evaluation form yet)</div></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($eventItemsList)): ?>
<div class="card-evora p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0"><i class="bi bi-box-seam text-primary me-1"></i> Inventory</h6>
        <a href="<?= url('organizer-items', ['event_id' => $event['id']]) ?>" class="btn btn-sm btn-outline-primary">Manage Items</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Item</th><th>Total</th><th>Claimed</th><th>Remaining</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($eventItemsList as $item): ?>
                <?php $lowStock = (int) $item['total_quantity'] > 0 && ($item['claimed_quantity'] / $item['total_quantity']) >= 0.8; ?>
                <tr>
                    <td><?= e($item['item_name']) ?></td>
                    <td><?= $item['total_quantity'] ?></td>
                    <td><?= $item['claimed_quantity'] ?></td>
                    <td><?= max(0, $item['total_quantity'] - $item['claimed_quantity']) ?></td>
                    <td><?php if ($lowStock): ?><span class="badge bg-warning text-dark">Low Stock</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-megaphone text-primary me-1"></i> Send Announcement</h6>
    <form method="post" action="<?= url('organizer-announcement-send') ?>" class="d-flex gap-2 flex-wrap mb-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
        <textarea name="message" class="form-control" rows="2" style="min-width:250px;flex:1;" placeholder="e.g. Reminder: registration closes tomorrow." required></textarea>
        <button type="submit" class="btn btn-evora-primary align-self-end"><i class="bi bi-send"></i> Send to Participants &amp; Staff</button>
    </form>
    <?php if (!empty($announcements)): ?>
    <h6 class="small text-uppercase text-muted fw-bold mb-2">Recent Announcements</h6>
    <ul class="list-unstyled mb-0">
        <?php foreach ($announcements as $a): ?>
            <li class="border-bottom py-2">
                <p class="small mb-1"><?= nl2br(e($a['message'])) ?></p>
                <span class="text-muted small"><?= date('M d, Y H:i', strtotime($a['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3">Tools</h6>
    <div class="row g-4">
        <div class="col-lg-4">
            <h6 class="small text-uppercase text-muted fw-bold mb-2">Before the Event</h6>
            <div class="row g-2">
                <?php foreach ($toolGroups['before'] as $tool): ?>
                    <div class="col-6">
                        <a href="<?= url($tool['route'], ['event_id' => $event['id']]) ?>" class="text-decoration-none" title="<?= e($tool['description'] ?? '') ?>">
                            <div class="card-evora p-3 text-center h-100"><i class="bi <?= e($tool['icon']) ?> fs-4 text-primary"></i><div class="small mt-1 text-dark"><?= e($tool['label']) ?></div></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <h6 class="small text-uppercase text-muted fw-bold mb-2">During the Event</h6>
            <?php if (empty($toolGroups['during'])): ?>
                <p class="text-muted small">Tracked above in Event Day &mdash; Live Status.</p>
            <?php else: ?>
            <div class="row g-2">
                <?php foreach ($toolGroups['during'] as $tool): ?>
                    <div class="col-6">
                        <a href="<?= url($tool['route'], ['event_id' => $event['id']]) ?>" class="text-decoration-none" title="<?= e($tool['description'] ?? '') ?>">
                            <div class="card-evora p-3 text-center h-100"><i class="bi <?= e($tool['icon']) ?> fs-4 text-primary"></i><div class="small mt-1 text-dark"><?= e($tool['label']) ?></div></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <h6 class="small text-uppercase text-muted fw-bold mb-2">After the Event</h6>
            <div class="row g-2">
                <?php foreach ($toolGroups['after'] as $tool): ?>
                    <div class="col-6">
                        <a href="<?= url($tool['route'], ['event_id' => $event['id']]) ?>" class="text-decoration-none" title="<?= e($tool['description'] ?? '') ?>">
                            <div class="card-evora p-3 text-center h-100"><i class="bi <?= e($tool['icon']) ?> fs-4 text-primary"></i><div class="small mt-1 text-dark"><?= e($tool['label']) ?></div></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
