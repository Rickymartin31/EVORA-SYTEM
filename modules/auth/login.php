<?php
// The only page a sign-in may continue to (used when Connect PayPal moves an organizer to 127.0.0.1).
$next = ($_GET['next'] ?? '') === 'organizer-paypal-account' ? 'organizer-paypal-account' : '';
if (Auth::check()): redirect($next !== '' && Auth::role() === 'organizer' ? $next : Auth::role() . '-dashboard'); endif;
if (!empty($_GET['return']) && is_string($_GET['return'])): Auth::rememberNextFromQuery($_GET['return']); endif; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — EVORA</title>
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
    <h3 class="fw-bold mb-1">EVO<span style="color:var(--evora-primary)">RA</span> Login</h3>
    <p class="text-muted small mb-3">Sign in to manage your events, staff, or registrations.</p>
    <div class="auth-role-chips mb-4" aria-label="Who signs in here">
        <span><i class="bi bi-person" aria-hidden="true"></i> Participants</span>
        <span><i class="bi bi-building" aria-hidden="true"></i> Organizers</span>
        <span><i class="bi bi-person-badge" aria-hidden="true"></i> Staff</span>
        <span><i class="bi bi-shield-lock" aria-hidden="true"></i> Administrators</span>
    </div>

    <?= MaintenanceService::bannerHtml(true) ?>
    <?php if (!empty($_GET['expired'])): ?>
        <div class="alert alert-warning">Your session expired. Please log in again.</div>
    <?php endif; ?>
    <?php if ($next !== ''): ?>
        <div class="alert alert-info small">Sign in once more here to connect your PayPal account. You will then go straight to your PayPal Account page.</div>
    <?php endif; ?>
    <?php if ($ok = flash('success')): ?>
        <div class="alert alert-success"><?= e($ok) ?></div>
    <?php endif; ?>
    <?php if ($err = flash('error')): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endif; ?>
    <?php if ($unverified = flash('unverified_email')): ?>
        <form method="post" action="<?= url('verify-email-resend') ?>" class="alert alert-warning small d-flex justify-content-between align-items-center gap-2">
            <?= Csrf::field() ?><input type="hidden" name="email" value="<?= e($unverified) ?>">
            <span>Didn't get the email?</span><button class="btn btn-sm btn-outline-dark">Resend verification link</button>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= url('login.post') ?>">
        <?= Csrf::field() ?>
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <div class="form-floating mb-3">
            <input type="text" name="email" id="loginEmail" class="form-control" placeholder="Username or email" required autofocus>
            <label for="loginEmail"><i class="bi bi-envelope me-1"></i>Username or Email</label>
        </div>
        <div class="form-floating mb-3">
            <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Password" required>
            <label for="loginPassword"><i class="bi bi-lock me-1"></i>Password</label>
        </div>
        <div class="text-end mb-3"><a href="<?= url('forgot-password') ?>" class="small">Forgot password?</a></div>
        <?= TurnstileService::widget() ?>
        <button type="submit" class="btn btn-evora-primary w-100">Login</button>
    </form>
    <hr>
    <p class="text-center small mb-1">Don't have an account?</p>
    <div class="d-flex gap-2">
        <a href="<?= url('register-participant') ?>" class="btn btn-outline-secondary btn-sm w-50">Join as Participant</a>
        <a href="<?= url('register-organizer') ?>" class="btn btn-outline-secondary btn-sm w-50">Apply as Organizer</a>
    </div>
    <p class="text-center small mt-3"><a href="<?= url('home') ?>" class="back-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to home</a></p>
    <p class="text-center small text-muted mb-0"><a href="<?= url('user-agreement') ?>" class="text-muted">User Agreement</a> · <a href="<?= url('privacy-policy') ?>" class="text-muted">Privacy Policy</a></p>
</div>
</body>
</html>
