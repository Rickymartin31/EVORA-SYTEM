<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$pStmt->execute(['uid' => $userId]);
$participant = $pStmt->fetch();

// Payments finished without coming back to EVORA (closed tab, webhook not
// reachable) are confirmed here before the list is read.
if ($participant) {
    PaymentConfirmationService::autoCheckForParticipant($db, (int) $participant['id'], $userId);
}

$stmt = $db->prepare(
    'SELECT r.*, e.name AS event_name, e.event_date, COALESCE(rc.fee, e.fee) AS fee, e.id AS event_id,
        c.slug AS category_slug, e.status AS event_status, pay.status AS payment_status, pay.payment_channel, q.id AS qr_id, q.invalidated_at AS qr_invalidated_at,
        f.id AS feedback_id
     FROM registrations r
     JOIN events e ON e.id = r.event_id
     JOIN event_categories c ON c.id = e.category_id
     LEFT JOIN payments pay ON pay.registration_id = r.id
     LEFT JOIN qr_codes q ON q.registration_id = r.id
     LEFT JOIN race_categories rc ON rc.id = r.race_category_id
     LEFT JOIN event_feedback f ON f.event_id = e.id AND f.registration_id = r.id
     WHERE r.participant_id = :pid
     ORDER BY r.registered_at DESC'
);
$stmt->execute(['pid' => $participant['id'] ?? 0]);
$registrations = $stmt->fetchAll();
// Refunds per registration, newest first.
$refunds = RefundService::forParticipant($db, (int) ($participant['id'] ?? 0));
// v71: when and where to claim the race kit / items, and whether this registration still has items to claim
$claimSlotsByEvent = ClaimScheduleService::slotsForEvents($db, array_column($registrations, 'event_id'));
$unclaimedRegs = ClaimScheduleService::unclaimedRegistrations($db, array_column(array_filter($registrations, fn($r) => $r['status'] === 'approved'), 'id'));

