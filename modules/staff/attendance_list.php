<?php
Auth::requireRole('staff');
$db = Database::connection();
$userId = Auth::id();

$assignmentsStmt = $db->prepare(
    'SELECT es.id AS event_staff_id, e.id AS event_id, e.name AS event_name
     FROM event_staff es JOIN events e ON e.id = es.event_id
     WHERE es.user_id = :uid
     AND es.id IN (SELECT event_staff_id FROM staff_permissions WHERE permission_key = "record_attendance")
     AND e.status NOT IN ("removed", "archived") -- events an admin removed are not shown to staff
     ORDER BY (e.event_date < CURDATE()), CASE WHEN e.event_date >= CURDATE() THEN e.event_date END, e.event_date DESC'
);
$assignmentsStmt->execute(['uid' => $userId]);
$assignments = $assignmentsStmt->fetchAll();

$eventId = (int) ($_GET['event_id'] ?? ($assignments[0]['event_id'] ?? 0));

$allowed = array_column($assignments, 'event_id');
if (!in_array($eventId, $allowed, true)) {
    $eventId = $allowed[0] ?? 0;
}

$attendance = [];
if ($eventId) {
    $stmt = $db->prepare(
        'SELECT a.*, u.full_name, u.email, s.full_name AS staff_name
         FROM attendance a
         JOIN registrations r ON r.id = a.registration_id
         JOIN participants p ON p.id = r.participant_id
         JOIN users u ON u.id = p.user_id
         JOIN users s ON s.id = a.staff_id
         WHERE a.event_id = :eid
         ORDER BY a.scanned_at DESC'
    );
    $stmt->execute(['eid' => $eventId]);
    $attendance = $stmt->fetchAll();
}

$pageDescription = 'Participants you checked in.';
$pageTitle = 'Attendance Records';
$activeRoute = 'staff-attendance';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php if (empty($assignments)): ?>
    <div class="card-evora p-4 text-center text-muted">You do not have attendance permission for any assigned event.</div>
<?php else: ?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-semibold mb-0">Attendance Records</h6>
        <select class="form-select form-select-sm w-auto mw-100" onchange="location.href=this.value">
            <?php foreach ($assignments as $a): ?>
                <option value="<?= url('staff-attendance', ['event_id' => $a['event_id']]) ?>" <?= $a['event_id'] == $eventId ? 'selected' : '' ?>><?= e($a['event_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <p class="small text-muted">Total checked in: <strong><?= count($attendance) ?></strong></p>
    <?php if (empty($attendance)): ?>
        <?= emptyState('bi-clipboard-x', 'No attendance recorded yet. Participants you check in with the QR scanner appear here.', 'Scan QR Code', url('staff-scan')) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-sm table-datatable">
            <thead><tr><th>Participant</th><th>Email</th><th>Scanned By</th><th>Date/Time</th></tr></thead>
            <tbody>
            <?php foreach ($attendance as $a): ?>
                <tr>
                    <td><?= e($a['full_name']) ?></td>
                    <td><?= e($a['email']) ?></td>
                    <td><?= e($a['staff_name']) ?></td>
                    <td><?= date('M d, Y H:i:s', strtotime($a['scanned_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
