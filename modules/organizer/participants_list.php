<?php
/**
 * Participants roster (v57): everyone who joined one event, for viewing, searching, printing and
 * exporting. Approving and payments stay on Registrations; this page only reads.
 * GET event_id, status = approved (default) | pending | all, format = xlsx | pdf (download).
 */
Auth::requireRole('organizer');
$db = Database::connection();

$eventStmt = $db->prepare('SELECT e.*, c.slug AS category_slug FROM events e JOIN organizers o ON o.id = e.organizer_id
                           JOIN event_categories c ON c.id = e.category_id WHERE e.id = :id AND o.user_id = :uid');
$eventStmt->execute(['id' => (int) ($_GET['event_id'] ?? 0), 'uid' => Auth::id()]);
$event = $eventStmt->fetch();
if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}
$eventId = (int) $event['id'];
$isRun = $event['category_slug'] === 'fun-run';
$isSports = $event['category_slug'] === 'sports-tournament';
$checkInLabel = $event['category_slug'] === 'seminar-workshop' ? 'Attended' : 'Checked In';

$filter = in_array($_GET['status'] ?? '', ['pending', 'all'], true) ? $_GET['status'] : 'approved';
$sql = 'SELECT r.id, r.status, r.bib_number, r.distance_category, r.registered_at, u.full_name, u.email, u.phone,
               rc.name AS race_category, COALESCE(rc.fee, e.fee) AS fee,
               COALESCE(t.team_name, (SELECT t2.team_name FROM team_members tm JOIN teams t2 ON t2.id = tm.team_id
                                      WHERE tm.participant_id = r.participant_id AND t2.event_id = r.event_id LIMIT 1)) AS team_name,
               (SELECT pay.status FROM payments pay WHERE pay.registration_id = r.id ORDER BY pay.id DESC LIMIT 1) AS payment_status,
               (SELECT MIN(a.scanned_at) FROM attendance a WHERE a.registration_id = r.id AND a.status = "present") AS checked_in_at
        FROM registrations r
        JOIN participants p ON p.id = r.participant_id
        JOIN users u ON u.id = p.user_id
        JOIN events e ON e.id = r.event_id
        LEFT JOIN race_categories rc ON rc.id = r.race_category_id
        LEFT JOIN teams t ON t.id = r.team_id
        WHERE r.event_id = :eid';
$sql .= $filter === 'all' ? ' AND r.status IN ("approved", "pending")' : ' AND r.status = :status';
$sql .= $isRun ? ' ORDER BY r.bib_number IS NULL, r.bib_number, u.full_name' : ' ORDER BY u.full_name';
$stmt = $db->prepare($sql);
$stmt->execute(['eid' => $eventId] + ($filter === 'all' ? [] : ['status' => $filter]));
$people = $stmt->fetchAll();

$countStmt = $db->prepare('SELECT status, COUNT(*) c FROM registrations WHERE event_id = :e AND status IN ("approved", "pending") GROUP BY status');
$countStmt->execute(['e' => $eventId]);
$counts = array_column($countStmt->fetchAll(), 'c', 'status') + ['approved' => 0, 'pending' => 0];

$paymentLabel = function (array $p): string {
    if ((float) $p['fee'] <= 0) {
        return 'Free';
    }
    return match ($p['payment_status']) {
        'approved' => 'Paid',
        'pending' => 'Pending',
        'refunded' => 'Refunded',
        null => 'Not paid',
        default => 'Not paid (' . $p['payment_status'] . ')',
    };
};
$category = fn (array $p): string => (string) ($p['race_category'] ?: $p['distance_category'] ?: '');

// ---- downloads
$format = $_GET['format'] ?? '';
if (in_array($format, ['xlsx', 'pdf'], true)) {
    $columns = array_merge(['#'], $isRun ? ['Bib'] : [], ['Name'], $isSports ? ['Team'] : ['Category'], ['Contact', 'Email', 'Payment', $checkInLabel, 'Status']);
    $rows = [];
    foreach ($people as $i => $p) {
        $rows[] = array_merge([$i + 1], $isRun ? [$p['bib_number'] ?: '—'] : [], [$p['full_name']], [$isSports ? ($p['team_name'] ?: '—') : ($category($p) ?: '—')],
            [$p['phone'] ?: '—', $p['email'], $paymentLabel($p), $p['checked_in_at'] ? date('M d, g:i A', strtotime($p['checked_in_at'])) : 'No', ucfirst($p['status'])]);
    }
    Logger::log(Auth::id(), 'participants_exported', 'Exported the participant list (' . $format . ') of event #' . $eventId);
    $title = 'Participants — ' . $event['name'];
    $format === 'xlsx' ? ReportExporter::excel($title, $columns, $rows) : ReportExporter::pdf($title, $columns, $rows);
}

