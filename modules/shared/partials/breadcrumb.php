<?php
/**
 * Expects $breadcrumbs to be an array of ['label' => string, 'route' => ?string, 'params' => array]
 * set by the including page before this partial is included. The last item is rendered
 * as the current (non-linked) page.
 */
$breadcrumbs = $breadcrumbs ?? [];
if (empty($breadcrumbs)) {
    return;
}
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0 small">
        <?php $last = array_key_last($breadcrumbs); ?>
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i === $last || empty($crumb['route'])): ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e($crumb['label']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= url($crumb['route'], $crumb['params'] ?? []) ?>"><?= e($crumb['label']) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
