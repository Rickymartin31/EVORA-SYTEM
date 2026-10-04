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
    EvaluationService::submitEvent($db, Auth::id(), $eventId, [
        'overall_rating' => $_POST['overall_rating'] ?? null,
        'speaker_rating' => $_POST['speaker_rating'] ?? null,
        'session_rating' => $_POST['session_rating'] ?? null,
        'venue_rating' => $_POST['venue_rating'] ?? null,
    ], (string) ($_POST['suggestions'] ?? ''));
    unset($_SESSION['evaluation_snooze']['event:' . $eventId]);
    flash('success', 'Thank you for your feedback!');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    if (str_contains($e->getMessage(), 'completed') || str_contains($e->getMessage(), 'registration') || str_contains($e->getMessage(), 'Cancelled')) {
        redirect('my-registrations');
    }
}
redirect('event-feedback', ['event_id' => $eventId]);
