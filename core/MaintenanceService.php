<?php

/**
 * Maintenance mode (v60).
 *
 * The state lives in a small JSON file (storage/logs/maintenance.json), not in the database, so it
 * still works while the database itself is being repaired. It can be switched from the admin page
 * (admin-maintenance) or from the command line (scripts/maintenance.php) when the web app is broken.
 *
 * While maintenance is on:
 *  - administrators use EVORA normally (to test the fix before reopening);
 *  - everyone else sees the "under maintenance" page (HTTP 503) and nothing new can be started:
 *    no registration, no checkout, no form posts;
 *  - a PayPal payment the buyer already approved can still be captured, so no payment is left
 *    half done (paypal-api action=capture/cancel, paypal-return, paypal-result);
 *  - background jobs that need participants to act (waitlist offers, unpaid reminders/closing) pause.
 * A scheduled window shows a notice banner beforehand and switches maintenance on by itself at its
 * start time; it stays on until an administrator ends it.
 */
class MaintenanceService
{
    /** Routes everyone may open during maintenance. */
    private const OPEN_ROUTES = ['login', 'login.post', 'login-otp', 'google-login', 'google-callback', 'google-complete', 'logout', 'privacy-policy', 'user-agreement', 'paypal-return', 'paypal-result'];
    /** Routes answered with JSON (a 503 JSON body instead of the page). */
    private const JSON_ROUTES = ['notifications-poll', 'today-stats', 'session-ping', 'global-search', 'organizer-race-live', 'race-track-action', 'paypal-api'];
    /** Show the "scheduled maintenance" banner this long before the start. */
    private const NOTICE_DAYS = 7;

    private static ?array $state = null;

    public static function file(): string
    {
        return EVORA_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'maintenance.json';
    }

    /** ['manual' => bool, 'message' => str, 'until' => 'Y-m-d H:i:s'|null, 'scheduled_start' => ..., 'scheduled_end' => ..., 'started_at' => ..., 'updated_by' => str] */
    public static function state(): array
    {
        if (self::$state === null) {
            $raw = is_file(self::file()) ? json_decode((string) @file_get_contents(self::file()), true) : null;
            self::$state = (is_array($raw) ? $raw : []) + ['manual' => false, 'message' => '', 'until' => null, 'scheduled_start' => null, 'scheduled_end' => null, 'started_at' => null, 'updated_by' => ''];
        }

        return self::$state;
    }

