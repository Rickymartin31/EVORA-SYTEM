<?php
/** Expects $pageTitle and optionally $activeRoute to be set by the including page. */
$pageTitle = $pageTitle ?? 'EVORA';

$unreadCount = 0;
$recentNotifications = [];
$topbarAvatar = null;
$navCounts = [];
$rateEvora = null;
if (Auth::check()) {
    // ⭐ Rate EVORA (participants, organizers, staff): own evaluation state, and a
    // one-time "Share your experience" reminder once a milestone is reached.
    if (in_array(Auth::role(), EvaluationService::PLATFORM_ROLES, true)) {
        EvaluationService::ensurePlatformReminder(Database::connection(), Auth::id(), (string) Auth::role());
        $rateEvoraEval = EvaluationService::platformEvaluation(Database::connection(), Auth::id());
        $rateEvora = [
            'evaluation' => $rateEvoraEval,
            'ineligible' => $rateEvoraEval ? null : EvaluationService::platformIneligibleReason(Database::connection(), Auth::id(), (string) Auth::role()),
        ];
    }
    // Opening a module clears the badge for that module's notifications.
    NotificationService::markModuleViewed(Database::connection(), Auth::id(), (string) Auth::role(), $activeRoute ?? '');
    // Alerts honour the user's Notification Preferences (records are never hidden from the Notifications page).
    $mutedAlerts = AccountService::mutedAlerts(Database::connection(), Auth::id());
    $unreadCount = NotificationService::unreadCount(Database::connection(), Auth::id(), $mutedAlerts);
    $recentNotifications = NotificationService::recent(Database::connection(), Auth::id(), 10, $mutedAlerts);
    $navCounts = NotificationService::sidebarCounts(Database::connection(), Auth::id(), (string) Auth::role(), $mutedAlerts);

    $avatarStmt = Database::connection()->prepare('SELECT profile_picture_path, full_name, email FROM users WHERE id = :uid');
    $avatarStmt->execute(['uid' => Auth::id()]);
    $topbarUser = $avatarStmt->fetch() ?: [];
    $topbarAvatar = $topbarUser['profile_picture_path'] ?? null;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e(systemSettings()['system_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= assetUrl('css/theme.css') ?>" rel="stylesheet">
    <?= themeInit() ?>
    <?= pageHead($pageMeta ?? []) ?>
</head>
<body<?= Auth::role() === 'participant' ? ' class="has-bottom-nav"' : '' ?> data-session-timeout="<?= (int) SESSION_TIMEOUT ?>" data-session-ping="<?= e(url('session-ping')) ?>" data-session-login="<?= e(url('login', ['expired' => 1])) ?>" data-session-logout="<?= e(url('logout')) ?>">
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>
<div class="evora-main">
    <header class="evora-topbar">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarMobileToggle" aria-controls="evoraSidebar" aria-expanded="false" aria-label="Open navigation menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none d-lg-inline-block" id="sidebarCollapseToggle" title="Collapse sidebar" aria-label="Collapse sidebar">
                <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
            </button>
            <h1 class="h5 mb-0 fw-bold"><?= e($pageTitle) ?></h1>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="topbar-search" data-search-open data-search-url="<?= e(url('global-search')) ?>" aria-label="Search (Ctrl+K)" title="Search (Ctrl+K)"><i class="bi bi-search" aria-hidden="true"></i><span class="ts-label">Search…</span><kbd>Ctrl K</kbd></button>
            <button type="button" class="btn btn-link p-0 text-decoration-none theme-toggle" data-theme-toggle aria-label="Switch theme"></button>
            <div class="dropdown">
                <a href="#" class="position-relative text-dark text-decoration-none dropdown-toggle notif-bell" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notifications<?= $unreadCount ? ' (' . $unreadCount . ' unread)' : '' ?>">
                    <i class="bi bi-bell fs-5" aria-hidden="true"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-count<?= $unreadCount > 0 ? '' : ' d-none' ?>" style="font-size:.6rem;"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                </a>
                <?php /* Live refresh: app.js polls notifications-poll and swaps this menu while it is closed. */ ?>
                <div class="dropdown-menu dropdown-menu-end p-0 shadow notif-dropdown" data-notif-poll="<?= e(url('notifications-poll')) ?>" data-notif-unread="<?= (int) $unreadCount ?>">
                    <?php include __DIR__ . '/notification_dropdown.php'; ?>
                </div>
            </div>
            <?php if ($rateEvora): ?>
            <button type="button" class="btn btn-link p-0 text-decoration-none rate-evora-btn <?= $rateEvora['evaluation'] ? 'rated' : '' ?>" data-bs-toggle="modal" data-bs-target="#rateEvoraModal"
                    title="<?= $rateEvora['evaluation'] ? 'You rated EVORA — thank you!' : 'Rate EVORA System' ?>" aria-label="<?= $rateEvora['evaluation'] ? 'EVORA rated' : 'Rate EVORA System' ?>">
                <i class="bi <?= $rateEvora['evaluation'] ? 'bi-star-fill' : 'bi-star' ?> fs-5"></i><?php if ($rateEvora['evaluation']): ?><i class="bi bi-check-lg rate-evora-check"></i><?php endif; ?>
            </button>
            <?php endif; ?>
            <div class="dropdown">
                <a class="d-flex align-items-center text-decoration-none text-dark dropdown-toggle account-toggle-btn" href="#" data-bs-toggle="dropdown" aria-label="Account menu">
                    <?php if ($topbarAvatar): ?>
                        <img src="<?= uploadUrl($topbarAvatar) ?>" alt="" class="avatar avatar-30 me-1" data-fallback-initial="<?= e(mb_strtoupper(mb_substr($topbarUser['full_name'] ?? '?', 0, 1))) ?>">
                    <?php else: ?>
                        <span class="account-avatar-sm account-avatar-initials me-1"><?= e(mb_strtoupper(mb_substr($topbarUser['full_name'] ?? '?', 0, 1))) ?></span>
                    <?php endif; ?>
                    <span class="d-none d-sm-inline small fw-semibold"><?= e($topbarUser['full_name'] ?? '') ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end shadow account-menu p-0">
                    <div class="account-menu-header d-flex align-items-center gap-2 px-3 py-3">
                        <?php if ($topbarAvatar): ?>
                            <img src="<?= uploadUrl($topbarAvatar) ?>" alt="" class="avatar avatar-42" data-fallback-initial="<?= e(mb_strtoupper(mb_substr($topbarUser['full_name'] ?? '?', 0, 1))) ?>">
                        <?php else: ?>
                            <span class="account-avatar-md account-avatar-initials"><?= e(mb_strtoupper(mb_substr($topbarUser['full_name'] ?? '?', 0, 1))) ?></span>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate"><?= e($topbarUser['full_name'] ?? '') ?></div>
                            <div class="small text-muted text-truncate"><?= e($topbarUser['email'] ?? '') ?></div>
                            <span class="badge bg-light text-dark border mt-1"><?= e(AccountService::roleLabel((string) Auth::role())) ?></span>
                        </div>
                    </div>
                    <div class="py-1">
                        <a class="dropdown-item account-menu-item" href="<?= url('profile') ?>"><i class="bi bi-person"></i>Profile</a>
                        <a class="dropdown-item account-menu-item" href="<?= url('account-settings') ?>"><i class="bi bi-gear"></i>Account Settings</a>
                        <a class="dropdown-item account-menu-item" href="<?= url('notification-preferences') ?>"><i class="bi bi-bell"></i>Notification Preferences</a>
                        <a class="dropdown-item account-menu-item" href="<?= url('account-security') ?>"><i class="bi bi-shield-lock"></i>Security</a>
                        <a class="dropdown-item account-menu-item" href="<?= url('activity-log') ?>"><i class="bi bi-clock-history"></i>Activity Log</a>
                    </div>
                    <div class="border-top py-1">
                        <a class="dropdown-item account-menu-item d-none" href="#" data-pwa-install><i class="bi bi-phone"></i>Install EVORA App</a>
                        <a class="dropdown-item account-menu-item text-danger" href="<?= url('logout') ?>"><i class="bi bi-box-arrow-right"></i>Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <?php include __DIR__ . '/rate_evora_modal.php'; ?>
    <main class="evora-content" id="main-content" tabindex="-1">
        <?php $flashSuccess = flash('success'); $flashError = flash('error'); ?>
        <?php /* Success: brief toast (4 s). Error: stays until the user closes it. */ ?>
        <?php if ($flashSuccess): ?><?= evoraAlert('success', e($flashSuccess), ['toast' => true]) ?><?php endif; ?>
        <?php if ($flashError): ?><?= evoraAlert('error', e($flashError)) ?><?php endif; ?>
        <?php if (!empty($pageDescription) || !empty($pageActions)): ?>
        <?php /* Page header: pages set $pageDescription (plain text) and optionally $pageActions (trusted HTML built by the page). */ ?>
        <div class="page-header">
            <div class="page-header-text">
                <?php if (!empty($pageDescription)): ?><p class="page-description"><?= e($pageDescription) ?></p><?php endif; ?>
            </div>
            <?php if (!empty($pageActions)): ?><div class="page-actions"><?= $pageActions ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
