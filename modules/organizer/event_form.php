<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

if (!$organizer) {
    flash('error', 'Your organizer account must be approved before creating events.');
    redirect('organizer-dashboard');
}

$eventId = (int) ($_GET['id'] ?? 0);
$event = null;

if ($eventId) {
    $stmt = $db->prepare(
        'SELECT e.*, c.slug AS category_slug, c.name AS category_name FROM events e JOIN event_categories c ON c.id = e.category_id
         WHERE e.id = :id AND e.organizer_id = :oid'
    );
    $stmt->execute(['id' => $eventId, 'oid' => $organizer['id']]);
    $event = $stmt->fetch();
    if (!$event) {
        flash('error', 'Event not found.');
        redirect('organizer-events');
    }
}

$hasRegistrations = false;
if ($event) {
    $regCountStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE event_id = :id');
    $regCountStmt->execute(['id' => $event['id']]);
    $hasRegistrations = (int) $regCountStmt->fetch()['c'] > 0;
}
// Paid events take PayPal, paid directly to the organizer's confirmed PayPal Business account.
$formPayPalAccount = PAYPAL_ENABLED ? PayPalAccountService::current($db, (int) $organizer['id']) : null;
$paypalReady = PAYPAL_ENABLED && $formPayPalAccount !== null;
// an existing paid event that already has registrations under the legacy provider (PayMongo)
$isLegacyPaidEvent = $event && $hasRegistrations && ($event['payment_provider'] ?? null) !== 'paypal';
$categories =$db->query('SELECT * FROM event_categories ORDER BY name')->fetchAll();

$lockedCategory = null;
$lockedSport = null;
if (!$event) {
    $pickedCategoryId = (int) ($_GET['category_id'] ?? 0);
    if ($pickedCategoryId > 0) {
        foreach ($categories as $c) {
            if ((int) $c['id'] === $pickedCategoryId) {
                $lockedCategory = $c;
                break;
            }
        }
    }
    $pickedSportId = (int) ($_GET['sport_id'] ?? 0);
    if ($pickedSportId > 0) {
        $sportStmt = $db->prepare('SELECT * FROM sports WHERE id = :id');
        $sportStmt->execute(['id' => $pickedSportId]);
        $lockedSport = $sportStmt->fetch() ?: null;
    }
}

$isSportsTournament = $lockedCategory
    ? $lockedCategory['slug'] === 'sports-tournament'
    : (($event['category_slug'] ?? null) === 'sports-tournament');

$isFunRun = $lockedCategory
    ? $lockedCategory['slug'] === 'fun-run'
    : (($event['category_slug'] ?? null) === 'fun-run');

if ($isSportsTournament && $event && !empty($event['sport_id'])) {
    $sportStmt = $db->prepare('SELECT * FROM sports WHERE id = :id');
    $sportStmt->execute(['id' => $event['sport_id']]);
    $lockedSport = $sportStmt->fetch() ?: null;
}

$existingRaceCategories = [];
if ($isFunRun && $event) {
    $rcStmt = $db->prepare('SELECT * FROM race_categories WHERE event_id = :id ORDER BY fee, name');
    $rcStmt->execute(['id' => $event['id']]);
    $existingRaceCategories = $rcStmt->fetchAll();
}

// Payment type isn't a stored column — it's derived from whether any fee on
// this event is currently > 0, so an existing event's fee(s) already imply
// the right radio state. New events default to Paid (Free while PayPal cannot take payments).
if (!$event) {
    $currentPaymentType = $paypalReady ? 'paid' : 'free';
} elseif ($isFunRun) {
    $currentPaymentType = array_sum(array_column($existingRaceCategories, 'fee')) > 0 ? 'paid' : 'free';
} else {
    $currentPaymentType = (float) ($event['fee'] ?? 0) > 0 ? 'paid' : 'free';
}

$pageDescription = 'Event details participants see when they register.';
$pageTitle = $event ? 'Edit Event' : 'Create Event';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';