    private static function save(array $state): void
    {
        $dir = dirname(self::file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = self::file() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, self::file())) {
            throw new RuntimeException('Could not save the maintenance setting (storage/logs is not writable).');
        }
        self::$state = null;
    }

    /** True while maintenance is on (switched on by hand, or a scheduled window has started). */
    public static function isActive(): bool
    {
        $s = self::state();

        return !empty($s['manual']) || ($s['scheduled_start'] && time() >= strtotime($s['scheduled_start']));
    }

    /** Expected end time (or null). */
    public static function until(): ?string
    {
        $s = self::state();

        return $s['until'] ?: ($s['scheduled_end'] ?: null);
    }

    public static function isOverdue(): bool
    {
        return self::isActive() && self::until() !== null && time() > strtotime(self::until());
    }

    /** A scheduled window that has not started yet (for the banner): ['start' => ts, 'end' => ts|null] or null. */
    public static function upcoming(): ?array
    {
        $s = self::state();
        if (self::isActive() || !$s['scheduled_start']) {
            return null;
        }
        $start = strtotime($s['scheduled_start']);
        if ($start - time() > self::NOTICE_DAYS * 86400) {
            return null;
        }

        return ['start' => $start, 'end' => $s['scheduled_end'] ? strtotime($s['scheduled_end']) : null];
    }

    public static function startNow(string $by, string $message, ?string $until): void
    {
        $s = self::state();
        $s['manual'] = true;
        $s['message'] = trim($message);
        $s['until'] = $until;
        $s['started_at'] = date('Y-m-d H:i:s');
        $s['updated_by'] = $by;
        self::save($s);
    }

    public static function schedule(string $by, string $start, ?string $end, string $message): void
    {
        if (strtotime($start) <= time()) {
            throw new DomainException('The start time must be in the future. To start right now, use Turn On Now.');
        }
        if ($end !== null && strtotime($end) <= strtotime($start)) {
            throw new DomainException('The end time must be after the start time.');
        }
        $s = self::state();
        $s['scheduled_start'] = $start;
        $s['scheduled_end'] = $end;
        $s['message'] = trim($message);
        $s['updated_by'] = $by;
        self::save($s);
    }

    /** Ends maintenance (and clears any schedule). */
    public static function end(string $by): void
    {
        self::save(['manual' => false, 'message' => '', 'until' => null, 'scheduled_start' => null, 'scheduled_end' => null, 'started_at' => null, 'updated_by' => $by]);
    }

    // ------------------------------------------------------------------ web gate

    /** Called by index.php before routing: stops non-admins while maintenance is on. */
    public static function guard(string $route): void
    {
        if (!self::isActive() || (Auth::check() && Auth::role() === 'admin')) {
            return;
        }
        if (in_array($route, self::OPEN_ROUTES, true)) {
            return;
        }
        // A payment the buyer already approved at PayPal may finish; nothing new may start.
        if ($route === 'paypal-api' && in_array($_POST['action'] ?? '', ['capture', 'cancel'], true)) {
            return;
        }
        if (in_array($route, self::JSON_ROUTES, true)) {
            http_response_code(503);
            header('Retry-After: 600');
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['error' => 'EVORA is under maintenance. Please try again later.', 'maintenance' => true]);
            exit;
        }
        self::renderPage();
    }

    public static function renderPage(): never
    {
        $s = self::state();
        $until = self::until();
        http_response_code(503);
        header('Retry-After: ' . ($until && strtotime($until) > time() ? max(60, strtotime($until) - time()) : 900));
        header('Cache-Control: no-store');
        $message = $s['message'] !== '' ? $s['message'] : 'We are improving EVORA. Your registrations, payments and tickets are safe.';
        $when = $until
            ? (self::isOverdue() ? 'We expected to be back by ' . date('M d, g:i A', strtotime($until)) . '. It is taking a little longer; thank you for your patience.'
                : 'Expected back: <strong>' . date('l, M d, g:i A', strtotime($until)) . '</strong>')
            : 'We will be back shortly.';
        $loggedIn = Auth::check();
        $css = e(assetUrl('css/theme.css'));
        $login = e(url('login'));
        $logout = e(url('logout'));
        $msg = nl2br(e($message));
        $head = function_exists('themeInit') ? themeInit() : '';
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Under Maintenance — EVORA</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="{$css}">
{$head}
</head>
<body class="maintenance-page">
<main class="maintenance-card" role="main">
  <div class="maintenance-icon" aria-hidden="true"><i class="bi bi-tools"></i></div>
  <h1 class="h3 fw-bold mb-2">EVORA is under maintenance</h1>
  <p class="text-muted mb-3">{$msg}</p>
  <p class="mb-4">{$when}</p>
  <ul class="maintenance-points list-unstyled small text-start mx-auto mb-4">
    <li><i class="bi bi-shield-check"></i> Your account, registrations and payments are kept safe.</li>
    <li><i class="bi bi-credit-card"></i> No new registration or payment can start, so nothing is left half done.</li>
    <li><i class="bi bi-arrow-clockwise"></i> Refresh this page later to continue where you left off.</li>
  </ul>
  <button type="button" class="btn btn-evora-primary" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Try Again</button>
  <div class="small text-muted mt-4">
HTML;
        echo $loggedIn ? '<a href="' . $logout . '">Log out</a>' : '<a href="' . $login . '">Administrator sign in</a>';
        echo "</div>\n</main>\n</body>\n</html>";
        exit;
    }

    // ------------------------------------------------------------------ banners

    /** The banner on every page: upcoming window for everyone, "maintenance is ON" for administrators. */
    public static function bannerHtml(bool $public = false): string
    {
        if (self::isActive()) {
            if ($public && (!Auth::check() || Auth::role() !== 'admin')) {
                return evoraAlert('action', '<strong>EVORA is under maintenance.</strong> Only administrators can sign in right now.', ['icon' => 'bi-tools', 'class' => 'maintenance-banner']);
            }
            if (!Auth::check() || Auth::role() !== 'admin') {
                return '';
            }
            $text = '<strong>Maintenance mode is ON.</strong> Only administrators can use EVORA right now.'
                . (self::isOverdue() ? ' It is past the expected end (' . e(date('M d, g:i A', strtotime(self::until()))) . ').' : '');

            return evoraAlert('action', $text, ['icon' => 'bi-tools', 'class' => 'maintenance-banner',
                'actions' => '<a class="btn btn-sm btn-outline-dark" href="' . e(url('admin-maintenance')) . '">Manage</a>']);
        }
        $up = self::upcoming();
        if (!$up) {
            return '';
        }
        $text = '<strong>Scheduled maintenance:</strong> ' . e(date('l, M d, g:i A', $up['start']))
            . ($up['end'] ? ' to ' . e(date(date('Y-m-d', $up['end']) === date('Y-m-d', $up['start']) ? 'g:i A' : 'M d, g:i A', $up['end'])) : '')
            . '. EVORA will be unavailable during this time; please finish registrations and payments before it starts.';

        return evoraAlert('info', $text, ['icon' => 'bi-calendar2-event', 'class' => 'maintenance-banner']);
    }
}
