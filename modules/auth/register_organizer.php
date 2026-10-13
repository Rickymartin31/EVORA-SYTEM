<?php

if (Auth::check()) {
    redirect(Auth::role() . '-dashboard');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $fullName = trim($_POST['full_name'] ?? '');
    $orgName = trim($_POST['organization_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $orgInfo = trim($_POST['organization_info'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($fullName === '' || $orgName === '' || $contactNumber === '' || $email === '' || $password === '') {
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

    $validIdPath = null;
    $docsPath = null;

    if (empty($errors)) {
        if (empty($_FILES['valid_id']['name'])) {
            $errors[] = 'A valid ID upload is required.';
        } else {
            $validIdPath = Upload::handle($_FILES['valid_id'], 'ids', UPLOAD_ALLOWED_DOC);
            if (!$validIdPath) {
                $errors[] = 'Valid ID must be a JPG, PNG, or PDF under 5MB.';
            }
        }

        if (!empty($_FILES['supporting_docs']['name'])) {
            $docsPath = Upload::handle($_FILES['supporting_docs'], 'docs', UPLOAD_ALLOWED_DOC);
            if (!$docsPath) {
                $errors[] = 'Supporting document must be a JPG, PNG, or PDF under 5MB.';
            }
        }
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
            $roleStmt = $db->query('SELECT id FROM roles WHERE name = "organizer"');
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
                'phone' => $contactNumber,
            ]);
            $userId = (int) $db->lastInsertId();
            $db->prepare('UPDATE users SET terms_accepted_at = NOW(), terms_version = :v WHERE id = :id')->execute(['v' => EVORA_LEGAL_VERSION, 'id' => $userId]);

            $stmt = $db->prepare(
                'INSERT INTO organizers (user_id, organization_name, contact_number, organization_info, status)
                 VALUES (:user_id, :org_name, :contact, :info, "pending")'
            );
            $stmt->execute([
                'user_id' => $userId,
                'org_name' => $orgName,
                'contact' => $contactNumber,
                'info' => $orgInfo ?: null,
            ]);
            $organizerId = (int) $db->lastInsertId();

            $stmt = $db->prepare(
                'INSERT INTO organizer_verifications (organizer_id, valid_id_path, supporting_docs_path)
                 VALUES (:organizer_id, :valid_id, :docs)'
            );
            $stmt->execute([
                'organizer_id' => $organizerId,
                'valid_id' => $validIdPath,
                'docs' => $docsPath,
            ]);

            $db->commit();
            Logger::log($userId, 'register', 'Organizer application submitted');

            // Notify all admins
            $admins = $db->query(
                'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin"'
            )->fetchAll();
            foreach ($admins as $admin) {
                notify((int) $admin['id'], 'New Organizer Application', $orgName . ' has applied to become an organizer.', 'organizer_application',
                    ['category' => 'system', 'notice_type' => 'action_required', 'reference_type' => 'organizer', 'reference_id' => $organizerId]);
            }

            $needsVerify = EmailVerificationService::startForNewAccount($db, $userId);
            flash('success', 'Application submitted! ' . ($needsVerify ? 'Please verify your email first: we sent a link to ' . $email . '. ' : '') . 'You will be notified once an administrator reviews your account.');
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
    <title>Apply as Organizer — EVORA</title>
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
<div class="auth-card" style="max-width:620px;">
    <h3 class="fw-bold mb-1">Apply as Organizer</h3>
    <p class="text-muted small mb-3">Submit your organization details and verification documents for admin review.</p>
    <?php $accountType = 'organizer'; include EVORA_ROOT . '/modules/auth/partials/account_type_switch.php'; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" action="<?= url('register-organizer') ?>" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-building me-1"></i>Organization Information</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="text" name="full_name" id="orgFullName" class="form-control" placeholder="Full Name" value="<?= e(old('full_name')) ?>" required>
                    <label for="orgFullName">Full Name *</label>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="text" name="organization_name" id="orgName" class="form-control" placeholder="Organization Name" value="<?= e(old('organization_name')) ?>" required>
                    <label for="orgName">Organization Name *</label>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="text" name="contact_number" id="orgContact" class="form-control" placeholder="Contact Number" value="<?= e(old('contact_number')) ?>" required>
                    <label for="orgContact">Contact Number *</label>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="email" name="email" id="orgEmail" class="form-control" placeholder="name@example.com" value="<?= e(old('email')) ?>" required>
                    <label for="orgEmail">Email Address *</label>
                </div>
            </div>
        </div>
        <div class="form-floating mb-4">
            <textarea name="organization_info" id="orgInfo" class="form-control" placeholder="Organization Information" style="height:100px;"><?= e(old('organization_info')) ?></textarea>
            <label for="orgInfo">Organization Information</label>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-3"><i class="bi bi-file-earmark-check me-1"></i>Verification Documents</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Valid ID *</label>
                <input type="file" name="valid_id" class="form-control" accept=".jpg,.jpeg,.png,.pdf" data-preview="#validIdPreview" required>
                <div class="form-text">JPG, PNG, or PDF, max 5MB.</div>
                <div id="validIdPreview"></div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Supporting Documents</label>
                <input type="file" name="supporting_docs" class="form-control" accept=".jpg,.jpeg,.png,.pdf" data-preview="#docsPreview">
                <div class="form-text">Optional — e.g. SEC/DTI registration.</div>
                <div id="docsPreview"></div>
            </div>
        </div>

        <h6 class="text-uppercase text-muted small fw-bold mb-3 mt-2"><i class="bi bi-shield-lock me-1"></i>Account Security</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="password" name="password" id="orgPassword" class="form-control" data-strength placeholder="Password" required minlength="8">
                    <label for="orgPassword">Password *</label>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="form-floating">
                    <input type="password" name="confirm_password" id="orgConfirm" class="form-control" data-match="#orgPassword" placeholder="Confirm Password" required minlength="8">
                    <label for="orgConfirm">Confirm Password *</label>
                </div>
            </div>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="accept_terms" value="1" id="orgTerms" required>
            <label class="form-check-label small" for="orgTerms">I have read and agree to the <a href="<?= url('user-agreement') ?>" target="_blank" rel="noopener">User Agreement</a> and the <a href="<?= url('privacy-policy') ?>" target="_blank" rel="noopener">Privacy Policy</a>, including how EVORA uses my personal information.</label>
        </div>
        <?= TurnstileService::widget() ?>
        <button type="submit" class="btn btn-evora-primary w-100">Submit Application</button>
    </form>
    <p class="text-center small mt-3">Already have an account? <a href="<?= url('login') ?>">Login</a></p>
</div>
</body>
</html>
<?php clearOld(); ?>
