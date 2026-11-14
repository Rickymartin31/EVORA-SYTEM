<?php
/**
 * List of post-event reviews (v80). Expects $reviews from EvaluationService::publicReviews().
 * Options: $reviewFullNames (organizer view: real names), $reviewShowEvent (event name per review),
 * $reviewReplyForms (organizer may reply).
 */
$reviewFullNames = $reviewFullNames ?? false;
$reviewShowEvent = $reviewShowEvent ?? false;
$reviewReplyForms = $reviewReplyForms ?? false;
?>
<ul class="list-unstyled mb-0 review-list">
    <?php foreach ($reviews as $rv): ?>
    <li class="review-item">
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div>
                <strong class="small"><?= e($reviewFullNames ? $rv['full_name'] : $rv['public_name']) ?></strong>
                <?php if ($rv['attended']): ?><span class="badge bg-success-subtle text-success-emphasis ms-1"><i class="bi bi-check2-circle" aria-hidden="true"></i> Attended</span><?php endif; ?>
                <?php if ($reviewShowEvent): ?><span class="small text-muted"> · <?= e($rv['event_name']) ?></span><?php endif; ?>
            </div>
            <span class="text-muted small"><?= date('M d, Y', strtotime($rv['created_at'])) ?></span>
        </div>
        <div class="rating-stars"><?= EvaluationService::stars($rv['overall_rating']) ?></div>
        <?php if ($rv['expectations_met'] || $rv['join_again']): ?>
            <div class="small text-muted">
                <?= $rv['expectations_met'] ? 'Expectations met: ' . e(EvaluationService::EXPECTATION_LABELS[$rv['expectations_met']]) : '' ?>
                <?= $rv['expectations_met'] && $rv['join_again'] ? ' · ' : '' ?>
                <?= $rv['join_again'] ? 'Would join again: ' . e(EvaluationService::JOIN_AGAIN_LABELS[$rv['join_again']]) : '' ?>
            </div>
        <?php endif; ?>
        <?php if ($rv['liked']): ?><p class="small mb-1 mt-1"><span class="fw-semibold text-success">Liked:</span> <?= nl2br(e($rv['liked'])) ?></p><?php endif; ?>
        <?php if ($rv['suggestions']): ?><p class="small mb-1 mt-1"><?= nl2br(e($rv['suggestions'])) ?></p><?php endif; ?>
        <?php if (!empty($rv['photos'])): ?>
            <div class="review-photos mt-2">
                <?php foreach ($rv['photos'] as $k => $photo): ?>
                    <a href="<?= uploadUrl($photo) ?>" target="_blank" rel="noopener" title="Open photo <?= $k + 1 ?>"><img src="<?= uploadUrl($photo) ?>" alt="Photo <?= $k + 1 ?> from <?= e($rv['public_name']) ?>'s review" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($rv['improvement_tags']): ?>
            <div class="mt-1"><?php foreach (explode(',', $rv['improvement_tags']) as $t): ?><span class="review-tag"><?= e(EvaluationService::IMPROVEMENT_TAGS[$t][0] ?? $t) ?></span> <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($rv['organizer_reply']): ?>
            <div class="review-reply mt-2">
                <div class="small fw-semibold"><i class="bi bi-reply" aria-hidden="true"></i> Organizer's reply <span class="text-muted fw-normal">· <?= date('M d, Y', strtotime($rv['organizer_replied_at'])) ?></span></div>
                <div class="small"><?= nl2br(e($rv['organizer_reply'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($reviewReplyForms): ?>
            <details class="mt-2"<?= $rv['organizer_reply'] ? '' : ' open' ?>>
                <summary class="small text-primary" style="cursor:pointer"><?= $rv['organizer_reply'] ? 'Edit reply' : 'Reply publicly' ?></summary>
                <form method="post" action="<?= url('organizer-review-reply') ?>" class="mt-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="feedback_id" value="<?= (int) $rv['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $rv['event_id'] ?>">
                    <textarea name="reply" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" aria-label="Reply to <?= e($rv['public_name']) ?>" placeholder="e.g. Thank you! We will add more water stations next time."><?= e((string) $rv['organizer_reply']) ?></textarea>
                    <button type="submit" class="btn btn-sm btn-evora-primary">Save reply</button>
                    <?php if ($rv['organizer_reply']): ?><span class="small text-muted ms-2">Clear the text and save to remove the reply.</span><?php endif; ?>
                </form>
            </details>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
