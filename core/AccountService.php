<?php

/**
 * User Account Center (v33): personal settings, notification alert
 * preferences, login history and the user's own activity log.
 *
 * Every method works on ONE user id, which callers always take from the
 * session (Auth::id()) — there is no way to pass another user's id from a form.
 *
 * Notification preferences only control ALERTS (bell count, dropdown, sidebar
 * badges). Notifications are still created and stay on the Notifications
 * page; "Action Required" notifications always alert.
 */
class AccountService
{
    /** Preference key => [label, description]. Keys match user_settings.notify_<key>. */
    public const NOTIFY_OPTIONS = [
        'payment' => ['Payment Notifications', 'Payments confirmed, failed or received.'],
        'refund' => ['Refund Notifications', 'Refund requests, approvals and completions.'],
        'payout' => ['Payout Updates', 'Payout requests, approvals and transfers.'],
        'event' => ['Event Updates', 'Registrations, event approvals, cancellations and reminders.'],
        'system' => ['System Announcements', 'Announcements and account updates from EVORA.'],
        'evaluation' => ['Evaluation Reminders', 'Requests to rate an event or EVORA itself.'],
    ];

    public const LANGUAGES = ['en' => 'English'];

    public const TIMEZONES = ['Asia/Manila' => 'Philippines (Asia/Manila, UTC+8)', 'UTC' => 'UTC'];

    /** Friendly names for activity_logs.action. */
    private const ACTION_LABELS = [
        'login' => 'Login successful',
        'logout' => 'Logged out',
        'register' => 'Account created',
        'profile_updated' => 'Updated profile',
        'password_changed' => 'Changed password',
        'account_preferences_updated' => 'Updated account preferences',
        'notification_preferences_updated' => 'Updated notification preferences',
        'notification_read' => 'Read notification',
        'evaluation_submitted' => 'Submitted EVORA evaluation',
        'feedback_submitted' => 'Submitted event feedback',
        'registration_submitted' => 'Registered for an event',
        'refund_requested' => 'Requested a refund',
        'payment_submitted' => 'Submitted a payment',
        'paymongo_checkout_created' => 'Started online payment',
    ];

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /** The user's settings; defaults (all alerts on) when never saved. */
    public static function settings(PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT * FROM user_settings WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $defaults = ['user_id' => $userId, 'language' => 'en', 'timezone' => 'Asia/Manila', 'updated_at' => null];
        foreach (array_keys(self::NOTIFY_OPTIONS) as $key) {
            $defaults['notify_' . $key] = 1;
        }
        $defaults['notify_email'] = 1;

        return $defaults;
    }

    public static function savePreferences(PDO $db, int $userId, string $language, string $timezone): void
    {
        if (!isset(self::LANGUAGES[$language])) {
            throw new DomainException('Choose a supported language.');
        }
        if (!isset(self::TIMEZONES[$timezone])) {
            throw new DomainException('Choose a supported timezone.');
        }
        $db->prepare(
            'INSERT INTO user_settings (user_id, language, timezone) VALUES (:uid, :lang, :tz)
             ON DUPLICATE KEY UPDATE language = VALUES(language), timezone = VALUES(timezone)'
        )->execute(['uid' => $userId, 'lang' => $language, 'tz' => $timezone]);
        Logger::log($userId, 'account_preferences_updated', 'Language ' . $language . ', timezone ' . $timezone);
    }

    /**
     * $enabled: the option keys the user left checked; every other key is muted.
     * $email (v35): also send notifications by email (muted categories are not emailed).
     */
    public static function saveNotificationPreferences(PDO $db, int $userId, array $enabled, bool $email = true): void
    {
        $values = [];
        foreach (array_keys(self::NOTIFY_OPTIONS) as $key) {
            $values['notify_' . $key] = in_array($key, $enabled, true) ? 1 : 0;
        }
        $muted = array_keys(array_filter($values, fn($v) => $v === 0));
        $values['notify_email'] = $email ? 1 : 0;
        $columns = array_keys($values);
        $db->prepare(
            'INSERT INTO user_settings (user_id, ' . implode(', ', $columns) . ') VALUES (:uid, :' . implode(', :', $columns) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $columns))
        )->execute(['uid' => $userId] + $values);

        Logger::log($userId, 'notification_preferences_updated', ($muted
            ? 'Muted alerts: ' . implode(', ', array_map(fn($c) => substr($c, 7), $muted))
            : 'All notification alerts on') . '; email ' . ($email ? 'on' : 'off'));
    }

    /** Muted preference keys (for NotificationService alert counts). */
    public static function mutedAlerts(PDO $db, int $userId): array
    {
        $settings = self::settings($db, $userId);

        return array_values(array_filter(array_keys(self::NOTIFY_OPTIONS), fn($key) => (int) $settings['notify_' . $key] === 0));
    }

    // ---------------------------------------------------------------------
    // Security and activity (own rows only)
    // ---------------------------------------------------------------------

    /** Recent successful sign-ins of this user, newest first. */
    public static function loginHistory(PDO $db, int $userId, int $limit = 10): array
    {
        $stmt = $db->prepare('SELECT created_at, ip_address, user_agent FROM activity_logs WHERE user_id = :uid AND action = "login" ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
        $stmt->execute(['uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** The user's own activity, newest first. Returns [rows, total]. */
    public static function activity(PDO $db, int $userId, int $limit = 25, int $offset = 0): array
    {
        $count = $db->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id = :uid');
        $count->execute(['uid' => $userId]);
        $stmt = $db->prepare(
            'SELECT id, action, description, ip_address, user_agent, created_at FROM activity_logs
             WHERE user_id = :uid ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute(['uid' => $userId]);

        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    /** "Chrome · Windows" from a user agent; "—" when unknown (older log rows). */
    public static function deviceLabel(?string $userAgent): string
    {
        if (!$userAgent) {
            return '—';
        }
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return $browser . ' · ' . $os;
    }

    public static function roleLabel(string $role): string
    {
        return $role === 'admin' ? 'Administrator' : ucfirst($role);
    }
}
