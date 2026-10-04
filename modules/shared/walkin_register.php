<?php
Auth::requireRole(['organizer', 'staff']);
$db = Database::connection();
$userId = Auth::id();
$role = Auth::role();

// Events this operator is allowed to walk-in-register participants into.
if ($role === 'organizer') {
    $orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $orgStmt->execute(['uid' => $userId]);
    $organizer = $orgStmt->fetch();
    $eventsStmt = $db->prepare(
        "SELECT e.*, c.slug AS category_slug,
            (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'approved') AS approved_count
         FROM events e JOIN event_categories c ON c.id = e.category_id
         WHERE e.organizer_id = :oid AND e.status IN ('open','ongoing') ORDER BY e.event_date"
    );
    $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
} else {
    $eventsStmt = $db->prepare(
        "SELECT e.*, c.slug AS category_slug,
            (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'approved') AS approved_count
         FROM events e
         JOIN event_categories c ON c.id = e.category_id
         JOIN event_staff es ON es.event_id = e.id
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         WHERE es.user_id = :uid AND sp.permission_key = 'manage_walkin' AND e.status IN ('open','ongoing')
         ORDER BY e.event_date"
    );
    $eventsStmt->execute(['uid' => $userId]);
}
$events = $eventsStmt->fetchAll();

// Per-event category data embedded for the client-side event->category switch —
// avoids a page reload when the operator picks a different event.
$eventData = [];
foreach ($events as $ev) {
    $entry = [
        'name' => $ev['name'],
        'isFunRun' => $ev['category_slug'] === 'fun-run',
        'fee' => (float) $ev['fee'],
        'maxParticipants' => (int) $ev['max_participants'],
        'approvedCount' => (int) $ev['approved_count'],
        'raceCategories' => [],
    ];
    if ($entry['isFunRun']) {
        $rcStmt = $db->prepare(
            "SELECT rc.*, (SELECT COUNT(*) FROM registrations r WHERE r.race_category_id = rc.id AND r.status = 'approved') AS approved_count
             FROM race_categories rc WHERE rc.event_id = :id ORDER BY rc.fee, rc.name"
        );
        $rcStmt->execute(['id' => $ev['id']]);
        foreach ($rcStmt->fetchAll() as $rc) {
            $entry['raceCategories'][] = [
                'id' => (int) $rc['id'], 'name' => $rc['name'], 'fee' => (float) $rc['fee'],
                'max' => (int) $rc['max_participants'], 'approved' => (int) $rc['approved_count'],
            ];
        }
    }
    $eventData[(int) $ev['id']] = $entry;
}

// --- Participant lookup state ---
$q = trim($_GET['q'] ?? '');
$participantId = (int) ($_GET['participant_id'] ?? 0);
$isNew = isset($_GET['new']);
$selectedParticipant = null;
$searchResults = [];

if ($participantId) {
    $pStmt = $db->prepare(
        'SELECT p.id AS participant_id, u.full_name, u.email, u.phone
         FROM participants p JOIN users u ON u.id = p.user_id WHERE p.id = :id'
    );
    $pStmt->execute(['id' => $participantId]);
    $selectedParticipant = $pStmt->fetch();
    if (!$selectedParticipant) {
        flash('error', 'Participant not found.');
        redirect('walkin-register');
    }
} elseif ($q !== '') {
    $searchStmt = $db->prepare(
        'SELECT p.id AS participant_id, u.full_name, u.email, u.phone
         FROM participants p JOIN users u ON u.id = p.user_id
         WHERE u.email = :q1 OR u.phone = :q2 ORDER BY u.full_name LIMIT 10'
    );
    $searchStmt->execute(['q1' => $q, 'q2' => $q]);
    $searchResults = $searchStmt->fetchAll();
}

$showRegistrationForm = $selectedParticipant || $isNew;

