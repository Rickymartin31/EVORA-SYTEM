<?php
/**
 * Floating evaluation card (bottom-right), included by layout_bottom.php.
 * Participants: a completed event they have not rated yet takes priority.
 * Participants/organizers/staff: "How was your experience using EVORA?" once
 * they have used EVORA (same rules as the ⭐ button). "Later" hides a card for a day.
 * No sidebar item — evaluation is contextual only.
 */
if (!Auth::check() || !in_array(Auth::role(), EvaluationService::PLATFORM_ROLES, true)) {
    return;
}
if (in_array($activeRoute ?? '', ['event-feedback', 'evaluate'], true) || ($_GET['route'] ?? '') === 'event-feedback') {
    return;
}

$promptDb = Database::connection();
$promptEvent = null;
$promptPlatform = false;
if (Auth::role() === 'participant') {
    $promptEvent = EvaluationService::pendingEventEvaluation($promptDb, Auth::id());
    if ($promptEvent && EvaluationService::isSnoozed('event:' . $promptEvent['event_id'])) {
        $promptEvent = null;
    }
}
if (!$promptEvent && !EvaluationService::isSnoozed('platform')) {
    $promptPlatform = EvaluationService::platformPromptDue($promptDb, Auth::id(), (string) Auth::role());
}
if (!$promptEvent && !$promptPlatform) {
    return;
}
?>
<div class="evaluation-prompt card-evora shadow-lg p-3" role="dialog" aria-labelledby="evaluationPromptTitle">
    <?php if ($promptEvent): ?>
        <div class="d-flex justify-content-between align-items-start">
            <div class="fw-semibold" id="evaluationPromptTitle">🎉 Event Completed!</div>
            <form method="post" action="<?= url('evaluation-action') ?>" class="m-0">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="snooze">
                <input type="hidden" name="key" value="event:<?= (int) $promptEvent['event_id'] ?>">
                <button type="submit" class="btn-close btn-sm" aria-label="Later"></button>
            </form>
        </div>
        <div class="small text-muted mb-1"><?= e($promptEvent['event_name']) ?> · <?= date('M d, Y', strtotime($promptEvent['event_date'])) ?></div>
        <div class="small mb-1">How was your experience?</div>
        <div class="evaluation-prompt-stars mb-2">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <a href="<?= url('event-feedback', ['event_id' => $promptEvent['event_id'], 'rating' => $i]) ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</a>
            <?php endfor; ?>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('event-feedback', ['event_id' => $promptEvent['event_id']]) ?>" class="btn btn-sm btn-evora-primary">Rate Event</a>
            <form method="post" action="<?= url('evaluation-action') ?>" class="m-0">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="snooze">
                <input type="hidden" name="key" value="event:<?= (int) $promptEvent['event_id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Later</button>
            </form>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-start">
            <div class="fw-semibold" id="evaluationPromptTitle">How was your experience using EVORA?</div>
            <form method="post" action="<?= url('evaluation-action') ?>" class="m-0">
                <?= Csrf::field() ?>
                <input type="hidden" name="do" value="snooze">
                <input type="hidden" name="key" value="platform">
                <button type="submit" class="btn-close btn-sm" aria-label="Later"></button>
            </form>
        </div>
        <form method="post" action="<?= url('evaluation-action') ?>" class="mt-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="do" value="platform_quick">
            <div class="star-rating star-rating-sm mb-2" role="radiogroup" aria-label="Rating from 1 to 5 stars">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" name="rating" id="platform_rating_<?= $i ?>" value="<?= $i ?>" required>
                    <label for="platform_rating_<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label>
                <?php endfor; ?>
            </div>
            <textarea name="comment" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Comment (optional)"></textarea>
            <div class="d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-sm btn-evora-primary">Submit</button>
                <button type="submit" name="do" value="snooze" formnovalidate class="btn btn-sm btn-outline-secondary">Later</button>
                <input type="hidden" name="key" value="platform">
                <a href="<?= url('evaluate') ?>" class="small ms-auto">Full survey</a>
            </div>
        </form>
    <?php endif; ?>
</div>
