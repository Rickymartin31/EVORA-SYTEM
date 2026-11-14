<?php
/** Post-event review form (v80/v81). Expects $eventId, $aspects, $tags, $preselect, $publicName; optional $reviewReturn ("event" = back to the event page). */
?>
<form method="post" action="<?= url('event-feedback-save') ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <?php if (($reviewReturn ?? '') === 'event'): ?><input type="hidden" name="return" value="event"><?php endif; ?>

    <label class="form-label mb-1">Overall, how was the event? *</label>
    <div class="star-rating mb-3" role="radiogroup" aria-label="Rating from 1 to 5 stars">
        <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" name="overall_rating" id="overall_<?= $i ?>" value="<?= $i ?>" <?= $preselect === $i ? 'checked' : '' ?> required>
            <label for="overall_<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label>
        <?php endfor; ?>
    </div>

    <fieldset class="mb-3">
        <legend class="form-label small fw-semibold mb-1">How satisfied were you with… <span class="text-muted fw-normal">(optional, 1 = poor, 5 = excellent)</span></legend>
        <?php foreach ($aspects as $col => $label): ?>
        <div class="aspect-row">
            <span class="small"><?= e($label) ?></span>
            <div class="btn-group btn-group-sm" role="group" aria-label="<?= e($label) ?>">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <input type="radio" class="btn-check" name="<?= $col ?>" id="<?= $col ?>_<?= $i ?>" value="<?= $i ?>">
                    <label class="btn btn-outline-primary" for="<?= $col ?>_<?= $i ?>"><?= $i ?></label>
                <?php endfor; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="mb-3">
        <legend class="form-label small fw-semibold mb-1">Did the event meet your expectations? *</legend>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (EvaluationService::EXPECTATION_LABELS as $key => $label): ?>
                <input type="radio" class="btn-check" name="expectations_met" id="exp_<?= $key ?>" value="<?= $key ?>" required>
                <label class="btn btn-sm btn-outline-primary" for="exp_<?= $key ?>"><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="mb-3">
        <legend class="form-label small fw-semibold mb-1">Would you join this organizer's events again? *</legend>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (EvaluationService::JOIN_AGAIN_LABELS as $key => $label): ?>
                <input type="radio" class="btn-check" name="join_again" id="again_<?= $key ?>" value="<?= $key ?>" required>
                <label class="btn btn-sm btn-outline-primary" for="again_<?= $key ?>"><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="mb-3">
        <label class="form-label small fw-semibold" for="liked">What went well? <span class="text-muted fw-normal">(optional)</span></label>
        <textarea name="liked" id="liked" class="form-control" rows="2" maxlength="500" placeholder="e.g. Fast check-in and friendly marshals."></textarea>
    </div>

    <fieldset class="mb-3">
        <legend class="form-label small fw-semibold mb-1">What should be improved? <span class="text-muted fw-normal">(tap all that apply)</span></legend>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($tags as $key => $label): ?>
                <input type="checkbox" class="btn-check" name="improvement_tags[]" id="tag_<?= $key ?>" value="<?= $key ?>" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary rounded-pill" for="tag_<?= $key ?>"><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="mb-3">
        <label class="form-label small fw-semibold" for="suggestions">Comments or suggestions <span class="text-muted fw-normal">(optional)</span></label>
        <textarea name="suggestions" id="suggestions" class="form-control" rows="3" maxlength="1000" placeholder="e.g. Add more water stations after the 3 km mark."></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label small fw-semibold" for="reviewPhotos"><i class="bi bi-camera" aria-hidden="true"></i> Add photos <span class="text-muted fw-normal">(optional, up to <?= EvaluationService::REVIEW_PHOTO_MAX ?> JPG or PNG, 5 MB each)</span></label>
        <input type="file" name="photos[]" id="reviewPhotos" class="form-control" accept=".jpg,.jpeg,.png" multiple data-max-files="<?= EvaluationService::REVIEW_PHOTO_MAX ?>">
        <div class="review-photo-preview mt-2" id="reviewPhotoPreview" aria-live="polite"></div>
    </div>

    <p class="small text-muted mb-3"><i class="bi bi-globe2" aria-hidden="true"></i> Your review will be shown on the event page as <b><?= e($publicName) ?></b> with an "Attended" label. It cannot be edited after you submit it.</p>
    <button type="submit" class="btn btn-evora-primary">Submit Review</button>
</form>
