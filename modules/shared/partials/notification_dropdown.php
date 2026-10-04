<?php
/**
 * Bell dropdown content (header, latest notifications, footer). Rendered by layout_top.php
 * and by notifications-poll for the live refresh. Expects $unreadCount and $recentNotifications
 * (both already scoped to the logged-in user and their notification preferences).
 */
?>
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <strong class="small">Notifications<?= $unreadCount ? ' <span class="badge bg-danger rounded-pill">' . $unreadCount . ' new</span>' : '' ?></strong>
                        <?php if ($unreadCount > 0): ?>
                        <form method="post" action="<?= url('notification-action') ?>" class="m-0">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="do" value="mark_all_read">
                            <button type="submit" class="btn btn-link btn-sm p-0 small">Mark all as read</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="notif-dropdown-list">
                    <?php if (empty($recentNotifications)): ?>
                        <div class="empty-state empty-state-sm"><i class="bi bi-bell-slash" aria-hidden="true"></i><p>No notifications yet.</p></div>
                    <?php else: ?>
                        <?php foreach ($recentNotifications as $n): [, , $linkLabel] = NotificationService::link($n, (string) Auth::role()); ?>
                            <div class="notif-item d-flex gap-2 px-3 py-2 border-bottom <?= $n['is_read'] ? '' : 'notif-unread' ?>">
                                <div class="pt-1"><?= NotificationService::icon($n['category'], (string) $n['type']) ?></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="small fw-semibold"><?= e($n['title']) ?></div>
                                        <?php if (!$n['is_read']): ?><span class="notif-dot" title="Unread"></span><?php endif; ?>
                                    </div>
                                    <div class="small text-muted notif-message"><?= e($n['message']) ?></div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <span class="text-muted" style="font-size:.7rem;" title="<?= e(date('M d, Y g:i A', strtotime($n['created_at']))) ?>"><?= e(NotificationService::timeAgo($n['created_at'])) ?></span>
                                        <div class="d-flex gap-2">
                                            <?php if (!$n['is_read']): ?>
                                            <form method="post" action="<?= url('notification-action') ?>" class="m-0">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="do" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                                <button type="submit" class="btn btn-link btn-sm p-0 text-muted" style="font-size:.75rem;">Mark read</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" action="<?= url('notification-action') ?>" class="m-0">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="do" value="open">
                                                <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= $linkLabel === 'Review' ? 'btn-warning' : 'btn-outline-primary' ?> py-0 px-2" style="font-size:.75rem;"><?= e($linkLabel) ?></button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                    <div class="text-center py-2">
                        <a href="<?= url('notifications') ?>" class="small">View All Notifications</a>
                    </div>
