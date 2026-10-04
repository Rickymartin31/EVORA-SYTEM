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

$items = $db->prepare('SELECT * FROM event_items WHERE event_id = :eid ORDER BY id DESC');
$items->execute(['eid' => $eventId]);
$itemsList = $items->fetchAll();

$editItem = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $db->prepare('SELECT * FROM event_items WHERE id = :id AND event_id = :eid');
    $editStmt->execute(['id' => $editId, 'eid' => $eventId]);
    $editItem = $editStmt->fetch() ?: null;
}

$itemTypeLabels = [
    'kit' => 'Race Kit',
    'shirt' => 'Shirt',
    'bib' => 'Bib',
    'medal' => 'Medal',
    'other' => 'Other',
];

$pageDescription = 'Track the items and equipment prepared for this event.';
$pageTitle = 'Event Items';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Event Items'],
]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Items for This Event</h6>
            <?php if (empty($itemsList)): ?>
                <?= emptyState('bi-box-seam', 'No items defined yet (e.g. race kits, shirts, certificates).') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-datatable">
                    <thead><tr><th>Item</th><th>Type</th><th>Total Qty</th><th>Claimed</th><th>Remaining</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($itemsList as $item): ?>
                        <?php $remaining = max(0, $item['total_quantity'] - $item['claimed_quantity']); ?>
                        <tr>
                            <td><?= e($item['item_name']) ?><?= $item['variant_label'] ? ' <span class="text-muted small">(' . e($item['variant_label']) . ')</span>' : '' ?></td>
                            <td><span class="badge bg-secondary"><?= e($itemTypeLabels[$item['item_type']] ?? 'Other') ?></span></td>
                            <td><?= $item['total_quantity'] ?></td>
                            <td><?= $item['claimed_quantity'] ?></td>
                            <td>
                                <?= $remaining ?>
                                <?php if ($item['low_stock_threshold'] > 0 && $remaining <= $item['low_stock_threshold']): ?>
                                    <span class="badge bg-danger ms-1">Low Stock</span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= url('organizer-items', ['event_id' => $eventId, 'edit' => $item['id']]) ?>" class="btn btn-sm btn-outline-secondary">Edit</a></td>
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
            <h6 class="fw-semibold mb-3"><?= $editItem ? 'Edit Item' : 'Add Item' ?></h6>
            <form method="post" action="<?= url('organizer-item-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="item_action" value="<?= $editItem ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="item_id" value="<?= $editItem['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small">Item Name *</label>
                    <input type="text" name="item_name" class="form-control form-control-sm" placeholder="e.g. Race Kit, Event Shirt, Certificate" value="<?= e($editItem['item_name'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Type *</label>
                    <select name="item_type" class="form-select form-select-sm">
                        <?php foreach ($itemTypeLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editItem['item_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Variant / Size</label>
                    <input type="text" name="variant_label" class="form-control form-control-sm" placeholder="e.g. M, L, XL (optional)" value="<?= e($editItem['variant_label'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Total Quantity *</label>
                    <input type="number" name="total_quantity" min="1" class="form-control form-control-sm" value="<?= e($editItem['total_quantity'] ?? '') ?>" required>
                    <?php if ($editItem && (int) $editItem['claimed_quantity'] > 0): ?>
                        <div class="form-text"><?= (int) $editItem['claimed_quantity'] ?> already claimed — total quantity can't be set below this.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Low Stock Alert Threshold</label>
                    <input type="number" name="low_stock_threshold" min="0" value="<?= e($editItem['low_stock_threshold'] ?? 0) ?>" class="form-control form-control-sm">
                    <div class="form-text">Flag as low stock when remaining qty drops to this number or below.</div>
                </div>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editItem ? 'Update Item' : 'Add Item' ?></button>
                <?php if ($editItem): ?>
                    <a href="<?= url('organizer-items', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
