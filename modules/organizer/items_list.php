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

$suggested = EventItemService::suggestedQuantity($db, $event); // v73: default quantity for new items
// v74: quick-add buttons depend on the event type (a fun run hands out one race kit)
$catStmt = $db->prepare('SELECT slug FROM event_categories WHERE id = :id');
$catStmt->execute(['id' => $event['category_id']]);
$itemPresets = EventItemService::presetsFor($catStmt->fetchColumn() ?: null);

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
                    <thead><tr><th>Item</th><th>Total Qty</th><th>Claimed</th><th>Remaining</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($itemsList as $item): ?>
                        <?php $remaining = max(0, $item['total_quantity'] - $item['claimed_quantity']); ?>
                        <tr>
                            <td><?= e($item['item_name']) ?><?= $item['variant_label'] ? ' <span class="text-muted small">(' . e($item['variant_label']) . ')</span>' : '' ?><?= !empty($item['contents']) ? '<div class="small text-muted">Includes: ' . e($item['contents']) . '</div>' : '' ?></td>
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
            <?php if (!$editItem): ?>
                <div class="small text-muted mb-1">Quick add</div>
                <div class="d-flex flex-wrap gap-1 mb-3" id="itemPresets">
                    <?php foreach ($itemPresets as $presetLabel => [$presetName, $presetType, $presetSizes, $presetContents]): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-name="<?= e($presetName) ?>" data-type="<?= e($presetType) ?>" data-sizes="<?= $presetSizes ? '1' : '0' ?>" data-contents="<?= e($presetContents) ?>">+ <?= e($presetLabel) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= url('organizer-item-save') ?>" id="itemForm">
                <?= Csrf::field() ?>
                <input type="hidden" name="item_action" value="<?= $editItem ? 'update' : 'create' ?>">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="item_id" value="<?= $editItem['id'] ?>">
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label small">Item Name *</label>
                    <input type="text" name="item_name" id="itemName" class="form-control form-control-sm" placeholder="e.g. Race Kit, Event Shirt, Certificate" value="<?= e($editItem['item_name'] ?? '') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">What's inside (optional)</label>
                    <input type="text" name="contents" id="itemContents" class="form-control form-control-sm" maxlength="255" placeholder="e.g. T-shirt and race bib" value="<?= e($editItem['contents'] ?? '') ?>">
                </div>
                <?php if ($editItem): ?>
                    <div class="mb-2">
                        <label class="form-label small">Size / Variant</label>
                        <input type="text" name="variant_label" class="form-control form-control-sm" placeholder="e.g. M (optional)" value="<?= e($editItem['variant_label'] ?? '') ?>">
                    </div>
                <?php else: ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="use_sizes" value="1" id="useSizes">
                        <label class="form-check-label small" for="useSizes">This item comes in sizes (e.g. the shirt in a race kit)</label>
                    </div>
                    <div id="sizeGrid" class="mb-2" hidden>
                        <div class="small text-muted mb-1">Quantity per size (leave blank for sizes you don't have)</div>
                        <div class="row g-1">
                            <?php foreach (EventItemService::SIZES as $size): ?>
                                <div class="col-3">
                                    <label class="form-label small mb-0" for="size_<?= e($size) ?>"><?= e($size) ?></label>
                                    <input type="number" min="0" name="size_qty[<?= e($size) ?>]" id="size_<?= e($size) ?>" class="form-control form-control-sm size-qty" inputmode="numeric">
                                </div>
                            <?php endforeach; ?>
                            <div class="col-6">
                                <label class="form-label small mb-0" for="customSize">Other size</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="custom_size" id="customSize" class="form-control" maxlength="40" placeholder="e.g. Kids">
                                    <input type="number" min="0" name="custom_size_qty" class="form-control size-qty" placeholder="Qty" aria-label="Quantity for the other size">
                                </div>
                            </div>
                        </div>
                        <div class="small mt-1">Total: <span class="fw-semibold" id="sizeTotal">0</span> · each size is listed as its own item, and a participant can claim only one size.</div>
                    </div>
                <?php endif; ?>
                <div class="mb-2" id="qtyBox">
                    <label class="form-label small">Total Quantity *</label>
                    <input type="number" name="total_quantity" id="totalQty" min="1" class="form-control form-control-sm" value="<?= e($editItem['total_quantity'] ?? ($suggested['quantity'] ?: '')) ?>" required>
                    <?php if ($editItem && (int) $editItem['claimed_quantity'] > 0): ?>
                        <div class="form-text"><?= (int) $editItem['claimed_quantity'] ?> already claimed — total quantity can't be set below this.</div>
                    <?php elseif (!$editItem && ($suggested['approved'] || $suggested['slots'])): ?>
                        <div class="form-text"><?= $suggested['approved'] ?> approved participant<?= $suggested['approved'] === 1 ? '' : 's' ?><?= $suggested['slots'] ? ' · ' . $suggested['slots'] . ' slots' : '' ?>. Add a few extra for walk-ins.</div>
                    <?php endif; ?>
                </div>
                <details class="mb-3" <?= $editItem ? 'open' : '' ?>>
                    <summary class="small text-muted">More options</summary>
                    <label class="form-label small mt-2">Low Stock Alert</label>
                    <input type="number" name="low_stock_threshold" min="0" value="<?= $editItem ? e($editItem['low_stock_threshold']) : '' ?>" placeholder="Automatic (10% of quantity)" class="form-control form-control-sm">
                    <div class="form-text">Shows "Low Stock" when the remaining quantity drops to this number. Leave blank for automatic.</div>
                </details>
                <button type="submit" class="btn btn-evora-primary btn-sm w-100"><?= $editItem ? 'Update Item' : 'Add Item' ?></button>
                <?php if ($editItem): ?>
                    <a href="<?= url('organizer-items', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">Cancel</a>
                <?php endif; ?>
            </form>
            <?php if (!$editItem): ?>
            <script>
            (function () {
                const name = document.getElementById('itemName');
                const useSizes = document.getElementById('useSizes'), grid = document.getElementById('sizeGrid');
                const qtyBox = document.getElementById('qtyBox'), qty = document.getElementById('totalQty');
                function toggleSizes() {
                    grid.hidden = !useSizes.checked;
                    qtyBox.hidden = useSizes.checked;
                    qty.required = !useSizes.checked;
                    qty.disabled = useSizes.checked;
                }
                function total() {
                    let t = 0;
                    document.querySelectorAll('.size-qty').forEach(i => { t += parseInt(i.value, 10) || 0; });
                    document.getElementById('sizeTotal').textContent = t;
                }
                useSizes.addEventListener('change', toggleSizes);
                document.querySelectorAll('.size-qty').forEach(i => i.addEventListener('input', total));
                document.querySelectorAll('#itemPresets button').forEach(b => b.addEventListener('click', () => {
                    name.value = b.dataset.name;
                    document.getElementById('itemContents').value = b.dataset.contents || '';
                    useSizes.checked = b.dataset.sizes === '1';
                    toggleSizes();
                    (useSizes.checked ? document.querySelector('.size-qty') : qty).focus();
                }));
                toggleSizes();
            })();
            </script>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
