<?php
// "Continue with Google" for participants (v41):
//   google-login     → starts the sign-in (moves to the Return URL's host first: the session cookie is host-bound)
//   google-callback  → Google sends the person back here (through the root forwarder)
//   google-complete  → a first-time Google user accepts the terms before the account is created
$db = Database::connection();
$route = $_GET['route'] ?? '';

if (Auth::check()) {
    redirect(Auth::role() . '-dashboard');
}
if (!GoogleAuthService::enabled()) {
    flash('error', 'Continue with Google is not available yet. Please sign in with your email and password.');
    redirect('login');
}

if ($route === 'google-login') {
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host !== GoogleAuthService::returnHost()) {
        header('Location: http://' . GoogleAuthService::returnHost() . url('google-login'));
        exit;
    }
    header('Location: ' . GoogleAuthService::authorizeUrl());
    exit;
}

if ($route === 'google-callback') {
    if (isset($_GET['error'])) {
        unset($_SESSION['google_oauth']);
        flash('error', 'Google sign-in was cancelled.');
        redirect('login');
    }
    try {
        $identity = GoogleAuthService::identityFromCallback((string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
        $result = GoogleAuthService::resolve($db, $identity);
    } catch (DomainException $e) {
        Logger::log(null, 'google_signin_failed', mb_substr($e->getMessage(), 0, 200));
        flash('error', $e->getMessage());
        redirect('login');
    }
    if (isset($result['user'])) {
        Auth::login($result['user']);
        Logger::log((int) $result['user']['id'], 'google_signin', 'Signed in with Google');
        redirect('participant-dashboard');
    }
    $_SESSION['google_pending'] = $result['new'] + ['at' => time()];
    redirect('google-complete');
}

// google-complete: first-time Google user confirms and accepts the terms.
$pending = $_SESSION['google_pending'] ?? null;
if (!$pending || time() - (int) $pending['at'] > 900) {
    unset($_SESSION['google_pending']);
    flash('error', 'Your Google sign-in expired. Please try again.');
    redirect('login');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['google_pending']);
        redirect('login');
    }
    if (empty($_POST['accept_terms'])) {
        flash('error', 'Please agree to the User Agreement and Privacy Policy to create your account.');
        redirect('google-complete');
    }
    try {
        $taken = $db->prepare('SELECT 1 FROM users WHERE email = :e OR google_sub = :s');
        $taken->execute(['e' => $pending['email'], 's' => $pending['sub']]);
        if ($taken->fetchColumn()) {
            throw new DomainException('An account with this email already exists. Please sign in instead.');
        }
        $user = GoogleAuthService::createParticipant($db, $pending);
    } catch (DomainException $e) {
        unset($_SESSION['google_pending']);
        flash('error', $e->getMessage());
        redirect('login');
    }
    unset($_SESSION['google_pending']);
    Auth::login($user);
    flash('success', 'Welcome to EVORA, ' . $user['full_name'] . '! Your account was created with Google.');
    redirect('participant-dashboard');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your account — EVORA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= assetUrl('css/theme.css') ?>" rel="stylesheet">
    <?= themeInit() ?>
    <?= pageHead($pageMeta ?? []) ?>
</head>
<body class="auth-shell">
<div class="auth-card">
    <h3 class="fw-bold mb-1"><i class="bi bi-google me-1" aria-hidden="true"></i>Create your EVORA account</h3>
    <p class="text-muted small mb-3">You signed in with Google. Check your details, then create your participant account.</p>
    <?php if ($err = flash('error')): ?>
        <div class="alert alert-danger small"><?= e($err) ?></div>
    <?php endif; ?>
    <dl class="row small mb-3">
        <dt class="col-4">Name</dt><dd class="col-8"><?= e($pending['name']) ?></dd>
        <dt class="col-4">Email</dt><dd class="col-8"><?= e($pending['email']) ?> <span class="badge bg-success">Verified by Google</span></dd>
        <dt class="col-4">Account</dt><dd class="col-8">Participant</dd>
    </dl>
    <form method="post" action="<?= url('google-complete') ?>">
        <?= Csrf::field() ?>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="gTerms" required>
            <label class="form-check-label small" for="gTerms">I have read and agree to the <a href="<?= url('user-agreement') ?>" target="_blank" rel="noopener">User Agreement</a> and the <a href="<?= url('privacy-policy') ?>" target="_blank" rel="noopener">Privacy Policy</a>, including how EVORA uses my personal information.</label>
        </div>
        <button type="submit" class="btn btn-evora-primary w-100">Create Account</button>
    </form>
    <form method="post" action="<?= url('google-complete') ?>" class="mt-2">
        <?= Csrf::field() ?><input type="hidden" name="action" value="cancel">
        <button class="btn btn-link btn-sm w-100 text-muted">Cancel</button>
    </form>
    <p class="small text-muted mt-2 mb-0">Organizers apply with the organizer form instead. You can also set a password later with “Forgot password”.</p>
</div>
</body>
</html>
