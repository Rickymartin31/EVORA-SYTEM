<?php

/**
 * Notification center (v31): categories, action links, unread counts and
 * read tracking on top of the existing notifications table.
 *
 *   system event -> notify($userId, $title, $message, $type, $meta)
 *   -> notifications row (category, notice_type, reference_type/id)
 *   -> bell count + dropdown, sidebar badges, Notifications page
 *   -> user opens it (POST + CSRF) -> marked read -> redirected to the module
 *
 * Links are never stored: they are built from reference_type/reference_id for
 * the role of the person viewing, so a notification can only lead to pages of
 * the viewer's own role (and every page re-checks access itself).
 */
class NotificationService
{
    public const CATEGORIES = [
        'payment' => ['Payments', 'bi-cash-coin', 'success'],
        'refund' => ['Refunds', 'bi-arrow-counterclockwise', 'danger'],
        'payout' => ['Payouts', 'bi-wallet2', 'primary'],
        'event' => ['Events', 'bi-calendar-event', 'info'],
        'system' => ['System', 'bi-info-circle', 'secondary'],
    ];

    public const NOTICE_TYPES = [
        'informational' => ['Info', 'secondary'],
        'action_required' => ['Action Required', 'warning'],
        'status' => ['Status Update', 'info'],
    ];

    /** Existing `type` keys -> [category, notice_type] (callers may override through $meta). */
    private const TYPE_DEFAULTS = [
        'payment_status' => ['payment', 'status'],
        'payment_submission' => ['payment', 'action_required'],
        'payment_received' => ['payment', 'informational'],
        'payment_issue' => ['payment', 'action_required'],
        'refund' => ['refund', 'status'],
        'finance' => ['payout', 'status'],
        'new_registration' => ['event', 'informational'],
        'registration_status' => ['event', 'status'],
        'registration_closed' => ['event', 'status'],
        'event_submitted' => ['event', 'action_required'],
        'event_cancellation_request' => ['event', 'action_required'],
        'event_approved' => ['event', 'status'],
        'event_rejected' => ['event', 'status'],
        'event_removed' => ['event', 'status'],
        'event_cancelled' => ['event', 'status'],
        'event_reminder' => ['event', 'informational'],
        'route_update' => ['event', 'informational'],
        'announcement' => ['event', 'informational'],
        'staff_assignment' => ['event', 'informational'],
        'certificate_issued' => ['event', 'status'],
        'organizer_application' => ['system', 'action_required'],
        'event_evaluation_request' => ['event', 'action_required'],
        'event_feedback_received' => ['event', 'informational'],
        'platform_feedback' => ['system', 'informational'],
        'platform_feedback_request' => ['system', 'action_required'],
        'organizer_status' => ['system', 'status'],
        'admin_announcement' => ['system', 'informational'],
        'payout_dispute' => ['payout', 'action_required'],
        'payout_timeliness' => ['payout', 'status'],
        'payout_receipt' => ['payout', 'action_required'],
        // v31.1 triggers
        'account_security' => ['system', 'action_required'],
        'account_status' => ['system', 'status'],
        'payout_agreement' => ['payout', 'action_required'],
        'event_update' => ['event', 'status'],
        'capacity_warning' => ['event', 'informational'],
        'check_in' => ['event', 'status'],
        'item_release' => ['event', 'status'],
        'incident' => ['event', 'action_required'],
        'staff_update' => ['event', 'informational'],
    ];

    // ---------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------

