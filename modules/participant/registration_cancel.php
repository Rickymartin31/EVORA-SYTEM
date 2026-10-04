<?php
// Participant cancels their own registration (POST + CSRF). v39
Auth::requireRole('participant');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my-registrations');
}
Csrf::requireValid();
try {
    flash('success', ParticipantToolsService::cancelRegistration(Database::connection(), (int) ($_POST['registration_id'] ?? 0), (int) Auth::id(), (string) ($_POST['reason'] ?? '')));
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('my-registrations');
