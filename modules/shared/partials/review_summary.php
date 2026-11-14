<?php
/**
 * Post-event review summary (v80): average stars, star bars, satisfaction / effectiveness / return
 * percentages, aspect averages and top areas for improvement. Expects $stats from
 * EvaluationService::reviewStats(); optional $summaryDetailed (aspects and improvement areas).
 */
$summaryDetailed = $summaryDetailed ?? true;
$expYes = EvaluationService::pct($stats['expectations'], 'yes');
$againYes = EvaluationService::pct($stats['join_again'], 'yes');
?>
<div class="review-summary row g-3 align-items-center">
    <div class="col-sm-4 col-lg-3 text-center">
        <div class="review-average"><?= number_format($stats['average'], 1) ?></div>
        <div class="rating-stars fs-5"><?= EvaluationService::stars($stats['average']) ?></div>
        <div class="small text-muted"><?= $stats['count'] ?> review<?= $stats['count'] === 1 ? '' : 's' ?></div>
    </div>
    <div class="col-sm-8 col-lg-4 small">
        <?php for ($i = 5; $i >= 1; $i--): $pctStar = $stats['count'] ? round(100 * $stats['distribution'][$i] / $stats['count']) : 0; ?>
            <div class="d-flex align-items-center gap-2">
                <span class="text-nowrap review-bar-label"><?= $i ?> ★</span>
                <div class="progress flex-grow-1 review-bar" role="img" aria-label="<?= $stats['distribution'][$i] ?> reviews with <?= $i ?> stars"><div class="progress-bar bg-warning" style="width:<?= $pctStar ?>%"></div></div>
                <span class="text-muted review-bar-count"><?= $stats['distribution'][$i] ?></span>
            </div>
        <?php endfor; ?>
    </div>
    <div class="col-lg-5">
        <div class="row g-2 text-center small">
            <div class="col-4"><div class="review-kpi"><b><?= $stats['satisfied_pct'] ?>%</b><span>satisfied (4–5★)</span></div></div>
            <div class="col-4"><div class="review-kpi"><b><?= $expYes !== null ? $expYes . '%' : '—' ?></b><span>expectations fully met</span></div></div>
            <div class="col-4"><div class="review-kpi"><b><?= $againYes !== null ? $againYes . '%' : '—' ?></b><span>would join again</span></div></div>
        </div>
    </div>
</div>
<?php if ($summaryDetailed && ($stats['aspects'] || $stats['tags'])): ?>
<div class="row g-3 mt-1">
    <?php if ($stats['aspects']): ?>
    <div class="col-md-6">
        <h3 class="small fw-semibold mb-2">Satisfaction by area</h3>
        <?php foreach ($stats['aspects'] as $a): ?>
            <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e($a['label']) ?></span><span><span class="rating-stars"><?= EvaluationService::stars($a['average']) ?></span> <?= number_format($a['average'], 1) ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($stats['tags']): ?>
    <div class="col-md-6">
        <h3 class="small fw-semibold mb-2">Most mentioned areas for improvement</h3>
        <?php foreach (array_slice($stats['tags'], 0, 6, true) as $tag => $times): ?>
            <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e(EvaluationService::IMPROVEMENT_TAGS[$tag][0] ?? $tag) ?></span><span class="text-muted"><?= $times ?> mention<?= $times === 1 ? '' : 's' ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
