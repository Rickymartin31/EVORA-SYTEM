<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

// Staff-only: with no event chosen yet, show a picker instead of erroring.
// Organizer behavior is completely unchanged.
if ($eventId === 0 && $role === 'staff') {
    $eventsStmt = $db->prepare(
        "SELECT e.id, e.name, e.event_date FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_sessions' AND c.slug = 'seminar-workshop'
         ORDER BY e.event_date DESC"
    );
    $eventsStmt->execute(['uid' => $userId]);
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a seminar to manage its sessions.';
    $pageTitle = 'Schedule';
    $activeRoute = 'organizer-schedule';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Schedule']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-clock-history', 'No seminar/workshop events available to you for session management.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('organizer-schedule', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
                    <div class="fw-semibold"><?= e($ev['name']) ?></div>
                    <div class="small text-muted"><?= date('M d, Y', strtotime($ev['event_date'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
    <?php
    exit;
}

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_sessions'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have session management access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$speakersStmt = $db->prepare('SELECT * FROM event_speakers WHERE event_id = :id ORDER BY speaker_name');
$speakersStmt->execute(['id' => $eventId]);
$speakers = $speakersStmt->fetchAll();

$scheduleStmt = $db->prepare(
    'SELECT sc.*, sp.speaker_name FROM event_schedule sc LEFT JOIN event_speakers sp ON sp.id = sc.speaker_id
     WHERE sc.event_id = :id ORDER BY sc.session_date IS NULL, sc.session_date, sc.start_time'
);
$scheduleStmt->execute(['id' => $eventId]);
$scheduleList = $scheduleStmt->fetchAll();

$editSession = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($scheduleList as $sc) {
        if ((int) $sc['id'] === $editId) {
            $editSession = $sc;
            break;
        }
    }
}

// Session type is a category, not a status: one neutral badge style (status colors stay reserved).
$typeLabels = [
    'keynote' => ['label' => 'Keynote', 'badge' => 'bg-primary'],
    'lecture' => ['label' => 'Lecture', 'badge' => 'bg-primary'],
    'workshop' => ['label' => 'Workshop', 'badge' => 'bg-primary'],
    'breakout' => ['label' => 'Breakout Session', 'badge' => 'bg-primary'],
    'panel' => ['label' => 'Panel Discussion', 'badge' => 'bg-primary'],
    'hands_on' => ['label' => 'Hands-on Activity', 'badge' => 'bg-primary'],
];
$statusLabels = [
    'scheduled' => ['label' => 'Scheduled', 'badge' => 'bg-secondary'],
    'ongoing' => ['label' => 'Ongoing', 'badge' => 'bg-danger'],
    'completed' => ['label' => 'Completed', 'badge' => 'bg-success'],
    'cancelled' => ['label' => 'Cancelled', 'badge' => 'bg-dark'],
];

$pageDescription = 'Plan the seminar program: sessions, speakers, venues and live status.';
$pageTitle = 'Schedule';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Schedule'],
] : [
    ['label' => $event['name']],
    ['label' => 'Schedule'],
]); ?>

