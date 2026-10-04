<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $route = '', array $params = []): string
{
    $query = array_merge(['route' => $route], $params);
    return BASE_URL . '/index.php?' . http_build_query($query);
}

/**
 * Same as url(), but scheme+host qualified — needed for redirect URLs handed
 * to an external service (e.g. PayMongo's success_url/cancel_url) that can't
 * resolve a path-only URL.
 */
function absoluteUrl(string $route = '', array $params = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($route, $params);
}

function redirect(string $route, array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function setOld(array $data): void
{
    $_SESSION['old'] = $data;
}

function clearOld(): void
{
    unset($_SESSION['old']);
}

function currency($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function assetUrl(string $path): string
{
    $relative = ltrim($path, '/');
    $fullPath = EVORA_ROOT . '/public/assets/' . $relative;
    $version = is_file($fullPath) ? filemtime($fullPath) : null;

    return BASE_URL . '/assets/' . $relative . ($version ? '?v=' . $version : '');
}

function uploadUrl(string $relativePath): string
{
    return BASE_URL . '/uploads_guard.php?f=' . urlencode($relativePath);
}

/**
 * Renders a small ↑/↓/flat trend indicator comparing the last two points of a
 * chronological numeric series (oldest first), e.g. a monthly-counts array.
 */
function trendIndicator(array $series, string $suffix = ' vs last month'): string
{
    if (count($series) < 2) {
        return '';
    }
    $current = (float) end($series);
    $previous = (float) $series[count($series) - 2];

    if ($previous == 0.0) {
        if ($current == 0.0) {
            return '<span class="trend-flat"><i class="bi bi-dash"></i> No change' . e($suffix) . '</span>';
        }
        return '<span class="trend-up"><i class="bi bi-arrow-up-right"></i> New activity' . e($suffix) . '</span>';
    }

    $change = round((($current - $previous) / $previous) * 100, 1);
    if ($change > 0) {
        return '<span class="trend-up"><i class="bi bi-arrow-up-right"></i> +' . $change . '%' . e($suffix) . '</span>';
    }
    if ($change < 0) {
        return '<span class="trend-down"><i class="bi bi-arrow-down-right"></i> ' . $change . '%' . e($suffix) . '</span>';
    }
    return '<span class="trend-flat"><i class="bi bi-dash"></i> No change' . e($suffix) . '</span>';
}

/**
 * Reads the single-row system_settings config, cached per-request. Falls back
 * to sane defaults if the row is somehow missing (e.g. a fresh install that
 * hasn't run migration_v10 yet) so nothing else in the app has to null-check.
 */
function systemSettings(): array
{
    static $settings = null;
    if ($settings === null) {
        $row = Database::connection()->query('SELECT * FROM system_settings WHERE id = 1')->fetch();
        $settings = $row ?: [
            'system_name' => 'EVORA', 'system_description' => null,
            'contact_email' => null, 'contact_phone' => null, 'logo_path' => null,
            'default_announcement_note' => null,
        ];
    }
    return $settings;
}

/** @param array<int, array{label: string, route?: string, params?: array}> $breadcrumbs */
function renderBreadcrumb(array $breadcrumbs): void
{
    include EVORA_ROOT . '/modules/shared/partials/breadcrumb.php';
}

/**
 * Lifecycle stepper (presentation only). Each step: ['label' => 'Approved',
 * 'meta' => 'Sep 30, 2026', 'state' => 'done'|'current'|'upcoming'|'failed'].
 * Every step has a text label, so the state never relies on color alone.
 */
function statusSteps(array $steps, string $ariaLabel = 'Status progress'): string
{
    $html = '<ol class="status-steps" aria-label="' . e($ariaLabel) . '">';
    foreach ($steps as $step) {
        $state = in_array($step['state'] ?? '', ['done', 'current', 'failed'], true) ? $step['state'] : 'upcoming';
        $srState = ['done' => 'completed', 'current' => 'current step', 'failed' => 'stopped here', 'upcoming' => 'not reached'][$state];
        $html .= '<li class="' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>'
            . '<span class="step-label">' . e($step['label']) . '</span>'
            . (!empty($step['meta']) ? '<span class="step-meta">' . e($step['meta']) . '</span>' : '')
            . '<span class="visually-hidden"> (' . $srState . ')</span></li>';
    }

    return $html . '</ol>';
}

/**
 * Empty-state block. Optionally with a helpful next step, e.g.
 * emptyState('bi-calendar-x', 'No events yet.', 'Create Event', url('organizer-event-type')).
 */
function emptyState(string $icon, string $message, ?string $actionLabel = null, ?string $actionUrl = null): string
{
    $action = ($actionLabel !== null && $actionUrl !== null)
        ? '<a href="' . e($actionUrl) . '" class="btn btn-evora-primary btn-sm">' . e($actionLabel) . '</a>'
        : '';

    return '<div class="empty-state"><i class="bi ' . e($icon) . '" aria-hidden="true"></i><p>' . e($message) . '</p>' . $action . '</div>';
}

/**
 * EVORA notice component (UI Design System, "Alerts & notices"). One place decides
 * how long a message stays on screen:
 *   'action'  — action required (agreement, verification, missing setup). Never
 *               auto-hides and has no close button; the page stops rendering it
 *               once the action is done.
 *   'error'   — never auto-hides; the user closes it.
 *   'warning' — never auto-hides; the user closes it.
 *   'info'    — stays unless $opts['autohide'] (ms) is given.
 *   'success' — auto-hides after 4 s (override with $opts['autohide']).
 * $html is trusted HTML: escape any user data with e() before passing it.
 * $opts: 'icon' (bootstrap-icons name), 'actions' (trusted HTML, e.g. a button),
 *        'autohide' (ms, 0 = never), 'class' (extra classes, e.g. 'small mb-3'),
 *        'toast' (true: shown as a SweetAlert toast for the same time when available).
 * Plain .alert markup elsewhere is also persistent; only data-autohide hides.
 */
function evoraAlert(string $kind, string $html, array $opts = []): string
{
    $kinds = [
        'action' => ['warning', 'bi-exclamation-triangle', 0, false],
        'error' => ['danger', 'bi-x-octagon', 0, true],
        'warning' => ['warning', 'bi-exclamation-triangle', 0, true],
        'info' => ['info', 'bi-info-circle', 0, true],
        'success' => ['success', 'bi-check-circle', 4000, true],
    ];
    [$variant, $icon, $autohide, $dismissible] = $kinds[$kind] ?? $kinds['info'];
    $icon = $opts['icon'] ?? $icon;
    $autohide = (int) ($opts['autohide'] ?? $autohide);
    $classes = 'alert alert-' . $variant . ' evora-alert evora-alert--' . $kind
        . ($dismissible ? ' alert-dismissible fade show' : '') . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
    $role = in_array($kind, ['action', 'error'], true) ? 'alert' : 'status';

    return '<div class="' . e($classes) . '" role="' . $role . '" data-alert="' . e($kind) . '"' . ($autohide > 0 ? ' data-autohide="' . $autohide . '"' : '') . (!empty($opts['toast']) && $autohide > 0 ? ' data-toast="' . e($kind) . '"' : '') . '>'
        . '<i class="bi ' . e($icon) . ' evora-alert-icon" aria-hidden="true"></i>'
        . '<div class="evora-alert-body">' . $html . '</div>'
        . (!empty($opts['actions']) ? '<div class="evora-alert-actions">' . $opts['actions'] . '</div>' : '')
        . ($dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' : '')
        . '</div>';
}

/**
 * Creates a notification. $meta (optional): category, notice_type,
 * reference_type, reference_id — see NotificationService.
 */
function notify(int $userId, string $title, string $message, string $type = 'general', array $meta = []): void
{
    // Entry points outside index.php (webhook, CLI scripts) load core files one by one.
    if (!class_exists('NotificationService', false)) {
        require_once EVORA_ROOT . '/core/NotificationService.php';
    }
    NotificationService::create(Database::connection(), $userId, $title, $message, $type, $meta);
}

/**
 * Marks a registration approved if it isn't already — used when a successful
 * payment (gateway-verified or organizer-reviewed manual proof) is itself the
 * signal that the registration is good, instead of requiring a separate
 * organizer "Approve" click before payment can even happen.
 */
/** Version of the User Agreement + Privacy Policy that new accounts accept (shown on both pages). */
const EVORA_LEGAL_VERSION = '1.2';

/** Human label for payments.payment_channel (PayPal = paid directly to the organizer). */
function paymentChannelLabel(?string $channel): string
{
    return match ($channel) {
        'paypal' => 'PayPal',
        'paymongo' => 'PayMongo',
        'cash_walkin' => 'Cash Walk-in',
        'manual' => 'Manual',
        default => '—',
    };
}

function approveRegistrationIfNeeded(PDO $db, int $registrationId, ?int $actorUserId = null): bool
{
    $stmt = $db->prepare('UPDATE registrations SET status = "approved", reviewed_by = :by, reviewed_at = NOW() WHERE id = :id AND status != "approved"');
    $stmt->execute(['by' => $actorUserId, 'id' => $registrationId]);

    return $stmt->rowCount() > 0;
}

/**
 * Issues a QR code for a registration if one doesn't already exist. Shared by
 * every path that can mark a registration payable-and-cleared: free-event
 * auto-approval and the PayMongo webhook.
 * Returns true if a QR was newly generated, false if one already existed.
 */
function issueQrIfMissing(PDO $db, int $registrationId, ?int $actorUserId = null): bool
{
    $existingQr = $db->prepare('SELECT id FROM qr_codes WHERE registration_id = :rid');
    $existingQr->execute(['rid' => $registrationId]);
    if ($existingQr->fetch()) {
        return false;
    }

    [$qrPath, $token] = QrService::generate($registrationId);
    $stmt = $db->prepare('INSERT INTO qr_codes (registration_id, code_token, qr_image_path) VALUES (:rid, :token, :path)');
    $stmt->execute(['rid' => $registrationId, 'token' => $token, 'path' => $qrPath]);
    Logger::log($actorUserId, 'qr_generated', 'QR generated for registration #' . $registrationId);

    return true;
}


/**
 * Night mode (v44). Goes right after theme.css in every <head>: sets data-theme / data-bs-theme on
 * <html> before the page paints (saved choice in localStorage, else the device setting), and makes
 * every [data-theme-toggle] button switch it. Pages without such a button get a small floating one.
 * Printing always uses the light theme.
 */
function themeInit(): string
{
    return <<<'HTML'
<script>
(function () {
    var KEY = 'evora_theme', root = document.documentElement, printing = false;
    function saved() { try { var t = localStorage.getItem(KEY); return t === 'dark' || t === 'light' ? t : null; } catch (e) { return null; } }
    function device() { return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
    function current() { return saved() || device(); }
    function paintButtons(t) {
        var label = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        var icon = t === 'dark' ? 'bi-sun' : 'bi-moon-stars';
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            b.setAttribute('aria-label', label);
            b.setAttribute('title', label);
            b.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
        });
    }
    function apply(t) {
        root.setAttribute('data-theme', t);
        root.setAttribute('data-bs-theme', t);
        paintButtons(t);
        try { document.dispatchEvent(new CustomEvent('evora:theme', { detail: t })); } catch (e) {}
    }
    window.evoraTheme = {
        get: function () { return root.getAttribute('data-theme') || 'light'; },
        set: function (t) { try { localStorage.setItem(KEY, t); } catch (e) {} apply(t); }
    };
    apply(current());
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-theme-toggle]') : null;
        if (!b) { return; }
        e.preventDefault();
        window.evoraTheme.set(window.evoraTheme.get() === 'dark' ? 'light' : 'dark');
    });
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var follow = function () { if (!saved() && !printing) { apply(device()); } };
        if (mq.addEventListener) { mq.addEventListener('change', follow); } else if (mq.addListener) { mq.addListener(follow); }
    }
    document.addEventListener('DOMContentLoaded', function () {
        if (!document.querySelector('[data-theme-toggle]')) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'theme-toggle theme-toggle-float';
            b.setAttribute('data-theme-toggle', '');
            document.body.appendChild(b);
        }
        paintButtons(window.evoraTheme.get());
    });
    window.addEventListener('beforeprint', function () { printing = true; root.setAttribute('data-theme', 'light'); root.setAttribute('data-bs-theme', 'light'); });
    window.addEventListener('afterprint', function () { printing = false; apply(current()); });
})();
</script>
HTML;
}


