<?php
// Second sign-in step for administrators (v37): enter the 6-digit code emailed after the password.
$db = Database::connection();
if (Auth::check()) {
    redirect(Auth::role() . '-dashboard');
}
$pending = LoginOtpService::pendingUser($db);
if (!$pending) {
    flash('error', 'Your sign-in expired. Please log in again.');
    redirect('login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (($_POST['action'] ?? '') === 'resend') {
        $error = LoginOtpService::send($db);
        flash($error === null ? 'success' : 'error', $error ?? 'A new code was sent to your email.');
        redirect('login-otp');
    }
    if (($_POST['action'] ?? '') === 'cancel') {
        LoginOtpService::clear();
        redirect('login');
    }
    try {
        $user = LoginOtpService::verify($db, (string) ($_POST['code'] ?? ''));
        $next = LoginOtpService::next();
        LoginOtpService::clear();
        Auth::login($user);
        Logger::log((int) $user['id'], 'login_otp_verified', 'Signed in with an emailed login code');
        redirect($next !== '' && $user['role_name'] === 'organizer' ? $next : $user['role_name'] . '-dashboard');
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect(LoginOtpService::pendingUser($db) ? 'login-otp' : 'login');
    }
}

// Show only part of the address the code went to.
[$local, $domain] = explode('@', (string) $pending['email']) + [1 => ''];
$maskedEmail = mb_substr($local, 0, 2) . str_repeat('•', max(1, mb_strlen($local) - 2)) . '@' . $domain;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Code — EVORA</title>
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
    <h3 class="fw-bold mb-1"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Enter Login Code</h3>
    <p class="text-muted small mb-4">We emailed a 6-digit code to <strong><?= e($maskedEmail) ?></strong>. It expires in <?= LoginOtpService::TTL_MINUTES ?> minutes. Check your spam folder if you don't see it.</p>

    <?php if ($ok = flash('success')): ?>
        <div class="alert alert-success small"><?= e($ok) ?></div>
    <?php endif; ?>
    <?php if ($err = flash('error')): ?>
        <div class="alert alert-danger small"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= url('login-otp') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="form-floating mb-3">
            <input type="text" name="code" id="otpCode" class="form-control text-center fs-4" style="letter-spacing:.4em" placeholder="000000"
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
            <label for="otpCode">6-digit code</label>
        </div>
        <button type="submit" class="btn btn-evora-primary w-100">Verify and Log In</button>
    </form>
    <div class="d-flex justify-content-between mt-3">
        <form method="post" action="<?= url('login-otp') ?>" class="m-0"><?= Csrf::field() ?><input type="hidden" name="action" value="resend">
            <button class="btn btn-link btn-sm p-0">Send a new code</button></form>
        <form method="post" action="<?= url('login-otp') ?>" class="m-0"><?= Csrf::field() ?><input type="hidden" name="action" value="cancel">
            <button class="btn btn-link btn-sm p-0 text-muted">Cancel</button></form>
    </div>
</div>
</body>
</html>