<?php
$sessionBadge = [
    'scheduled' => ['scheduled', 'Scheduled'], 'ongoing' => ['live', 'Ongoing'],
    'completed' => ['completed', 'Completed'], 'cancelled' => ['cancelled', 'Cancelled'],
];
?>
<div class="row g-3">
    <div class="col-lg-7">
        <section class="card-evora p-4" aria-label="Seminar program">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-semibold mb-0">Seminar Program</h2>
                <?php if ($scheduleList): ?><span class="small text-muted"><?= count($scheduleList) ?> session<?= count($scheduleList) === 1 ? '' : 's' ?></span><?php endif; ?>
            </div>
            <?php if (empty($scheduleList)): ?>
                <?= emptyState('bi-calendar-x', 'No sessions created. Add your first seminar session with the form.') ?>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($scheduleList as $sc): ?>
                    <?php $t = $typeLabels[$sc['session_type']] ?? $typeLabels['lecture']; ?>
                    <?php [$badgeClass, $badgeLabel] = $sessionBadge[$sc['status']] ?? $sessionBadge['scheduled']; ?>
                    <div class="col-md-6">
                        <article class="session-card<?= $sc['status'] === 'ongoing' ? ' is-ongoing' : ($sc['status'] === 'cancelled' ? ' is-cancelled' : '') ?>" aria-label="<?= e($sc['session_title']) ?>">
                            <div class="session-card-head">
                                <h3 class="session-title"><?= e($sc['session_title']) ?></h3>
                                <span class="badge badge-status-<?= $badgeClass ?> flex-shrink-0"><?= e($badgeLabel) ?></span>
                            </div>
                            <div><span class="badge <?= $t['badge'] ?>"><?= e($t['label']) ?></span></div>
                            <ul class="session-facts">
                                <li><i class="bi bi-clock" aria-hidden="true"></i> <span class="visually-hidden">Schedule: </span><?= $sc['session_date'] ? date('M d', strtotime($sc['session_date'])) . ', ' : '' ?><?= date('h:i A', strtotime($sc['start_time'])) ?> – <?= date('h:i A', strtotime($sc['end_time'])) ?></li>
                                <li><i class="bi bi-mic" aria-hidden="true"></i> <span class="visually-hidden">Speaker: </span><?= $sc['speaker_name'] ? e($sc['speaker_name']) : '<span class="text-muted">No speaker assigned</span>' ?></li>
                                <li><i class="bi bi-geo-alt" aria-hidden="true"></i> <span class="visually-hidden">Venue: </span><?= $sc['venue'] ? e($sc['venue']) : '<span class="text-muted">Venue not set</span>' ?></li>
                            </ul>
                            <div class="session-card-foot">
                                <form method="post" action="<?= url('organizer-schedule-save') ?>" class="d-flex align-items-center gap-2">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="schedule_action" value="update_status">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                    <label class="small text-muted" for="status<?= (int) $sc['id'] ?>">Status</label>
                                    <select id="status<?= (int) $sc['id'] ?>" name="status" class="form-select form-select-sm w-auto" data-autosubmit
                                            data-confirm-value="cancelled" data-confirm-title="Cancel this session?" data-confirm-button="Cancel Session"
                                            data-confirm-text="<?= e($sc['session_title']) ?> will be marked cancelled and left out of the seminar's session count.">
                                        <?php foreach ($statusLabels as $key => $info): ?>
                                            <option value="<?= $key ?>" <?= $sc['status'] === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <div class="d-flex gap-1">
                                    <a href="<?= url('organizer-schedule', ['event_id' => $eventId, 'edit' => $sc['id']]) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Edit <?= e($sc['session_title']) ?>">Edit</a>
                                    <form method="post" action="<?= url('organizer-schedule-save') ?>" class="d-inline" data-confirm="<?= e($sc['session_title']) ?> and its attendance records will be removed from the program." data-confirm-title="Remove this session?" data-confirm-button="Remove Session" data-confirm-variant="danger">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="schedule_action" value="delete">
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove <?= e($sc['session_title']) ?>">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3"><?= $editSession ? 'Edit Session' : 'Add Session' ?></h6>
            <form method="post" action="<?= url('organizer-schedule-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="schedule_action" value="<?= $editSession ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editSession): ?><input type="hidden" name="schedule_id" value="<?= $editSession['id'] ?>"><?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small" for="scTitle">Session Title *</label>
                    <input id="scTitle" type="text" name="session_title" class="form-control form-control-sm" value="<?= e($editSession['session_title'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="scDesc">Description</label>
                    <textarea id="scDesc" name="description" class="form-control form-control-sm" rows="2"><?= e($editSession['description'] ?? '') ?></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="scDate">Date</label>
                    <input id="scDate" type="date" name="session_date" class="form-control form-control-sm" value="<?= e($editSession['session_date'] ?? $event['event_date']) ?>">
                </div>
                <div class="row">
                    <div class="col-6 mb-2">
                        <label class="form-label small" for="scStart">Start *</label>
                        <input id="scStart" type="time" name="start_time" class="form-control form-control-sm" value="<?= $editSession ? substr($editSession['start_time'], 0, 5) : '' ?>" required>
                    </div>
                    <div class="col-6 mb-2">
                        <label class="form-label small" for="scEnd">End *</label>
                        <input id="scEnd" type="time" name="end_time" class="form-control form-control-sm" value="<?= $editSession ? substr($editSession['end_time'], 0, 5) : '' ?>" required>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="scVenue">Venue</label>
                    <input id="scVenue" type="text" name="venue" class="form-control form-control-sm" placeholder="e.g. Main Hall, Room A" value="<?= e($editSession['venue'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="scType">Session Type *</label>
                    <select id="scType" name="session_type" class="form-select form-select-sm">
                        <?php foreach ($typeLabels as $key => $info): ?>
                            <option value="<?= $key ?>" <?= ($editSession['session_type'] ?? 'lecture') === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="scSpeaker">Speaker</label>
                    <select id="scSpeaker" name="speaker_id" class="form-select form-select-sm">
                        <option value="">None</option>
                        <?php foreach ($speakers as $sp): ?><option value="<?= $sp['id'] ?>" <?= (int) ($editSession['speaker_id'] ?? 0) === (int) $sp['id'] ? 'selected' : '' ?>><?= e($sp['speaker_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editSession ? 'Update Session' : 'Add Session' ?></button>
                <?php if ($editSession): ?>
                    <a href="<?= url('organizer-schedule', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
