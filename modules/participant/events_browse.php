<?php
$db = Database::connection();
$pageMeta = ['title' => 'Browse Events — EVORA', 'description' => 'Open fun runs, tournaments, seminars and competitions. Register and pay online in minutes and get your QR e-ticket by email.'];

$categoryFilter = (int) ($_GET['category'] ?? 0);
$search = trim($_GET['q'] ?? '');
$verifiedOnly = !empty($_GET['verified']);
// v80: "Past Events" tab — completed events of the last PAST_MONTHS months with their reviews.
const PAST_MONTHS = 6;
$pastTab = ($_GET['tab'] ?? '') === 'past';

$sql = "SELECT e.*, c.name AS category_name, o.organization_name, " . OrganizerBadgeService::sqlVerified('o') . " AS organizer_verified, " . EvaluationService::ORGANIZER_RATING_COLUMNS . ", " . EVENT_CARD_COLUMNS . ",
            (SELECT ROUND(AVG(ef.overall_rating), 1) FROM event_feedback ef WHERE ef.event_id = e.id) AS event_rating,
            (SELECT COUNT(*) FROM event_feedback ef WHERE ef.event_id = e.id) AS event_reviews
        FROM events e
        JOIN event_categories c ON c.id = e.category_id
        JOIN organizers o ON o.id = e.organizer_id
        WHERE " . ($pastTab
            ? "e.status IN ('completed','archived') AND e.event_date < CURDATE() AND e.event_date >= CURDATE() - INTERVAL " . PAST_MONTHS . " MONTH"
            : "e.status IN ('open','ongoing') AND e.event_date >= CURDATE()"); // events whose date has passed are not listed as upcoming
$params = [];
if ($categoryFilter > 0) {
    $sql .= ' AND e.category_id = :cat';
    $params['cat'] = $categoryFilter;
}
if ($search !== '') {
    // name, venue or organizer (separate placeholders: native prepares cannot reuse a name)
    $sql .= ' AND (e.name LIKE :q1 OR e.venue LIKE :q2 OR o.organization_name LIKE :q3)';
    $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
}
if ($verifiedOnly) {
    $sql .= ' AND ' . OrganizerBadgeService::sqlVerified('o');
}
$sql .= $pastTab ? ' ORDER BY e.event_date DESC' : ' ORDER BY e.event_date ASC';
$tabLink = fn (bool $past): string => url('events', array_filter(['tab' => $past ? 'past' : null, 'q' => $search ?: null, 'category' => $categoryFilter ?: null, 'verified' => $verifiedOnly ? 1 : null]));

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$categories = $db->query('SELECT * FROM event_categories ORDER BY name')->fetchAll();

$pageDescription = 'Find an event and register in a few steps.';
$pageTitle = 'Browse Events';
$isGuest = !Auth::check();
$activeRoute = 'events';

if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_head.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
}
?>
<ul class="nav nav-pills browse-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link <?= $pastTab ? '' : 'active' ?>" href="<?= $tabLink(false) ?>"<?= $pastTab ? '' : ' aria-current="page"' ?>><i class="bi bi-calendar-event" aria-hidden="true"></i> Upcoming</a></li>
    <li class="nav-item"><a class="nav-link <?= $pastTab ? 'active' : '' ?>" href="<?= $tabLink(true) ?>"<?= $pastTab ? ' aria-current="page"' : '' ?>><i class="bi bi-clock-history" aria-hidden="true"></i> Past Events &amp; Reviews</a></li>
</ul>
<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('events') ?>" class="row g-2" role="search">
        <input type="hidden" name="route" value="events"><?php /* a GET form drops the query string of its action, so the route must be a field */ ?>
        <?php if ($pastTab): ?><input type="hidden" name="tab" value="past"><?php endif; ?>
        <div class="col-md-6">
            <input type="search" name="q" class="form-control" placeholder="Search by event, venue or organizer..." value="<?= e($search) ?>" aria-label="Search events" maxlength="100">
        </div>
        <div class="col-md-4">
            <select name="category" class="form-select">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $categoryFilter == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-evora-primary w-100" type="submit">Search</button></div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="verified" value="1" id="verifiedOnly" <?= $verifiedOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label small" for="verifiedOnly">Verified organizers only<?= verifiedBadge(true) ?></label>
            </div>
        </div>
    </form>
