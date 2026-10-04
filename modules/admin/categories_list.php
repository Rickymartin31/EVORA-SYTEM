<?php
Auth::requireRole('admin');
$db = Database::connection();

$categories = $db->query(
    'SELECT c.*, (SELECT COUNT(*) FROM events e WHERE e.category_id = c.id) AS event_count
     FROM event_categories c ORDER BY c.name'
)->fetchAll();

$pageDescription = 'Configure the event categories organizers can choose from.';
$pageTitle = 'Event Categories';
$activeRoute = 'admin-categories';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Event Categories</h6>
            <?php if (empty($categories)): ?>
                <?= emptyState('bi-tags', 'No categories yet.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th>Name</th><th>Slug</th><th>Events Using It</th><th style="width:1%;"></th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td>
                                <form method="post" action="<?= url('admin-category-save') ?>" class="d-flex gap-1">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="category_action" value="update">
                                    <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                    <input type="text" name="name" value="<?= e($c['name']) ?>" class="form-control form-control-sm">
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Save</button>
                                </form>
                            </td>
                            <td class="small text-muted"><?= e($c['slug']) ?></td>
                            <td><?= (int) $c['event_count'] ?></td>
                            <td>
                                <form method="post" action="<?= url('admin-category-save') ?>" data-confirm="Delete the &quot;<?= e($c['name']) ?>&quot; category? This is only possible if no events use it.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="category_action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" <?= (int) $c['event_count'] > 0 ? 'disabled title="In use by ' . (int) $c['event_count'] . ' event(s) — cannot delete"' : '' ?>>Delete</button>
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
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Add Category</h6>
            <form method="post" action="<?= url('admin-category-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="category_action" value="create">
                <div class="mb-2">
                    <label class="form-label small">Category Name *</label>
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Cultural Show" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Slug (optional)</label>
                    <input type="text" name="slug" class="form-control form-control-sm" placeholder="auto-generated from name if left blank">
                    <div class="form-text">Used internally; lowercase letters, numbers, and hyphens only.</div>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100">Add Category</button>
            </form>
            <div class="alert alert-light border small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>The organizer's "Choose Event Type" wizard currently only offers
                Fun Run, Sports Tournament, and Seminar/Workshop. New categories added here are usable elsewhere in the
                system (reports, filters) but won't appear in that wizard unless it's reopened to more types.
            </div>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