/** Scheme + host of the site (from APP_URL when set, else the current request). */
function siteOrigin(): string
{
    if (defined('APP_URL')) {
        $p = parse_url(APP_URL);
        if (!empty($p['host'])) {
            return ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Share previews + installable app (v45). Goes right after themeInit() in every <head>:
 * description and Open Graph / Twitter tags (Facebook, Messenger and X show a title, text and
 * picture when a link is shared), the web app manifest and icons, and the service worker.
 * $meta (all optional): title, description, image (absolute URL or site path), url, type.
 */
function pageHead(array $meta = []): string
{
    $siteName = 'EVORA';
    try {
        $siteName = (string) (systemSettings()['system_name'] ?? '') ?: 'EVORA';
    } catch (Throwable $e) {
        // settings unavailable (e.g. database down): keep the default name
    }
    $absolute = fn (string $u): string => preg_match('#^https?://#i', $u) ? $u : siteOrigin() . $u;
    $title = (string) ($meta['title'] ?? $siteName . ' — Event Registration, Payments & QR Check-in');
    $description = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($meta['description']
        ?? 'Find fun runs, tournaments, seminars and more. Register and pay online, get your QR e-ticket by email, and check in fast on event day.'))));
    $description = mb_strimwidth($description, 0, 200, '…');
    $image = $absolute((string) ($meta['image'] ?? assetUrl('img/og-default.png')));
    $pageUrl = $absolute((string) ($meta['url'] ?? ($_SERVER['REQUEST_URI'] ?? '/')));
    $h = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $tags = [
        '<meta name="description" content="' . $h($description) . '">',
        '<meta property="og:site_name" content="' . $h($siteName) . '">',
        '<meta property="og:type" content="' . $h((string) ($meta['type'] ?? 'website')) . '">',
        '<meta property="og:title" content="' . $h($title) . '">',
        '<meta property="og:description" content="' . $h($description) . '">',
        '<meta property="og:url" content="' . $h($pageUrl) . '">',
        '<meta property="og:image" content="' . $h($image) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . $h($title) . '">',
        '<meta name="twitter:description" content="' . $h($description) . '">',
        '<meta name="twitter:image" content="' . $h($image) . '">',
        '<meta name="theme-color" content="#1e1b4b">',
        '<link rel="manifest" href="' . $h(BASE_URL . '/manifest.json') . '">',
        '<link rel="icon" type="image/png" sizes="192x192" href="' . $h(assetUrl('img/icon-192.png')) . '">',
        '<link rel="apple-touch-icon" href="' . $h(assetUrl('img/apple-touch-icon.png')) . '">',
        '<meta name="apple-mobile-web-app-title" content="EVORA">',
        '<meta name="mobile-web-app-capable" content="yes">',
    ];
    if (!isset($meta['image'])) {
        $tags[] = '<meta property="og:image:width" content="1200">';
        $tags[] = '<meta property="og:image:height" content="630">';
    }
    $base = json_encode(BASE_URL . '/', JSON_UNESCAPED_SLASHES);
    $tags[] = <<<HTML
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register({$base} + 'sw.js', { scope: {$base} }).catch(function () {}); });
}
// "Install EVORA app": [data-pwa-install] items appear only when the browser offers installing.
(function () {
    var prompt = null;
    function show(on) { document.querySelectorAll('[data-pwa-install]').forEach(function (b) { b.classList.toggle('d-none', !on); }); }
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); prompt = e; show(true); });
    window.addEventListener('appinstalled', function () { prompt = null; show(false); });
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-pwa-install]') : null;
        if (!b || !prompt) { return; }
        e.preventDefault();
        prompt.prompt();
        prompt.userChoice.then(function () { prompt = null; show(false); });
    });
})();
// A broken profile photo becomes the person's initial (img[data-fallback-initial], v48).
document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.hasAttribute('data-fallback-initial')) { return; }
    var s = document.createElement('span');
    s.className = img.className + ' account-avatar-initials';
    s.textContent = img.getAttribute('data-fallback-initial') || '?';
    s.setAttribute('aria-hidden', 'true');
    img.replaceWith(s);
}, true);
// Thin loading bar at the top while the next page loads (v48).
(function () {
    var bar = null, timer = null, stopTimer = null;
    function stop() { clearTimeout(timer); clearTimeout(stopTimer); timer = null; if (bar) { bar.remove(); bar = null; } }
    function start() {
        if (timer || bar) { return; }
        timer = setTimeout(function () {
            bar = document.createElement('div');
            bar.className = 'page-loading-bar';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-label', 'Loading');
            document.body.appendChild(bar);
            requestAnimationFrame(function () { requestAnimationFrame(function () { if (bar) { bar.classList.add('is-active'); } }); });
        }, 150);
        stopTimer = setTimeout(stop, 12000); // a download or a cancelled navigation never leaves it running
    }
    var isDownload = function (u) { return /uploads_guard/.test(u.pathname) || /route=[^&]*(export|ticket|receipt|pdf|ics|csv|download|certificate-generate|certificate-preview)/i.test(u.search); };
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href) || (a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-bs-toggle')) { return; }
        var u = new URL(a.href, location.href);
        if (u.origin !== location.origin || isDownload(u) || (u.pathname === location.pathname && u.search === location.search)) { return; }
        start();
    });
    document.addEventListener('submit', function (e) {
        var f = e.target;
        setTimeout(function () {
            if (e.defaultPrevented || (f.target && f.target !== '_self')) { return; }
            var u = new URL(f.getAttribute('action') || location.href, location.href);
            if (!isDownload(u) && !(f.querySelector('[name=route]') && /export|csv|pdf/i.test(f.querySelector('[name=route]').value))) { start(); }
        }, 0);
    });
    window.addEventListener('pageshow', stop);
})();
// Password fields (v52): a show/hide button on every password box; [data-strength] boxes show a
// strength meter, and [data-match="#id"] boxes say whether they match the first password.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[type=password]:not([data-no-toggle])').forEach(function (input) {
        var box = input.parentElement;
        if (!box.classList.contains('form-floating')) { // label above the box: wrap the box itself
            box = document.createElement('div');
            input.parentNode.insertBefore(box, input);
            box.appendChild(input);
        }
        box.classList.add('pw-wrap');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            input.classList.toggle('pw-revealed', show);
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.innerHTML = '<i class="bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + '" aria-hidden="true"></i>';
            input.focus();
        });
        input.insertAdjacentElement('afterend', btn);
    });
    var levels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
    document.querySelectorAll('input[data-strength]').forEach(function (input) {
        var meter = document.createElement('div');
        meter.className = 'pw-strength';
        meter.setAttribute('aria-live', 'polite');
        meter.innerHTML = '<div class="pw-strength-bars" aria-hidden="true"><span></span><span></span><span></span><span></span></div><span class="pw-strength-text">Use at least 8 characters with letters and numbers.</span>';
        (input.closest('.pw-wrap') || input.parentElement).insertAdjacentElement('afterend', meter);
        input.addEventListener('input', function () {
            var v = input.value, score = 0;
            if (v.length >= 8) {
                score = 1;
                if (v.length >= 12) score++;
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;
                score = Math.min(4, Math.max(1, score - (new Set(v).size === 1 || /^(1234|abcd|pass|qwer)/i.test(v) ? 2 : 0)));
            }
            meter.dataset.level = v ? String(score) : '';
            meter.querySelector('.pw-strength-text').innerHTML = v ? 'Password strength: <strong>' + levels[score] + '</strong>' + (score < 3 ? ' — add upper and lower case, numbers or symbols.' : '') : 'Use at least 8 characters with letters and numbers.';
        });
    });
    document.querySelectorAll('input[data-match]').forEach(function (input) {
        var first = document.querySelector(input.dataset.match);
        if (!first) return;
        var note = document.createElement('div');
        note.className = 'pw-match';
        note.setAttribute('aria-live', 'polite');
        (input.closest('.pw-wrap') || input.parentElement).insertAdjacentElement('afterend', note);
        var check = function () {
            if (!input.value) { note.textContent = ''; note.className = 'pw-match'; return; }
            var ok = input.value === first.value;
            note.className = 'pw-match ' + (ok ? 'is-ok' : 'is-bad');
            note.innerHTML = ok ? '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Passwords match' : '<i class="bi bi-x-circle me-1" aria-hidden="true"></i>Passwords do not match yet';
        };
        input.addEventListener('input', check);
        first.addEventListener('input', check);
    });
});
</script>
HTML;

    return implode("\n    ", $tags) . "\n";
}

