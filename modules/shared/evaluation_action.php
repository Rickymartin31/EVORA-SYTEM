<?php
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notifications');
}
Csrf::requireValid();

$db = Database::connection();
$do = $_POST['do'] ?? '';

if ($do === 'snooze') {
    // "Later": hide this prompt for a day (session only, nothing is saved).
    EvaluationService::snooze((string) ($_POST['key'] ?? ''));
} elseif ($do === 'platform_quick') {
    // The user always comes from the session; one evaluation per user.
    try {
        EvaluationService::submitPlatformQuick($db, Auth::id(), (string) Auth::role(), $_POST['rating'] ?? null, (string) ($_POST['comment'] ?? ''));
        flash('success', 'Thank you for rating EVORA!');
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
    }
} elseif ($do === 'platform_rate') {
    // ⭐ Rate EVORA: overall rating + optional category ratings for the user's own role.
    try {
        EvaluationService::submitPlatform($db, Auth::id(), (string) Auth::role(), $_POST['rating'] ?? null, (string) ($_POST['comment'] ?? ''),
            is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [], 'star_button');
        flash('success', 'Thank you for rating EVORA!');
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
    }
} else {
    flash('error', 'Invalid action.');
}

// Back to the page the prompt was shown on (same-origin path + query only).
$refererParts = !empty($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER']) : false;
if ($refererParts && ($refererParts['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '')) {
    header('Location: ' . ($refererParts['path'] ?? '/') . (isset($refererParts['query']) ? '?' . $refererParts['query'] : ''));
    exit;
}
redirect(match (Auth::role()) { 'organizer' => 'organizer-dashboard', 'staff' => 'staff-dashboard', 'admin' => 'admin-dashboard', default => 'participant-dashboard' });
