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
             WHERE e.organizer_id = :oid AND c.slug = 'seminar-workshop' ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['oid' => $organizer['id'] ?? 0]);
    } else {
        $eventsStmt = $db->prepare(
            "SELECT e.id, e.name, e.event_date FROM events e
             JOIN event_categories c ON c.id = e.category_id
             JOIN event_staff es ON es.event_id = e.id
             JOIN staff_permissions sp ON sp.event_staff_id = es.id
             WHERE es.user_id = :uid AND sp.permission_key = 'manage_sessions' AND c.slug = 'seminar-workshop'
             ORDER BY e.event_date DESC"
        );
        $eventsStmt->execute(['uid' => $userId]);
    }
    $events = $eventsStmt->fetchAll();

    $pageDescription = 'Choose a seminar to scan session attendance.';
    $pageTitle = 'Session Attendance Scan';
    $activeRoute = 'session-attendance-scan';
    include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
    ?>
    <?php renderBreadcrumb([['label' => 'Session Attendance Scan']]); ?>
    <?php if ($role === 'staff'): ?>
    <a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
    <?php endif; ?>
    <div class="card-evora p-4">
        <h6 class="fw-semibold mb-3">Choose an Event</h6>
        <?php if (empty($events)): ?>
            <?= emptyState('bi-qr-code-scan', 'No seminar/workshop events available to you for session attendance.') ?>
        <?php else: ?>
        <div class="list-group">
            <?php foreach ($events as $ev): ?>
                <a href="<?= url('session-attendance-scan', ['event_id' => $ev['id']]) ?>" class="list-group-item list-group-item-action">
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
         WHERE e.id = :id AND es.user_id = :uid AND sp.permission_key = 'manage_sessions'"
    );
    $eventStmt->execute(['id' => $eventId, 'uid' => $userId]);
}
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found or you do not have session attendance access to it.');
    redirect($role === 'organizer' ? 'organizer-events' : 'staff-dashboard');
}

EventAccess::requireEventType($db, $eventId, 'seminar-workshop', $role === 'organizer' ? 'organizer-events' : 'staff-dashboard');

$sessionsStmt = $db->prepare(
    "SELECT * FROM event_schedule WHERE event_id = :eid AND status != 'cancelled' ORDER BY session_date IS NULL, session_date, start_time"
);
$sessionsStmt->execute(['eid' => $eventId]);
$sessions = $sessionsStmt->fetchAll();

$pageDescription = 'Scan participant QR codes to record who attended each session.';
$pageTitle = 'Session Attendance Scan';
$activeRoute = 'session-attendance-scan';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'Session Attendance Scan', 'route' => 'session-attendance-scan'],
    ['label' => $event['name']],
]); ?>
<?php if ($role === 'staff'): ?>
<a href="<?= url('staff-dashboard') ?>" class="text-decoration-none small mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Staff Dashboard</a>
<?php endif; ?>

<?php if (empty($sessions)): ?>
    <div class="card-evora p-4"><?= emptyState('bi-calendar-x', 'No sessions created for this seminar yet. Sessions must exist before attendance can be scanned.', $role === 'organizer' ? 'Add a Session' : null, $role === 'organizer' ? url('organizer-schedule', ['event_id' => $eventId]) : null) ?></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-evora p-4">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-qr-code-scan text-primary me-1" aria-hidden="true"></i> Scan Participant QR</h2>
            <div class="mb-3">
                <label class="form-label small" for="sessionSelect">Session</label>
                <select id="sessionSelect" class="form-select">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['session_title']) ?> (<?= date('h:i A', strtotime($s['start_time'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="qr-reader" class="qr-reader-box frame-bordered"></div>
            <div class="mt-3">
                <label class="form-label small" for="manualToken">Or enter QR text manually</label>
                <div class="input-group">
                    <input type="text" id="manualToken" class="form-control form-control-sm" placeholder="Paste QR token here">
                    <button type="button" class="btn btn-evora-primary btn-sm" id="manualSubmit">Record Attendance</button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-evora p-4 mb-3 d-none" id="resultCard">
            <div id="resultBody" aria-live="polite"></div>
        </div>
        <div class="card-evora p-4">
            <h2 class="h6 fw-semibold mb-3"><i class="bi bi-clock-history text-primary me-1" aria-hidden="true"></i> Recent Scans</h2>
            <p class="small text-muted mb-0" id="recentEmpty">No scans yet in this session. Results appear here as you scan.</p>
            <ul class="list-unstyled small mb-0" id="recentScans"></ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const csrfToken = "<?= Csrf::token() ?>";
const scanUrl = "<?= url('session-attendance-action') ?>";
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
    document.getElementById('recentEmpty').classList.add('d-none');
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

function recordAttendance(value) {
    const sessionId = document.getElementById('sessionSelect').value;
    fetch(scanUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${encodeURIComponent(csrfToken)}&event_id=${encodeURIComponent(eventId)}&session_id=${encodeURIComponent(sessionId)}&value=${encodeURIComponent(value)}`
    }).then(r => r.json()).then(data => {
        renderResult(data);
        logScan(data.success, data.message);
    }).catch(() => {
        renderResult({ success: false, message: 'Network or server error.' });
    });
}

document.getElementById('manualSubmit').addEventListener('click', function () {
    const val = document.getElementById('manualToken').value.trim();
    if (val) { recordAttendance(val); document.getElementById('manualToken').value = ''; }
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
                recordAttendance(decodedText);
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
