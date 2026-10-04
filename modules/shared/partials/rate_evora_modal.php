<?php
/**
 * "Rate EVORA" dialog opened by the ⭐ beside the notification bell
 * (participants, organizers, staff — admins only see analytics).
 * Expects $rateEvora from layout_top.php. Rendered outside the top bar so the
 * modal is not trapped inside its backdrop-filter stacking context.
 */
if (empty($rateEvora)) {
    return;
}
$rateRole = (string) Auth::role();
?>
<div class="modal fade" id="rateEvoraModal" tabindex="-1" aria-labelledby="rateEvoraTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-semibold" id="rateEvoraTitle">How was your experience using EVORA?</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <?php if ($rateEvora['evaluation']): $myEval = $rateEvora['evaluation']; $myRating = $myEval['overall_rating'] ?? null; ?>
                <div class="modal-body">
                    <p class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Thank you — you already rated EVORA<?= $myEval['created_at'] ? ' on ' . date('M d, Y', strtotime($myEval['created_at'])) : '' ?>.</p>
                    <?php if ($myRating): ?><div class="fs-4 rating-stars"><?= EvaluationService::stars($myRating) ?> <span class="text-dark fs-6"><?= (int) $myRating ?>/5</span></div><?php endif; ?>
                    <?php if ($myEval['comment']): ?><p class="small text-muted mt-2 mb-0">"<?= e($myEval['comment']) ?>"</p><?php endif; ?>
                    <p class="small text-muted mt-3 mb-0">Ratings cannot be changed once submitted.</p>
                </div>
            <?php elseif ($rateEvora['ineligible']): ?>
                <div class="modal-body">
                    <p class="mb-0 small"><i class="bi bi-info-circle text-primary me-1"></i> <?= e($rateEvora['ineligible']) ?></p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= url('evaluation-action') ?>">
                    <div class="modal-body">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="do" value="platform_rate">
                        <label class="form-label small mb-1">Overall rating *</label>
                        <div class="star-rating mb-3" role="radiogroup" aria-label="Rating from 1 to 5 stars">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="rating" id="rate_evora_<?= $i ?>" value="<?= $i ?>" required>
                                <label for="rate_evora_<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</label>
                            <?php endfor; ?>
                        </div>
                        <?php foreach (EvaluationService::CATEGORY_QUESTIONS[$rateRole] ?? [] as $column => $question): ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2">
                                <span class="small"><?= e($question) ?> <span class="text-muted">(optional)</span></span>
                                <div class="star-rating star-rating-sm" role="radiogroup" aria-label="<?= e($question) ?>">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <input type="radio" name="categories[<?= e($column) ?>]" id="rate_<?= e($column) ?>_<?= $i ?>" value="<?= $i ?>">
                                        <label for="rate_<?= e($column) ?>_<?= $i ?>" title="<?= $i ?>">★</label>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <label class="form-label small mt-2">Comment (optional)</label>
                        <textarea name="comment" class="form-control form-control-sm" rows="3" maxlength="1000" placeholder="What do you like, or what should we improve?"></textarea>
                    </div>
                    <div class="modal-footer">
                        <a href="<?= url('evaluate') ?>" class="small me-auto">Detailed ISO survey</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-evora-primary">Submit</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if (!empty($_GET['rate_evora'])): ?>
<script>
// Opened from the "Share your experience using EVORA." notification.
document.addEventListener('DOMContentLoaded', function () {
    if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(document.getElementById('rateEvoraModal')).show(); }
});
</script>
<?php endif; ?>