$pageDescription = 'Everyone who joined this event. To approve or review payments, use Registrations.';
$pageTitle = 'Participants';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => $event['name'], 'route' => 'organizer-event-manage', 'params' => ['id' => $eventId]],
    ['label' => 'Participants'],
]); ?>

<div class="card-evora p-4 participants-roster">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h6 class="fw-semibold mb-0">Participants of <?= e($event['name']) ?></h6>
            <div class="small text-muted"><?= date('M d, Y', strtotime($event['event_date'])) ?><?= $event['venue'] ? ' · ' . e($event['venue']) : '' ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2 d-print-none">
            <a href="<?= url('organizer-participants', ['event_id' => $eventId, 'status' => $filter, 'format' => 'xlsx']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a href="<?= url('organizer-participants', ['event_id' => $eventId, 'status' => $filter, 'format' => 'pdf']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-print-roster><i class="bi bi-printer"></i> Print</button>
            <a href="<?= url('organizer-registrations', ['event_id' => $eventId]) ?>" class="btn btn-sm btn-evora-primary"><i class="bi bi-clipboard-check"></i> Review Registrations</a>
        </div>
    </div>

    <div class="btn-group btn-group-sm mb-3 d-print-none" role="group" aria-label="Show">
        <a href="<?= url('organizer-participants', ['event_id' => $eventId]) ?>" class="btn btn-outline-secondary <?= $filter === 'approved' ? 'active' : '' ?>">Approved (<?= (int) $counts['approved'] ?>)</a>
        <a href="<?= url('organizer-participants', ['event_id' => $eventId, 'status' => 'pending']) ?>" class="btn btn-outline-secondary <?= $filter === 'pending' ? 'active' : '' ?>">Pending (<?= (int) $counts['pending'] ?>)</a>
        <a href="<?= url('organizer-participants', ['event_id' => $eventId, 'status' => 'all']) ?>" class="btn btn-outline-secondary <?= $filter === 'all' ? 'active' : '' ?>">All (<?= (int) $counts['approved'] + (int) $counts['pending'] ?>)</a>
    </div>

    <?php if (!$people): ?>
        <?= emptyState('bi-people', $filter === 'approved' ? 'No approved participants yet.' : 'Nobody here yet.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr>
                <th>#</th>
                <?php if ($isRun): ?><th>Bib</th><?php endif; ?>
                <th>Name</th>
                <th><?= $isSports ? 'Team' : 'Category' ?></th>
                <th>Contact</th>
                <th>Payment</th>
                <th><?= $checkInLabel ?></th>
                <th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($people as $i => $p): ?>
                <?php $pay = $paymentLabel($p); ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <?php if ($isRun): ?><td class="fw-semibold text-nowrap"><?= e($p['bib_number'] ?: '—') ?></td><?php endif; ?>
                    <td><?= e($p['full_name']) ?><div class="small text-muted"><?= e($p['email']) ?></div></td>
                    <td><?= e(($isSports ? $p['team_name'] : $category($p)) ?: '—') ?></td>
                    <td class="text-nowrap"><?= e($p['phone'] ?: '—') ?></td>
                    <td><span class="badge <?= $pay === 'Paid' ? 'bg-success' : ($pay === 'Free' ? 'bg-secondary' : ($pay === 'Pending' ? 'bg-warning text-dark' : 'bg-danger')) ?>"><?= e($pay) ?></span></td>
                    <td><?= $p['checked_in_at'] ? '<i class="bi bi-check-circle-fill text-success"></i> ' . date('M d, g:i A', strtotime($p['checked_in_at'])) : '<span class="text-muted">No</span>' ?></td>
                    <td><span class="badge badge-status-<?= e($p['status']) ?>"><?= ucfirst(e($p['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
// Print every row, not only the current page of the table.
document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-print-roster]')) return;
    var dt = (window.jQuery && jQuery.fn.DataTable && jQuery('.participants-roster table').length) ? jQuery('.participants-roster table').DataTable() : null;
    var len = dt ? dt.page.len() : null;
    if (dt) { dt.search('').page.len(-1).draw(); }
    window.print();
    if (dt) { dt.page.len(len).draw(); }
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
