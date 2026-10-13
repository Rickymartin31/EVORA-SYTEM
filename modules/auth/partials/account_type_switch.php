<?php
/** Account type switch on the sign-up pages (v63): $accountType = 'participant' | 'organizer'. */
$types = [
    'participant' => ['route' => 'register-participant', 'icon' => 'bi-person', 'title' => 'Participant', 'sub' => 'Join events'],
    'organizer' => ['route' => 'register-organizer', 'icon' => 'bi-building', 'title' => 'Organizer', 'sub' => 'Host events'],
];
?>
<nav class="account-type-switch mb-4" aria-label="Account type">
    <?php foreach ($types as $key => $t): ?>
        <a href="<?= url($t['route']) ?>" class="account-type-option<?= $accountType === $key ? ' is-active' : '' ?>"<?= $accountType === $key ? ' aria-current="page"' : '' ?>>
            <i class="bi <?= $t['icon'] ?>" aria-hidden="true"></i>
            <span><strong><?= $t['title'] ?></strong><small><?= $t['sub'] ?></small></span>
        </a>
    <?php endforeach; ?>
</nav>
