<?php
/** Tabs shared by the Account Center pages. Expects $accountTab. */
$accountTabs = [
    'profile' => ['bi-person', 'Profile'],
    'account-settings' => ['bi-gear', 'Account Settings'],
    'notification-preferences' => ['bi-bell', 'Notification Preferences'],
    'account-security' => ['bi-shield-lock', 'Security'],
    'activity-log' => ['bi-clock-history', 'Activity Log'],
];
?>
<div class="account-header d-flex align-items-center gap-3 mb-3">
    <div>
        <h5 class="fw-bold mb-0">My Account</h5>
        <div class="small text-muted">Manage your profile, preferences and account security.</div>
    </div>
</div>
<ul class="nav account-tabs mb-4 flex-nowrap overflow-auto">
    <?php foreach ($accountTabs as $route => [$icon, $label]): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap <?= ($accountTab ?? '') === $route ? 'active' : '' ?>" href="<?= url($route) ?>"<?= ($accountTab ?? '') === $route ? ' aria-current="page"' : '' ?>>
                <i class="bi <?= $icon ?> me-1"></i><?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
