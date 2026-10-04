<?php
Auth::requireRole('organizer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-events');
}
Csrf::requireValid();

$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_POST['event_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $db->prepare('SELECT * FROM events WHERE id = :id AND organizer_id = :oid');
$stmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $stmt->fetch();

if (!$event) {
    flash('error', 'Event not found.');
    redirect('organizer-events');
}

// Not a status change: the organizer asks EVORA to cancel the event (only an
// admin can cancel, because paid participants must be refunded).
if ($action === 'request_cancellation') {
    $reason = trim($_POST['reason'] ?? '');
    if (!in_array($event['status'], ['pending_approval', 'approved', 'open', 'closed', 'ongoing'], true)) {
        flash('error', 'A ' . $event['status'] . ' event cannot be cancelled.');
    } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
        flash('error', 'Give a cancellation reason of 10–500 characters.');
    } else {
        Logger::log($userId, 'event_cancellation_requested', 'Event #' . $eventId . ' (' . $event['name'] . '): ' . $reason);
        PayoutRequestService::notifyAdmins($db, 'Event Cancellation Request', 'The organizer of "' . $event['name'] . '" asks to cancel it. Reason: ' . $reason,
            ['category' => 'event', 'notice_type' => 'action_required', 'reference_type' => 'event', 'reference_id' => $eventId], 'event_cancellation_request');
        flash('success', 'Cancellation request sent to the EVORA admin. You will be notified once it is reviewed.');
    }
    redirect('organizer-event-manage', ['id' => $eventId]);
}

// action => [allowed current statuses, new status, success message]
$transitions = [
    'submit_for_approval' => [['draft'], 'pending_approval', 'Event submitted for admin approval.'],
    'open_registration' => [['approved'], 'open', 'Registration is now open.'],
    'close_registration' => [['open'], 'closed', 'Registration closed.'],
    'mark_ongoing' => [['open', 'closed'], 'ongoing', 'Event marked as ongoing.'],
    'mark_completed' => [['ongoing'], 'completed', 'Event marked as completed.'],
];

if (!isset($transitions[$action])) {
    flash('error', 'Invalid action.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

[$allowedFrom, $toStatus, $message] = $transitions[$action];

if (!in_array($event['status'], $allowedFrom, true)) {
    flash('error', 'This event cannot transition from "' . $event['status'] . '" using that action.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

// A PayPal event stays a PayPal event (never converted to PayMongo); while PayPal
// is switched off its registration cannot be opened, because nobody could pay.
if ($action === 'open_registration' && ($event['payment_provider'] ?? null) === 'paypal' && !PAYPAL_ENABLED) {
    flash('error', 'PayPal payment is paused right now, so registration for this PayPal event cannot be opened. Please try again later.');
    redirect('organizer-event-manage', ['id' => $eventId]);
}

// A paid event needs somewhere for the money to go before it is published: the
// organizer's own linked PayPal account (direct payment). PayMongo is legacy only,
// so with PayPal switched off a paid event cannot be opened (online payments paused).
$isPaidEvent = false;
$paypalAccount = PAYPAL_ENABLED ? PayPalAccountService::current($db, (int) $organizer['id']) : null;
if (in_array($action, ['submit_for_approval', 'open_registration'], true)) {
    $paidStmt = $db->prepare('SELECT (e.fee > 0 OR EXISTS (SELECT 1 FROM race_categories rc WHERE rc.event_id = e.id AND rc.fee > 0)) FROM events e WHERE e.id = :id');
    $paidStmt->execute(['id' => $eventId]);
    $isPaidEvent = (bool) $paidStmt->fetchColumn();
    if ($isPaidEvent && PAYPAL_ENABLED && !$paypalAccount) {
        flash('error', 'Connect your PayPal Business account (Finance → PayPal Account) before publishing a paid event — participants pay you directly through PayPal.');
        redirect('organizer-paypal-account');
    }
    if ($isPaidEvent && !PAYPAL_ENABLED && $action === 'open_registration') {
        flash('error', 'Online payments are temporarily unavailable (PayPal is paused), so registration for a paid event cannot be opened right now. Please try again later.');
        redirect('organizer-event-manage', ['id' => $eventId]);
    }
}

EventStatusService::changeStatus($db, $eventId, $toStatus, $userId);
// Opening a paid event with PayPal on: it takes PayPal payments to the organizer's
// linked account (snapshot). Never switched once PayMongo money exists for the event.
if ($action === 'open_registration' && $isPaidEvent && $paypalAccount) {
    $db->prepare('UPDATE events e SET e.payment_provider = "paypal", e.organizer_paypal_account_id = :acct
                  WHERE e.id = :id AND e.payment_provider IS NULL
                    AND NOT EXISTS (SELECT 1 FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE r.event_id = e.id AND pay.payment_channel = "paymongo")')
        ->execute(['acct' => $paypalAccount['id'], 'id' => $eventId]);
}
Logger::log($userId, 'event_status_' . $action, 'Event #' . $eventId . ' (' . $event['name'] . ') -> ' . $toStatus);

// Registration ended by the organizer: record it and prepare the payout
// request right away (the scheduler would otherwise do it on its next run).
if (in_array($action, ['close_registration', 'mark_ongoing'], true)) {
    EventRegistrationService::markClosed($db, $eventId);
    try {
        if (PayoutRequestService::createForEvent($db, $eventId, $userId)) {
            $message .= ' A payout request for your earnings was created.';
        }
    } catch (Throwable $e) {
        Logger::log($userId, 'payout_request_error', 'Event #' . $eventId . ': ' . $e->getMessage());
    }
}

if ($action === 'mark_completed') {
    EvaluationService::notifyEventCompleted($db, $eventId);
    $autoIssuedCount = CertificateService::autoIssueForEvent($db, $eventId);
    if ($autoIssuedCount > 0) {
        Logger::log($userId, 'certificates_auto_issued', 'Auto-issued ' . $autoIssuedCount . ' certificate(s) for event #' . $eventId . ' on completion.');
    }
}

if ($action === 'submit_for_approval') {
    $admins = $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin"')->fetchAll();
    foreach ($admins as $admin) {
        notify((int) $admin['id'], 'Event Submitted for Approval', '"' . $event['name'] . '" is awaiting your review.', 'event_submitted', ['reference_type' => 'event', 'reference_id' => $eventId]);
    }
    NotificationService::safely(fn () => notify($userId, 'Event Submitted', '"' . $event['name'] . '" was submitted for admin approval. You will be notified when it is reviewed.', 'event_update',
        ['category' => 'event', 'notice_type' => 'informational', 'reference_type' => 'event', 'reference_id' => $eventId]));
}
if ($action === 'open_registration') {
    NotificationService::safely(fn () => notify($userId, 'Event Published', 'Registration for "' . $event['name'] . '" is now open to participants.', 'event_update',
        ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]));
}
if ($action === 'mark_ongoing') {
    NotificationService::toEventStaff($db, $eventId, 'Event Now Ongoing', '"' . $event['name'] . '" is now ongoing. Check-in scanning and event operations are open.', 'staff_update',
        ['category' => 'event', 'notice_type' => 'status', 'reference_type' => 'event', 'reference_id' => $eventId]);
}

flash('success', $message);
redirect('organizer-event-manage', ['id' => $eventId]);
