<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login');
}

Csrf::requireValid();

// Only one page may be continued to after sign-in (see login.php).
$next = ($_POST['next'] ?? '') === 'organizer-paypal-account' ? 'organizer-paypal-account' : '';
$loginParams = $next !== '' ? ['next' => $next] : [];

// Bot check (v43) before anything else is looked at.
if (!TurnstileService::verify()) {
    flash('error', TurnstileService::FAILED_MESSAGE);
    redirect('login', $loginParams);
}

$email = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    flash('error', 'Please enter both email and password.');
    redirect('login', $loginParams);
}

$user = Auth::verifyCredentials($email, $password);

if (!$user) {
    Logger::log(null, 'login_failed', 'Failed login attempt for ' . $email);
    flash('error', Auth::lastError() ?? 'Invalid credentials or inactive account.');
    if (Auth::$unverifiedEmail) {
        flash('unverified_email', $email); // lets the login page offer "resend the link"
    }
    redirect('login', $loginParams);
}

// Administrators with a real email address confirm the sign-in with an emailed code (v37).
if (LoginOtpService::required($user)) {
    $error = LoginOtpService::begin(Database::connection(), $user, $next);
    if ($error !== null) {
        LoginOtpService::clear();
        flash('error', $error);
        redirect('login', $loginParams);
    }
    redirect('login-otp');
}

Auth::login($user);
if ($next !== '' && $user['role_name'] === 'organizer') {
    redirect($next);
}
Auth::redirectIntended($user['role_name'] . '-dashboard'); // v65: back to the page they wanted
