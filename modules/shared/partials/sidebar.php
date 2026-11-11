<?php
/** Expects $activeRoute to be set by the including page. */
$role = Auth::role();
$activeRoute = $activeRoute ?? '';

function navLink(string $route, string $icon, string $label, string $active, int $badge = 0): string
{
    $isActive = $active === $route ? 'active' : '';
    $badgeHtml = $badge > 0 ? ' <span class="badge bg-danger rounded-pill ms-auto nav-label" aria-label="' . $badge . ' unread">' . $badge . '</span>' : '';
    return '<li class="nav-item"><a class="nav-link ' . $isActive . ' d-flex align-items-center" href="' . url($route) . '" title="' . e($label) . '"' . ($isActive ? ' aria-current="page"' : '') . '>'
        . '<i class="bi ' . $icon . ' me-2" aria-hidden="true"></i><span class="nav-label">' . e($label) . '</span>' . $badgeHtml . '</a></li>';
}

// Unread notification badges per module (computed in layout_top.php).
$navCounts = $navCounts ?? [];
$nb = fn(string $route): int => (int) ($navCounts[$route] ?? 0);

$pendingEventApprovalsNav = 0;
$refundsDueNav = 0; // PayPal refunds this organizer still owes (v36)
if ($role === 'organizer') {
    $navOrg = Database::connection()->prepare('SELECT id FROM organizers WHERE user_id = :uid');
    $navOrg->execute(['uid' => Auth::id()]);
    try {
        $refundsDueNav = RefundTrackingService::outstandingCount(Database::connection(), (int) ($navOrg->fetchColumn() ?: 0));
    } catch (PDOException $e) {
        $refundsDueNav = 0; // v36 table not installed on this copy
    }
}
if ($role === 'admin') {
    $pendingEventApprovalsNav = (int) Database::connection()->query("SELECT COUNT(*) c FROM events WHERE status = 'pending_approval'")->fetch()['c'];
}

