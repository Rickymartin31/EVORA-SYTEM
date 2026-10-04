<?php
$db = Database::connection();
$pageMeta = ['title' => 'Browse Events — EVORA', 'description' => 'Open fun runs, tournaments, seminars and competitions. Register and pay online in minutes and get your QR e-ticket by email.'];

$categoryFilter = (int) ($_GET['category'] ?? 0);
$search = trim($_GET['q'] ?? '');

$sql = "SELECT e.*, c.name AS category_name, o.organization_name, " . EVENT_CARD_COLUMNS . "
        FROM events e
        JOIN event_categories c ON c.id = e.category_id
        JOIN organizers o ON o.id = e.organizer_id
        WHERE e.status IN ('open','ongoing')
          AND e.event_date >= CURDATE()"; // events whose date has passed are no longer listed
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
$sql .= ' ORDER BY e.event_date ASC';

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
<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('events') ?>" class="row g-2" role="search">
        <input type="hidden" name="route" value="events"><?php /* a GET form drops the query string of its action, so the route must be a field */ ?>
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
    </form>
</div>

<div class="row g-3">
    <?php if (empty($events)): ?>
        <div class="col-12"><div class="card-evora"><?= ($search !== '' || $categoryFilter > 0)
            ? emptyState('bi-search', 'No upcoming events match your search.', 'Show all events', url('events'))
            : emptyState('bi-calendar-x', 'No open events right now. New events appear here as soon as organizers open registration — check back soon.') ?></div></div>
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
                    <?= eventCardBadges($ev) ?>
                    <h6 class="fw-semibold"><?= e($ev['name']) ?></h6>
                    <p class="small text-muted mb-1"><i class="bi bi-calendar3"></i> <?= date('D, M d, Y', strtotime($ev['event_date'])) ?><?= $ev['event_time'] ? ' · ' . date('g:i A', strtotime($ev['event_time'])) : '' ?></p>
                    <p class="small text-muted mb-1"><i class="bi bi-geo-alt"></i> <?= e($ev['venue']) ?></p>
                    <p class="small text-muted mb-1"><i class="bi bi-building"></i> <?= e($ev['organization_name']) ?></p>
                    <?= eventCardSlots($ev) ?: '<div class="mb-3"></div>' ?>
                    <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="btn btn-evora-primary btn-sm w-100">View Details</a>
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
