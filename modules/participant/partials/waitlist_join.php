<?php // Join-the-waitlist form (v39). Uses $event and optional $waitCategories (fun-run categories that are full). ?>
<form method="post" action="<?= url('waitlist-action') ?>" class="d-grid gap-2">
    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="join">
    <?php if (!empty($waitCategories)): ?>
        <select name="race_category_id" class="form-select form-select-sm" aria-label="Category to wait for" required>
            <?php foreach ($waitCategories as $wc): ?><option value="<?= (int) $wc['id'] ?>"><?= e($wc['name']) ?></option><?php endforeach; ?>
        </select>
    <?php endif; ?>
    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Join Waitlist</button>
    <span class="small text-muted">If a slot opens, it is held for you for <?= WaitlistService::OFFER_HOURS ?> hours and we notify you.</span>
</form>
