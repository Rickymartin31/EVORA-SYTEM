<?php $pageTitle = $pageTitle ?? 'EVORA'; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — EVORA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= assetUrl('css/theme.css') ?>" rel="stylesheet">
    <?= themeInit() ?>
    <?= pageHead($pageMeta ?? []) ?>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark" style="background:var(--evora-sidebar-bg);">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= url('home') ?>">EVO<span style="color:var(--evora-accent)">RA</span></a>
        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-sm theme-toggle theme-toggle-nav" data-theme-toggle aria-label="Switch theme"></button>
            <a href="<?= url('events') ?>" class="btn btn-outline-light btn-sm">Browse Events</a>
            <a href="<?= url('login') ?>" class="btn btn-outline-light btn-sm">Login</a>
            <a href="<?= url('register-participant') ?>" class="btn btn-light btn-sm">Sign Up</a>
        </div>
    </div>
</nav>
<div class="container py-4">
    <?php if ($flashSuccess = flash('success')): ?><?= evoraAlert('success', e($flashSuccess), ['autohide' => 5000]) ?><?php endif; ?>
    <?php if ($flashError = flash('error')): ?><?= evoraAlert('error', e($flashError)) ?><?php endif; ?>
