<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

// Supports Sports, Fun Run, and Seminar events — same generic announcement
// logic underneath, so this checks membership in that set rather than a
// single category (EventAccess::requireEventType only compares one).
$allowedCategories = ['sports-tournament', 'fun-run', 'seminar-workshop'];
$categoryOk = false;
foreach ($allowedCategories as $cat) {
    if (EventAccess::isCorrectCategory($db, $eventId, $cat)) {
        $categoryOk = true;
        break;
    }
}
if (!$categoryOk) {
    flash('error', 'This tool is not available for this event type.');
    redirect('organizer-events');
}

$announcementsStmt = $db->prepare('SELECT * FROM event_announcements WHERE event_id = :id ORDER BY created_at DESC LIMIT 50');
$announcementsStmt->execute(['id' => $eventId]);
$announcements = $announcementsStmt->fetchAll();

$phaseLabels = [
    'before' => ['label' => 'Before Event', 'badge' => 'bg-info text-dark', 'icon' => 'bi-calendar-event'],
    'during' => ['label' => 'During Event', 'badge' => 'bg-danger', 'icon' => 'bi-broadcast'],
    'after' => ['label' => 'After Event', 'badge' => 'bg-success', 'icon' => 'bi-check-circle'],
];
$phaseExamples = [
    'before' => 'e.g. Race reminders, assembly instructions, parking information, weather advisory',
    'during' => 'e.g. Route changes, emergency announcements, race delays',
    'after' => 'e.g. Results released, certificates available, photo announcements',
];

$pageTitle = 'Communication Center';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Communication Center'],
]); ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Send Announcement</h6>
            <form method="post" action="<?= url('organizer-announcement-send') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="return_to" value="communication_center">
                <div class="mb-2">
                    <label class="form-label small">Phase</label>
                    <select name="phase" id="phaseSelect" class="form-select form-select-sm">
                        <option value="">General</option>
                        <?php foreach ($phaseLabels as $key => $info): ?>
                            <option value="<?= $key ?>"><?= e($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="phaseHint">Optional — tags the announcement for organization below.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Message *</label>
                    <textarea name="message" class="form-control form-control-sm" rows="4" required placeholder="e.g. Reminder: race starts 6:00 AM, please arrive by 5:15 AM."></textarea>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><i class="bi bi-send"></i> Send to Participants &amp; Staff</button>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Announcement History</h6>
            <?php if (empty($announcements)): ?>
                <?= emptyState('bi-megaphone', 'No announcements sent yet.') ?>
            <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($announcements as $a): ?>
                    <?php $p = $phaseLabels[$a['phase']] ?? null; ?>
                    <li class="border-bottom py-2">
                        <?php if ($p): ?><span class="badge <?= $p['badge'] ?> mb-1"><i class="bi <?= $p['icon'] ?>"></i> <?= e($p['label']) ?></span><br><?php endif; ?>
                        <p class="small mb-1"><?= nl2br(e($a['message'])) ?></p>
                        <span class="text-muted small"><?= date('M d, Y H:i', strtotime($a['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.getElementById('phaseSelect').addEventListener('change', function () {
    const examples = <?= json_encode($phaseExamples) ?>;
    document.getElementById('phaseHint').textContent = examples[this.value] || 'Optional — tags the announcement for organization below.';
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
