<?php
Auth::requireRole('participant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my-registrations');
}
Csrf::requireValid();

$db = Database::connection();
$eventId = (int) ($_POST['event_id'] ?? 0);

// The participant always comes from the session; the service re-checks the
// registration, that the event is completed, and that this is the first evaluation.
try {
    EvaluationService::submitEvent($db, Auth::id(), $eventId, array_intersect_key($_POST, array_flip([
        'overall_rating', 'organization_rating', 'checkin_rating', 'venue_rating', 'speaker_rating', 'session_rating',
        'route_rating', 'kit_rating', 'officiating_rating', 'expectations_met', 'join_again', 'liked', 'improvement_tags',
    ])), (string) ($_POST['suggestions'] ?? ''), $_FILES['photos'] ?? null);
    unset($_SESSION['evaluation_snooze']['event:' . $eventId]);
    flash('success', 'Thank you for your review!');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    if (str_contains($e->getMessage(), 'completed') || str_contains($e->getMessage(), 'registration') || str_contains($e->getMessage(), 'Cancelled') || str_contains($e->getMessage(), 'attended')) {
        redirect('my-registrations');
    }
}
if (($_POST['return'] ?? '') === 'event') {
    // Written on the event page: go back there and show the reviews (redirect() cannot add a #fragment).
    header('Location: ' . url('event-view', ['id' => $eventId]) . '#reviews');
    exit;
}
redirect('event-feedback', ['event_id' => $eventId]);