/**
 * Organizer cancellation requests still waiting for an admin (v50): event id => [reason, requested_at].
 * A request is "open" while the event can still be cancelled. Read from the activity log written by
 * organizer/event_status_action.php ("Event #ID (name): reason").
 */
function cancellationRequests(PDO $db, ?int $eventId = null): array
{
    $sql = 'SELECT l.description, l.created_at FROM activity_logs l WHERE l.action = "event_cancellation_requested"'
        . ($eventId ? ' AND l.description LIKE :p' : '') . ' ORDER BY l.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($eventId ? ['p' => 'Event #' . $eventId . ' (%'] : []);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (preg_match('/^Event #(\d+) \(.*?\): (.*)$/s', (string) $row['description'], $m)) {
            $out[(int) $m[1]] = ['reason' => $m[2], 'requested_at' => $row['created_at']];
        }
    }
    if (!$out) {
        return [];
    }
    $ids = implode(',', array_map('intval', array_keys($out)));
    $open = $db->query("SELECT id FROM events WHERE id IN ($ids) AND status IN ('pending_approval', 'approved', 'open', 'closed', 'ongoing')")->fetchAll(PDO::FETCH_COLUMN);

    return array_intersect_key($out, array_flip(array_map('intval', $open)));
}

/** Extra columns an event-card query selects for eventCardInfo() (alias of events must be "e"). */
const EVENT_CARD_COLUMNS = '(SELECT COUNT(*) FROM registrations rr WHERE rr.event_id = e.id AND rr.status = "approved") AS approved_count,
    (SELECT MIN(rcf.fee) FROM race_categories rcf WHERE rcf.event_id = e.id) AS min_cat_fee,
    (SELECT COUNT(DISTINCT rcd.fee) FROM race_categories rcd WHERE rcd.event_id = e.id) AS cat_fee_count';