$pageDescription = 'Registration, payment, QR and refund status for each event.';
$pageTitle = 'My Registrations';
$activeRoute = 'my-registrations';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php $waitlists = WaitlistService::forParticipant($db, (int) ($participant['id'] ?? 0)); ?>
<?php if ($waitlists): ?>
<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-3"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>My Waitlists</h6>
    <ul class="list-unstyled mb-0">
        <?php foreach ($waitlists as $w): ?>
            <li class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-1">
                <span><a href="<?= url('event-view', ['id' => $w['event_id']]) ?>" class="fw-semibold"><?= e($w['event_name']) ?></a><?= $w['category_name'] ? ' <span class="small text-muted">(' . e($w['category_name']) . ')</span>' : '' ?></span>
                <?php if ($w['status'] === 'offered'): ?>
                    <span class="badge bg-success">Slot held for you until <?= date('M d, g:i A', strtotime($w['offer_expires_at'])) ?></span>
                <?php else: ?>
                    <span class="badge bg-secondary">Number <?= (int) $w['position'] ?> in line</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">My Registrations</h6>
    <?php if (empty($registrations)): ?>
        <?= emptyState('bi-clipboard-x', "You haven't registered for any events yet.", 'Browse Events', url('events')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Event</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($registrations as $r): ?>
                <?php
                // Presentation only — the same conditions as before decide what is shown.
                $regRefunds = $refunds[(int) $r['id']] ?? [];
                $isFree = $r['fee'] <= 0;
                $showPayment = !$isFree && !(in_array($r['status'], ['rejected', 'cancelled'], true) && $r['payment_status'] !== 'refunded');
                $canRefund = $showPayment && $r['payment_channel'] === 'paymongo' && $r['payment_status'] === 'approved' && $r['status'] === 'approved'
                    && !array_filter($regRefunds, fn($rf) => $rf['refund_type'] === 'registration' && $rf['status'] !== 'rejected');
                // a PayPal checkout left open (window closed without paying) can be continued
                $paypalPending = $r['payment_channel'] === 'paypal' && $r['payment_status'] === 'pending';
                $canPay = $showPayment && (in_array($r['payment_status'], [null, 'rejected', 'failed', 'cancelled'], true) || $paypalPending);
                ?>
                <tr>
                    <td data-order="<?= e($r['event_date']) ?>">
                        <div class="fw-semibold"><?= e($r['event_name']) ?></div>
                        <div class="small text-muted"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i><?= date('M d, Y', strtotime($r['event_date'])) ?></div>
                    </td>
                    <td>
                        <div class="status-stack">
                            <div class="status-line"><span class="status-key">Registration</span><span class="badge badge-status-<?= e($r['status']) ?>"><?= ucfirst(e($r['status'])) ?></span></div>
                            <div class="status-line"><span class="status-key">Payment</span>
                                <?php if ($isFree): ?>
                                    <span class="text-muted">Free event</span>
                                <?php elseif (!$showPayment): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <?php if ($r['payment_status']): ?><span class="badge badge-status-<?= e($r['payment_status']) ?>"><?= ucfirst(e($r['payment_status'])) ?></span><?php else: ?><span class="text-muted">Not paid yet</span><?php endif; ?>
                                    <?php if ($r['payment_channel']): ?><span class="small text-muted"><?= e(paymentChannelLabel($r['payment_channel'])) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="status-line"><span class="status-key">QR code</span>
                                <?php if ($r['qr_id'] && $r['qr_invalidated_at']): ?>
                                    <span class="text-danger small"><i class="bi bi-x-circle" aria-hidden="true"></i> Invalid</span>
                                <?php elseif ($r['qr_id']): ?>
                                    <span class="text-success small"><i class="bi bi-check-circle" aria-hidden="true"></i> Ready</span>
                                <?php else: ?>
                                    <span class="text-muted small">Not issued</span>
                                <?php endif; ?>
                            </div>
                            <?php $allKitSlots = $claimSlotsByEvent[(int) $r['event_id']] ?? []; $kitSlots = ClaimScheduleService::upcoming($allKitSlots); ?>
                            <?php if ($allKitSlots && $r['status'] === 'approved' && $r['event_date'] >= date('Y-m-d')): ?>
                                <div class="status-line"><span class="status-key">Event items</span>
                                    <?php if (isset($unclaimedRegs[(int) $r['id']])): ?><span class="badge bg-warning text-dark">To claim</span><?php else: ?><span class="text-success small"><i class="bi bi-check-circle" aria-hidden="true"></i> Claimed</span><?php endif; ?>
                                </div>
                                <?php if (isset($unclaimedRegs[(int) $r['id']])): ?>
                                    <div class="small text-muted ps-1">
                                        <?php foreach ($kitSlots as $slot): ?>
                                            <div><i class="bi bi-calendar-check me-1" aria-hidden="true"></i><?= e(ClaimScheduleService::slotWhen($slot)) ?> · <?= e($slot['location']) ?></div>
                                        <?php endforeach; ?>
                                        <div><?= $kitSlots ? 'Bring your QR code. You can still claim on event day.' : 'The claiming days have passed. You can still claim on event day; bring your QR code.' ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($r['payment_channel'] === 'paypal' && $r['payment_status'] === 'approved'): ?><div class="small text-muted mt-1"><i class="bi bi-info-circle" aria-hidden="true"></i> Paid directly to the organizer through PayPal. If this registration is cancelled, the organizer refunds you from PayPal — follow it on <a href="<?= url('my-payments') ?>">Payments &amp; Receipts</a>.</div><?php endif; ?>
                            <?php foreach ($regRefunds as $i => $rf): if ($rf['status'] === 'rejected' && $i > 0) continue; // older rejections hidden ?>
                                <div class="status-line"><span class="status-key">Refund</span><?= RefundService::badge($rf['status']) ?><span class="small text-muted"><?= RefundService::number((int) $rf['id']) ?></span></div>
                                <div class="refund-note ps-1">
                                    <?php if ($rf['status'] === 'pending'): ?>
                                        Request submitted — waiting for EVORA review.
                                    <?php elseif ($rf['status'] === 'approved'): ?>
                                        Approved. Your refund is being processed through PayMongo and goes back to your original payment method.
                                    <?php elseif ($rf['status'] === 'completed'): ?>
                                        Refund completed<?= $rf['completed_at'] ? ' on ' . date('M j, Y', strtotime($rf['completed_at'])) : '' ?> · Ref <?= e(PayoutService::maskReference($rf['refund_reference'])) ?>
                                    <?php elseif ($rf['status'] === 'rejected'): ?>
                                        Not approved<?= $rf['review_remarks'] ? ': ' . e($rf['review_remarks']) : '.' ?>
                                    <?php endif; ?>
                                    <?= $rf['refund_type'] === 'duplicate_charge' ? ' <span class="text-muted">(extra charge)</span>' : '' ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap justify-content-end gap-1">
                            <?php if ($canPay): ?>
                                <a href="<?= url('payment-upload', ['registration_id' => $r['id']]) ?>" class="btn btn-sm btn-evora-primary"><?= $paypalPending ? 'Continue Payment' : ($r['payment_status'] ? 'Retry Payment' : 'Pay Now') ?></a>
                            <?php endif; ?>
                            <?php if ($r['qr_id'] && !$r['qr_invalidated_at']): ?><a href="<?= url('my-qr') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-qr-code" aria-hidden="true"></i> My QR</a>
                                <?php if ($r['status'] === 'approved'): ?><a href="<?= url('my-ticket', ['registration_id' => $r['id']]) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-ticket-perforated" aria-hidden="true"></i> E-ticket</a><?php endif; ?><?php endif; ?>
                            <?php if (in_array($r['status'], ['pending', 'approved'], true) && $r['event_date'] >= date('Y-m-d')): ?><a href="<?= url('event-ics', ['id' => $r['event_id']]) ?>" class="btn btn-sm btn-outline-secondary" title="Add to calendar (.ics)"><i class="bi bi-calendar-plus" aria-hidden="true"></i><span class="visually-hidden">Add to calendar</span></a><?php endif; ?>
                            <?php if ($r['status'] === 'approved' && in_array($r['event_status'], EvaluationService::COMPLETED_EVENT_STATUSES, true)): ?>
                                <a href="<?= url('event-feedback', ['event_id' => $r['event_id']]) ?>" class="btn btn-sm btn-outline-<?= $r['feedback_id'] ? 'secondary' : 'success' ?>"><?= $r['feedback_id'] ? 'Feedback Sent' : 'Rate Event' ?></a>
                            <?php endif; ?>
                            <a href="<?= url('event-view', ['id' => $r['event_id']]) ?>" class="btn btn-sm btn-outline-secondary">View Event</a>
                        </div>
                        <?php if (in_array($r['status'], ['pending', 'approved'], true) && in_array($r['event_status'], ['approved', 'open', 'closed'], true) && $r['event_date'] > date('Y-m-d') && empty($r['team_id'])): ?>
                            <details class="mt-2 text-start"><summary class="small text-danger" style="cursor:pointer">Cancel registration</summary>
                                <form method="post" action="<?= url('my-registration-cancel') ?>" class="mt-2" data-confirm="Cancel your registration for <?= e($r['event_name']) ?>? This cannot be undone and your QR code will stop working.<?= $r['payment_status'] === 'approved' ? ' Your payment will be listed for a refund from the organizer.' : '' ?>" data-confirm-title="Cancel registration?" data-confirm-variant="danger" data-confirm-button="Yes, cancel it">
                                    <?= Csrf::field() ?><input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>">
                                    <input type="text" name="reason" class="form-control form-control-sm mb-1" maxlength="300" placeholder="Reason (optional)" aria-label="Reason for cancelling">
                                    <button class="btn btn-sm btn-outline-danger">Cancel Registration</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
