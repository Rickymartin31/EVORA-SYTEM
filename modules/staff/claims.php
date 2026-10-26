<?php
Auth::requireRole('staff');
$db = Database::connection();
$userId = Auth::id();

$assignmentsStmt = $db->prepare(
    'SELECT es.id AS event_staff_id, e.id AS event_id, e.name AS event_name
     FROM event_staff es JOIN events e ON e.id = es.event_id
     WHERE es.user_id = :uid
     AND es.id IN (SELECT event_staff_id FROM staff_permissions WHERE permission_key = "manage_claims")
     AND e.status NOT IN ("removed", "archived") -- events an admin removed are not shown to staff
     ORDER BY (e.event_date < CURDATE()), CASE WHEN e.event_date >= CURDATE() THEN e.event_date END, e.event_date DESC'
);
$assignmentsStmt->execute(['uid' => $userId]);
$assignments = $assignmentsStmt->fetchAll();

$itemsByEvent = [];
foreach ($assignments as $a) {
    $itemsStmt = $db->prepare('SELECT * FROM event_items WHERE event_id = :eid ORDER BY item_name, id');
    $itemsStmt->execute(['eid' => $a['event_id']]);
    $itemsByEvent[$a['event_id']] = $itemsStmt->fetchAll();
}
// v71: the claiming schedule set by the organizer (items can still be released outside it)
$schedulesByEvent = ClaimScheduleService::slotsForEvents($db, array_column($assignments, 'event_id'));

