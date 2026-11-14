<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$eventId = (int) ($_GET['event_id'] ?? 0);
// Registered (approved), attended, event completed, not cancelled — checked server-side.
try {
    $registration = EvaluationService::eventContext($db, $userId, $eventId);
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('my-registrations');
}
$existingFeedback = EvaluationService::feedbackFor($db, $eventId, (int) $registration['registration_id']);
$aspects = EvaluationService::aspectsFor($registration['category_slug']);
$tags = EvaluationService::tagsFor($registration['category_slug'], (bool) $registration['paid']);
$preselect = (int) ($_GET['rating'] ?? 0);
$publicName = EvaluationService::publicName((string) ($_SESSION['full_name'] ?? ''));

$pageDescription = 'Rate the event and help the organizer improve. Your review is shown on the event page.';
$pageTitle = 'Event Review';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<a href="<?= url('my-registrations') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to My Registrations</a>
<div class="card-evora p-4 mw-form-lg">
    <h6 class="fw-semibold mb-1">Review — <?= e($registration['event_name']) ?></h6>
    <p class="text-muted small"><?= date('M d, Y', strtotime($registration['event_date'])) ?></p>
    <?php if ($existingFeedback): $f = $existingFeedback; ?>
        <p class="text-muted small mb-3">You already reviewed this event. Thank you!</p>
        <dl class="row small mb-0">
            <dt class="col-sm-5">Your rating</dt><dd class="col-sm-7"><span class="rating-stars fs-5"><?= EvaluationService::stars($f['overall_rating']) ?></span> <?= (int) $f['overall_rating'] ?>/5</dd>
            <?php foreach (EvaluationService::aspectsFor($registration['category_slug']) as $col => $label): if ($f[$col] === null) continue; ?>
                <dt class="col-sm-5"><?= e($label) ?></dt><dd class="col-sm-7"><?= (int) $f[$col] ?>/5</dd>
            <?php endforeach; ?>
            <?php if ($f['expectations_met']): ?><dt class="col-sm-5">Met your expectations</dt><dd class="col-sm-7"><?= e(EvaluationService::EXPECTATION_LABELS[$f['expectations_met']]) ?></dd><?php endif; ?>
            <?php if ($f['join_again']): ?><dt class="col-sm-5">Would join again</dt><dd class="col-sm-7"><?= e(EvaluationService::JOIN_AGAIN_LABELS[$f['join_again']]) ?></dd><?php endif; ?>
            <?php if ($f['liked']): ?><dt class="col-sm-5">What went well</dt><dd class="col-sm-7"><?= nl2br(e($f['liked'])) ?></dd><?php endif; ?>
            <?php if ($f['improvement_tags']): ?><dt class="col-sm-5">To improve</dt><dd class="col-sm-7"><?php foreach (explode(',', $f['improvement_tags']) as $t): ?><span class="review-tag"><?= e(EvaluationService::IMPROVEMENT_TAGS[$t][0] ?? $t) ?></span> <?php endforeach; ?></dd><?php endif; ?>
            <?php if ($f['suggestions']): ?><dt class="col-sm-5">Comment</dt><dd class="col-sm-7"><?= nl2br(e($f['suggestions'])) ?></dd><?php endif; ?>
        </dl>
        <?php $myPhotos = EvaluationService::reviewPhotos($db, [(int) $f['id']])[(int) $f['id']] ?? []; if ($myPhotos): ?>
            <div class="review-photos mt-2"><?php foreach ($myPhotos as $k => $photo): ?><a href="<?= uploadUrl($photo) ?>" target="_blank" rel="noopener"><img src="<?= uploadUrl($photo) ?>" alt="Your review photo <?= $k + 1 ?>"></a><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($f['organizer_reply']): ?>
            <div class="review-reply mt-3"><div class="small fw-semibold"><i class="bi bi-reply" aria-hidden="true"></i> Organizer's reply</div><div class="small"><?= nl2br(e($f['organizer_reply'])) ?></div></div>
        <?php endif; ?>
        <a href="<?= url('event-view', ['id' => $eventId]) ?>#reviews" class="btn btn-sm btn-outline-secondary mt-3">See all reviews</a>
    <?php else: ?>
        <?php include __DIR__ . '/partials/review_form.php'; ?>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
