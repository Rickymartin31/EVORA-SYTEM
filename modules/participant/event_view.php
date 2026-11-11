<?php
$db = Database::connection();
$eventId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare(
    "SELECT e.*, c.name AS category_name, c.slug AS category_slug, o.organization_name,
            " . OrganizerBadgeService::sqlVerified('o') . " AS organizer_verified, o.badge_verified_at
     FROM events e JOIN event_categories c ON c.id = e.category_id JOIN organizers o ON o.id = e.organizer_id
     WHERE e.id = :id AND e.status != 'removed'"
);
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('events');
}

// An unpublished event (draft, waiting for approval, archived) is only shown to the people
// involved: admins, its organizer, its staff, and participants registered for it (v45).
if (!in_array($event['status'], ['approved', 'open', 'closed', 'ongoing', 'completed', 'cancelled'], true)) {
    $canSee = false;
    if (Auth::check()) {
        $uid = (int) Auth::id();
        $q = match (Auth::role()) {
            'admin' => null,
            'organizer' => 'SELECT 1 FROM organizers WHERE id = :x AND user_id = :u',
            'staff' => 'SELECT 1 FROM event_staff WHERE event_id = :x AND user_id = :u',
            'participant' => 'SELECT 1 FROM registrations r JOIN participants p ON p.id = r.participant_id WHERE r.event_id = :x AND p.user_id = :u',
            default => false,
        };
        if ($q === null) {
            $canSee = true;
        } elseif ($q !== false) {
            $see = $db->prepare($q);
            $see->execute(['x' => Auth::role() === 'organizer' ? (int) $event['organizer_id'] : $eventId, 'u' => $uid]);
            $canSee = (bool) $see->fetchColumn();
        }
    }
    if (!$canSee) {
        flash('error', 'Event not found.');
        redirect('events');
    }
}

$approvedCount = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$approvedCount->execute(['id' => $eventId]);
$approvedCountVal = $approvedCount->fetch()['c'];

$myRegistration = null;
$previousRegistration = null;
if (Auth::check() && Auth::role() === 'participant') {
    $pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
    $pStmt->execute(['uid' => Auth::id()]);
    $participant = $pStmt->fetch();
    if ($participant) {
        $rStmt = $db->prepare(
            'SELECT r.*, COALESCE(rc.fee, e.fee) AS fee, pay.status AS payment_status
             FROM registrations r
             JOIN events e ON e.id = r.event_id
             LEFT JOIN race_categories rc ON rc.id = r.race_category_id
             LEFT JOIN payments pay ON pay.registration_id = r.id
             WHERE r.event_id = :eid AND r.participant_id = :pid
             ORDER BY (r.status <> "cancelled") DESC, r.id DESC LIMIT 1'
        );
        $rStmt->execute(['eid' => $eventId, 'pid' => $participant['id']]);
        $myRegistration = $rStmt->fetch() ?: null;
        // Only a cancelled registration: shown as a note, and the participant may register again (v47).
        if ($myRegistration && $myRegistration['status'] === 'cancelled') {
            $previousRegistration = $myRegistration;
            $myRegistration = null;
        }
    }
}

// Waitlist, saved state and full categories for the signed-in participant (v39).
$waitEntry = null;
$eventFull = false;
$isSaved = false;
$fullCategories = [];
if (!empty($participant)) {
    WaitlistService::process($db, $eventId); // expire old offers / hand out freed slots before showing the page
    $waitEntry = WaitlistService::entry($db, $eventId, (int) $participant['id']);
    $eventFull = WaitlistService::isFull($db, $event, null, (int) $participant['id']);
    $isSaved = ParticipantToolsService::isSaved($db, (int) Auth::id(), $eventId);
    if ($event['category_slug'] === 'fun-run') {
        $fc = $db->prepare('SELECT id, name FROM race_categories WHERE event_id = :id ORDER BY fee');
        $fc->execute(['id' => $eventId]);
        foreach ($fc->fetchAll() as $cat) {
            if ($eventFull || WaitlistService::isFull($db, $event, (int) $cat['id'], (int) $participant['id'])) {
                $fullCategories[] = $cat;
            }
        }
    }
}
$waitActive = $waitEntry && in_array($waitEntry['status'], ['waiting', 'offered'], true);

