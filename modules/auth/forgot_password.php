<?php
// Forgot password (v35): asks for the account email and sends a one-time reset link.
if (Auth::check()) {
    redirect(Auth::role() . '-dashboard');
}
$db = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!TurnstileService::verify()) {
        flash('error', TurnstileService::FAILED_MESSAGE);
        redirect('forgot-password');
    }
    $error = PasswordResetService::request($db, (string) ($_POST['email'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($error !== null) {
        flash('error', $error);
        redirect('forgot-password');
    }
    // Same answer whether or not the address has an account.
    flash('success', 'If an EVORA account uses that email, we sent it a link to reset the password. Check your inbox (and spam folder); the link expires in ' . PasswordResetService::TTL_MINUTES . ' minutes.');
    redirect('login');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password — EVORA</title>
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
    <h3 class="fw-bold mb-1">Forgot Password</h3>
    <p class="text-muted small mb-4">Enter the email address of your EVORA account and we will email you a link to choose a new password.</p>

    <?php if ($err = flash('error')): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endif; ?>
    <?php if (!MailService::enabled()): ?>
        <div class="alert alert-warning small">Password reset by email is not available yet. Please contact an administrator to reset your password.</div>
    <?php endif; ?>

    <form method="post" action="<?= url('forgot-password') ?>">
        <?= Csrf::field() ?>
        <div class="form-floating mb-3">
            <input type="email" name="email" id="fpEmail" class="form-control" placeholder="Email address" required autofocus maxlength="150">
            <label for="fpEmail"><i class="bi bi-envelope me-1"></i>Email address</label>
        </div>
        <?= TurnstileService::widget() ?>
        <button type="submit" class="btn btn-evora-primary w-100" <?= MailService::enabled() ? '' : 'disabled' ?>>Send Reset Link</button>
    </form>
    <p class="text-center small mt-3"><a href="<?= url('login') ?>" class="back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to login</a></p>
    <p class="text-center small text-muted mb-0"><a href="<?= url('user-agreement') ?>" class="text-muted">User Agreement</a> · <a href="<?= url('privacy-policy') ?>" class="text-muted">Privacy Policy</a></p>
</div>
</body>
</html>
