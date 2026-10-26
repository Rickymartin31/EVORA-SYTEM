<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

$eventId = (int) ($_GET['event_id'] ?? 0);

if ($eventId === 0) {
    if ($role === 'organizer') {
        $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
        $orgStmt->execute(['uid' => $userId]);
        $organizer = $orgStmt->fetch();
        $eventsStmt = $db->prepare('SELECT id, name, event_date FROM events WHERE organizer_id = :oid ORDER BY event_date DESC');
        $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
    } else {
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_staff es ON es.event_id = e.id
             JOIN staff_permissions sp ON sp.event_staff_id = es.id
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_claims'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a fun run to release race kits.';
    $pageTitle = 'Kit Claim Search';
    $activeRoute = 'kit-claim-search';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Kit Claim Search']]); ?>
    <?php if ($role === 'staff'): ?>
    <a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
    <?php endif; ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-search', 'No events available to you for kit claim verification.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('kit-claim-search', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
                    <div class="fw-semibold"><?= e($ev['name']) ?></div>
                    <div class="small text-muted"><?= date('M d, Y', strtotime($ev['event_date'])) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
    <?php
    exit;
}

if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventStmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
    $eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
} else {
    $eventStmt = $db->prepare(
        "SELECT e.* FROM events e
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_claims'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have kit-claiming access to it.');
    redirect('kit-claim-search');
}

$itemsStmt = $db->prepare('SELECT * FROM event_items WHERE event_id = :eid ORDER BY item_name, id');
$itemsStmt->execute(['eid' => $eventId]);
$items = $itemsStmt->fetchAll();
// v73: sizes of one item (same name) share one column; a participant claims only one of them
$itemGroups = [];
foreach ($items as $it) {
    $itemGroups[$it['item_name']][] = $it;
}

$search = trim($_GET['search'] ?? '');
$results = [];
if ($search !== '') {
    $regStmt = $db->prepare(
        "SELECT r.id AS registration_id, r.bib_number, r.distance_category, r.status, u.full_name,
            (SELECT status FROM payments WHERE registration_id = r.id ORDER BY id DESC LIMIT 1) AS payment_status,
            COALESCE(rc.fee, e.fee) AS fee
         FROM registrations r
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         JOIN events e ON e.id = r.event_id
         LEFT JOIN race_categories rc ON rc.id = r.race_category_id
         WHERE r.event_id = :eid AND (u.full_name LIKE :q1 OR r.bib_number LIKE :q2)
         ORDER BY u.full_name LIMIT 25"
    );
    $regStmt->execute(['eid' => $eventId, 'q1' => '%' . $search . '%', 'q2' => '%' . $search . '%']);
    $matches = $regStmt->fetchAll();

    $claimsStmt = $db->prepare(
        'SELECT ic.event_item_id, ic.registration_id FROM item_claims ic
         JOIN event_items ei ON ei.id = ic.event_item_id WHERE ei.event_id = :eid'
    );
    $claimsStmt->execute(['eid' => $eventId]);
    $claimedPairs = [];
    foreach ($claimsStmt->fetchAll() as $c) {
        $claimedPairs[$c['registration_id'] . ':' . $c['event_item_id']] = true;
    }

    foreach ($matches as $m) {
        $m['payment_ok'] = (float) $m['fee'] <= 0 || $m['payment_status'] === 'approved';
        $m['claims'] = [];
        foreach ($items as $item) {
            $m['claims'][$item['id']] = isset($claimedPairs[$m['registration_id'] . ':' . $item['id']]);
        }
        $results[] = $m;
    }
}

$pageDescription = 'Find a runner by name or bib and release their race kit.';
$pageTitle = 'Kit Claim Search';
$activeRoute = 'kit-claim-search';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb($role === 'organizer' ? [
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Kit Claim Search'],
] : [
    ['label' => $event['name']],
    ['label' => 'Kit Claim Search'],
]); ?>
<?php if ($role === 'staff'): ?>
<a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
<?php endif; ?>

<div class="card-evora p-4 mb-3">
    <form method="get" action="<?= url('kit-claim-search') ?>" class="d-flex gap-2">
        <input type="hidden" name="route" value="kit-claim-search">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <input type="text" name="search" class="form-control" placeholder="Search participant by name or bib number..." value="<?= e($search) ?>" autofocus>
        <button type="submit" class="btn btn-evora-primary"><i class="bi bi-search"></i> Search</button>
    </form>
    <div class="form-text mt-2">Use this when a QR code can't be scanned. QR-based claiming is still available on the <a href="<?= url('staff-claims') ?>">Item Releasing</a> page.</div>
</div>

<?php if ($search !== ''): ?>
<div class="card-evora p-4">
    <?php if (empty($results)): ?>
        <?= emptyState('bi-person-x', 'No matching participants found.') ?>
    <?php elseif (empty($items)): ?>
        <?= emptyState('bi-box-seam', 'No event items are defined for this event yet. The organizer adds kits and giveaways under Items.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Participant</th><th>Bib</th><th>Payment</th>
                <?php foreach ($itemGroups as $groupName => $groupItems): ?><th><?= e($groupName) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= e($r['full_name']) ?><?= $r['status'] !== 'approved' ? ' <span class="badge bg-secondary">' . e(ucfirst($r['status'])) . '</span>' : '' ?></td>
                    <td><?= e($r['bib_number'] ?: '—') ?></td>
                    <td><?= $r['payment_ok'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-danger">Unpaid</span>' ?></td>
                    <?php foreach ($itemGroups as $groupName => $groupItems): ?>
                        <?php
                        $claimedItem = null;
                        foreach ($groupItems as $gi) { if ($r['claims'][$gi['id']]) { $claimedItem = $gi; break; } }
                        $available = array_values(array_filter($groupItems, fn($gi) => $gi['claimed_quantity'] < $gi['total_quantity']));
                        ?>
                        <td>
                            <?php if (!$r['payment_ok'] || $r['status'] !== 'approved'): ?>
                                <span class="text-muted small">—</span>
                            <?php elseif ($claimedItem): ?>
                                <span class="badge bg-success">Claimed<?= $claimedItem['variant_label'] && count($groupItems) > 1 ? ' (' . e($claimedItem['variant_label']) . ')' : '' ?></span>
                            <?php elseif (!$available): ?>
                                <span class="badge bg-secondary">Out of stock</span>
                            <?php else: ?>
                                <form method="post" action="<?= url('kit-claim-search-action') ?>" class="d-flex gap-1">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="registration_id" value="<?= $r['registration_id'] ?>">
                                    <input type="hidden" name="search" value="<?= e($search) ?>">
                                    <?php if (count($groupItems) > 1): ?>
                                        <select name="item_id" class="form-select form-select-sm" aria-label="Size" style="width:auto">
                                            <?php foreach ($available as $gi): ?><option value="<?= $gi['id'] ?>"><?= e($gi['variant_label'] ?: $gi['item_name']) ?> (<?= $gi['total_quantity'] - $gi['claimed_quantity'] ?> left)</option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="item_id" value="<?= $available[0]['id'] ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Release</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
