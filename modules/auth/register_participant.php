<?php

if (Auth::check()) {
    redirect(Auth::role() . '-dashboard');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($fullName === '' || $email === '' || $password === '') {
        $errors[] = 'Please fill in all required fields.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Password confirmation does not match.';
    }
    if (!TurnstileService::verify()) {
        $errors[] = TurnstileService::FAILED_MESSAGE;
    }
    if (empty($_POST['accept_terms'])) {
        $errors[] = 'Please agree to the User Agreement and Privacy Policy.';
    }

    if (empty($errors)) {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (empty($errors)) {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $roleStmt = $db->prepare('SELECT id FROM roles WHERE name = "participant"');
            $roleStmt->execute();
            $roleId = $roleStmt->fetch()['id'];

            $stmt = $db->prepare(
                'INSERT INTO users (role_id, full_name, email, password_hash, phone, status)
                 VALUES (:role_id, :full_name, :email, :password_hash, :phone, "active")'
            );
            $stmt->execute([
                'role_id' => $roleId,
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'phone' => $phone ?: null,
            ]);
            $userId = (int) $db->lastInsertId();
            $db->prepare('UPDATE users SET terms_accepted_at = NOW(), terms_version = :v WHERE id = :id')->execute(['v' => EVORA_LEGAL_VERSION, 'id' => $userId]);

            $stmt = $db->prepare('INSERT INTO participants (user_id) VALUES (:user_id)');
            $stmt->execute(['user_id' => $userId]);

            $db->commit();
            Logger::log($userId, 'register', 'Participant account created');
            $needsVerify = EmailVerificationService::startForNewAccount($db, $userId);
            flash('success', $needsVerify
                ? 'Account created! We emailed a verification link to ' . $email . ' — open it, then log in. (Check your spam folder too.)'
                : 'Account created! You may now log in.');
            redirect('login');
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = 'Something went wrong. Please try again.';
        }
    }

    if (!empty($errors)) {
        setOld($_POST);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join EVORA</title>
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
<div class="auth-card" style="max-width:520px;">
    <h3 class="fw-bold mb-1">Join EVORA</h3>
    <p class="text-muted small mb-3">Create a participant account to browse and register for events.</p>
    <?php $accountType = 'participant'; include EVORA_ROOT . '/modules/auth/partials/account_type_switch.php'; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (GoogleAuthService::enabled()): ?>
        <a href="<?= url('google-login') ?>" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2"><i class="bi bi-google" aria-hidden="true"></i> Continue with Google</a>
        <p class="text-center small text-muted mt-2 mb-0">Already joined with Google? This same button signs you in.</p>
        <div class="d-flex align-items-center gap-2 my-3 text-muted small"><hr class="flex-grow-1 m-0">or sign up with email<hr class="flex-grow-1 m-0"></div>
    <?php endif; ?>
    <form method="post" action="<?= url('register-participant') ?>">
        <p class="req-note"><span class="req-mark" aria-hidden="true">*</span> Required field</p>
        <?= Csrf::field() ?>
        <div class="form-floating mb-3">
            <input type="text" name="full_name" id="rpName" class="form-control" placeholder="Full Name" value="<?= e(old('full_name')) ?>" required>
            <label for="rpName"><i class="bi bi-person me-1"></i>Full Name<span class="req-mark" aria-hidden="true">*</span></label>
        </div>
        <div class="form-floating mb-3">
            <input type="email" name="email" id="rpEmail" class="form-control" placeholder="name@example.com" value="<?= e(old('email')) ?>" required>
            <label for="rpEmail"><i class="bi bi-envelope me-1"></i>Email address<span class="req-mark" aria-hidden="true">*</span></label>
        </div>
        <div class="form-floating mb-3">
            <input type="text" name="phone" id="rpPhone" class="form-control" placeholder="Contact Number" value="<?= e(old('phone')) ?>">
            <label for="rpPhone"><i class="bi bi-telephone me-1"></i>Contact Number</label>
        </div>
        <div class="row">
            <div class="col-sm-6 mb-3">
                <div class="form-floating">
                    <input type="password" name="password" id="rpPassword" class="form-control" data-strength placeholder="Password" required minlength="8">
                    <label for="rpPassword">Password<span class="req-mark" aria-hidden="true">*</span></label>
                </div>
            </div>
            <div class="col-sm-6 mb-3">
                <div class="form-floating">
                    <input type="password" name="confirm_password" id="rpConfirm" class="form-control" data-match="#rpPassword" placeholder="Confirm Password" required minlength="8">
                    <label for="rpConfirm">Confirm Password<span class="req-mark" aria-hidden="true">*</span></label>
                </div>
            </div>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="rpTerms" required>
            <label class="form-check-label small" for="rpTerms">I have read and agree to the <a href="<?= url('user-agreement') ?>" target="_blank" rel="noopener">User Agreement</a> and the <a href="<?= url('privacy-policy') ?>" target="_blank" rel="noopener">Privacy Policy</a>, including how EVORA uses my personal information.<span class="req-mark" aria-hidden="true">*</span></label>
        </div>
        <?= TurnstileService::widget() ?>
        <button type="submit" class="btn btn-evora-primary w-100">Create Account</button>
    </form>
    <p class="text-center small mt-3">Already have an account? <a href="<?= url('login') ?>">Login</a></p>
</div>
</body>
</html>
<?php clearOld(); ?>
