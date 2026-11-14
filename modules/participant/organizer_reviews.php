<?php
// Public: all post-event reviews of one organizer's events (v80), like a seller's reviews page.
$db = Database::connection();
$organizerId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT o.*, ' . OrganizerBadgeService::sqlVerified('o') . ' AS organizer_verified FROM organizers o WHERE o.id = :id AND o.status = "approved"');
$stmt->execute(['id' => $organizerId]);
$organizer = $stmt->fetch();
if (!$organizer) {
    renderErrorPage(404, 'Organizer not found', 'This organizer page does not exist or is no longer active.');
}
$stats = EvaluationService::reviewStats($db, 'organizer', $organizerId);
$reviews = EvaluationService::publicReviews($db, 'organizer', $organizerId, 100);
$completed = $db->prepare('SELECT COUNT(*) FROM events WHERE organizer_id = :o AND status IN ("completed", "archived")');
$completed->execute(['o' => $organizerId]);
$completed = (int) $completed->fetchColumn();

$pageMeta = ['title' => $organizer['organization_name'] . ' — Reviews — EVORA', 'description' => 'What participants say about events by ' . $organizer['organization_name'] . '.'];
$pageTitle = 'Organizer Reviews';
$pageDescription = 'What participants who attended said about this organizer\'s events.';
$isGuest = !Auth::check();
$activeRoute = 'events';
if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_head.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
}
?>
<a href="<?= url('events') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Events</a>
<section class="card-evora p-4 mb-3" aria-labelledby="orgHeading">
    <h1 class="h5 fw-semibold mb-1" id="orgHeading"><i class="bi bi-building" aria-hidden="true"></i> <?= e($organizer['organization_name']) ?><?= verifiedBadge((bool) $organizer['organizer_verified'], true) ?></h1>
    <p class="small text-muted mb-3"><?= $completed ?> completed event<?= $completed === 1 ? '' : 's' ?> on EVORA · reviews come only from participants who attended</p>
    <?php if ($stats['count']): ?>
        <?php include EVORA_ROOT . '/modules/shared/partials/review_summary.php'; ?>
    <?php else: ?>
        <?= emptyState('bi-star', 'No reviews yet.') ?>
    <?php endif; ?>
</section>
<?php if ($reviews): ?>
<section class="card-evora p-4" aria-labelledby="orgReviewsHeading">
    <h2 class="h6 fw-semibold mb-2" id="orgReviewsHeading">Reviews</h2>
    <?php $reviewShowEvent = true; include EVORA_ROOT . '/modules/shared/partials/review_list.php'; ?>
</section>
<?php endif; ?>
<?php
if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_foot.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
}
?>
