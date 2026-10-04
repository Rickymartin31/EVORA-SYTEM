<?php
Auth::requireRole('participant');
$db = Database::connection();
$userId = Auth::id();

$eventId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare(
    "SELECT e.*, c.slug AS category_slug, s.name AS sport_name, s.sport_type, s.team_size, s.player_format, s.scoring_method
     FROM events e JOIN event_categories c ON c.id = e.category_id LEFT JOIN sports s ON s.id = e.sport_id
     WHERE e.id = :id AND e.status = 'open' AND e.event_date >= CURDATE()" // a past event is never open for registration
);
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'This event is not open for registration.');
    redirect('events');
}

$pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$pStmt->execute(['uid' => $userId]);
$participant = $pStmt->fetch();

// A cancelled registration does not count: the participant may register again (v47).
$existing = $db->prepare('SELECT id FROM registrations WHERE event_id = :eid AND participant_id = :pid AND status <> "cancelled"');
$existing->execute(['eid' => $eventId, 'pid' => $participant['id']]);
if ($existing->fetch()) {
    flash('error', 'You have already registered for this event.');
    redirect('event-view', ['id' => $eventId]);
}

$raceCategories = [];
if ($event['category_slug'] === 'fun-run') {
    $catStmt = $db->prepare(
        "SELECT rc.*, (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved_count
         FROM race_categories rc WHERE rc.event_id = :eid ORDER BY rc.fee, rc.name"
    );
    $catStmt->execute(['eid' => $eventId]);
    $raceCategories = $catStmt->fetchAll();

    if (empty($raceCategories)) {
        flash('error', 'Registration for this event is not open yet — the organizer has not set up race categories.');
        redirect('event-view', ['id' => $eventId]);
    }
}

$requiresPayment = $event['category_slug'] === 'fun-run' || (float) $event['fee'] > 0;
// PayPal events are paid directly to the organizer through PayPal; everything else uses PayMongo.
$isPayPalEvent = ($event['payment_provider'] ?? null) === 'paypal';

// PayPal switched off: nobody can register into a PayPal event (they could not pay).
if ($isPayPalEvent && !PAYPAL_ENABLED) {
    flash('error', 'Online payment for this event is paused right now, so new registrations are not accepted. Please try again later or contact the organizer.');
    redirect('event-view', ['id' => $eventId]);
}
// PayPal is the active provider: a legacy (PayMongo) paid event takes no new online payments.
if ($requiresPayment && PayPalPaymentService::legacyOnlinePaymentPaused($event['payment_provider'] ?? null)) {
    flash('error', PayPalPaymentService::LEGACY_PAUSED_MESSAGE);
    redirect('event-view', ['id' => $eventId]);
}
$onlineAvailable = $isPayPalEvent ? PAYPAL_ENABLED : PAYMONGO_ENABLED;

if ($requiresPayment && !$onlineAvailable) {
    flash('error', 'This event has no payment method configured yet. Please contact the organizer.');
    redirect('event-view', ['id' => $eventId]);
}

function sportRegistrationNote(array $event): ?string
{
    if ($event['category_slug'] !== 'sports-tournament' || empty($event['sport_name'])) {
        return null;
    }
    $scoringLabels = [
        'points' => 'scored by points', 'sets' => 'scored by sets won', 'rounds' => 'scored by rounds won',
        'win_draw_loss' => 'scored win, draw, or loss', 'time_rank' => 'ranked by finish time',
    ];
    $scoring = $scoringLabels[$event['scoring_method']] ?? 'scored by points';
    $usesTeams = $event['sport_type'] !== 'individual' || (int) $event['team_size'] !== 1;

    if (!$usesTeams) {
        return e($event['sport_name']) . ' — an individual event, ' . $scoring . '. You\'ll compete on your own; no team needed.';
    }

    $sizeText = $event['team_size'] ? 'teams of ' . (int) $event['team_size'] . ' player' . ((int) $event['team_size'] === 1 ? '' : 's') : 'teams';
    $formatText = $event['player_format'] ? ' (' . e($event['player_format']) . ')' : '';
    return e($event['sport_name']) . ' — a team event, ' . $sizeText . $formatText . ', ' . $scoring . '. Once your registration is approved, the organizer will place you on a team roster.';
}
$sportNote = sportRegistrationNote($event);