function val($event, $field, $default = '') {
    return e($event[$field] ?? $default);
}
?>
<a href="<?= $event ? url('organizer-event-manage', ['id' => $event['id']]) : url('organizer-events') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to <?= $event ? 'Event Management' : 'My Events' ?></a>
<div class="card-evora p-4" style="max-width:900px;">
    <h6 class="fw-semibold mb-3"><?= $event ? 'Edit Event' : 'Create New Event' ?></h6>
    <?php if ($hasRegistrations): ?>
    <?= evoraAlert('action', 'This event already has registered participants. Critical event settings are locked to protect existing registrations.', ['icon' => 'bi-lock-fill', 'class' => 'small']) ?>
    <?php endif; ?>
    <form method="post" action="<?= url('organizer-event-save') ?>" enctype="multipart/form-data" data-wizard="<?= $event ? 'edit' : 'new' ?>">
        <?= Csrf::field() ?>
        <?php if ($event): ?><input type="hidden" name="event_id" value="<?= $event['id'] ?>"><?php endif; ?>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-info-circle me-1"></i>Event Information</h6>
        <div class="row">
            <div class="col-md-8 mb-3">
                <div class="form-floating">
                    <input type="text" name="name" id="evName" class="form-control" placeholder="Event Name" value="<?= val($event, 'name') ?>" required>
                    <label for="evName">Event Name *</label>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <?php if ($hasRegistrations): ?>
                    <input type="hidden" name="category_id" value="<?= $event['category_id'] ?>">
                    <label class="form-label small text-muted mb-1">Event Category</label>
                    <div class="form-control-plaintext bg-light border rounded px-3 py-2">
                        <i class="bi bi-lock-fill text-muted me-1"></i><?= e($event['category_name']) ?>
                    </div>
                    <div class="form-text">Locked — this event already has registrations.</div>
                <?php elseif ($lockedCategory): ?>
                    <input type="hidden" name="category_id" value="<?= $lockedCategory['id'] ?>">
                    <label class="form-label small text-muted mb-1">Event Category</label>
                    <div class="form-control-plaintext bg-light border rounded px-3 py-2">
                        <i class="bi bi-check-circle-fill text-success me-1"></i><?= e($lockedCategory['name']) ?>
                    </div>
                    <div class="form-text">Chosen in the previous step. <a href="<?= url('organizer-event-type') ?>">Change type</a></div>
                <?php else: ?>
                <div class="form-floating">
                    <select name="category_id" id="evCategory" class="form-select" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($event['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="evCategory">Event Category *</label>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isSportsTournament): ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?php if ($lockedSport): ?>
                    <input type="hidden" name="sport_id" value="<?= $lockedSport['id'] ?>">
                    <label class="form-label small text-muted mb-1">Sport</label>
                    <div class="form-control-plaintext bg-light border rounded px-3 py-2">
                        <i class="bi bi-check-circle-fill text-success me-1"></i><?= e($lockedSport['name']) ?>
                    </div>
                    <?php if (!$event): ?>
                        <div class="form-text">Chosen in the previous step. <a href="<?= url('organizer-sport-select') ?>">Change sport</a></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-warning small mb-0">No sport selected. <a href="<?= url('organizer-sport-select') ?>">Choose a sport</a> first.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-floating mb-3">
            <textarea name="description" id="evDesc" class="form-control" placeholder="Description" style="height:100px;"><?= val($event, 'description') ?></textarea>
            <label for="evDesc">Description</label>
        </div>

        <div class="mb-4">
            <label class="form-label">Event Banner</label>
            <input type="file" name="banner" class="form-control" accept=".jpg,.jpeg,.png" data-preview="#bannerPreview">
            <?php if (!empty($event['banner_path'])): ?>
                <div class="form-text">Current: <a href="<?= uploadUrl($event['banner_path']) ?>" target="_blank">view banner</a> (upload a new file to replace)</div>
            <?php endif; ?>
            <div id="bannerPreview"></div>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-geo-alt me-1"></i>Date and Location</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="form-floating">
                    <?php if ($hasRegistrations): ?><input type="hidden" name="event_date" value="<?= val($event, 'event_date') ?>"><?php endif; ?>
                    <input type="date" id="evDate" class="form-control" placeholder="Date" value="<?= val($event, 'event_date') ?>" <?= $hasRegistrations ? 'disabled' : 'name="event_date" required' ?>>
                    <label for="evDate">Date <?= $hasRegistrations ? '(locked)' : '*' ?></label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="form-floating">
                    <input type="time" name="event_time" id="evTime" class="form-control" placeholder="Time" value="<?= val($event, 'event_time') ?>" required>
                    <label for="evTime">Time *</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="form-floating">
                    <input type="date" name="registration_deadline" id="evDeadline" class="form-control" placeholder="Registration Deadline" value="<?= val($event, 'registration_deadline') ?>">
                    <label for="evDeadline">Registration Deadline</label>
                </div>
                <div class="form-text">Optional — registration closes automatically after this date. You can also close it yourself anytime. Once closed, no new registrations are accepted so you can prepare the event.</div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="form-floating">
                    <input type="text" name="venue" id="evVenue" class="form-control" placeholder="Venue" value="<?= val($event, 'venue') ?>" required>
                    <label for="evVenue">Venue *</label>
                </div>
            </div>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-ticket-perforated me-1"></i>Registration Settings</h6>

        <div class="mb-3">
            <label class="form-label small text-muted mb-1">Event Payment Type <?= $hasRegistrations ? '(locked)' : '*' ?></label>
            <?php if ($hasRegistrations): ?><input type="hidden" name="payment_type" value="<?= e($currentPaymentType) ?>"><?php endif; ?>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="payTypeFree" value="free" <?= $currentPaymentType === 'free' ? 'checked' : '' ?> <?= $hasRegistrations ? 'disabled' : 'name="payment_type"' ?>>
                <label class="form-check-label" for="payTypeFree"><strong>Free Event</strong> — no registration fee, participants register directly, no payment step.</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="payTypePaid" value="paid" <?= $currentPaymentType === 'paid' ? 'checked' : '' ?> <?= $hasRegistrations ? 'disabled' : 'name="payment_type"' ?> <?= !$paypalReady && $currentPaymentType !== 'paid' ? 'disabled title="A confirmed PayPal Business account is needed for paid events."' : '' ?>>
                <label class="form-check-label" for="payTypePaid"><strong>Paid Event</strong> — participants pay through PayPal directly to your PayPal account. Successful payments are verified automatically.</label>
            </div>
            <?php if ($isLegacyPaidEvent && $currentPaymentType === 'paid'): ?>
            <div class="form-text">This event uses the legacy payment method (PayMongo); it takes no new online payments. Payments already made are not affected.</div>
            <?php elseif (!PAYPAL_ENABLED): ?>
            <div class="form-text text-warning">Online payment (PayPal) is temporarily unavailable, so only Free Event is available right now.</div>
            <?php elseif (!$formPayPalAccount): ?>
            <div class="form-text text-warning">Paid events need your PayPal Business account — connect it on your <a href="<?= url('organizer-paypal-account') ?>">PayPal Account</a> page.</div>
            <?php elseif ($hasRegistrations): ?>
            <div class="form-text">Locked — this event already has registrations.</div>
            <?php endif; ?>
        </div>
        <div class="small mb-3 p-2 border rounded bg-light">
            <i class="bi bi-paypal text-primary me-1"></i> Paid events: participants pay through PayPal. Payments are sent directly to your PayPal Business account and EVORA automatically verifies successful payments — EVORA does not collect or hold the money, so there is no payout to wait for.
            <?php if ($formPayPalAccount): ?>
                <div class="text-muted mt-1">PayPal account: <?= e(PayPalAccountService::maskId($formPayPalAccount['merchant_id'])) ?> (<?= $formPayPalAccount['verification_method'] === 'paypal_login' ? 'connected via PayPal' : 'linked earlier by an administrator' ?>)</div>
            <?php elseif (PAYPAL_ENABLED): ?>
                <div class="text-danger mt-1">No PayPal Business account connected yet — connect it on your <a href="<?= url('organizer-paypal-account') ?>">PayPal Account</a> page before you publish a paid event.</div>
            <?php endif; ?>
        </div>

        <?php if ($isFunRun): ?>
        <input type="hidden" name="fee" value="0">
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="number" min="1" name="max_participants" id="evMax" class="form-control" placeholder="Maximum Participants" value="<?= val($event, 'max_participants', '100') ?>" required>
                    <label for="evMax">Overall Event Slots *</label>
                </div>
                <div class="form-text">Total cap across all race categories combined.</div>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label small text-muted mb-1">Race Categories *</label>
            <div class="form-text mb-2">Add each distance you're running (e.g. 5KM, 10KM, 18KM) with its own fee and slot cap.</div>
            <div id="raceCategoryRows">
                <?php if (empty($existingRaceCategories)): ?>
                    <div class="row g-2 mb-2 race-cat-row">
                        <input type="hidden" name="race_cat_id[]" value="">
                        <div class="col-4"><input type="text" name="race_cat_name[]" class="form-control form-control-sm" placeholder="e.g. 5KM" required></div>
                        <div class="col-3"><input type="number" step="0.01" min="0" name="race_cat_fee[]" class="form-control form-control-sm race-cat-fee-input" placeholder="Fee (₱)" required></div>
                        <div class="col-3"><input type="number" min="1" name="race_cat_max[]" class="form-control form-control-sm" placeholder="Max Slots" required></div>
                        <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100 race-cat-remove">Remove</button></div>
                    </div>
                <?php else: ?>
                    <?php foreach ($existingRaceCategories as $rc): ?>
                    <div class="row g-2 mb-2 race-cat-row">
                        <input type="hidden" name="race_cat_id[]" value="<?= $rc['id'] ?>">
                        <div class="col-4"><input type="text" name="race_cat_name[]" class="form-control form-control-sm" value="<?= e($rc['name']) ?>" required></div>
                        <div class="col-3">
                            <?php if ($hasRegistrations): ?><input type="hidden" name="race_cat_fee[]" value="<?= e($rc['fee']) ?>"><?php endif; ?>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm race-cat-fee-input" value="<?= e($rc['fee']) ?>" title="<?= $hasRegistrations ? 'Locked — this event already has registrations.' : '' ?>" <?= $hasRegistrations ? 'disabled' : 'name="race_cat_fee[]" required' ?>>
                        </div>
                        <div class="col-3"><input type="number" min="1" name="race_cat_max[]" class="form-control form-control-sm" value="<?= (int) $rc['max_participants'] ?>" required></div>
                        <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100 race-cat-remove" <?= $hasRegistrations ? 'disabled title="Locked — this event already has registrations."' : '' ?>>Remove</button></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" id="raceCategoryAddBtn" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Add Another Category</button>
        </div>
        <script>
        (function () {
            const container = document.getElementById('raceCategoryRows');
            const addBtn = document.getElementById('raceCategoryAddBtn');
            function attachRemove(row) {
                row.querySelector('.race-cat-remove').addEventListener('click', function () {
                    if (container.querySelectorAll('.race-cat-row').length > 1) {
                        row.remove();
                    } else {
                        row.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (i) { i.value = ''; });
                    }
                });
            }
            container.querySelectorAll('.race-cat-row').forEach(attachRemove);
            addBtn.addEventListener('click', function () {
                const row = document.createElement('div');
                row.className = 'row g-2 mb-2 race-cat-row';
                row.innerHTML = '<input type="hidden" name="race_cat_id[]" value="">' +
                    '<div class="col-4"><input type="text" name="race_cat_name[]" class="form-control form-control-sm" placeholder="e.g. 10KM" required></div>' +
                    '<div class="col-3"><input type="number" step="0.01" min="0" name="race_cat_fee[]" class="form-control form-control-sm race-cat-fee-input" placeholder="Fee (₱)" required></div>' +
                    '<div class="col-3"><input type="number" min="1" name="race_cat_max[]" class="form-control form-control-sm" placeholder="Max Slots" required></div>' +
                    '<div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100 race-cat-remove">Remove</button></div>';
                container.appendChild(row);
                attachRemove(row);
                var checkedType = document.querySelector('input[name="payment_type"]:checked');
                if (checkedType && checkedType.value === 'free') {
                    var newFeeInput = row.querySelector('.race-cat-fee-input');
                    newFeeInput.readOnly = true;
                    newFeeInput.value = '0';
                }
            });
        })();
        </script>
        <?php else: ?>
        <div class="row">
            <div class="col-md-6 mb-3" id="feeFieldWrap" <?= $currentPaymentType === 'free' ? 'style="display:none;"' : '' ?>>
                <div class="form-floating">
                    <?php if ($hasRegistrations): ?><input type="hidden" name="fee" value="<?= val($event, 'fee', '0') ?>"><?php endif; ?>
                    <input type="number" step="0.01" min="0" id="evFee" class="form-control" placeholder="Fee" value="<?= val($event, 'fee', '0') ?>" <?= $hasRegistrations ? 'disabled' : 'name="fee" required' ?>>
                    <label for="evFee">Registration Fee (₱) <?= $hasRegistrations ? '(locked)' : '*' ?></label>
                </div>
                <?php if ($hasRegistrations): ?>
                <div class="form-text">Locked — this event already has registrations.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="number" min="1" name="max_participants" id="evMax" class="form-control" placeholder="Maximum Participants" value="<?= val($event, 'max_participants', '100') ?>" required>
                    <label for="evMax">Maximum Participants *</label>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$hasRegistrations): ?>
        <script>
        (function () {
            function applyPaymentType(type) {
                var free = type === 'free';
                var feeWrap = document.getElementById('feeFieldWrap');
                if (feeWrap) feeWrap.style.display = free ? 'none' : '';
                document.querySelectorAll('.race-cat-fee-input').forEach(function (input) {
                    input.readOnly = free;
                    if (free) {
                        if (input.dataset.prevValue === undefined) input.dataset.prevValue = input.value;
                        input.value = '0';
                    } else if (input.dataset.prevValue !== undefined) {
                        input.value = input.dataset.prevValue;
                    }
                });
            }
            document.querySelectorAll('input[name="payment_type"]').forEach(function (radio) {
                radio.addEventListener('change', function () { applyPaymentType(this.value); });
            });
            var checked = document.querySelector('input[name="payment_type"]:checked');
            applyPaymentType(checked ? checked.value : 'paid');
        })();
        </script>
        <?php endif; ?>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-credit-card me-1"></i>Payment Method</h6>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="evPaypalIndicator" <?= PAYPAL_ENABLED ? 'checked' : '' ?> disabled>
            <label class="form-check-label" for="evPaypalIndicator">
                <strong>PayPal Payment</strong>
                <?php if (PAYPAL_ENABLED): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Active</span>
                    <div class="form-text mb-0">Payments are sent directly to the organizer's PayPal account. EVORA automatically verifies successful payments and issues the QR code. Nothing to set up per event.</div>
                <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border ms-1">Temporarily unavailable</span>
                    <div class="form-text mb-0">Online payment is temporarily unavailable. Paid events can take PayPal payments again once it is back.</div>
                <?php endif; ?>
            </label>
        </div>

        <?php if ($isSportsTournament): ?>
        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-trophy me-1"></i>Tournament Settings</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="form-floating">
                    <select name="fee_mode" id="evFeeMode" class="form-select">
                        <option value="individual" <?= ($event['fee_mode'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Per Player</option>
                        <option value="team" <?= ($event['fee_mode'] ?? '') === 'team' ? 'selected' : '' ?>>Per Team</option>
                    </select>
                    <label for="evFeeMode">Fee Applies</label>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="form-floating">
                    <input type="number" min="1" name="max_teams" id="evMaxTeams" class="form-control" placeholder="Max Teams" value="<?= val($event, 'max_teams') ?>">
                    <label for="evMaxTeams">Maximum Teams/Entries</label>
                </div>
                <div class="form-text">Optional — leave blank if unlimited.</div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="form-floating">
                    <?php $sportDefaultFormat = $lockedSport['default_tournament_format'] ?? 'single_elim'; ?>
                    <select name="tournament_format" id="evTournamentFormat" class="form-select">
                        <?php $formats = ['single_elim' => 'Single Elimination', 'double_elim' => 'Double Elimination', 'round_robin' => 'Round Robin', 'league' => 'League / Ranking']; ?>
                        <?php foreach ($formats as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($event['tournament_format'] ?? $sportDefaultFormat) === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="evTournamentFormat">Tournament Format</label>
                </div>
            </div>
        </div>
        <?php if (($lockedSport['scoring_method'] ?? '') === 'points' && ($lockedSport['sport_type'] ?? '') === 'team'): ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="number" min="1" name="periods_count" id="evPeriods" class="form-control" placeholder="Periods" value="<?= val($event, 'periods_count', '4') ?>">
                    <label for="evPeriods">Number of Quarters/Periods</label>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="number" min="1" name="game_duration_minutes" id="evGameDuration" class="form-control" placeholder="Minutes per period" value="<?= val($event, 'game_duration_minutes', '12') ?>">
                    <label for="evGameDuration">Minutes per Quarter/Period</label>
                </div>
                <div class="form-text">Used by the Live Game Controller's clock.</div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-list-check me-1"></i>Additional Details</h6>
        <div class="form-floating mb-3">
            <textarea name="requirements" id="evReq" class="form-control" placeholder="Requirements" style="height:80px;"><?= val($event, 'requirements') ?></textarea>
            <label for="evReq">Requirements (e.g. Valid ID, Medical Certificate)</label>
        </div>
        <div class="form-floating mb-3">
            <textarea name="rules" id="evRules" class="form-control" placeholder="Event Rules" style="height:80px;"><?= val($event, 'rules') ?></textarea>
            <label for="evRules">Event Rules</label>
        </div>
        <div class="form-floating mb-3">
            <input type="text" name="contact_info" id="evContact" class="form-control" placeholder="Contact Information" value="<?= val($event, 'contact_info') ?>">
            <label for="evContact">Contact Information</label>
        </div>

        <?php if ($event): ?>
        <div class="alert alert-light border small">
            <i class="bi bi-info-circle"></i> Current status: <span class="badge badge-status-<?= e($event['status']) ?>"><?= ucfirst(str_replace('_', ' ', e($event['status']))) ?></span>
            — status changes (submit for approval, open/close registration, mark ongoing/completed) are made from the
            <a href="<?= url('organizer-event-manage', ['id' => $event['id']]) ?>">event management page</a>, not here.
        </div>
        <?php else: ?>
        <div class="alert alert-light border small"><i class="bi bi-info-circle"></i> New events start as a Draft. Submit it for admin approval from the event management page once you're ready to open registration.</div>
        <?php endif; ?>

        <button type="submit" class="btn btn-evora-primary"><?= $event ? 'Save Changes' : 'Create Event' ?></button>
        <a href="<?= $event ? url('organizer-event-manage', ['id' => $event['id']]) : url('organizer-events') ?>" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