$raceCategories = [];
$routeImages = [];
if ($event['category_slug'] === 'fun-run') {
    $catStmt = $db->prepare('SELECT name, fee FROM race_categories WHERE event_id = :id ORDER BY fee');
    $catStmt->execute(['id' => $eventId]);
    $raceCategories = $catStmt->fetchAll();

    $imgStmt = $db->prepare('SELECT * FROM event_route_images WHERE event_id = :id ORDER BY uploaded_at DESC');
    $imgStmt->execute(['id' => $eventId]);
    $routeImages = $imgStmt->fetchAll();
}

$announcementsStmt = $db->prepare('SELECT * FROM event_announcements WHERE event_id = :id ORDER BY created_at DESC LIMIT 20');
$announcementsStmt->execute(['id' => $eventId]);
$announcements = $announcementsStmt->fetchAll();

$speakers = [];
$schedule = [];
if ($event['category_slug'] === 'seminar-workshop') {
    $speakers = $db->prepare('SELECT * FROM event_speakers WHERE event_id = :id');
    $speakers->execute(['id' => $eventId]);
    $speakers = $speakers->fetchAll();

    $schedule = $db->prepare('SELECT sc.*, sp.speaker_name FROM event_schedule sc LEFT JOIN event_speakers sp ON sp.id = sc.speaker_id WHERE sc.event_id = :id ORDER BY sc.start_time');
    $schedule->execute(['id' => $eventId]);
    $schedule = $schedule->fetchAll();
}

$pageTitle = $event['name'];
// Share preview: event name, when/where/who, the first part of the description and the banner.
$pageMeta = [
    'title' => $event['name'],
    'description' => date('D, M j, Y', strtotime($event['event_date'])) . ($event['event_time'] ? ' · ' . date('g:i A', strtotime($event['event_time'])) : '')
        . ' · ' . $event['venue'] . ' · by ' . $event['organization_name'] . ((string) $event['description'] !== '' ? ' — ' . $event['description'] : ''),
    'url' => url('event-view', ['id' => $eventId]),
    'type' => 'article',
];
if ($event['banner_path']) {
    $pageMeta['image'] = uploadUrl($event['banner_path']);
}
$isGuest = !Auth::check();
$activeRoute = 'events';

