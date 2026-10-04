<?php
// Saved events (v39): events the participant bookmarked, with their registration state.
Auth::requireRole('participant');
$db = Database::connection();
$events = ParticipantToolsService::savedEvents($db, (int) Auth::id());

$pageTitle = 'Saved Events';
$pageDescription = 'Events you saved. We remind you two days before their registration closes.';
$activeRoute = 'saved-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([['label' => 'Saved Events']]); ?>
<section class="card-evora p-4" aria-labelledby="savedHeading">
    <h2 class="h6 fw-semibold mb-3" id="savedHeading">Saved events</h2>
    <?php if (!$events): ?>
        <?= emptyState('bi-bookmark', 'You have not saved any events yet. Open an event and click "Save".', 'Browse Events', url('events')) ?>
    <?php else: ?>
    <div class="list-group">
        <?php foreach ($events as $ev): ?>
            <?php
            $past = $ev['event_date'] < date('Y-m-d');
            $state = match (true) {
                $ev['my_status'] !== null => ['You are registered (' . $ev['my_status'] . ')', 'bg-success'],
                $past || in_array($ev['status'], ['completed', 'cancelled'], true) => ['Ended / not available', 'bg-secondary'],
                $ev['status'] === 'open' => ['Registration open' . ($ev['registration_deadline'] ? ' until ' . date('M d', strtotime($ev['registration_deadline'])) : ''), 'bg-primary'],
                default => ['Registration not open', 'bg-secondary'],
            };
            ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="fw-semibold"><?= e($ev['name']) ?></a>
                    <div class="small text-muted"><?= e($ev['category_name']) ?> · <?= date('M d, Y', strtotime($ev['event_date'])) ?> · <?= e($ev['venue']) ?></div>
                    <span class="badge <?= $state[1] ?> mt-1"><?= e($state[0]) ?></span>
                </div>
                <form method="post" action="<?= url('event-save-toggle') ?>" class="m-0">
                    <?= Csrf::field() ?><input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>"><input type="hidden" name="return" value="saved-events">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-bookmark-x me-1" aria-hidden="true"></i>Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