$pageDescription = 'Register a participant on site and record a cash payment.';
$pageTitle = 'Walk-in Registration';
$activeRoute = 'walkin-register';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4 mb-3 mw-form-lg">
    <h6 class="fw-semibold mb-3"><i class="bi bi-person-plus-fill text-primary me-1"></i>Walk-in Registration</h6>
    <p class="text-muted small">For participants registering physically on event day. Search first to avoid creating a duplicate account.</p>

    <?php if (!$showRegistrationForm): ?>
    <form method="get" action="<?= url('walkin-register') ?>" class="d-flex gap-2 mb-3">
        <input type="hidden" name="route" value="walkin-register">
        <input type="text" name="q" class="form-control" placeholder="Search by email or mobile number" value="<?= e($q) ?>">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i> Search</button>
    </form>

    <?php if ($q !== ''): ?>
        <?php if (empty($searchResults)): ?>
            <div class="alert alert-light border small">No existing participant matches "<?= e($q) ?>".</div>
        <?php else: ?>
        <div class="list-group mb-3">
            <?php foreach ($searchResults as $r): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?= e($r['full_name']) ?></div>
                        <div class="text-muted small"><?= e($r['email']) ?><?= $r['phone'] ? ' &bull; ' . e($r['phone']) : '' ?></div>
                    </div>
                    <a href="<?= url('walkin-register', ['participant_id' => $r['participant_id']]) ?>" class="btn btn-sm btn-evora-primary">Use This Participant</a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <a href="<?= url('walkin-register', ['new' => 1]) ?>" class="btn btn-outline-secondary btn-sm">None of these — Register New Participant</a>
    <?php else: ?>
        <a href="<?= url('walkin-register', ['new' => 1]) ?>" class="btn btn-outline-secondary btn-sm">Skip search — Register New Participant</a>
    <?php endif; ?>

    <?php else: ?>
    <a href="<?= url('walkin-register') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Search again</a>

    <?php if (empty($events)): ?>
        <?= emptyState('bi-calendar-x', 'No open events available for walk-in registration. ' . ($role === 'staff' ? 'Ask your organizer to grant you Walk-in Registration permission on an event.' : 'Open registration on an event first.')) ?>
    <?php else: ?>
    <form method="post" action="<?= url('walkin-register-save') ?>">
        <?= Csrf::field() ?>

        <?php if ($selectedParticipant): ?>
            <input type="hidden" name="participant_id" value="<?= $selectedParticipant['participant_id'] ?>">
            <div class="alert alert-light border small">
                <strong><?= e($selectedParticipant['full_name']) ?></strong><br>
                <?= e($selectedParticipant['email']) ?><?= $selectedParticipant['phone'] ? ' &bull; ' . e($selectedParticipant['phone']) : '' ?>
            </div>
        <?php else: ?>
            <h6 class="text-uppercase text-muted small fw-bold mb-2">New Participant</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="form-floating">
                        <input type="text" name="full_name" class="form-control" placeholder="Full Name" required>
                        <label>Full Name *</label>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="form-floating">
                        <input type="text" name="mobile_number" class="form-control" placeholder="Mobile Number" required>
                        <label>Mobile Number *</label>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="form-floating">
                        <input type="email" name="email" class="form-control" placeholder="Email (optional)">
                        <label>Email (optional)</label>
                    </div>
                    <div class="form-text">Leave blank if the participant has no email — a placeholder will be generated.</div>
                </div>
            </div>
        <?php endif; ?>

        <h6 class="text-uppercase text-muted small fw-bold mb-2 mt-2">Event &amp; Category</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <select name="event_id" id="wiEvent" class="form-select" required>
                        <option value="">Select event</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?= $ev['id'] ?>"><?= e($ev['name']) ?> — <?= date('M d, Y', strtotime($ev['event_date'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="wiEvent">Event *</label>
                </div>
            </div>
            <div class="col-md-6 mb-3" id="wiCategoryWrap" style="display:none;">
                <div class="form-floating">
                    <select name="race_category_id" id="wiCategory" class="form-select">
                        <option value="">Select category</option>
                    </select>
                    <label for="wiCategory">Race Category *</label>
                </div>
            </div>
        </div>

        <div class="alert alert-light border small" id="wiFeeBlock" style="display:none;">
            Amount Due: <strong id="wiFeeAmount">₱0.00</strong>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-2">Payment</h6>
        <div class="alert alert-light border small">
            <strong>Payment Method: Cash Walk-in</strong><br>
            <span class="text-muted">Payment is recorded as received on the spot and the registration is approved immediately.</span>
        </div>

        <button type="submit" class="btn btn-evora-primary w-100"><i class="bi bi-check-circle"></i> Complete Walk-in Registration</button>
    </form>

    <script>
    const wiEventData = <?= json_encode($eventData) ?>;
    const eventSelect = document.getElementById('wiEvent');
    const categoryWrap = document.getElementById('wiCategoryWrap');
    const categorySelect = document.getElementById('wiCategory');
    const feeBlock = document.getElementById('wiFeeBlock');
    const feeAmount = document.getElementById('wiFeeAmount');

    function formatPeso(n) {
        return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function syncEvent() {
        const ev = wiEventData[eventSelect.value];
        categorySelect.innerHTML = '<option value="">Select category</option>';
        if (!ev) {
            categoryWrap.style.display = 'none';
            categorySelect.required = false;
            feeBlock.style.display = 'none';
            return;
        }
        if (ev.isFunRun) {
            categoryWrap.style.display = '';
            categorySelect.required = true;
            ev.raceCategories.forEach(function (rc) {
                const full = rc.max > 0 && rc.approved >= rc.max;
                const opt = document.createElement('option');
                opt.value = rc.id;
                opt.textContent = rc.name + ' — ' + formatPeso(rc.fee) + (full ? ' (Full)' : '');
                opt.disabled = full;
                opt.dataset.fee = rc.fee;
                categorySelect.appendChild(opt);
            });
            feeBlock.style.display = 'none';
        } else {
            categoryWrap.style.display = 'none';
            categorySelect.required = false;
            feeAmount.textContent = formatPeso(ev.fee);
            feeBlock.style.display = ev.fee > 0 ? '' : 'none';
        }
    }

    categorySelect.addEventListener('change', function () {
        const opt = categorySelect.options[categorySelect.selectedIndex];
        if (opt && opt.dataset.fee !== undefined) {
            feeAmount.textContent = formatPeso(opt.dataset.fee);
            feeBlock.style.display = '';
        } else {
            feeBlock.style.display = 'none';
        }
    });

    eventSelect.addEventListener('change', syncEvent);
    syncEvent();
    </script>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