    /**
     * $meta (all optional): category, notice_type, reference_type, reference_id.
     * Unknown categories/notice types fall back to the defaults for $type.
     */
    public static function create(PDO $db, int $userId, string $title, string $message, string $type = 'general', array $meta = []): void
    {
        [$category, $noticeType] = self::TYPE_DEFAULTS[$type] ?? ['system', 'informational'];
        if (isset($meta['category'], self::CATEGORIES[$meta['category']])) {
            $category = $meta['category'];
        }
        if (isset($meta['notice_type'], self::NOTICE_TYPES[$meta['notice_type']])) {
            $noticeType = $meta['notice_type'];
        }
        $db->prepare(
            'INSERT INTO notifications (user_id, title, message, type, category, notice_type, reference_type, reference_id)
             VALUES (:user_id, :title, :message, :type, :category, :notice_type, :ref_type, :ref_id)'
        )->execute([
            'user_id' => $userId,
            'title' => mb_substr($title, 0, 200),
            'message' => mb_substr($message, 0, 500),
            'type' => mb_substr($type, 0, 50),
            'category' => $category,
            'notice_type' => $noticeType,
            'ref_type' => isset($meta['reference_type']) ? mb_substr((string) $meta['reference_type'], 0, 40) : null,
            'ref_id' => isset($meta['reference_id']) ? (int) $meta['reference_id'] : null,
        ]);
        self::queueEmail($db, $userId, $title, $message, $category, $noticeType, (int) $db->lastInsertId(), $meta);
    }

