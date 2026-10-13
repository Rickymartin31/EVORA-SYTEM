<?php
// Reset password (v35): the page the emailed link opens. The token travels in the URL only
// to this page; the form posts it back and it is checked again before anything changes.
$db = Database::connection();
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $error = PasswordResetService::reset($db, $token, (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
    if ($error === null) {
        if (Auth::check()) {
            Auth::logout();
            Auth::start();
        }
        flash('success', 'Your password was changed. You can now log in with your new password.');
        redirect('login');
    }
    flash('error', $error);
    redirect('reset-password', ['token' => $token]);
}

$account = PasswordResetService::findValid($db, $token);
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — EVORA</title>
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
<?php include EVORA_ROOT . '/modules/auth/partials/auth_floaters.php'; ?>
<div class="auth-card">
    <h3 class="fw-bold mb-1">Choose a New Password</h3>
    <?php if ($err = flash('error')): ?>
        <div class="alert alert-danger mt-3"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if (!$account): ?>
        <p class="text-muted small mb-3">This reset link is invalid, was already used, or has expired.</p>
        <a href="<?= url('forgot-password') ?>" class="btn btn-evora-primary w-100">Request a New Link</a>
    <?php else: ?>
        <p class="text-muted small mb-4">For <strong><?= e($account['email']) ?></strong>. Use at least 8 characters with at least one letter and one number.</p>
        <form method="post" action="<?= url('reset-password') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="form-floating mb-3">
                <input type="password" name="new_password" id="rpNew" class="form-control" data-strength placeholder="New password" autocomplete="new-password" minlength="8" maxlength="72" required autofocus>
                <label for="rpNew"><i class="bi bi-lock me-1"></i>New password</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" name="confirm_password" id="rpConfirm" class="form-control" data-match="#rpNew" placeholder="Confirm new password" autocomplete="new-password" minlength="8" maxlength="72" required>
                <label for="rpConfirm"><i class="bi bi-lock me-1"></i>Confirm new password</label>
            </div>
            <button type="submit" class="btn btn-evora-primary w-100">Change Password</button>
        </form>
    <?php endif; ?>
    <p class="text-center small mt-3"><a href="<?= url('login') ?>" class="back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to login</a></p>
    <p class="text-center small text-muted mb-0"><a href="<?= url('user-agreement') ?>" class="text-muted">User Agreement</a> · <a href="<?= url('privacy-policy') ?>" class="text-muted">Privacy Policy</a></p>
</div>
</body>
</html>
