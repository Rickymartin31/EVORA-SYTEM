<?php
// Organizer: public reply to a participant's review of one of their events (v80).
Auth::requireRole('organizer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('organizer-dashboard');
}
Csrf::requireValid();

$eventId = (int) ($_POST['event_id'] ?? 0);
try {
    EvaluationService::reply(Database::connection(), (int) ($_POST['feedback_id'] ?? 0), (int) Auth::id(), (string) ($_POST['reply'] ?? ''));
    flash('success', trim((string) ($_POST['reply'] ?? '')) !== '' ? 'Reply saved. It is now shown under the review.' : 'Reply removed.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('organizer-event-feedback', ['event_id' => $eventId]);