/** Price, slots and urgency of an event card (v52). Needs the EVENT_CARD_COLUMNS. */
function eventCardInfo(array $ev): array
{
    $hasCats = isset($ev['min_cat_fee']) && $ev['min_cat_fee'] !== null && $ev['min_cat_fee'] !== '';
    $fee = $hasCats ? (float) $ev['min_cat_fee'] : (float) $ev['fee'];
    $price = $fee > 0 ? (($hasCats && (int) ($ev['cat_fee_count'] ?? 0) > 1) ? 'From ' : '') . currency($fee) : 'Free';
    $max = (int) $ev['max_participants'];
    $taken = (int) ($ev['approved_count'] ?? 0);
    $left = $max > 0 ? max(0, $max - $taken) : null;
    $pct = $max > 0 ? (int) min(100, round($taken * 100 / $max)) : 0;
    $badges = [];
    if ($left === 0) {
        $badges[] = ['Full', 'badge-status-rejected'];
    } elseif ($max > 0 && $pct >= 80) {
        $badges[] = ['Almost full', 'badge-status-pending'];
    }
    $today = new DateTimeImmutable('today');
    if (!empty($ev['registration_deadline']) && $left !== 0) {
        $days = (int) $today->diff(new DateTimeImmutable($ev['registration_deadline']))->format('%r%a');
        if ($days === 0) {
            $badges[] = ['Closes today', 'badge-status-pending'];
        } elseif ($days > 0 && $days <= 3) {
            $badges[] = ['Closes in ' . $days . ' day' . ($days > 1 ? 's' : ''), 'badge-status-open'];
        }
    }
    $eventDays = (int) $today->diff(new DateTimeImmutable($ev['event_date']))->format('%r%a');
    if ($eventDays === 0) {
        $badges[] = ['Today', 'badge-status-approved'];
    } elseif ($eventDays === 1) {
        $badges[] = ['Tomorrow', 'badge-status-approved'];
    }

    return ['price' => $price, 'free' => $fee <= 0, 'max' => $max, 'taken' => $taken, 'left' => $left, 'pct' => $pct, 'badges' => $badges];
}