// Staff nav is permission-driven: a link only appears if the staff member
// holds that permission on at least one assigned event (anywhere). This is
// a discovery aid only — every target page independently re-checks
// event-level ownership/permission itself, so hiding a link here changes
// nothing about what's actually enforced server-side.
$staffPerms = [];
if ($role === 'staff') {
    $staffPermsStmt = Database::connection()->prepare(
        'SELECT DISTINCT sp.permission_key FROM event_staff es
         JOIN staff_permissions sp ON sp.event_staff_id = es.id
         JOIN events e ON e.id = es.event_id
         WHERE es.user_id = :uid AND e.status NOT IN ("removed", "archived")' // a removed event grants no menu items
    );
    $staffPermsStmt->execute(['uid' => Auth::id()]);
    $staffPerms = array_column($staffPermsStmt->fetchAll(), 'permission_key');
}
?>
<?php $settings = systemSettings(); ?>
<nav class="evora-sidebar" id="evoraSidebar" aria-label="Main navigation">
    <div class="brand d-flex align-items-center">
        <?php if (!empty($settings['logo_path'])): ?>
            <img src="<?= uploadUrl($settings['logo_path']) ?>" alt="<?= e($settings['system_name']) ?>" style="height:28px;width:auto;max-width:150px;object-fit:contain;">
        <?php else: ?>
            <span class="brand-mark">E</span><span class="brand-full">VO<span class="accent">RA</span></span>
        <?php endif; ?>
    </div>
    <ul class="nav flex-column pb-4">
        <?php if ($role === 'admin'): ?>
            <div class="nav-section-title">Platform</div>
            <?= navLink('admin-dashboard', 'bi-speedometer2', 'Dashboard', $activeRoute) ?>
            <?= navLink('admin-organizers', 'bi-person-check', 'Organizer Verification', $activeRoute, $nb('admin-organizers')) ?>
            <?= navLink('admin-badges', 'bi-patch-check-fill', 'Verified Badges', $activeRoute, OrganizerBadgeService::pendingCount(Database::connection())) ?>
            <?= navLink('admin-event-approvals', 'bi-patch-check', 'Event Approval', $activeRoute, max($pendingEventApprovalsNav, $nb('admin-event-approvals'))) ?>
            <?= navLink('admin-events', 'bi-calendar-event', 'Event Monitoring', $activeRoute, $nb('admin-events')) ?>
            <?= navLink('admin-calendar', 'bi-calendar3', 'Event Schedule', $activeRoute) ?>
            <?= navLink('admin-users', 'bi-people', 'User Management', $activeRoute) ?>
            <?= navLink('admin-categories', 'bi-tags', 'Event Categories', $activeRoute) ?>
            <?= navLink('admin-announcements', 'bi-megaphone', 'Announcements', $activeRoute) ?>
            <div class="nav-section-title">Insights</div>
            <?= navLink('admin-reports', 'bi-bar-chart', 'Reports', $activeRoute) ?>
            <?= navLink('admin-report-history', 'bi-archive', 'Report History', $activeRoute) ?>
            <?= navLink('admin-logs', 'bi-journal-text', 'Audit Trail', $activeRoute) ?>
            <?= navLink('admin-error-logs', 'bi-bug', 'Error Log', $activeRoute) ?>
            <div class="nav-section-title">Finance</div>
            <?= navLink('admin-paypal-transactions', 'bi-paypal', 'PayPal Transactions', $activeRoute) ?>
            <?= navLink('admin-paypal-accounts', 'bi-person-check', 'Organizer PayPal Accounts', $activeRoute) ?>
            <?= navLink('admin-refund-tracking', 'bi-arrow-counterclockwise', 'Refund Tracking', $activeRoute) ?>
            <div class="nav-section-title">System</div>
            <?= navLink('admin-settings', 'bi-sliders', 'System Settings', $activeRoute) ?>
            <?= navLink('admin-maintenance', 'bi-tools', 'Maintenance Mode', $activeRoute) ?>
        <?php elseif ($role === 'organizer'): ?>
            <div class="nav-section-title">Overview</div>
            <?= navLink('organizer-dashboard', 'bi-speedometer2', 'Dashboard', $activeRoute) ?>
            <?= navLink('organizer-verified-badge', 'bi-patch-check', 'Verified Badge', $activeRoute) ?>
            <div class="nav-section-title">Events</div>
            <?= navLink('organizer-events', 'bi-calendar-event', 'Manage Events', $activeRoute, $nb('organizer-events')) ?>
            <?= navLink('organizer-calendar', 'bi-calendar3', 'Event Schedule', $activeRoute) ?>
            <div class="nav-section-title">Operations</div>
            <?= navLink('organizer-registrations', 'bi-clipboard-check', 'Registrations', $activeRoute, $nb('organizer-registrations')) ?>
            <?= navLink('walkin-register', 'bi-person-plus-fill', 'Walk-in Registration', $activeRoute) ?>
            <div class="nav-section-title">Finance</div>
            <?= navLink('organizer-payment-dashboard', 'bi-cash-stack', 'Payment Dashboard', $activeRoute) ?>
            <?= navLink('organizer-payment-transactions', 'bi-receipt', 'Transaction History', $activeRoute, $nb('organizer-payment-transactions')) ?>
            <?= navLink('organizer-refunds', 'bi-arrow-counterclockwise', 'Refunds', $activeRoute, $refundsDueNav) ?>
            <?= navLink('organizer-paypal-account', 'bi-paypal', 'PayPal Account', $activeRoute) ?>
            <div class="nav-section-title">Reports</div>
            <?= navLink('organizer-reports', 'bi-bar-chart', 'Event Reports', $activeRoute) ?>
        <?php elseif ($role === 'staff'): ?>
            <div class="nav-section-title">My Assignment</div>
            <?= navLink('staff-dashboard', 'bi-speedometer2', 'Dashboard', $activeRoute, $nb('staff-dashboard')) ?>
            <?php if (in_array('scan_qr', $staffPerms, true)): ?><?= navLink('staff-scan', 'bi-qr-code-scan', 'Scan QR Code', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('record_attendance', $staffPerms, true)): ?><?= navLink('staff-attendance', 'bi-clipboard-data', 'Attendance Records', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_claims', $staffPerms, true)): ?><?= navLink('staff-claims', 'bi-box-seam', 'Item Releasing', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('view_announcements', $staffPerms, true)): ?><?= navLink('staff-announcements', 'bi-megaphone', 'Announcements', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_walkin', $staffPerms, true)): ?><?= navLink('walkin-register', 'bi-person-plus-fill', 'Walk-in Registration', $activeRoute) ?><?php endif; ?>
            <?php if (array_intersect(['manage_live_scores', 'manage_incidents'], $staffPerms)): ?>
            <div class="nav-section-title">Sports Tools</div>
            <?php if (in_array('manage_live_scores', $staffPerms, true)): ?><?= navLink('match-center', 'bi-broadcast', 'Match Operations', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_incidents', $staffPerms, true)): ?><?= navLink('incidents', 'bi-exclamation-octagon', 'Incident Reports', $activeRoute) ?><?php endif; ?>
            <?php endif; ?>
            <?php if (array_intersect(['manage_bibs', 'manage_finish_results', 'manage_checkpoints', 'manage_medical', 'view_race_operations'], $staffPerms)): ?>
            <div class="nav-section-title">Fun Run Tools</div>
            <?php if (in_array('manage_bibs', $staffPerms, true)): ?><?= navLink('organizer-bib-assign', 'bi-upc-scan', 'Bib Management', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_finish_results', $staffPerms, true)): ?><?= navLink('organizer-race-results', 'bi-flag-fill', 'Finish Results', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_checkpoints', $staffPerms, true)): ?><?= navLink('checkpoint-scan', 'bi-qr-code-scan', 'Checkpoint Monitoring', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_medical', $staffPerms, true)): ?><?= navLink('medical-incidents', 'bi-heart-pulse', 'Medical Incidents', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('view_race_operations', $staffPerms, true)): ?><?= navLink('race-operations-dashboard', 'bi-speedometer2', 'Race Operations', $activeRoute) ?><?php endif; ?>
            <?php endif; ?>
            <?php if (array_intersect(['manage_sessions', 'manage_speakers', 'manage_feedback'], $staffPerms)): ?>
            <div class="nav-section-title">Seminar Tools</div>
            <?php if (in_array('manage_sessions', $staffPerms, true)): ?><?= navLink('organizer-schedule', 'bi-clock-history', 'Session Management', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_speakers', $staffPerms, true)): ?><?= navLink('organizer-speakers', 'bi-mic', 'Speaker Management', $activeRoute) ?><?php endif; ?>
            <?php if (in_array('manage_feedback', $staffPerms, true)): ?><?= navLink('feedback-summary', 'bi-star', 'Feedback Summary', $activeRoute) ?><?php endif; ?>
            <?php endif; ?>
        <?php elseif ($role === 'participant'): ?>
            <div class="nav-section-title">Explore</div>
            <?= navLink('participant-dashboard', 'bi-speedometer2', 'Dashboard', $activeRoute) ?>
            <?= navLink('events', 'bi-calendar-event', 'Browse Events', $activeRoute) ?>
            <?= navLink('saved-events', 'bi-bookmark', 'Saved Events', $activeRoute) ?>
            <div class="nav-section-title">My Activity</div>
            <?= navLink('my-registrations', 'bi-clipboard-check', 'My Registrations', $activeRoute, $nb('my-registrations')) ?>
            <?= navLink('my-payments', 'bi-receipt', 'Payments & Receipts', $activeRoute) ?>
            <?= navLink('my-qr', 'bi-qr-code', 'QR Codes', $activeRoute) ?>
            <?= navLink('my-certificate', 'bi-award', 'Certificates', $activeRoute, $nb('my-certificate')) ?>
            <?= navLink('my-history', 'bi-trophy', 'My Results', $activeRoute) ?>
        <?php endif; ?>

    </ul>
</nav>
<script>
// Keep the sidebar's scroll position across page loads (every menu item is a full page load,
// which would otherwise jump the sidebar back to the top). Runs right after the sidebar markup,
// before the page is painted, so there is no visible jump. Per-tab (sessionStorage).
(function () {
    var sidebar = document.getElementById('evoraSidebar');
    if (!sidebar) { return; }
    var key = 'evora_sidebar_scroll';
    var restored = false;
    try {
        var saved = sessionStorage.getItem(key);
        if (saved !== null) { sidebar.scrollTop = parseInt(saved, 10) || 0; restored = true; }
    } catch (e) { /* storage unavailable: fall through */ }
    // Arrived without a saved position (e.g. from a notification link): bring the active item into view.
    var active = sidebar.querySelector('.nav-link.active');
    if (active) {
        var a = active.getBoundingClientRect(), s = sidebar.getBoundingClientRect();
        if (!restored || a.top < s.top || a.bottom > s.bottom) {
            if (a.top < s.top || a.bottom > s.bottom) { sidebar.scrollTop += (a.top - s.top) - (s.height / 2) + (a.height / 2); }
        }
    }
    function save() { try { sessionStorage.setItem(key, String(sidebar.scrollTop)); } catch (e) { /* ignore */ } }
    sidebar.addEventListener('click', function (ev) { if (ev.target.closest('a')) { save(); } });
    window.addEventListener('pagehide', save);
})();
</script>
