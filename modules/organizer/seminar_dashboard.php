<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', 'organizer-events');

$registeredStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id AND status = "approved"');
$registeredStmt->execute(['id' => $eventId]);
$registeredCount = (int) $registeredStmt->fetch()['c'];

$checkedInStmt = $db->prepare(
    'SELECT COUNT(DISTINCT a.registration_id) c FROM attendance a JOIN registrations r ON r.id = a.registration_id
     WHERE r.event_id = :id AND r.status = "approved"'
);
$checkedInStmt->execute(['id' => $eventId]);
$checkedInCount = (int) $checkedInStmt->fetch()['c'];
$attendanceRatePct = $registeredCount > 0 ? round($checkedInCount / $registeredCount * 100, 1) : 0.0;

$sessionsCountStmt = $db->prepare("SELECT COUNT(*) c FROM event_schedule WHERE event_id = :id AND status != 'cancelled'");
$sessionsCountStmt->execute(['id' => $eventId]);
$sessionsCount = (int) $sessionsCountStmt->fetch()['c'];

$speakersCountStmt = $db->prepare('SELECT COUNT(*) c FROM event_speakers WHERE event_id = :id');
$speakersCountStmt->execute(['id' => $eventId]);
$speakersCount = (int) $speakersCountStmt->fetch()['c'];

$avgRatingStmt = $db->prepare('SELECT AVG(overall_rating) v, COUNT(*) c FROM event_feedback WHERE event_id = :id');
$avgRatingStmt->execute(['id' => $eventId]);
$avgRatingRow = $avgRatingStmt->fetch();
$avgRating = $avgRatingRow['v'] !== null ? round((float) $avgRatingRow['v'], 1) : null;
$feedbackCount = (int) $avgRatingRow['c'];

$sessionAttendanceStmt = $db->prepare(
    "SELECT sc.id, sc.session_title, sc.start_time,
        (SELECT COUNT(*) FROM session_attendance sa WHERE sa.session_id = sc.id) AS attended_count
     FROM event_schedule sc WHERE sc.event_id = :id AND sc.status != 'cancelled' ORDER BY sc.session_date IS NULL, sc.session_date, sc.start_time"
);
$sessionAttendanceStmt->execute(['id' => $eventId]);
$sessionAttendance = $sessionAttendanceStmt->fetchAll();

$speakerSummaryStmt = $db->prepare(
    'SELECT sp.id, sp.speaker_name, COUNT(sc.id) AS session_count
     FROM event_speakers sp LEFT JOIN event_schedule sc ON sc.speaker_id = sp.id
     WHERE sp.event_id = :id GROUP BY sp.id, sp.speaker_name ORDER BY sp.speaker_name'
);
$speakerSummaryStmt->execute(['id' => $eventId]);
$speakerSummary = $speakerSummaryStmt->fetchAll();

require_once EVORA_ROOT . '/modules/shared/partials/sports_ui.php';
$absentCount = max(0, $registeredCount - $checkedInCount);
$pageDescription = 'Attendance, sessions, speakers and feedback for this seminar in one place.';
$pageActions = '<a href="' . url('organizer-schedule', ['event_id' => $eventId]) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar3" aria-hidden="true"></i> Sessions</a>'
    . '<a href="' . url('session-attendance-scan', ['event_id' => $eventId]) . '" class="btn btn-evora-primary btn-sm"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> Scan Attendance</a>';
$pageTitle = 'Seminar Operations Dashboard';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Seminar Operations Dashboard'],
]); ?>

<?= moduleOverview('bi-easel', $event['name'], [
    $event['event_date'] ? date('M d, Y', strtotime($event['event_date'])) : null,
    $event['venue'] ?? null,
    $sessionsCount . ' session' . ($sessionsCount === 1 ? '' : 's') . ' · ' . $speakersCount . ' speaker' . ($speakersCount === 1 ? '' : 's'),
], [
    ['label' => 'Total Participants', 'value' => $registeredCount, 'hint' => 'Approved registrations'],
    ['label' => 'Present', 'value' => $checkedInCount, 'tone' => 'success', 'hint' => 'Checked in'],
    ['label' => 'Absent', 'value' => $absentCount, 'tone' => 'danger', 'hint' => 'Not checked in'],
    ['label' => 'Attendance Rate', 'value' => $attendanceRatePct . '%', 'tone' => 'primary'],
], '<span class="badge badge-status-' . e($event['status']) . '">' . e(ucfirst($event['status'])) . '</span>') ?>

<div class="row g-3">
    <div class="col-lg-5">
        <section class="card-evora p-4 h-100" aria-label="Session attendance">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-people text-primary me-1" aria-hidden="true"></i> Session Attendance</h2>
            <?php if (empty($sessionAttendance)): ?>
                <?= emptyState('bi-calendar-x', 'No sessions created. Add your first seminar session to start tracking attendance.', 'Add a Session', url('organizer-schedule', ['event_id' => $eventId])) ?>
            <?php else: ?>
                <?php foreach ($sessionAttendance as $s): $attended = (int) $s['attended_count']; $pct = $registeredCount > 0 ? min(100, (int) round($attended / $registeredCount * 100)) : 0; ?>
                    <div class="attendance-row">
                        <div class="attendance-row-head">
                            <span class="attendance-row-title"><span class="fw-semibold"><?= e($s['session_title']) ?></span> <span class="text-muted small"><?= date('h:i A', strtotime($s['start_time'])) ?></span></span>
                            <span class="attendance-row-figure"><?= $attended ?> <span class="text-muted fw-normal">/ <?= $registeredCount ?></span></span>
                        </div>
                        <div class="progress progress-thin" role="progressbar" aria-label="<?= e($s['session_title']) ?> attendance" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?= $pct ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="card-evora p-4 h-100" aria-label="Speaker summary">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-mic text-primary me-1" aria-hidden="true"></i> Speakers</h2>
            <?php if (empty($speakerSummary)): ?>
                <?= emptyState('bi-mic-mute', 'No speakers added yet. Add speakers, then assign them to sessions.', 'Add a Speaker', url('organizer-speakers', ['event_id' => $eventId])) ?>
            <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($speakerSummary as $sp): ?>
                    <?php $initials = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($sp['speaker_name'])), 0, 2)))); ?>
                    <li class="d-flex align-items-center gap-2 py-2 border-bottom">
                        <span class="speaker-initials avatar-30 small" aria-hidden="true"><?= e($initials) ?></span>
                        <span class="flex-grow-1 min-w-0 fw-semibold"><?= e($sp['speaker_name']) ?></span>
                        <span class="badge bg-secondary"><?= (int) $sp['session_count'] ?> session<?= (int) $sp['session_count'] === 1 ? '' : 's' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-3">
        <section class="card-evora p-4 h-100 text-center" aria-label="Feedback">
            <h2 class="h6 fw-semibold mb-3 text-start"><i class="bi bi-chat-square-heart text-primary me-1" aria-hidden="true"></i> Feedback</h2>
            <?php if ($avgRating === null): ?>
                <p class="text-muted small">No feedback yet. Participants can rate the seminar after it is completed.</p>
            <?php else: ?>
                <div class="fs-2 fw-bold font-tabular"><?= $avgRating ?><span class="fs-6 text-muted">/5</span></div>
                <div class="rating-stars mb-1" aria-hidden="true"><?= EvaluationService::stars($avgRating) ?></div>
                <div class="small text-muted mb-3"><?= $feedbackCount ?> response<?= $feedbackCount === 1 ? '' : 's' ?></div>
            <?php endif; ?>
            <a href="<?= url('feedback-summary', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm">View Feedback</a>
        </section>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