$pageTitle = 'Register — ' . $event['name'];
$activeRoute = 'events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<a href="<?= url('event-view', ['id' => $eventId]) ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Event</a>

<div class="card-evora p-4 mw-form-lg">
    <h6 class="fw-semibold mb-3">Register for <?= e($event['name']) ?></h6>

    <?php if ($sportNote): ?>
    <div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i><?= $sportNote ?></div>
    <?php endif; ?>
    <form method="post" action="<?= url('event-register-save') ?>" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="event_id" value="<?= $eventId ?>">

        <?php if ($event['category_slug'] === 'fun-run'): ?>
        <div class="form-floating mb-3">
            <select name="race_category_id" id="raceCategory" class="form-select" required>
                <?php foreach ($raceCategories as $rc): ?>
                    <?php $catFull = (int) $rc['max_participants'] > 0 && (int) $rc['approved_count'] >= (int) $rc['max_participants']; ?>
                    <option value="<?= $rc['id'] ?>" <?= $catFull ? 'disabled' : '' ?>>
                        <?= e($rc['name']) ?> — <?= currency($rc['fee']) ?><?= $catFull ? ' (Full)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label for="raceCategory">Race Category *</label>
        </div>
        <?php elseif ($event['category_slug'] === 'seminar-workshop'): ?>
        <div class="form-floating mb-3">
            <input type="text" name="organization" id="regOrganization" class="form-control" placeholder="Organization">
            <label for="regOrganization">Organization / Company (optional)</label>
        </div>
        <div class="form-floating mb-3">
            <input type="text" name="profession" id="regProfession" class="form-control" placeholder="Profession">
            <label for="regProfession">Profession (optional)</label>
        </div>
        <div class="form-floating mb-3">
            <input type="text" name="dietary_preference" id="regDietary" class="form-control" placeholder="Dietary preference">
            <label for="regDietary">Dietary Preference (optional)</label>
        </div>
        <div class="form-floating mb-3">
            <textarea name="special_requirements" id="regSpecial" class="form-control" placeholder="Special requirements" style="height:80px"></textarea>
            <label for="regSpecial">Special Requirements (optional)</label>
        </div>
        <?php endif; ?>

        <?php if ($event['requirements']): ?>
        <div class="alert alert-light border small">
            <strong>Requirements:</strong> <?= nl2br(e($event['requirements'])) ?>
        </div>
        <div class="mb-3">
            <label class="form-label">Event Requirements Upload</label>
            <div class="form-text mt-0 mb-1">Upload required documents requested by the organizer (e.g. valid ID, medical certificate) — this is separate from payment and applies no matter how you pay.</div>
            <input type="file" name="requirements_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" data-preview="#reqPreview">
            <div class="form-text">JPG, PNG, or PDF, max 5MB.</div>
            <div id="reqPreview"></div>
        </div>
        <?php endif; ?>

        <?php if ($event['rules']): ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="agree_rules" id="agreeRules" required>
            <label class="form-check-label small" for="agreeRules">I have read and agree to the event rules.</label>
        </div>
        <?php endif; ?>

        <?php if ($requiresPayment): ?>
        <div class="mb-3">
            <label class="form-label fw-semibold mb-2">Payment Method</label>
            <input type="hidden" name="pay_method_choice" value="online">
            <?php if ($isPayPalEvent): ?>
            <div class="form-text"><strong>PayPal Online Payment</strong> — Pay securely through PayPal. Your payment goes directly to the organizer's PayPal account and is confirmed automatically.</div>
            <?php else: ?>
            <div class="form-text"><strong>PayMongo Online Payment</strong> — Pay securely using GCash, Maya, or Card. Payment will be verified automatically.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="submit" id="regSubmitBtn" class="btn btn-evora-primary w-100"><?= $requiresPayment ? 'Submit Registration & Proceed to Payment' : 'Submit Registration' ?></button>
    </form>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
