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
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_categories c ON c.id = e.category_id
             WHERE e.organizer_id = :oid AND c.slug = 'fun-run' ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
    } else {
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_categories c ON c.id = e.category_id
             JOIN event_staff es ON es.event_id = e.id
             JOIN staff_permissions sp ON sp.event_staff_id = es.id
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_checkpoints' AND c.slug = 'fun-run'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a fun run to scan runners at a checkpoint.';
    $pageTitle = 'Checkpoint Scan';
    $activeRoute = 'checkpoint-scan';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Checkpoint Scan']]); ?>
    <?php if ($role === 'staff'): ?>
    <a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
    <?php endif; ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-signpost-split', 'No fun run events available to you for checkpoint scanning.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('checkpoint-scan', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_checkpoints'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have checkpoint scanning access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'fun-run', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$checkpointsStmt = $db->prepare("SELECT * FROM event_checkpoints WHERE event_id = :eid AND status = 'active' ORDER BY sequence_no");
$checkpointsStmt->execute(['eid' => $eventId]);
$checkpoints = $checkpointsStmt->fetchAll();

$pageDescription = 'Scan runner QR codes or bibs as they pass this checkpoint.';
$pageTitle = 'Checkpoint Scan';
$activeRoute = 'checkpoint-scan';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Checkpoint Scan', 'route' => 'checkpoint-scan'],
    ['label' => $event['name']],
]); ?>
<?php if ($role === 'staff'): ?>
<a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
<?php endif; ?>

<?php if (empty($checkpoints)): ?>
    <div class="card-evora"><?= emptyState('bi-signpost-split', 'No active checkpoints are defined for this event yet. The organizer sets them up under Checkpoints.') ?></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Scan Runner BIB / QR</h6>
            <div class="mb-3">
                <label class="form-label small">Checkpoint</label>
                <select id="checkpointSelect" class="form-select">
                    <?php foreach ($checkpoints as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['label']) ?><?= $c['distance_km'] !== null ? ' (KM ' . number_format((float) $c['distance_km'], 1) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="qr-reader" class="qr-reader-box frame-bordered"></div>
            <div class="mt-3">
                <label class="form-label small">Or enter BIB number / QR text manually</label>
                <div class="input-group">
                    <input type="text" id="manualToken" class="form-control form-control-sm" placeholder="BIB number or QR token">
                    <button class="btn btn-outline-secondary btn-sm" id="manualSubmit">Record Passage</button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-evora p-4 mb-3 d-none" id="resultCard">
            <div id="resultBody" aria-live="polite"></div>
        </div>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Recent Scans</h6>
            <ul class="list-unstyled small mb-0" id="recentScans"></ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const csrfToken = "<?= Csrf::token() ?>";
const scanUrl = "<?= url('checkpoint-scan-action') ?>";
const eventId = <?= (int) $eventId ?>;
let lastToken = null;
let lastTime = 0;

function logScan(success, text) {
    const li = document.createElement('li');
    li.className = 'border-bottom py-2 d-flex gap-2 align-items-start';
    const badge = document.createElement('span');
    badge.className = 'badge ' + (success ? 'bg-success' : 'bg-danger');
    badge.textContent = success ? 'Recorded' : 'Failed';
    const msg = document.createElement('span');
    msg.className = 'flex-grow-1';
    msg.textContent = text;
    const time = document.createElement('span');
    time.className = 'text-muted nowrap';
    time.textContent = new Date().toLocaleTimeString();
    li.append(badge, msg, time);
    document.getElementById('recentScans').prepend(li);
}

function renderResult(data) {
    const card = document.getElementById('resultCard');
    const body = document.getElementById('resultBody');
    card.classList.remove('d-none');
    const alert = document.createElement('div');
    alert.className = 'alert mb-0 ' + (data.success ? 'alert-success' : 'alert-danger');
    const icon = document.createElement('i');
    icon.className = 'bi me-1 ' + (data.success ? 'bi-check-circle' : 'bi-x-circle');
    icon.setAttribute('aria-hidden', 'true');
    alert.append(icon, document.createTextNode(data.message));
    body.replaceChildren(alert);
}

function recordPassage(value) {
    const checkpointId = document.getElementById('checkpointSelect').value;
    fetch(scanUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${encodeURIComponent(csrfToken)}&event_id=${encodeURIComponent(eventId)}&checkpoint_id=${encodeURIComponent(checkpointId)}&value=${encodeURIComponent(value)}`
    }).then(r => r.json()).then(data => {
        renderResult(data);
        logScan(data.success, data.success ? data.message : data.message);
    }).catch(() => {
        renderResult({ success: false, message: 'Network or server error.' });
    });
}

document.getElementById('manualSubmit').addEventListener('click', function () {
    const val = document.getElementById('manualToken').value.trim();
    if (val) { recordPassage(val); document.getElementById('manualToken').value = ''; }
});

const html5QrCode = new Html5Qrcode("qr-reader");
Html5Qrcode.getCameras().then(cameras => {
    if (cameras && cameras.length) {
        html5QrCode.start(
            cameras[0].id,
            { fps: 10, qrbox: 220 },
            (decodedText) => {
                const now = Date.now();
                if (decodedText === lastToken && (now - lastTime) < 4000) return;
                lastToken = decodedText;
                lastTime = now;
                recordPassage(decodedText);
            },
            () => {}
        );
    } else {
        document.getElementById('qr-reader').innerHTML = '<p class="text-muted small">No camera found. Use manual entry below.</p>';
    }
}).catch(() => {
    document.getElementById('qr-reader').innerHTML = '<p class="text-muted small">Camera access unavailable. Use manual entry below.</p>';
});
</script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
