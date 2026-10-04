<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.*, c.slug AS category_slug FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

$staffStmt = $db->prepare(
    'SELECT es.*, u.full_name, u.email, u.phone
     FROM event_staff es JOIN users u ON u.id = es.user_id
     WHERE es.event_id = :eid ORDER BY es.created_at DESC'
);
$staffStmt->execute(['eid' => $eventId]);
$staffList = $staffStmt->fetchAll();

$permStmt = $db->prepare('SELECT permission_key FROM staff_permissions WHERE event_staff_id = :id');

// Full lookup — used to label already-granted permissions (including any
// legacy/cross-category grant) in the "Staff Assigned" table below, so an
// existing record never silently loses its display label.
$allPermissions = [
    'scan_qr' => 'Scan QR Code',
    'verify_participant' => 'Verify Participants',
    'record_attendance' => 'Record Attendance',
    'manage_claims' => 'Manage Item Releasing',
    'view_announcements' => 'View Announcements',
    'manage_walkin' => 'Walk-in Registration',
    'manage_live_scores' => 'Live Match Scoring (Sports)',
    'manage_incidents' => 'Incident Reports (Sports)',
    'manage_bibs' => 'Bib Assignment (Fun Run)',
    'manage_finish_results' => 'Finish-Line Recording (Fun Run)',
    'manage_checkpoints' => 'Checkpoint Scanning (Fun Run)',
    'manage_medical' => 'Medical/Emergency Logging (Fun Run)',
    'view_race_operations' => 'Race Operations Dashboard (Fun Run)',
    'manage_sessions' => 'Session Management & Attendance (Seminar)',
    'manage_speakers' => 'Speaker Management (Seminar)',
    'manage_feedback' => 'Feedback Summary (Seminar)',
];

// General permissions apply to every event type; category permissions are
// only relevant (and only offered on the Add Staff form below) for the
// event's own category, so organizers can't accidentally assign a tool
// permission that doesn't apply to this event.
$generalPermissions = [
    'scan_qr' => 'Scan QR Code',
    'verify_participant' => 'Verify Participants',
    'record_attendance' => 'Record Attendance',
    'manage_claims' => 'Manage Item Releasing',
    'view_announcements' => 'View Announcements',
    'manage_walkin' => 'Walk-in Registration',
];
$categoryPermissions = [
    'sports-tournament' => [
        'manage_live_scores' => 'Live Match Scoring (Sports)',
        'manage_incidents' => 'Incident Reports (Sports)',
    ],
    'fun-run' => [
        'manage_bibs' => 'Bib Assignment (Fun Run)',
        'manage_finish_results' => 'Finish-Line Recording (Fun Run)',
        'manage_checkpoints' => 'Checkpoint Scanning (Fun Run)',
        'manage_medical' => 'Medical/Emergency Logging (Fun Run)',
        'view_race_operations' => 'Race Operations Dashboard (Fun Run)',
    ],
    'seminar-workshop' => [
        'manage_sessions' => 'Session Management & Attendance (Seminar)',
        'manage_speakers' => 'Speaker Management (Seminar)',
        'manage_feedback' => 'Feedback Summary (Seminar)',
    ],
];
$formPermissions = $generalPermissions + ($categoryPermissions[$event['category_slug']] ?? []);

$taskStatusLabels = [
    'not_started' => ['label' => 'Not Started', 'badge' => 'bg-secondary'],
    'in_progress' => ['label' => 'In Progress', 'badge' => 'bg-warning text-dark'],
    'completed' => ['label' => 'Completed', 'badge' => 'bg-success'],
];

$pageDescription = 'Assign staff to your events and choose what they can do.';
$pageTitle = 'Manage Staff';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Manage Staff'],
]); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Staff Assigned to This Event</h6>
            <?php if (empty($staffList)): ?>
                <?= emptyState('bi-person-badge', 'No staff assigned yet. Add staff to help with check-in, claims and event operations.') ?>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle table-sm table-datatable">
                    <thead><tr><th>Name</th><th>Assignment / Task</th><th>Status</th><th>Permissions</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($staffList as $s): ?>
                        <?php $permStmt->execute(['id' => $s['id']]); $perms = array_column($permStmt->fetchAll(), 'permission_key'); ?>
                        <tr>
                            <td><?= e($s['full_name']) ?><br><span class="text-muted small"><?= e($s['email']) ?></span></td>
                            <td class="small">
                                <?= e($s['assignment_area'] ?: '—') ?>
                                <?php if ($s['task_note']): ?><br><span class="text-muted"><?= e($s['task_note']) ?></span><?php endif; ?>
                            </td>
                            <td style="min-width:170px;">
                                <form method="post" action="<?= url('organizer-staff-task-update') ?>" class="d-flex gap-1">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_staff_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="assignment_area" value="<?= e($s['assignment_area'] ?? '') ?>">
                                    <input type="hidden" name="task_note" value="<?= e($s['task_note'] ?? '') ?>">
                                    <select name="task_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach ($taskStatusLabels as $key => $info): ?>
                                            <option value="<?= $key ?>" <?= $s['task_status'] === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <?php foreach ($perms as $p): ?>
                                    <span class="badge bg-light text-dark border me-1 mb-1"><?= e($allPermissions[$p] ?? $p) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= url('organizer-staff-delete') ?>" data-confirm="Remove this staff member from the event?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="event_staff_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-evora p-4">
            <h6 class="fw-semibold mb-3">Add Staff Member</h6>
            <form method="post" action="<?= url('organizer-staff-save') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-2">
                    <label class="form-label small">Full Name *</label>
                    <input type="text" name="full_name" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Email *</label>
                    <input type="email" name="email" class="form-control form-control-sm" required>
                    <div class="form-text">If this email already belongs to a staff account, they'll simply be assigned to this event.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Phone</label>
                    <input type="text" name="phone" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Assignment Area</label>
                    <input type="text" name="assignment_area" class="form-control form-control-sm" placeholder="e.g. Registration Booth">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Task</label>
                    <input type="text" name="task_note" class="form-control form-control-sm" placeholder="e.g. Verify participants">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Temporary Password *</label>
                    <input type="text" name="password" class="form-control form-control-sm" required minlength="8">
                    <div class="form-text">Share this with the staff member (used only if this is a new account).</div>
                </div>
                <label class="form-label small">Permissions</label>
                <?php foreach ($formPermissions as $key => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $key ?>" id="perm_<?= $key ?>" checked>
                        <label class="form-check-label small" for="perm_<?= $key ?>"><?= e($label) ?></label>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-evora-primary btn-sm mt-3 w-100">Add Staff</button>
            </form>
        </div>
    </div>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
