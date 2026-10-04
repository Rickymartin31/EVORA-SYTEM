<?php // Leave-the-waitlist form (v39). Uses $event. ?>
<form method="post" action="<?= url('waitlist-action') ?>" class="mt-2 d-grid" data-confirm="Leave the waitlist? You would lose your place in line." data-confirm-title="Leave waitlist?" data-confirm-button="Yes, leave">
    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="leave">
    <button class="btn btn-link btn-sm text-muted">Leave waitlist</button>
</form>
