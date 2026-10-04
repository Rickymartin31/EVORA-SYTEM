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

$feedback = EvaluationService::eventFeedback($db, $eventId);
$count = count($feedback);
$average = $count ? round(array_sum(array_column($feedback, 'overall_rating')) / $count, 1) : 0;
$distribution = array_fill(1, 5, 0);
foreach ($feedback as $f) {
    $distribution[(int) $f['overall_rating']]++;
}

$pageDescription = 'What participants said about this event.';
$pageTitle = 'Event Feedback';
$activeRoute = 'organizer-dashboard';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Dashboard', 'route' => 'organizer-dashboard'], ['label' => 'Feedback — ' . $event['name']]]); ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card"><div class="stat-value"><span class="rating-stars"><?= EvaluationService::stars($average) ?></span> <?= $count ? $average : '—' ?></div><div class="stat-label">Average Rating</div></div>
    </div>
    <div class="col-md-4">
        <div class="stat-card"><div class="stat-value"><?= $count ?></div><div class="stat-label">Total Reviews</div></div>
    </div>
    <div class="col-md-4">
        <div class="card-evora p-3 h-100 small">
            <?php for ($i = 5; $i >= 1; $i--): $pct = $count ? round(100 * $distribution[$i] / $count) : 0; ?>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-nowrap" style="width:2.5rem;"><?= $i ?> ★</span>
                    <div class="progress flex-grow-1" style="height:6px;"><div class="progress-bar bg-warning" style="width:<?= $pct ?>%"></div></div>
                    <span class="text-muted" style="width:2rem;"><?= $distribution[$i] ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<div class="card-evora p-4">
    <h6 class="fw-semibold mb-1"><?= e($event['name']) ?></h6>
    <p class="small text-muted"><?= date('M d, Y', strtotime($event['event_date'])) ?> · Ratings are submitted by participants and cannot be changed.</p>
    <?php if (!$feedback): ?>
        <?= emptyState('bi-star', in_array($event['status'], EvaluationService::COMPLETED_EVENT_STATUSES, true) ? 'No feedback yet.' : 'Participants can rate this event once it is marked completed.') ?>
    <?php else: ?>
    <ul class="list-unstyled mb-0">
        <?php foreach ($feedback as $f): ?>
            <li class="border-bottom py-3">
                <div class="d-flex justify-content-between">
                    <strong class="small"><?= e($f['participant_name']) ?></strong>
                    <span class="text-muted small"><?= date('M d, Y', strtotime($f['created_at'])) ?></span>
                </div>
                <div class="rating-stars"><?= EvaluationService::stars($f['overall_rating']) ?> <span class="text-muted small"><?= (int) $f['overall_rating'] ?>/5</span></div>
                <?php if ($f['suggestions']): ?><p class="small mb-0 mt-1"><?= nl2br(e($f['suggestions'])) ?></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
