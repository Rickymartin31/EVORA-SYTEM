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

EventAccess::requireEventType($db, $eventId, 'fun-run', 'organizer-events');

$categoriesStmt = $db->prepare(
    "SELECT rc.*,
        (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status NOT IN ('rejected', 'cancelled')) AS registered_count,
        (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved_count
     FROM race_categories rc WHERE rc.event_id = :eid ORDER BY rc.fee, rc.name"
);
$categoriesStmt->execute(['eid' => $eventId]);
$categories = $categoriesStmt->fetchAll();

$editCategory = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $db->prepare('SELECT * FROM race_categories WHERE id = :id AND event_id = :eid');
    $editStmt->execute(['id' => $editId, 'eid' => $eventId]);
    $editCategory = $editStmt->fetch() ?: null;
}

$pageDescription = 'Set each distance with its own fee and slot cap — runners pick one when they register.';
$pageTitle = 'Race Categories';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Race Categories'],
]); ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Race Categories</h6>
            <?php if (empty($categories)): ?>
                <?= emptyState('bi-list-ol', 'No race categories yet. Add your first distance (e.g. 3KM, 5KM, 10KM) with its own fee and slot cap using the form.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th scope="col">Category</th><th scope="col" class="num">Fee</th><th scope="col">Slots</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($c['name']) ?></td>
                            <td class="num money"><?= currency($c['fee']) ?></td>
                            <td>
                                <span class="fw-semibold"><?= (int) $c['approved_count'] ?> / <?= (int) $c['max_participants'] ?></span> <span class="small text-muted">approved · <?= (int) $c['registered_count'] ?> registered</span>
                                <?php if ((int) $c['max_participants'] > 0 && (int) $c['approved_count'] >= (int) $c['max_participants']): ?>
                                    <span class="badge bg-warning ms-1">Full</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a href="<?= url('organizer-race-categories', ['event_id' => $eventId, 'edit' => $c['id']]) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Edit <?= e($c['name']) ?>">Edit</a>
                                <form method="post" action="<?= url('organizer-race-category-save') ?>" class="d-inline" data-confirm="Existing registrations keep their recorded distance but lose the category link." data-confirm-title="Remove this race category?" data-confirm-button="Remove Category" data-confirm-variant="danger">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="race_category_action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="race_category_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove <?= e($c['name']) ?>">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3"><?= $editCategory ? 'Edit Category' : 'Add Category' ?></h6>
            <form method="post" action="<?= url('organizer-race-category-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="race_category_action" value="<?= $editCategory ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editCategory): ?>
                    <input type="hidden" name="race_category_id" value="<?= $editCategory['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small">Name *</label>
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. 5KM" value="<?= e($editCategory['name'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Fee *</label>
                    <input type="number" step="0.01" min="0" name="fee" class="form-control form-control-sm" value="<?= e($editCategory['fee'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Max Participants *</label>
                    <input type="number" min="1" name="max_participants" class="form-control form-control-sm" value="<?= e($editCategory['max_participants'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editCategory ? 'Update Category' : 'Add Category' ?></button>
                <?php if ($editCategory): ?>
                    <a href="<?= url('organizer-race-categories', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
