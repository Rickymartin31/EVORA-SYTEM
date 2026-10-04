<?php
Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-events');
}
Csrf::requireValid();

$db = Database::connection();
$eventId = (int) ($_POST['event_id'] ?? 0);
$action = $_POST['action'] ?? '';
$returnParams = !empty($_POST['return_status']) ? ['status' => $_POST['return_status']] : [];
// Acted from the event's own page (Admin actions card, v50): go back to that page.
$back = (($_POST['return_to'] ?? '') === 'event' && $eventId > 0) ? ['event-view', ['id' => $eventId]] : ['admin-events', $returnParams];

if (!in_array($action, ['remove', 'archive', 'cancel'], true)) {
    flash('error', 'Invalid action.');
    redirect(...$back);
}

$stmt = $db->prepare('SELECT e.*, o.user_id AS organizer_user_id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect(...$back);
}

if ($action === 'archive') {
    if ($event['status'] !== 'completed') {
        flash('error', 'Only completed events can be archived.');
        redirect(...$back);
    }
    EventStatusService::changeStatus($db, $eventId, 'archived', Auth::id());
    Logger::log(Auth::id(), 'event_archived', 'Admin archived event #' . $eventId . ' (' . $event['name'] . ')');
    flash('success', 'Event archived.');
    redirect(...$back);
}

if ($action === 'cancel') {
    // The event will not happen. Money EVORA collected is returned through
    // Refund Requests -> Refund All Paid Participants (never automatically).
    $remarks = trim($_POST['remarks'] ?? '');
    if (!in_array($event['status'], ['pending_approval', 'approved', 'open', 'closed', 'ongoing'], true)) {
        flash('error', 'A ' . $event['status'] . ' event cannot be cancelled.');
        redirect(...$back);
    }
    if (mb_strlen($remarks) < 10 || mb_strlen($remarks) > 500) {
        flash('error', 'Enter a cancellation reason of 10–500 characters.');
        redirect(...$back);
    }
    EventStatusService::changeStatus($db, $eventId, 'cancelled', Auth::id(), $remarks);
    PayoutRequestService::recalculateQuietly($db, $eventId);
    Logger::log(Auth::id(), 'event_cancelled', 'Admin cancelled event #' . $eventId . ' (' . $event['name'] . '): ' . $remarks);
    notify((int) $event['organizer_user_id'], 'Event Cancelled', 'Your event "' . $event['name'] . '" was cancelled by an administrator. Reason: ' . $remarks, 'event_cancelled', ['reference_type' => 'event', 'reference_id' => $eventId]);
    // PayPal payments went straight to the organizer's PayPal account: EVORA never
    // held that money, so those participants are told the organizer refunds them.
    $participants = $db->prepare(
        'SELECT p.user_id, MAX(pay.payment_channel = "paypal" AND pay.status = "approved") AS paid_paypal
         FROM registrations r JOIN participants p ON p.id = r.participant_id
         LEFT JOIN payments pay ON pay.registration_id = r.id
         WHERE r.event_id = :id AND r.status IN ("pending", "approved") GROUP BY p.user_id'
    );
    $participants->execute(['id' => $eventId]);
    foreach ($participants->fetchAll() as $participant) {
        $message = $participant['paid_paypal']
            ? '"' . $event['name'] . '" was cancelled. You paid through PayPal directly to the organizer, so EVORA did not receive this money and cannot refund it. Your refund must come from the organizer through their PayPal account. You can follow it on Payments & Receipts — contact the organizer if you do not receive it.'
            : '"' . $event['name'] . '" was cancelled. If you paid online, EVORA will refund you and notify you.';
        notify((int) $participant['user_id'], 'Event Cancelled', $message, 'event_cancelled', ['reference_type' => 'event', 'reference_id' => $eventId]);
    }
    $paypalPaid = $db->prepare('SELECT COUNT(*) c, COALESCE(SUM(pay.amount), 0) total FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id AND pay.payment_channel = "paypal" AND pay.status = "approved"');
    $paypalPaid->execute(['id' => $eventId]);
    $paypalPaid = $paypalPaid->fetch();
    if ((int) $paypalPaid['c'] > 0) {
        notify((int) $event['organizer_user_id'], 'Refund Your PayPal Participants', (int) $paypalPaid['c'] . ' participant(s) paid ' . currency($paypalPaid['total']) . ' for "' . $event['name'] . '" directly to your PayPal account. EVORA did not receive this money and cannot refund it — please refund them from your PayPal account, then record each refund on your Refunds page.', 'event_cancelled', ['reference_type' => 'event', 'reference_id' => $eventId]);
    }
    $paypalNote = (int) $paypalPaid['c'] > 0 ? ' ' . (int) $paypalPaid['c'] . ' participant(s) paid through PayPal directly to the organizer — EVORA cannot refund those; the organizer was told to refund them from PayPal.' : '';
    NotificationService::toEventStaff($db, $eventId, 'Event Cancelled', '"' . $event['name'] . '" was cancelled. Your assignment for this event no longer applies.', 'event_cancelled', ['notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]);
    NotificationService::toOtherAdmins($db, (int) Auth::id(), 'Event Cancelled', '"' . $event['name'] . '" was cancelled by another administrator. Reason: ' . $remarks, 'event_cancelled', ['notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]);
    $paid = $db->prepare('SELECT COUNT(*) FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = :id AND pay.payment_channel = "paymongo" AND pay.status = "approved"');
    $paid->execute(['id' => $eventId]);
    if ((int) $paid->fetchColumn() > 0) {
        flash('success', 'Event cancelled. It has participants who paid through the legacy PayMongo method — refund them from the PayMongo dashboard.' . $paypalNote);
        redirect(...$back);
    }
    flash('success', 'Event cancelled.' . $paypalNote);
    redirect(...$back);
}

EventStatusService::changeStatus($db, $eventId, 'removed', Auth::id());
Logger::log(Auth::id(), 'event_removed', 'Admin removed event #' . $eventId . ' (' . $event['name'] . ')');
notify((int) $event['organizer_user_id'], 'Event Removed', 'Your event "' . $event['name'] . '" was removed by an administrator for policy reasons.', 'event_removed', ['reference_type' => 'event', 'reference_id' => $eventId]);

flash('success', 'Event removed.');
redirect('admin-events', $returnParams);
