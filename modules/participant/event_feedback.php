<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$eventId = (int) ($_GET['event_id'] ?? 0);
// Registered (approved), event completed, not cancelled — checked server-side.
try {
    $registration = EvaluationService::eventContext($db, $userId, $eventId);
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    redirect('my-registrations');
}
$existingFeedback = EvaluationService::feedbackFor($db, $eventId, (int) $registration['registration_id']);
$isSeminar = $registration['category_slug'] === 'seminar-workshop';
$preselect = (int) ($_GET['rating'] ?? 0);

$pageDescription = 'Tell the organizer how the event went.';
$pageTitle = 'Event Evaluation';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<a href="<?= url('my-registrations') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to My Registrations</a>
<div class="card-evora p-4 mw-form-lg">
    <h6 class="fw-semibold mb-1">Evaluate — <?= e($registration['event_name']) ?></h6>
    <p class="text-muted small"><?= date('M d, Y', strtotime($registration['event_date'])) ?></p>
    <?php if ($existingFeedback): ?>
        <p class="text-muted small mb-3">You already evaluated this event. Thank you!</p>
        <dl class="row small mb-0">
            <dt class="col-sm-5">Your Rating</dt><dd class="col-sm-7"><span class="rating-stars fs-5"><?= EvaluationService::stars($existingFeedback['overall_rating']) ?></span> <?= (int) $existingFeedback['overall_rating'] ?>/5</dd>
            <?php if ($existingFeedback['speaker_rating'] !== null): ?><dt class="col-sm-5">Speaker Effectiveness</dt><dd class="col-sm-7"><?= (int) $existingFeedback['speaker_rating'] ?>/5</dd><?php endif; ?>
            <?php if ($existingFeedback['session_rating'] !== null): ?><dt class="col-sm-5">Session Relevance</dt><dd class="col-sm-7"><?= (int) $existingFeedback['session_rating'] ?>/5</dd><?php endif; ?>
            <?php if ($existingFeedback['venue_rating'] !== null): ?><dt class="col-sm-5">Venue Quality</dt><dd class="col-sm-7"><?= (int) $existingFeedback['venue_rating'] ?>/5</dd><?php endif; ?>
            <?php if ($existingFeedback['suggestions']): ?><dt class="col-sm-5">Comment</dt><dd class="col-sm-7"><?= nl2br(e($existingFeedback['suggestions'])) ?></dd><?php endif; ?>
        </dl>
    <?php else: ?>
        <form method="post" action="<?= url('event-feedback-save') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <label class="form-label small mb-1">How was your experience? *</label>
            <div class="star-rating mb-3" role="radiogroup" aria-label="Rating from 1 to 5 stars">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" name="overall_rating" id="overall_<?= $i ?>" value="<?= $i ?>" <?= $preselect === $i ? 'checked' : '' ?> required>
                    <label for="overall_<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label>
                <?php endfor; ?>
            </div>
            <?php if ($isSeminar): ?>
                <?php foreach (['speaker_rating' => 'Speaker Effectiveness (optional)', 'session_rating' => 'Session Relevance (optional)', 'venue_rating' => 'Venue Quality (optional)'] as $key => $label): ?>
                <div class="mb-3">
                    <label class="form-label small"><?= e($label) ?></label>
                    <div class="btn-group w-100" role="group">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <input type="radio" class="btn-check" name="<?= $key ?>" id="<?= $key ?>_<?= $i ?>" value="<?= $i ?>">
                            <label class="btn btn-outline-primary" for="<?= $key ?>_<?= $i ?>"><?= $i ?></label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label small">Comment (optional)</label>
                <textarea name="suggestions" class="form-control" rows="3" maxlength="1000" placeholder="e.g. The event was organized well."></textarea>
            </div>
            <button type="submit" class="btn btn-evora-primary">Submit Evaluation</button>
        </form>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
