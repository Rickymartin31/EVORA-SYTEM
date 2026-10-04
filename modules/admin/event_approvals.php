<?php
Auth::requireRole('admin');
$db = Database::connection();

$stmt = $db->query(
    "SELECT e.*, o.organization_name, c.name AS category_name
     FROM events e
     JOIN organizers o ON o.id = e.organizer_id
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.status = 'pending_approval'
     ORDER BY e.updated_at ASC"
);
$events = $stmt->fetchAll();

$pageDescription = 'Events submitted by organizers that are waiting for your review.';
$pageTitle = 'Event Approvals';
$activeRoute = 'admin-event-approvals';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <h6 class="fw-semibold mb-3">Events Awaiting Approval</h6>
    <?php if (empty($events)): ?>
        <?= emptyState('bi-patch-check', 'No events are currently awaiting approval.') ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle table-datatable">
            <thead><tr><th>Event</th><th>Organizer</th><th>Category</th><th>Date</th><th>Fee</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= e($ev['name']) ?></td>
                    <td><?= e($ev['organization_name']) ?></td>
                    <td><?= e($ev['category_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= $ev['fee'] > 0 ? currency($ev['fee']) : 'Free' ?></td>
                    <td>
                        <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Preview</a>
                        <form method="post" action="<?= url('admin-event-approval-action') ?>" class="d-inline" data-confirm="Approve this event so the organizer can open registration?">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <button type="submit" name="action" value="approve" class="btn btn-sm btn-outline-success">Approve</button>
                        </form>
                        <form method="post" action="<?= url('admin-event-approval-action') ?>" class="d-inline" id="rejectForm<?= $ev['id'] ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="remarks">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="rejectEvent('rejectForm<?= $ev['id'] ?>')">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
function rejectEvent(formId) {
    const form = document.getElementById(formId);
    if (typeof Swal === 'undefined') {
        const reason = prompt('Reason for rejection (sent back to the organizer as a draft):');
        if (reason === null || reason.trim() === '') return;
        form.remarks.value = reason;
        form.submit();
        return;
    }
    Swal.fire({
        title: 'Reject this event?',
        input: 'textarea',
        inputLabel: 'Reason (sent back to the organizer as a draft)',
        inputPlaceholder: 'Explain what needs to change...',
        showCancelButton: true,
        confirmButtonText: 'Reject Event',
        confirmButtonColor: '#dc2626',
        inputValidator: (value) => !value.trim() ? 'A reason is required.' : undefined,
    }).then(function (result) {
        if (result.isConfirmed) {
            form.remarks.value = result.value;
            form.submit();
        }
    });
}
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