$pageDescription = 'Release event kits and items to participants.';
$pageTitle = 'Item Releasing';
$activeRoute = 'staff-claims';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (empty($assignments)): ?>
    <div class="card-evora p-4 text-center text-muted">You do not have item-claiming permission for any assigned event.</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Scan to Release Item</h6>
            <div class="mb-3">
                <label class="form-label small">Event</label>
                <select id="eventSelect" class="form-select">
                    <?php foreach ($assignments as $a): ?>
                        <option value="<?= $a['event_id'] ?>"><?= e($a['event_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small">Item</label>
                <select id="itemSelect" class="form-select">
                    <?php foreach ($itemsByEvent as $eid => $items): ?>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= $item['id'] ?>" data-event="<?= $eid ?>" class="item-opt-<?= $eid ?>">
                                <?= e(EventItemService::label($item)) ?> (<?= max(0, $item['total_quantity'] - $item['claimed_quantity']) ?> left)
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($assignments as $a): $slots = ClaimScheduleService::upcoming($schedulesByEvent[(int) $a['event_id']] ?? []); ?>
                <div class="claim-sched small mb-3" data-event="<?= (int) $a['event_id'] ?>" hidden>
                    <?php if ($slots): ?>
                        <div class="fw-semibold mb-1"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Claiming schedule</div>
                        <ul class="mb-1 ps-3">
                            <?php foreach ($slots as $slot): ?>
                                <li<?= $slot['claim_date'] === date('Y-m-d') ? ' class="fw-semibold"' : '' ?>><?= e(ClaimScheduleService::slotWhen($slot)) ?> · <?= e($slot['location']) ?><?= $slot['claim_date'] === date('Y-m-d') ? ' <span class="badge bg-success">Today</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="text-muted">Items can still be released outside these times, including on event day.</div>
                    <?php else: ?>
                        <div class="text-muted"><i class="bi bi-calendar me-1" aria-hidden="true"></i>No upcoming claiming schedule.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div id="qr-reader" style="width:100%;"></div>
            <div class="mt-3">
                <label class="form-label small">Or enter QR text manually</label>
                <div class="input-group">
                    <input type="text" id="manualToken" class="form-control form-control-sm" placeholder="Paste QR token here">
                    <button class="btn btn-outline-secondary btn-sm" id="manualSubmit">Verify &amp; Release</button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-evora p-4" id="resultCard" style="display:none;">
            <h6 class="fw-semibold mb-3">Result</h6>
            <div id="resultBody"></div>
        </div>
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Recent Claims</h6>
            <ul class="list-unstyled small mb-0" id="recentClaims"></ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const csrfToken = "<?= Csrf::token() ?>";
const claimUrl = "<?= url('staff-claim-action') ?>";

function filterItems() {
    const eventId = document.getElementById('eventSelect').value;
    document.querySelectorAll('#itemSelect option').forEach(opt => {
        opt.hidden = opt.dataset.event !== eventId;
    });
    document.querySelectorAll('.claim-sched').forEach(el => { el.hidden = el.dataset.event !== eventId; });
    const firstVisible = document.querySelector(`#itemSelect option[data-event="${eventId}"]`);
    document.getElementById('itemSelect').value = firstVisible ? firstVisible.value : '';
}
document.getElementById('eventSelect').addEventListener('change', filterItems);
filterItems();

function renderResult(data) {
    const card = document.getElementById('resultCard');
    const body = document.getElementById('resultBody');
    card.style.display = 'block';
    if (data.valid) {
        body.innerHTML = `<div class="alert alert-success mb-2"><i class="bi bi-check-circle"></i> ITEM RELEASED</div>
            <p class="mb-0">${data.message}</p>`;
        const li = document.createElement('li');
        li.className = 'border-bottom py-2 text-success';
        li.textContent = `✔ ${data.message} — ${new Date().toLocaleTimeString()}`;
        document.getElementById('recentClaims').prepend(li);
    } else {
        body.innerHTML = `<div class="alert alert-danger mb-2"><i class="bi bi-x-circle"></i> NOT RELEASED</div>
            <p class="mb-0">${data.message}</p>`;
        const li = document.createElement('li');
        li.className = 'border-bottom py-2 text-danger';
        li.textContent = `✘ ${data.message} — ${new Date().toLocaleTimeString()}`;
        document.getElementById('recentClaims').prepend(li);
    }
}

function claimToken(token) {
    const eventId = document.getElementById('eventSelect').value;
    const itemId = document.getElementById('itemSelect').value;
    if (!itemId) { renderResult({ valid: false, message: 'No item selected for this event.' }); return; }
    fetch(claimUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf_token=${encodeURIComponent(csrfToken)}&token=${encodeURIComponent(token)}&event_id=${encodeURIComponent(eventId)}&item_id=${encodeURIComponent(itemId)}`
    }).then(r => r.json()).then(data => {
        renderResult(data);
        if (data.valid) {
            const opt = document.querySelector(`#itemSelect option[value="${itemId}"]`);
            const match = opt ? opt.textContent.match(/^(.*)\((\d+) left\)\s*$/) : null;
            if (match) {
                const remaining = Math.max(0, parseInt(match[2], 10) - 1);
                opt.textContent = `${match[1]}(${remaining} left)`;
            }
        }
    }).catch(() => {
        renderResult({ valid: false, message: 'Network or server error.' });
    });
}

document.getElementById('manualSubmit').addEventListener('click', function () {
    const val = document.getElementById('manualToken').value.trim();
    if (val) claimToken(val);
});

let lastToken = null, lastTime = 0;
const html5QrCode = new Html5Qrcode("qr-reader");
Html5Qrcode.getCameras().then(cameras => {
    if (cameras && cameras.length) {
        html5QrCode.start(cameras[0].id, { fps: 10, qrbox: 220 }, (decodedText) => {
            const now = Date.now();
            if (decodedText === lastToken && (now - lastTime) < 4000) return;
            lastToken = decodedText; lastTime = now;
            claimToken(decodedText);
        }, () => {});
    } else {
        document.getElementById('qr-reader').innerHTML = '<p class="text-muted small">No camera found. Use manual entry below.</p>';
    }
}).catch(() => {
    document.getElementById('qr-reader').innerHTML = '<p class="text-muted small">Camera access unavailable. Use manual entry below.</p>';
});
</script>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
