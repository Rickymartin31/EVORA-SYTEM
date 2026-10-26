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

// v71: claiming schedule and reminders
$claimSlots = ClaimScheduleService::slots($db, $eventId);
$claimRecipients = count(array_unique(array_column(ClaimScheduleService::recipients($db, $eventId), 'user_id')));
$lastClaimReminder = ClaimScheduleService::lastReminder($db, $eventId);
$canRemind = ClaimScheduleService::upcoming($claimSlots) && $itemsList && $claimRecipients > 0 && in_array($event['status'], ClaimScheduleService::ACTIVE_EVENT_STATUSES, true);

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

        <div class="card-evora p-4 mt-3" id="claiming">
            <h6 class="fw-semibold mb-1"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Claiming Schedule</h6>
            <p class="small text-muted mb-3">Set when and where participants can claim their race kit and other items, usually a day or two before the event. Items can still be released outside these times, including on event day.</p>
            <?php if ($claimSlots): ?>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($claimSlots as $slot): ?>
                        <?php $slotPassed = !ClaimScheduleService::upcoming([$slot]); ?>
                        <li class="d-flex justify-content-between align-items-start gap-2 border-bottom py-2<?= $slotPassed ? ' opacity-50' : '' ?>">
                            <div>
                                <div class="fw-semibold"><?= e(ClaimScheduleService::slotWhen($slot)) ?><?= $slotPassed ? ' <span class="badge bg-secondary">Done</span>' : '' ?></div>
                                <div class="small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e($slot['location']) ?></div>
                                <?php if ($slot['note']): ?><div class="small text-muted"><?= e($slot['note']) ?></div><?php endif; ?>
                            </div>
                            <form method="post" action="<?= url('organizer-claim-schedule-save') ?>" data-confirm="Remove the claiming schedule on <?= e(ClaimScheduleService::slotWhen($slot)) ?>?" data-confirm-title="Remove schedule?" data-confirm-variant="danger" data-confirm-button="Remove">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <input type="hidden" name="schedule_action" value="delete">
                                <input type="hidden" name="schedule_id" value="<?= (int) $slot['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" aria-label="Remove schedule"><i class="bi bi-trash" aria-hidden="true"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small text-muted fst-italic mb-3">No claiming schedule yet.</p>
            <?php endif; ?>

            <?php if (count($claimSlots) < ClaimScheduleService::MAX_SLOTS): ?>
            <form method="post" action="<?= url('organizer-claim-schedule-save') ?>" class="row g-2 align-items-end">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <input type="hidden" name="schedule_action" value="add">
                <div class="col-sm-4">
                    <label class="form-label small">Date *</label>
                    <input type="date" name="claim_date" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>" max="<?= e($event['event_date']) ?>" required>
                </div>
                <div class="col-6 col-sm-4">
                    <label class="form-label small">From *</label>
                    <input type="time" name="start_time" class="form-control form-control-sm" value="09:00" required>
                </div>
                <div class="col-6 col-sm-4">
                    <label class="form-label small">To *</label>
                    <input type="time" name="end_time" class="form-control form-control-sm" value="17:00" required>
                </div>
                <div class="col-12">
                    <label class="form-label small">Location *</label>
                    <input type="text" name="location" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Tilang Ultras HQ, Pag-antayan, Cantilan" value="<?= e($claimSlots ? end($claimSlots)['location'] : '') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small">Note (optional)</label>
                    <input type="text" name="note" class="form-control form-control-sm" maxlength="300" placeholder="e.g. Bring your QR code or a valid ID">
                </div>
                <div class="col-12">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Claiming Schedule</button>
                </div>
            </form>
            <?php endif; ?>

            <hr class="my-4">
            <h6 class="fw-semibold mb-1"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>Remind Participants to Claim</h6>
            <p class="small text-muted mb-2">Sends a notification and an email with the claiming schedule to every approved, paid participant who has not claimed all items yet. On the last claiming day, EVORA also reminds them automatically in the morning.</p>
            <div class="small mb-2">
                <span class="fw-semibold"><?= $claimRecipients ?></span> participant<?= $claimRecipients === 1 ? '' : 's' ?> still to claim
                <?php if ($lastClaimReminder): ?>
                    · Last reminder: <?= date('M j, g:i A', strtotime($lastClaimReminder['sent_at'])) ?> (<?= $lastClaimReminder['kind'] === 'automatic' ? 'automatic' : 'sent by organizer' ?>, <?= (int) $lastClaimReminder['recipients'] ?> notified)
                <?php endif; ?>
            </div>
            <form method="post" action="<?= url('organizer-claim-remind') ?>" data-confirm="Send the claiming reminder to <?= $claimRecipients ?> participant<?= $claimRecipients === 1 ? '' : 's' ?>? They will receive a notification and an email." data-confirm-title="Remind participants?" data-confirm-button="Send Reminder">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <button class="btn btn-evora-primary btn-sm" <?= $canRemind ? '' : 'disabled' ?>><i class="bi bi-send" aria-hidden="true"></i> Remind Participants to Claim</button>
            </form>
            <?php if (!$canRemind): ?>
                <div class="form-text">
                    <?php if (!$itemsList): ?>Add the items to be claimed first.
                    <?php elseif (!$claimSlots): ?>Add a claiming schedule first.
                    <?php elseif (!ClaimScheduleService::upcoming($claimSlots)): ?>All claiming schedules have passed. Add a new one to send a reminder.
                    <?php elseif ($claimRecipients === 0): ?>No one to remind: everyone who can claim has already claimed all items.
                    <?php else: ?>Reminders can only be sent for an approved event that has not ended.
                    <?php endif; ?>
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
