<?php
Auth::requireRole('organizer');
$db = Database::connection();

$eventId = (int) ($_GET['event_id'] ?? 0);
// Own events only: another organizer's event is refused outright.
$stmt = $db->prepare(
    'SELECT e.id, e.name, e.event_date, e.status FROM events e JOIN organizers o ON o.id = e.organizer_id
     WHERE e.id = :eid AND o.user_id = :uid'
);
$stmt->execute(['eid' => $eventId, 'uid' => Auth::id()]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(403);
    die('You can only view feedback for your own events.');
}

// v80: post-event evaluation results (satisfaction, effectiveness, areas to improve) and replies.
$stats = EvaluationService::reviewStats($db, 'event', $eventId);
$reviews = EvaluationService::publicReviews($db, 'event', $eventId, 200);
$attendees = $db->prepare('SELECT COUNT(*) FROM registrations r WHERE r.event_id = :e AND r.status = "approved" AND ' . EvaluationService::ATTENDED_SQL);
$attendees->execute(['e' => $eventId]);
$attendees = (int) $attendees->fetchColumn();

$pageDescription = 'Post-event evaluation: how participants rated this event and what to improve. Reviews and your replies are public on the event page.';
$pageTitle = 'Event Evaluation';
$activeRoute = 'organizer-dashboard';
$pageActions = '<a href="' . url('event-view', ['id' => $eventId]) . '#reviews" class="btn btn-sm btn-outline-secondary"><i class="bi bi-globe2" aria-hidden="true"></i> See public reviews</a>';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Dashboard', 'route' => 'organizer-dashboard'], ['label' => 'Evaluation — ' . $event['name']]]); ?>

<section class="card-evora p-4 mb-3" aria-labelledby="evalSummaryHeading">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <h2 class="h6 fw-semibold mb-0" id="evalSummaryHeading"><?= e($event['name']) ?> <span class="text-muted fw-normal small">· <?= date('M d, Y', strtotime($event['event_date'])) ?></span></h2>
        <span class="small text-muted">Response rate: <b><?= $stats['count'] ?></b> of <b><?= $attendees ?></b> attendees<?= $attendees ? ' (' . round(100 * min($stats['count'], $attendees) / $attendees) . '%)' : '' ?></span>
    </div>
    <?php if ($stats['count']): ?>
        <?php include EVORA_ROOT . '/modules/shared/partials/review_summary.php'; ?>
    <?php else: ?>
        <?= emptyState('bi-star', in_array($event['status'], EvaluationService::COMPLETED_EVENT_STATUSES, true) ? 'No reviews yet. Participants who attended are asked to review the event.' : 'Participants who attended can review this event once it is marked completed.') ?>
    <?php endif; ?>
</section>

<?php if ($reviews): ?>
<section class="card-evora p-4" aria-labelledby="reviewsHeading">
    <h2 class="h6 fw-semibold mb-1" id="reviewsHeading">Reviews (<?= count($reviews) ?>)</h2>
    <p class="small text-muted">Participants' answers cannot be changed. Your reply is shown under the review on the public event page, and the participant is notified.</p>
    <?php $reviewFullNames = true; $reviewReplyForms = true; include EVORA_ROOT . '/modules/shared/partials/review_list.php'; ?>
</section>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