/** Badge row of an event card: category, price, then "Almost full" / "Closes in 2 days" / "Today". */
function eventCardBadges(array $ev): string
{
    $i = eventCardInfo($ev);
    $html = '<div class="event-card-badges"><span class="badge bg-light text-dark border">' . e($ev['category_name']) . '</span>'
        . '<span class="badge event-price ' . ($i['free'] ? 'bg-success-subtle text-success-emphasis' : 'bg-primary-subtle text-primary-emphasis') . '">' . e($i['price']) . '</span>';
    foreach ($i['badges'] as [$label, $class]) {
        $html .= '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }

    return $html . '</div>';
}

/** "12 slots left" with a fill bar (nothing when the event has no capacity limit). */
function eventCardSlots(array $ev): string
{
    $i = eventCardInfo($ev);
    if ($i['max'] <= 0) {
        return '';
    }
    $state = $i['left'] === 0 ? ' is-full' : ($i['pct'] >= 80 ? ' is-tight' : '');
    $text = $i['left'] === 0 ? 'Full — join the waitlist' : $i['left'] . ' slot' . ($i['left'] === 1 ? '' : 's') . ' left';

    return '<div class="event-slots' . $state . '"><div class="event-slots-text"><span>' . e($text) . '</span><span>' . $i['taken'] . ' / ' . $i['max'] . '</span></div>'
        . '<div class="progress" role="progressbar" aria-label="Slots taken" aria-valuenow="' . $i['pct'] . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:' . $i['pct'] . '%"></div></div></div>';
}

/**
 * Events happening today for the signed-in organizer (own events) or staff member (assigned
 * events), with approved and checked-in counts and, for staff, their permissions (v54).
 */
function todayEventsFor(PDO $db): array
{
    $uid = (int) Auth::id();
    $counts = '(SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = "approved") AS approved,
               (SELECT COUNT(DISTINCT a.registration_id) FROM attendance a WHERE a.event_id = e.id) AS checked_in';
    $live = 'e.event_date = CURDATE() AND e.status IN ("approved", "open", "closed", "ongoing", "completed")';
    if (Auth::role() === 'organizer') {
        $stmt = $db->prepare("SELECT e.id, e.name, e.event_time, e.venue, e.status, $counts FROM events e JOIN organizers o ON o.id = e.organizer_id
                              WHERE o.user_id = :u AND $live ORDER BY e.event_time, e.id");
    } elseif (Auth::role() === 'staff') {
        $stmt = $db->prepare("SELECT e.id, e.name, e.event_time, e.venue, e.status, $counts,
                                     (SELECT GROUP_CONCAT(sp.permission_key) FROM staff_permissions sp WHERE sp.event_staff_id = es.id) AS perms
                              FROM event_staff es JOIN events e ON e.id = es.event_id WHERE es.user_id = :u AND $live ORDER BY e.event_time, e.id");
    } else {
        return [];
    }
    $stmt->execute(['u' => $uid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['perms'] = isset($row['perms']) && $row['perms'] !== null ? explode(',', $row['perms']) : [];
    }

    return $rows;
}

/** Shows the branded error page (404 / 403, v54) and stops. */
function renderErrorPage(int $code, string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    $errCode = $code;
    $errTitle = $title;
    $errMessage = $message;
    include EVORA_ROOT . '/modules/shared/error_page.php';
    exit;
}