</div>

<div class="row g-3">
    <?php if ($pastTab): ?>
        <div class="col-12"><p class="small text-muted mb-0">Events completed in the last <?= PAST_MONTHS ?> months. Open one to read what participants who attended said about it.</p></div>
    <?php endif; ?>
    <?php if (empty($events)): ?>
        <div class="col-12"><div class="card-evora"><?= ($search !== '' || $categoryFilter > 0 || $verifiedOnly)
            ? emptyState('bi-search', $pastTab ? 'No past events match your search.' : 'No upcoming events match your search.', 'Show all events', url('events', $pastTab ? ['tab' => 'past'] : []))
            : ($pastTab
                ? emptyState('bi-clock-history', 'No completed events in the last ' . PAST_MONTHS . ' months yet.')
                : emptyState('bi-calendar-x', 'No open events right now. New events appear here as soon as organizers open registration — check back soon.')) ?></div></div>
    <?php endif; ?>
    <?php foreach ($events as $ev): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card-evora h-100">
                <?php if ($ev['banner_path']): ?>
                    <img src="<?= uploadUrl($ev['banner_path']) ?>" class="w-100" style="height:160px;object-fit:cover;border-radius:.75rem .75rem 0 0;">
                <?php else: ?>
                    <div class="w-100 d-flex align-items-center justify-content-center text-white event-cover-placeholder">
                        <i class="bi bi-calendar-event fs-1"></i>
                    </div>
                <?php endif; ?>
                <div class="p-3">
                    <?php if ($pastTab): ?>
                        <div class="mb-2"><span class="badge bg-secondary-subtle text-secondary-emphasis"><?= e($ev['category_name']) ?></span> <span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check2-circle" aria-hidden="true"></i> Completed</span></div>
                    <?php else: ?>
                        <?= eventCardBadges($ev) ?>
                    <?php endif; ?>
                    <h6 class="fw-semibold"><?= e($ev['name']) ?></h6>
                    <p class="small text-muted mb-1"><i class="bi bi-calendar3"></i> <?= date('D, M d, Y', strtotime($ev['event_date'])) ?><?= $ev['event_time'] ? ' · ' . date('g:i A', strtotime($ev['event_time'])) : '' ?></p>
                    <p class="small text-muted mb-1"><i class="bi bi-geo-alt"></i> <?= e($ev['venue']) ?></p>
                    <p class="small text-muted mb-1"><i class="bi bi-building"></i> <?= e($ev['organization_name']) ?><?= verifiedBadge((bool) $ev['organizer_verified']) ?><?= EvaluationService::ratingBadge($ev['organizer_rating'], $ev['organizer_reviews'], url('organizer-reviews', ['id' => $ev['organizer_id']])) ?></p>
                    <?php if ($pastTab): ?>
                        <p class="small mb-3"><?= (int) $ev['event_reviews']
                            ? '<span class="rating-stars">' . EvaluationService::stars($ev['event_rating']) . '</span> <b>' . number_format((float) $ev['event_rating'], 1) . '</b> <span class="text-muted">· ' . (int) $ev['event_reviews'] . ' review' . ((int) $ev['event_reviews'] === 1 ? '' : 's') . '</span>'
                            : '<span class="text-muted">No reviews yet</span>' ?></p>
                        <a href="<?= url('event-view', ['id' => $ev['id']]) ?>#reviews" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-star" aria-hidden="true"></i> See Reviews</a>
                    <?php else: ?>
                        <?= eventCardSlots($ev) ?: '<div class="mb-3"></div>' ?>
                        <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="btn btn-evora-primary btn-sm w-100">View Details</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php
if ($isGuest) {
    include EVORA_ROOT . '/modules/shared/partials/public_foot.php';
} else {
    include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php';
}
?>
