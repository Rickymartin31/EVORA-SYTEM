<?php
Auth::requireLogin();
$db = Database::connection();
$userId = Auth::id();
$role = (string) Auth::role();

// Tabs: All, Unread, then one per category. Every query is scoped to the signed-in user.
$tabs = ['' => 'All', 'unread' => 'Unread'];
foreach (NotificationService::CATEGORIES as $key => [$label]) {
    $tabs[$key] = $label;
}
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? ($_GET['tab'] ?? '') : '';
$notifications = NotificationService::listForUser($db, $userId, $tab);
$unread = NotificationService::unreadByCategory($db, $userId);

$pageDescription = 'All your notifications. Open one to go to the related page.';
$pageTitle = 'Notifications';
$activeRoute = 'notifications';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h6 class="fw-semibold mb-0">Notifications <?= $unread['all'] ? '<span class="badge bg-danger rounded-pill">' . $unread['all'] . ' unread</span>' : '' ?></h6>
        <?php $tabUnread = $tab === '' || $tab === 'unread' ? $unread['all'] : ($unread[$tab] ?? 0); ?>
        <?php if ($tabUnread > 0): ?>
        <form method="post" action="<?= url('notification-action') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="do" value="mark_all_read">
            <input type="hidden" name="category" value="<?= isset(NotificationService::CATEGORIES[$tab]) ? e($tab) : '' ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-check2-all me-1"></i> Mark all as read<?= isset(NotificationService::CATEGORIES[$tab]) ? ' (' . e($tabs[$tab]) . ')' : '' ?></button>
        </form>
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs small mb-3 flex-nowrap overflow-auto">
        <?php foreach ($tabs as $key => $label): $count = $key === '' ? 0 : ($key === 'unread' ? $unread['all'] : ($unread[$key] ?? 0)); ?>
            <li class="nav-item">
                <a class="nav-link text-nowrap <?= $tab === $key ? 'active' : '' ?>" href="<?= url('notifications', $key !== '' ? ['tab' => $key] : []) ?>">
                    <?= e($label) ?><?= $count ? ' <span class="badge bg-danger rounded-pill">' . $count . '</span>' : '' ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (empty($notifications)): ?>
        <?= emptyState('bi-bell-slash', $tab === 'unread' ? 'You are all caught up.' : 'No notifications here yet.') ?>
    <?php else: ?>
    <ul class="list-unstyled mb-0">
        <?php foreach ($notifications as $n): [, , $linkLabel] = NotificationService::link($n, $role); ?>
            <li class="notif-row border-bottom py-3 px-2 d-flex gap-3 <?= $n['is_read'] ? '' : 'notif-unread' ?>">
                <div class="fs-5"><?= NotificationService::icon($n['category'], (string) $n['type']) ?></div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <strong class="small"><?= e($n['title']) ?></strong>
                        <?= NotificationService::categoryBadge($n['category']) ?>
                        <?php if ($n['notice_type'] === 'action_required'): ?><?= NotificationService::noticeBadge('action_required') ?><?php endif; ?>
                        <?= $n['is_read'] ? '<span class="badge bg-light text-muted border">Read</span>' : '<span class="badge bg-danger">Unread</span>' ?>
                    </div>
                    <p class="small mb-1 text-muted notif-message"><?= e($n['message']) ?></p>
                    <span class="text-muted small" title="<?= e(date('M d, Y g:i A', strtotime($n['created_at']))) ?>"><?= e(NotificationService::timeAgo($n['created_at'])) ?> · <?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></span>
                </div>
                <div class="d-flex flex-column align-items-end gap-1 text-nowrap">
                    <form method="post" action="<?= url('notification-action') ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="do" value="open">
                        <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $linkLabel === 'Review' ? 'btn-warning' : 'btn-outline-primary' ?>"><?= e($linkLabel) ?></button>
                    </form>
                    <div class="d-flex gap-2">
                        <?php if (!$n['is_read']): ?>
                        <form method="post" action="<?= url('notification-action') ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="do" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-link p-0">Mark as read</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?= url('notification-action') ?>" data-confirm="Delete this notification?">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="do" value="delete">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0">Delete</button>
                        </form>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