if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_head.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
}
?>
<?php if (Auth::role() === 'admin'): ?>
<a href="<?= url('admin-events') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Event Monitoring</a>
<?php else: ?>
<a href="<?= url('events') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Events</a>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($event['banner_path']): ?>
            <img src="<?= uploadUrl($event['banner_path']) ?>" class="w-100 mb-3" style="max-height:320px;object-fit:cover;border-radius:.75rem;">
        <?php endif; ?>
        <span class="badge bg-light text-dark border mb-2"><?= e($event['category_name']) ?></span>
        <h3 class="fw-bold"><?= e($event['name']) ?></h3>
        <p class="text-muted"><?= nl2br(e($event['description'])) ?></p>

        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-3">Event Details</h6>
            <dl class="row small mb-0">
                <dt class="col-sm-3">Organizer</dt><dd class="col-sm-9"><?= e($event['organization_name']) ?><?= verifiedBadge((bool) $event['organizer_verified'], true) ?>
                    <?php if ($event['organizer_verified']): ?><div class="small text-muted">Identity and organization documents checked by an EVORA administrator on <?= date('M d, Y', strtotime($event['badge_verified_at'])) ?>.</div><?php endif; ?></dd>
                <dt class="col-sm-3">Date &amp; Time</dt><dd class="col-sm-9"><?= date('F d, Y', strtotime($event['event_date'])) ?> at <?= date('h:i A', strtotime($event['event_time'])) ?></dd>
                <dt class="col-sm-3">Venue</dt><dd class="col-sm-9"><?= e($event['venue']) ?></dd>
                <?php if (!empty($event['registration_deadline'])): ?>
                <dt class="col-sm-3">Registration Deadline</dt><dd class="col-sm-9"><?= date('F d, Y', strtotime($event['registration_deadline'])) ?></dd>
                <?php endif; ?>
                <dt class="col-sm-3">Fee</dt>
                <dd class="col-sm-9">
                    <?php if (!empty($raceCategories)): ?>
                        <?php foreach ($raceCategories as $rc): ?>
                            <span class="badge bg-light text-dark border me-1"><?= e($rc['name']) ?>: <?= currency($rc['fee']) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?= $event['fee'] > 0 ? currency($event['fee']) : 'Free' ?>
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-3">Slots</dt><dd class="col-sm-9"><?= $approvedCountVal ?> / <?= $event['max_participants'] ?> registered</dd>
                <dt class="col-sm-3">Contact</dt><dd class="col-sm-9"><?= e($event['contact_info'] ?: '—') ?></dd>
            </dl>
        </div>

        <?php if (!empty($announcements)): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2"><i class="bi bi-megaphone me-1"></i>Announcements</h6>
            <ul class="list-unstyled mb-0">
                <?php foreach ($announcements as $a): ?>
                    <li class="border-bottom py-2">
                        <p class="small mb-1"><?= nl2br(e($a['message'])) ?></p>
                        <span class="text-muted small"><?= date('M d, Y H:i', strtotime($a['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($routeImages)): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2"><i class="bi bi-signpost-split me-1"></i>Route Map</h6>
            <div class="row g-2">
                <?php foreach ($routeImages as $img): ?>
                    <div class="col-6 col-md-4">
                        <a href="<?= uploadUrl($img['image_path']) ?>" target="_blank">
                            <img src="<?= uploadUrl($img['image_path']) ?>" class="img-fluid rounded border" style="width:100%;height:160px;object-fit:cover;">
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($event['requirements']): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2">Requirements</h6>
            <p class="small mb-0"><?= nl2br(e($event['requirements'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($event['rules']): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2">Event Rules</h6>
            <p class="small mb-0"><?= nl2br(e($event['rules'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($speakers)): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2">Speakers</h6>
            <?php foreach ($speakers as $sp): ?>
                <p class="small mb-1"><strong><?= e($sp['speaker_name']) ?></strong> — <?= e($sp['topic']) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($schedule)): ?>
        <div class="card-evora p-4 mb-3">
            <h6 class="fw-semibold mb-2">Schedule</h6>
            <?php foreach ($schedule as $sc): ?>
                <p class="small mb-1"><?= date('h:i A', strtotime($sc['start_time'])) ?> - <?= date('h:i A', strtotime($sc['end_time'])) ?>: <?= e($sc['session_title']) ?> <?= $sc['speaker_name'] ? '(' . e($sc['speaker_name']) . ')' : '' ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <?php if (Auth::role() === 'admin'): ?>
        <?php $adminRequest = cancellationRequests($db, $eventId)[$eventId] ?? null; $cancellable = in_array($event['status'], ['pending_approval', 'approved', 'open', 'closed', 'ongoing'], true); ?>
        <div class="card-evora p-4 mb-3 border-warning-subtle">
            <h6 class="fw-semibold mb-1"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Admin actions</h6>
            <p class="small text-muted mb-3">Status: <span class="badge badge-status-<?= e($event['status']) ?>"><?= ucfirst(str_replace('_', ' ', e($event['status']))) ?></span></p>
            <?php if ($adminRequest): ?>
                <?= evoraAlert('action', '<strong>The organizer asked to cancel this event</strong> on ' . e(date('M d, Y g:i A', strtotime($adminRequest['requested_at']))) . '.<br>Reason: ' . e($adminRequest['reason']), ['icon' => 'bi-exclamation-triangle', 'class' => 'small']) ?>
            <?php endif; ?>
            <div class="d-grid gap-2">
            <?php if ($cancellable): ?>
                <form method="post" action="<?= url('admin-event-action') ?>"
                      data-prompt="Registrations stop and the organizer and participants are notified. Paid participants must then be refunded. Enter a reason (at least 10 characters, shown to the organizer)." data-prompt-field="remarks" data-prompt-min="10" data-prompt-title="Cancel this event?" data-prompt-button="Cancel Event">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="remarks" value=""><input type="hidden" name="return_to" value="event">
                    <button type="submit" class="btn btn-outline-warning w-100"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Cancel Event<?= $adminRequest ? ' (approve request)' : '' ?></button>
                </form>
            <?php endif; ?>
            <?php if ($event['status'] === 'completed'): ?>
                <form method="post" action="<?= url('admin-event-action') ?>" data-confirm="Archive this completed event?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="return_to" value="event">
                    <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive</button>
                </form>
            <?php endif; ?>
            <?php if (!in_array($event['status'], ['removed', 'archived'], true)): ?>
                <form method="post" action="<?= url('admin-event-action') ?>" data-confirm="Remove this event? It is hidden from the platform for policy reasons and the organizer is notified." data-confirm-variant="danger">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="return_to" value="event">
                    <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Remove (policy violation)</button>
                </form>
            <?php endif; ?>
            </div>
            <?php if (in_array($event['status'], ['removed', 'archived'], true)): ?><p class="small text-muted mt-2 mb-0">No further admin actions for a <?= e(str_replace('_', ' ', $event['status'])) ?> event.</p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php // Phones: the main button of this card also shows in a sticky bar at the bottom (v48).
        $ctaFees = array_map('floatval', array_column($raceCategories, 'fee'));
        $ctaPrice = $ctaFees ? (min($ctaFees) > 0 ? (count(array_unique($ctaFees)) > 1 ? 'From ' : '') . currency(min($ctaFees)) : 'Free') : ((float) $event['fee'] > 0 ? currency($event['fee']) : 'Free'); ?>
        <div class="card-evora p-4" data-mobile-cta data-cta-label="<?= e($event['name']) ?>" data-cta-price="<?= e($ctaPrice) ?>">
            <h6 class="fw-semibold mb-3">Registration</h6>
            <?php if ($previousRegistration): ?>
                <p class="small text-muted mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> You cancelled your earlier registration for this event. You can register again while registration is open.</p>
            <?php endif; ?>
            <?php if ($isGuest): ?>
                <p class="small text-muted">Log in as a participant to register for this event.</p>
                <a href="<?= url('login', ['return' => 'route=event-view&id=' . (int) $event['id']]) ?>" class="btn btn-evora-primary w-100">Login to Register</a>
            <?php elseif (Auth::role() !== 'participant'): ?>
                <p class="small text-muted mb-0">Only participant accounts can register for events.</p>
            <?php elseif ($myRegistration): ?>
                <p class="small mb-2">Your registration status:</p>
                <span class="badge badge-status-<?= e($myRegistration['status']) ?> fs-6"><?= ucfirst(e($myRegistration['status'])) ?></span>
                <?php if ((float) $myRegistration['fee'] > 0 && !in_array($myRegistration['status'], ['rejected', 'cancelled'], true) && in_array($myRegistration['payment_status'], [null, 'pending', 'rejected', 'failed', 'cancelled'], true)): ?>
                    <a href="<?= url('payment-upload', ['registration_id' => $myRegistration['id']]) ?>" class="btn btn-evora-primary w-100 mt-3">
                        <?php if ($myRegistration['payment_status'] === 'pending'): ?>Continue Payment
                        <?php elseif ($myRegistration['payment_status']): ?>Retry Payment
                        <?php else: ?>Continue to Payment
                        <?php endif; ?>
                    </a>
                <?php elseif ($myRegistration['status'] === 'approved'): ?>
                    <?php if ($event['category_slug'] === 'fun-run' && !empty($event['gps_tracking']) && date('Y-m-d') >= $event['event_date'] && date('Y-m-d') <= date('Y-m-d', strtotime($event['event_date'] . ' +' . RaceTrackingService::GPX_UPLOAD_DAYS . ' days'))): ?>
                        <a href="<?= url('race-tracker', ['registration_id' => $myRegistration['id']]) ?>" class="btn btn-evora-primary w-100 mt-3"><i class="bi bi-broadcast"></i> Open Race Tracker</a>
                    <?php endif; ?>
                    <a href="<?= url('my-qr') ?>" class="btn btn-outline-primary w-100 mt-3">View My QR</a>
                <?php endif; ?>
            <?php elseif ($event['status'] !== 'open' || $event['event_date'] < date('Y-m-d')): ?>
                <p class="small text-muted mb-0">Registration is not currently open for this event.</p>
            <?php elseif (!empty($event['registration_deadline']) && $event['registration_deadline'] < date('Y-m-d')): ?>
                <p class="small text-danger mb-0">The registration deadline (<?= date('M d, Y', strtotime($event['registration_deadline'])) ?>) has passed.</p>
            <?php elseif ($waitActive && $waitEntry['status'] === 'offered'): ?>
                <div class="alert alert-success small mb-2"><strong>A slot is held for you</strong> until <?= date('M d, g:i A', strtotime($waitEntry['offer_expires_at'])) ?>. Register before then to keep it.</div>
                <a href="<?= url('event-register', ['id' => $event['id']]) ?>" class="btn btn-evora-primary w-100">Register Now</a>
                <?php include EVORA_ROOT . '/modules/participant/partials/waitlist_leave.php'; ?>
            <?php elseif ($waitActive): ?>
                <p class="small mb-2">This event is full. You are <strong>number <?= (int) $waitEntry['position'] ?></strong> on the waitlist<?= $waitEntry['race_category_id'] ? ' for your chosen category' : '' ?>. We will notify you if a slot opens.</p>
                <?php include EVORA_ROOT . '/modules/participant/partials/waitlist_leave.php'; ?>
            <?php elseif ($eventFull): ?>
                <p class="small text-danger mb-2">This event has reached maximum capacity.</p>
                <?php $waitCategories = $fullCategories; include EVORA_ROOT . '/modules/participant/partials/waitlist_join.php'; ?>
            <?php else: ?>
                <a href="<?= url('event-register', ['id' => $event['id']]) ?>" class="btn btn-evora-primary w-100">Register Now</a>
                <?php if ($fullCategories): ?>
                    <p class="small text-muted mt-3 mb-1">Full categories: <?= e(implode(', ', array_column($fullCategories, 'name'))) ?>. Want one of them?</p>
                    <?php $waitCategories = $fullCategories; include EVORA_ROOT . '/modules/participant/partials/waitlist_join.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if (in_array($event['status'], ['approved', 'open', 'closed', 'ongoing'], true)): ?>
        <div class="card-evora p-4 mt-3">
            <h6 class="fw-semibold mb-3">Keep track</h6>
            <div class="d-grid gap-2">
                <?php if (!$isGuest && Auth::role() === 'participant'): ?>
                    <form method="post" action="<?= url('event-save-toggle') ?>" class="m-0 d-grid"><?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <button class="btn btn-sm <?= $isSaved ? 'btn-secondary' : 'btn-outline-secondary' ?>"><i class="bi <?= $isSaved ? 'bi-bookmark-fill' : 'bi-bookmark' ?> me-1" aria-hidden="true"></i><?= $isSaved ? 'Saved — click to remove' : 'Save Event' ?></button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(ParticipantToolsService::googleCalendarUrl($event)) ?>" target="_blank" rel="noopener"><i class="bi bi-google me-1" aria-hidden="true"></i>Add to Google Calendar</a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('event-ics', ['id' => $event['id']]) ?>"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Add to Phone / Outlook (.ics)</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_foot.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
}
?>
