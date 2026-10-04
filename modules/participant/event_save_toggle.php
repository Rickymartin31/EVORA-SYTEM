<?php
// Save / un-save an event (POST + CSRF). v39
Auth::requireRole('participant');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('events');
}
Csrf::requireValid();
$eventId = (int) ($_POST['event_id'] ?? 0);
try {
    $saved = ParticipantToolsService::toggleSaved(Database::connection(), (int) Auth::id(), $eventId);
    flash('success', $saved ? 'Event saved. Find it under Saved Events — we will remind you before registration closes.' : 'Removed from your saved events.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect(($_POST['return'] ?? '') === 'saved-events' ? 'saved-events' : 'event-view', ($_POST['return'] ?? '') === 'saved-events' ? [] : ['id' => $eventId]);