    /**
     * Also emails the notification (v35) when email is configured and the user keeps email
     * on. A muted category is not emailed, except Action Required (same rule as alerts).
     * Never blocks or fails the notification itself.
     */
    /**
     * What to attach to the email (v43): a payment receipt, the e-ticket and a calendar file when a
     * payment is confirmed; the e-ticket and calendar when a registration is approved with its QR ready.
     * The files themselves are built when the email is sent.
     */
    private static function emailAttachments(PDO $db, int $userId, string $title, array $meta): ?array
    {
        $refType = $meta['reference_type'] ?? null;
        $refId = (int) ($meta['reference_id'] ?? 0);
        if ($title === 'Payment Confirmed' && $refType === 'payment' && $refId) {
            $stmt = $db->prepare('SELECT r.id AS registration_id, r.event_id, (SELECT COUNT(*) FROM qr_codes q WHERE q.registration_id = r.id AND q.invalidated_at IS NULL) AS has_qr
                FROM payments pay JOIN registrations r ON r.id = pay.registration_id WHERE pay.id = :id');
            $stmt->execute(['id' => $refId]);
            if ($p = $stmt->fetch()) {
                return array_values(array_filter([
                    ['type' => 'receipt', 'payment_id' => $refId],
                    $p['has_qr'] ? ['type' => 'ticket', 'registration_id' => (int) $p['registration_id']] : null,
                    ['type' => 'ics', 'event_id' => (int) $p['event_id']],
                ]));
            }
        }
        if (in_array($title, ['Registration Approved', 'Registration Confirmed'], true) && $refType === 'event' && $refId) {
            $stmt = $db->prepare('SELECT r.id FROM registrations r JOIN participants p ON p.id = r.participant_id
                JOIN qr_codes q ON q.registration_id = r.id AND q.invalidated_at IS NULL WHERE r.event_id = :eid AND p.user_id = :uid AND r.status = "approved" LIMIT 1');
            $stmt->execute(['eid' => $refId, 'uid' => $userId]);
            if ($regId = (int) $stmt->fetchColumn()) {
                return [['type' => 'ticket', 'registration_id' => $regId], ['type' => 'ics', 'event_id' => $refId]];
            }
        }

        if (in_array($title, ['Registration Approved', 'Registration Confirmed'], true) && $refType === 'registration' && $refId) {
            // walk-in registrations point at the registration itself
            $stmt = $db->prepare('SELECT r.event_id FROM registrations r JOIN participants p ON p.id = r.participant_id
                JOIN qr_codes q ON q.registration_id = r.id AND q.invalidated_at IS NULL WHERE r.id = :rid AND p.user_id = :uid AND r.status = "approved" LIMIT 1');
            $stmt->execute(['rid' => $refId, 'uid' => $userId]);
            if ($eventId = (int) $stmt->fetchColumn()) {
                return [['type' => 'ticket', 'registration_id' => $refId], ['type' => 'ics', 'event_id' => $eventId]];
            }
        }

        return null;
    }

    private static function queueEmail(PDO $db, int $userId, string $title, string $message, string $category, string $noticeType, int $notificationId, array $meta = []): void
    {
        if (!class_exists('MailService', false)) {
            require_once EVORA_ROOT . '/core/MailService.php';
        }
        if (!MailService::enabled()) {
            return;
        }
        try {
            $stmt = $db->prepare('SELECT u.email, u.status, s.* FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.id = :uid');
            $stmt->execute(['uid' => $userId]);
            $row = $stmt->fetch();
            if (!$row || $row['status'] !== 'active' || (isset($row['notify_email']) && (int) $row['notify_email'] === 0)) {
                return;
            }
            $prefKey = 'notify_' . $category;
            if ($noticeType !== 'action_required' && isset($row[$prefKey]) && (int) $row[$prefKey] === 0) {
                return;
            }
            $attachments = self::emailAttachments($db, $userId, $title, $meta);
            $attachedNote = '';
            if ($attachments) {
                $names = ['receipt' => 'your payment receipt (PDF)', 'ticket' => 'your e-ticket with the check-in QR code (PDF)', 'ics' => 'a calendar file to add the event to your phone or calendar'];
                $attachedNote = "\n\nAttached: " . implode(', ', array_map(fn($a) => $names[$a['type']] ?? $a['type'], $attachments)) . '.';
            }
            $body = $message . $attachedNote . "\n\nOpen EVORA to see the details:\n" . MailService::link('notifications');
            MailService::queue($db, $userId, (string) $row['email'], 'EVORA: ' . $title, $body, $notificationId, $attachments);
        } catch (Throwable $e) {
            if (class_exists('Logger', false)) {
                Logger::log(null, 'email_queue_error', mb_substr($e->getMessage(), 0, 250));
            }
        }
    }

    /** Sends to every active admin. */
    public static function toAdmins(PDO $db, string $title, string $message, string $type, array $meta = []): void
    {
        foreach ($db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" AND u.status = "active"')->fetchAll(PDO::FETCH_COLUMN) as $adminUserId) {
            self::create($db, (int) $adminUserId, $title, $message, $type, $meta);
        }
    }

    // ---------------------------------------------------------------------
    // Trigger helpers (v31.1). A notification is a side effect: it must never
    // stop or roll back the action that caused it, so these swallow and log
    // their own errors.
    // ---------------------------------------------------------------------

    public static function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            if (class_exists('Logger', false)) {
                Logger::log(null, 'notification_error', mb_substr($e->getMessage(), 0, 250));
            } else {
                error_log('EVORA notification error: ' . $e->getMessage());
            }
        }
    }

    /** Every active admin except the one who acted (admins are not told about their own actions). */
    public static function toOtherAdmins(PDO $db, int $exceptAdminId, string $title, string $message, string $type, array $meta = []): void
    {
        self::safely(function () use ($db, $exceptAdminId, $title, $message, $type, $meta) {
            $stmt = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" AND u.status = "active" AND u.id <> :me');
            $stmt->execute(['me' => $exceptAdminId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminUserId) {
                self::create($db, (int) $adminUserId, $title, $message, $type, $meta);
            }
        });
    }

    /** The organizer who owns the event (skipped when $exceptUserId is that organizer). */
    public static function toEventOrganizer(PDO $db, int $eventId, string $title, string $message, string $type, array $meta = [], ?int $exceptUserId = null): void
    {
        self::safely(function () use ($db, $eventId, $title, $message, $type, $meta, $exceptUserId) {
            $stmt = $db->prepare('SELECT o.user_id FROM events e JOIN organizers o ON o.id = e.organizer_id WHERE e.id = :id');
            $stmt->execute(['id' => $eventId]);
            $userId = (int) $stmt->fetchColumn();
            if ($userId && $userId !== $exceptUserId) {
                self::create($db, $userId, $title, $message, $type, $meta);
            }
        });
    }

    /** Staff assigned to the event (optionally except the one who acted). */
    public static function toEventStaff(PDO $db, int $eventId, string $title, string $message, string $type, array $meta = [], ?int $exceptUserId = null): void
    {
        self::safely(function () use ($db, $eventId, $title, $message, $type, $meta, $exceptUserId) {
            $stmt = $db->prepare('SELECT DISTINCT es.user_id FROM event_staff es JOIN users u ON u.id = es.user_id WHERE es.event_id = :id AND u.status = "active"');
            $stmt->execute(['id' => $eventId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                if ((int) $userId !== $exceptUserId) {
                    self::create($db, (int) $userId, $title, $message, $type, $meta);
                }
            }
        });
    }

    /** Every approved, active organizer account. */
    public static function toApprovedOrganizers(PDO $db, string $title, string $message, string $type, array $meta = []): void
    {
        self::safely(function () use ($db, $title, $message, $type, $meta) {
            $ids = $db->query('SELECT o.user_id FROM organizers o JOIN users u ON u.id = o.user_id WHERE o.status = "approved" AND u.status = "active"')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $userId) {
                self::create($db, (int) $userId, $title, $message, $type, $meta);
            }
        });
    }

    /** Sends unless the same user already has this type for the same reference (e.g. "capacity 90%" once per event). */
    public static function once(PDO $db, int $userId, string $title, string $message, string $type, array $meta = []): bool
    {
        $sent = false;
        self::safely(function () use ($db, $userId, $title, $message, $type, $meta, &$sent) {
            $stmt = $db->prepare('SELECT 1 FROM notifications WHERE user_id = :u AND type = :t AND title = :title AND reference_type <=> :rt AND reference_id <=> :rid LIMIT 1');
            $stmt->execute(['u' => $userId, 't' => $type, 'title' => mb_substr($title, 0, 200), 'rt' => $meta['reference_type'] ?? null, 'rid' => isset($meta['reference_id']) ? (int) $meta['reference_id'] : null]);
            if (!$stmt->fetchColumn()) {
                self::create($db, $userId, $title, $message, $type, $meta);
                $sent = true;
            }
        });

        return $sent;
    }

    // ---------------------------------------------------------------------
    // Reading (always scoped to one user)
    // ---------------------------------------------------------------------

    /** Notification types that are "Evaluation Reminders" in the user's alert preferences (v33). */
    public const EVALUATION_TYPES = ['event_evaluation_request', 'platform_feedback_request', 'platform_feedback', 'event_feedback_received'];

    /**
     * SQL condition that drops the user's muted categories from ALERTS only
     * (bell count, dropdown, sidebar badges) — notifications are still created
     * and listed on the Notifications page. Action-required ones always alert.
     * $muted holds preference keys (category names or 'evaluation'); anything
     * unknown is ignored, so the fragment only ever contains fixed values.
     */
    public static function alertFilterSql(array $muted): string
    {
        $evaluationTypes = "'" . implode("', '", self::EVALUATION_TYPES) . "'";
        $conditions = [];
        foreach (array_unique($muted) as $key) {
            if ($key === 'evaluation') {
                $conditions[] = "type IN ($evaluationTypes)";
            } elseif (isset(self::CATEGORIES[$key])) {
                $conditions[] = "(category = '$key' AND type NOT IN ($evaluationTypes))";
            }
        }

        return $conditions ? " AND (notice_type = 'action_required' OR NOT (" . implode(' OR ', $conditions) . '))' : '';
    }

    public static function unreadCount(PDO $db, int $userId, array $muted = []): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0' . self::alertFilterSql($muted));
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public static function recent(PDO $db, int $userId, int $limit = 10, array $muted = []): array
    {
        $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = :uid' . self::alertFilterSql($muted) . ' ORDER BY is_read ASC, id DESC LIMIT ' . max(1, min(50, $limit)));
        $stmt->execute(['uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** $tab: '' (all), 'unread', or a category. */
    public static function listForUser(PDO $db, int $userId, string $tab = '', int $limit = 200): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = :uid';
        $params = ['uid' => $userId];
        if ($tab === 'unread') {
            $sql .= ' AND is_read = 0';
        } elseif (isset(self::CATEGORIES[$tab])) {
            $sql .= ' AND category = :cat';
            $params['cat'] = $tab;
        }
        $stmt = $db->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Unread counts per category plus 'all'. */
    public static function unreadByCategory(PDO $db, int $userId): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $stmt = $db->prepare('SELECT category, COUNT(*) c FROM notifications WHERE user_id = :uid AND is_read = 0 GROUP BY category');
        $stmt->execute(['uid' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['category']] = (int) $row['c'];
        }
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    public static function findForUser(PDO $db, int $notificationId, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM notifications WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $notificationId, 'uid' => $userId]);

        return $stmt->fetch() ?: null;
    }

    // ---------------------------------------------------------------------
    // Read tracking
    // ---------------------------------------------------------------------

    public static function markRead(PDO $db, int $notificationId, int $userId): bool
    {
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :uid AND is_read = 0');
        $stmt->execute(['id' => $notificationId, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** $category '' = all categories. */
    public static function markAllRead(PDO $db, int $userId, string $category = ''): int
    {
        $sql = 'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = :uid AND is_read = 0';
        $params = ['uid' => $userId];
        if (isset(self::CATEGORIES[$category])) {
            $sql .= ' AND category = :cat';
            $params['cat'] = $category;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * "Remove badge when viewed": opening a sidebar module marks the viewer's
     * unread notifications that belong to that module as read.
     */
    public static function markModuleViewed(PDO $db, int $userId, string $role, string $route): int
    {
        if ($route === '') {
            return 0;
        }
        $stmt = $db->prepare('SELECT id, type, category, reference_type FROM notifications WHERE user_id = :uid AND is_read = 0');
        $stmt->execute(['uid' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $n) {
            if (self::moduleFor($n, $role) === $route) {
                $ids[] = (int) $n['id'];
            }
        }
        if (!$ids) {
            return 0;
        }
        $update = $db->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $update->execute(array_merge([$userId], $ids));

        return $update->rowCount();
    }

    // ---------------------------------------------------------------------
    // Sidebar badges and links
    // ---------------------------------------------------------------------

    /**
     * The sidebar module a notification belongs to, for the viewer's role.
     * Also the fallback link target when the notification has no reference.
     */
    public static function moduleFor(array $n, string $role): ?string
    {
        $type = $n['type'] ?? '';
        $ref = $n['reference_type'] ?? '';
        $category = $n['category'] ?? 'system';

        switch ($role) {
            case 'admin':
                return match (true) {
                    $type === 'organizer_application' || $ref === 'organizer' => 'admin-organizers',
                    $type === 'badge_application' || $ref === 'organizer_badge' => 'admin-badges',
                    $type === 'event_submitted' => 'admin-event-approvals',
                    $ref === 'user' => 'admin-users',
                    $category === 'payment' => 'admin-paypal-transactions',
                    $category === 'event' => 'admin-events',
                    default => null,
                };
            case 'organizer':
                return match (true) {
                    $type === 'new_registration' || $ref === 'registration' => 'organizer-registrations',
                    $type === 'organizer_badge' || $ref === 'organizer_badge' => 'organizer-verified-badge',
                    $category === 'payment' => 'organizer-payment-transactions',
                    $category === 'event' => 'organizer-events',
                    default => null,
                };
            case 'participant':
                return match (true) {
                    $type === 'certificate_issued' => 'my-certificate',
                    in_array($category, ['payment', 'refund'], true) || in_array($type, ['registration_status', 'event_cancelled', 'check_in', 'item_release'], true) => 'my-registrations',
                    default => null,
                };
            case 'staff':
                return $category === 'event' ? 'staff-dashboard' : null;
        }

        return null;
    }

    /**
     * Where "View"/"Review" leads for the viewer's role: [route, params, label].
     * Specific record when the notification references one, else its module,
     * else the Notifications page.
     */
    public static function link(array $n, string $role): array
    {
        $label = ($n['notice_type'] ?? '') === 'action_required' ? 'Review' : 'View';
        $ref = $n['reference_type'] ?? null;
        $id = (int) ($n['reference_id'] ?? 0);

        // v32.1 "Share your experience using EVORA." opens the ⭐ Rate EVORA dialog.
        if (($n['type'] ?? '') === 'platform_feedback_request') {
            $dashboard = ['participant' => 'participant-dashboard', 'organizer' => 'organizer-dashboard', 'staff' => 'staff-dashboard'][$role] ?? 'notifications';
            return [$dashboard, $dashboard === 'notifications' ? [] : ['rate_evora' => 1], $label];
        }
        if ($ref && $id > 0) {
            // v32 evaluation notifications open the evaluation screens.
            $evaluationTarget = match ($role . ':' . ($n['type'] ?? '')) {
                'participant:event_evaluation_request' => ['event-feedback', ['event_id' => $id]],
                'organizer:event_feedback_received' => ['organizer-event-feedback', ['event_id' => $id]],
                'admin:platform_feedback' => ['admin-evaluations', []],
                default => null,
            };
            if ($evaluationTarget) {
                return [$evaluationTarget[0], $evaluationTarget[1], $label];
            }
            $target = match ($role . ':' . $ref) {
                'admin:organizer' => ['admin-organizer-review', ['id' => $id]],
                'admin:organizer_badge' => ['admin-badge-review', ['id' => $id]],
                'organizer:organizer_badge' => ['organizer-verified-badge', []],
                'admin:event' => ($n['type'] ?? '') === 'event_submitted' ? ['admin-event-approvals', []] : ['admin-events', []],
                'organizer:event' => ['organizer-event-manage', ['id' => $id]],
                'organizer:registration' => ['organizer-registrations', []],
                'participant:event' => ['event-view', ['id' => $id]],
                'participant:registration', 'participant:payment' => ['my-registrations', []],
                'admin:user' => ['admin-users', []],
                'organizer:incident', 'staff:incident' => ['incident-view', ['id' => $id]],
                'organizer:medical_incident', 'staff:medical_incident' => ['medical-incident-view', ['id' => $id]],
                'staff:event' => ['staff-dashboard', []],
                default => null,
            };
            if ($target) {
                return [$target[0], $target[1], $label];
            }
        }

        $module = self::moduleFor($n, $role);

        return [$module ?? 'notifications', [], $label];
    }

    /** Sidebar badge counts: route => unread notifications for that module. */
    public static function sidebarCounts(PDO $db, int $userId, string $role, array $muted = []): array
    {
        $stmt = $db->prepare('SELECT type, category, reference_type FROM notifications WHERE user_id = :uid AND is_read = 0' . self::alertFilterSql($muted));
        $stmt->execute(['uid' => $userId]);
        $counts = ['notifications' => 0];
        foreach ($stmt->fetchAll() as $n) {
            $counts['notifications']++;
            if ($module = self::moduleFor($n, $role)) {
                $counts[$module] = ($counts[$module] ?? 0) + 1;
            }
        }

        return $counts;
    }

    // ---------------------------------------------------------------------
    // Display helpers
    // ---------------------------------------------------------------------

    /** Category icon; security notices (account_security / account_status) show a shield. */
    public static function icon(string $category, string $type = ''): string
    {
        [, $icon, $color] = self::CATEGORIES[$category] ?? self::CATEGORIES['system'];
        if (in_array($type, ['account_security', 'account_status'], true)) {
            [$icon, $color] = ['bi-shield-lock', 'danger'];
        }

        return '<i class="bi ' . $icon . ' text-' . $color . '"></i>';
    }

    public static function categoryBadge(string $category): string
    {
        [$label, , $color] = self::CATEGORIES[$category] ?? self::CATEGORIES['system'];

        return '<span class="badge bg-' . $color . '-subtle text-' . $color . '-emphasis border">' . e(rtrim($label, 's')) . '</span>';
    }

    public static function noticeBadge(string $noticeType): string
    {
        [$label, $color] = self::NOTICE_TYPES[$noticeType] ?? self::NOTICE_TYPES['informational'];

        return '<span class="badge bg-' . $color . ($color === 'warning' ? ' text-dark' : '') . '">' . e($label) . '</span>';
    }

    /** "2 minutes ago", "Yesterday", "Sep 28, 2026". */
    public static function timeAgo(string $datetime): string
    {
        $seconds = time() - strtotime($datetime);
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($seconds < 86400 && date('Y-m-d', strtotime($datetime)) === date('Y-m-d')) {
            $h = intdiv($seconds, 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if (date('Y-m-d', strtotime($datetime)) === date('Y-m-d', strtotime('-1 day'))) {
            return 'Yesterday';
        }

        return date('M d, Y', strtotime($datetime));
    }
}
