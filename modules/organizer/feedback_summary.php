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
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_feedback' AND c.slug = 'seminar-workshop'
         ORDER BY e.event_date DESC"
    );
    $eventsStmt->execute(['uid' => $userId]);
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose an event to see its participant feedback.';
    $pageTitle = 'Feedback Summary';
    $activeRoute = 'feedback-summary';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Feedback Summary']]); ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-star', 'No seminar/workshop events available to you for feedback review.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('feedback-summary', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_feedback'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have feedback access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$summaryStmt = $db->prepare(
    'SELECT COUNT(*) c, AVG(overall_rating) avg_overall, AVG(speaker_rating) avg_speaker,
        AVG(session_rating) avg_session, AVG(venue_rating) avg_venue
     FROM event_feedback WHERE event_id = :id'
);
$summaryStmt->execute(['id' => $eventId]);
$summary = $summaryStmt->fetch();

$responsesStmt = $db->prepare(
    'SELECT f.*, u.full_name FROM event_feedback f
     JOIN registrations r ON r.id = f.registration_id
     JOIN participants p ON p.id = r.participant_id
     JOIN users u ON u.id = p.user_id
     WHERE f.event_id = :id ORDER BY f.created_at DESC'
);
$responsesStmt->execute(['id' => $eventId]);
$responses = $responsesStmt->fetchAll();

function fmtAvg($v): string
{
    return $v !== null ? number_format((float) $v, 1) . '/5' : '—';
}

$pageDescription = 'Ratings and comments from participants for this event.';
$pageTitle = 'Feedback Summary';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Feedback Summary'],
] : [
    ['label' => $event['name']],
    ['label' => 'Feedback Summary'],
]); ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold"><?= (int) $summary['c'] ?></div><div class="small text-muted">Responses</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold text-primary"><?= fmtAvg($summary['avg_overall']) ?></div><div class="small text-muted">Overall</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold"><?= fmtAvg($summary['avg_speaker']) ?></div><div class="small text-muted">Speaker</div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-evora p-3 text-center"><div class="fs-4 fw-bold"><?= fmtAvg($summary['avg_session']) ?></div><div class="small text-muted">Session</div></div>
    </div>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">Individual Responses</h6>
    <?php if (empty($responses)): ?>
        <?= emptyState('bi-star', 'No feedback submitted yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-sm">
            <thead><tr><th>Participant</th><th>Overall</th><th>Speaker</th><th>Session</th><th>Venue</th><th>Suggestions</th><th>Submitted</th></tr></thead>
            <tbody>
            <?php foreach ($responses as $r): ?>
                <tr>
                    <td class="small"><?= e($r['full_name']) ?></td>
                    <td><?= (int) $r['overall_rating'] ?>/5</td>
                    <td><?= $r['speaker_rating'] !== null ? (int) $r['speaker_rating'] . '/5' : '—' ?></td>
                    <td><?= $r['session_rating'] !== null ? (int) $r['session_rating'] . '/5' : '—' ?></td>
                    <td><?= $r['venue_rating'] !== null ? (int) $r['venue_rating'] . '/5' : '—' ?></td>
                    <td class="small"><?= $r['suggestions'] ? e($r['suggestions']) : '—' ?></td>
                    <td class="small"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
