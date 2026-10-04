<?php
// Opens the emailed verification link (GET ?token=...), or re-sends it (POST from the login page). v38.
$db = Database::connection();
if (($_GET['route'] ?? '') === 'verify-email-resend') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValid();
        EmailVerificationService::resend($db, (string) ($_POST['email'] ?? ''));
        flash('success', 'If that account still needs verifying, we sent a new link. Check your inbox and spam folder.');
    }
    redirect('login');
}
header('Referrer-Policy: no-referrer');
$name = EmailVerificationService::verify($db, (string) ($_GET['token'] ?? ''));
if ($name !== null) {
    flash('success', 'Email verified — thank you, ' . $name . '! You can now log in.');
} else {
    flash('error', 'This verification link is invalid, was already used, or has expired. Log in to get a new one.');
}
redirect('login');
