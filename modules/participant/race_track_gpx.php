<?php
/**
 * GPX upload of a finished run (v68), for runners who recorded the race with Strava or a sports watch.
 * POST multipart: csrf_token, registration_id, gpx (file). The run is checked like a live track.
 */
Auth::requireRole('participant');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my-registrations');
}
Csrf::requireValid();

$db = Database::connection();
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$check = RaceTrackingService::trackable($db, $registrationId, Auth::id());
if (!$check['reg']) {
    flash('error', 'Registration not found.');
    redirect('my-registrations');
}
$why = RaceTrackingService::gpxBlockedReason($check['reg']);
if ($why !== null) {
    flash('error', $why);
    redirect('race-tracker', ['registration_id' => $registrationId]);
}

try {
    $track = RaceTrackingService::importGpx($db, $check['reg'], $_FILES['gpx'] ?? [], Auth::id());
    flash('success', 'Your run was uploaded (' . number_format((int) $track['distance_m'] / 1000, 2) . ' km). The route check is shown below.');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
}
redirect('race-tracker', ['registration_id' => $registrationId]);
