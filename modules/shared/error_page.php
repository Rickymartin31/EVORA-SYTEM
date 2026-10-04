<?php
/**
 * Branded error page (v54) for 404 / 403 (500 is drawn by ErrorMonitor, which cannot rely on templates).
 * Expects $errCode, $errTitle, $errMessage. Offers Back, and Home (or the user's dashboard).
 */
$errCode = (int) ($errCode ?? 404);
$errIcon = ['403' => 'bi-shield-lock', '404' => 'bi-signpost-split'][(string) $errCode] ?? 'bi-exclamation-triangle';
$errHome = Auth::check() ? url(Auth::role() . '-dashboard') : url('home');
$errHomeLabel = Auth::check() ? 'Go to my dashboard' : 'Go to home page';
$pageTitle = $errTitle;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($errTitle) ?> — EVORA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= assetUrl('css/theme.css') ?>" rel="stylesheet">
    <?= themeInit() ?>
    <meta name="robots" content="noindex">
</head>
<body class="error-shell">
<main class="error-card" role="main">
    <a href="<?= url('home') ?>" class="error-brand" aria-label="EVORA home"><span class="error-mark">E</span><span>EVO<b>RA</b></span></a>
    <div class="error-icon" aria-hidden="true"><i class="bi <?= $errIcon ?>"></i></div>
    <div class="error-code"><?= $errCode ?></div>
    <h1 class="error-title"><?= e($errTitle) ?></h1>
    <p class="error-text"><?= e($errMessage) ?></p>
    <div class="error-actions">
        <button type="button" class="btn btn-outline-secondary" onclick="history.length > 1 ? history.back() : location.href = '<?= e($errHome) ?>'"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Go back</button>
        <a href="<?= e($errHome) ?>" class="btn btn-evora-primary"><i class="bi bi-house-door me-1" aria-hidden="true"></i><?= e($errHomeLabel) ?></a>
    </div>
    <?php if ($errCode === 404): ?><p class="error-hint">Check the address, or use the menu to find what you need.</p><?php endif; ?>
</main>
</body>
</html>
