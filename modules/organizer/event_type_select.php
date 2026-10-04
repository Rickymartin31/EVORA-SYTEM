<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT * FROM organizers WHERE user_id = :uid AND status = "approved"');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

if (!$organizer) {
    flash('error', 'Your organizer account must be approved before creating events.');
    redirect('organizer-dashboard');
}

$categories = $db->query("SELECT * FROM event_categories WHERE slug IN ('fun-run', 'sports-tournament', 'seminar-workshop')
    ORDER BY FIELD(slug, 'fun-run', 'sports-tournament', 'seminar-workshop')")->fetchAll();

/**
 * Per-category wizard content. "tools" names match the actual organizer tool
 * pages linked from event_manage.php's Setup Guide/tool grid, so nothing
 * promised here is unavailable once the event exists.
 */
$categoryMeta = [
    'fun-run' => [
        'icon' => 'bi-flag',
        'tagline' => 'A timed race with one or more distance categories.',
        'manages' => [
            'Runner registration and profiles', 'Multiple race categories/distances', 'Registration fee collection',
            'PayPal online payment (paid directly to you)', 'Race kit / bib assignment', 'QR code check-in on race day',
            'Attendance recording', 'Race results', 'Certificate issuance', 'Reports and analytics',
        ],
        'checklist' => [
            'Race categories/distances you will offer (e.g. 3K, 5K, 10K)', 'Free Event or Paid Event per category, and the fee if paid',
            'Maximum number of runners you can accommodate', 'Race kit / bib inventory, and how many per category',
            'Requirements to collect from runners (e.g. medical certificate, valid ID)',
            'Venue, route, and race-day schedule', 'Registration deadline',
        ],
        'tools' => [
            ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'], ['icon' => 'bi-credit-card', 'label' => 'Payments'],
            ['icon' => 'bi-box-seam', 'label' => 'Event Items (bibs / kits)'], ['icon' => 'bi-person-badge', 'label' => 'Staff (QR check-in)'],
            ['icon' => 'bi-award', 'label' => 'Certificates'], ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => null,
    ],
    'sports-tournament' => [
        'icon' => 'bi-trophy',
        'tagline' => 'Teams or players competing in one sport across scheduled matches.',
        'manages' => [
            'Sport selection', 'Team / player registration', 'Registration fee and PayPal online payment (paid directly to you)', 'Team roster management',
            'Match scheduling', 'Tournament bracket', 'Match scores', 'Standings (derived from scores)', 'Attendance', 'Reports',
        ],
        'checklist' => [
            'Which sport(s) will be played', 'Team-based or individual format, and the max number of teams/players',
            'Bracket format (single elimination, round robin, etc.) and number of rounds',
            'Free Event or Paid Event, and the registration fee if paid', 'Court/venue availability and match schedule',
            'Requirements for players (e.g. valid ID, waiver)',
        ],
        'tools' => [
            ['icon' => 'bi-people-fill', 'label' => 'Teams'], ['icon' => 'bi-trophy', 'label' => 'Matches & Scores'],
            ['icon' => 'bi-diagram-3', 'label' => 'Bracket'], ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'],
            ['icon' => 'bi-credit-card', 'label' => 'Payments'], ['icon' => 'bi-person-badge', 'label' => 'Staff'],
            ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => null,
    ],
    'seminar-workshop' => [
        'icon' => 'bi-mic',
        'tagline' => 'Talks or training sessions delivered by one or more speakers.',
        'manages' => [
            'Participant registration', 'Registration fee and PayPal online payment (paid directly to you)', 'Speaker profiles', 'Session schedule',
            'Attendance', 'Certificate issuance',
        ],
        'checklist' => [
            'Speaker list, bios, and topics', 'Session schedule (time blocks per speaker/topic)',
            'Free Event or Paid Event, and the registration fee if paid', 'Requirements to collect from attendees',
            'How you will gather feedback/evaluation from attendees (see note below)',
        ],
        'tools' => [
            ['icon' => 'bi-mic', 'label' => 'Speakers'], ['icon' => 'bi-clock-history', 'label' => 'Schedule'],
            ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'], ['icon' => 'bi-credit-card', 'label' => 'Payments'],
            ['icon' => 'bi-person-badge', 'label' => 'Staff'], ['icon' => 'bi-award', 'label' => 'Certificates'],
            ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => 'EVORA does not yet have a built-in per-event participant evaluation/feedback form — plan to collect that separately (e.g. a shared form link) if you need it.',
    ],
    'festival' => [
        'icon' => 'bi-stars',
        'tagline' => 'Several activities or segments running under one event.',
        'manages' => [
            'Multiple activities/segments under one event', 'Participant registration', 'Staff assignment per activity',
            'Event items (giveaways, kits, tickets)', 'Activity schedule', 'Attendance', 'Reports',
        ],
        'checklist' => [
            'List of activities/segments happening during the festival', 'Schedule/timeline for each activity',
            'Registration fee(s), if any', 'Staff needed per activity', 'Items to prepare or distribute',
        ],
        'tools' => [
            ['icon' => 'bi-clock-history', 'label' => 'Schedule'], ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'],
            ['icon' => 'bi-person-badge', 'label' => 'Staff'], ['icon' => 'bi-box-seam', 'label' => 'Event Items'],
            ['icon' => 'bi-award', 'label' => 'Certificates'], ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => null,
    ],
    'community-event' => [
        'icon' => 'bi-people',
        'tagline' => 'A community activity that mainly needs registration and volunteers.',
        'manages' => [
            'Participant registration', 'Volunteer/staff assignment', 'Event items', 'Attendance', 'Reports',
        ],
        'checklist' => [
            'Expected number of participants/volunteers', 'Roles you need volunteers/staff for',
            'Items to prepare (supplies, giveaways)', 'Venue and schedule',
        ],
        'tools' => [
            ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'], ['icon' => 'bi-person-badge', 'label' => 'Staff'],
            ['icon' => 'bi-box-seam', 'label' => 'Event Items'], ['icon' => 'bi-award', 'label' => 'Certificates'],
            ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => null,
    ],
    'competition' => [
        'icon' => 'bi-award',
        'tagline' => 'A judged or scored contest outside of a sports tournament format.',
        'manages' => [
            'Entrant registration', 'Registration fee and payment validation', 'Event items (materials, prizes)',
            'Attendance', 'Certificate issuance', 'Reports',
        ],
        'checklist' => [
            'Judging criteria/rules (see note below)', 'Entry requirements', 'Registration fee (or mark it free)',
            'Prizes/materials to prepare', 'Venue and schedule',
        ],
        'tools' => [
            ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'], ['icon' => 'bi-credit-card', 'label' => 'Payments'],
            ['icon' => 'bi-box-seam', 'label' => 'Event Items'], ['icon' => 'bi-person-badge', 'label' => 'Staff'],
            ['icon' => 'bi-award', 'label' => 'Certificates'], ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => 'EVORA does not yet have a built-in judging/scoring module for general competitions — track scores and rankings outside the system for now.',
    ],
    'other' => [
        'icon' => 'bi-calendar-event',
        'tagline' => 'Anything that does not fit the categories above.',
        'manages' => [
            'Participant registration', 'Registration fee and payment validation', 'Staff assignment', 'Attendance',
            'Certificate issuance', 'Reports',
        ],
        'checklist' => [
            'Basic event details (venue, schedule, requirements)', 'Registration fee (or mark it free)', 'Staff needed',
        ],
        'tools' => [
            ['icon' => 'bi-clipboard-check', 'label' => 'Registrations'], ['icon' => 'bi-credit-card', 'label' => 'Payments'],
            ['icon' => 'bi-person-badge', 'label' => 'Staff'], ['icon' => 'bi-award', 'label' => 'Certificates'],
            ['icon' => 'bi-bar-chart', 'label' => 'Reports'],
        ],
        'note' => null,
    ],
];
$defaultMeta = $categoryMeta['other'];

$pageTitle = 'Event Setup Wizard';
$activeRoute = 'organizer-events';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<?php renderBreadcrumb([
    ['label' => 'My Events', 'route' => 'organizer-events'],
    ['label' => 'Choose Event Type'],
]); ?>

<div class="card-evora p-4 mb-3">
    <h6 class="fw-semibold mb-1">Event Setup Wizard</h6>
    <p class="text-muted small mb-0">Pick the type of event you're organizing. For each type, EVORA shows what the
        system will help you manage, what to prepare before you create the event, and which tools you'll use once
        it's live &mdash; so this isn't just a blank form, it's a guide for running the whole event.</p>
</div>

<div class="row g-3 mb-3" role="tablist">
    <?php foreach ($categories as $i => $c): ?>
        <?php $meta = $categoryMeta[$c['slug']] ?? $defaultMeta; ?>
        <div class="col-md-4">
            <button type="button" class="event-type-card btn w-100 h-100 text-start p-3 <?= $i === 0 ? 'active' : '' ?>"
                    id="tab-<?= e($c['slug']) ?>" data-bs-toggle="pill" data-bs-target="#pane-<?= e($c['slug']) ?>" role="tab">
                <i class="bi <?= e($meta['icon']) ?> fs-3 text-primary"></i>
                <div class="fw-semibold mt-2"><?= e($c['name']) ?></div>
                <div class="text-muted small mt-1"><?= e($meta['tagline']) ?></div>
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div class="card-evora p-3 p-md-4">
    <div class="tab-content">
        <?php foreach ($categories as $i => $c): ?>
            <?php $meta = $categoryMeta[$c['slug']] ?? $defaultMeta; ?>
            <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="pane-<?= e($c['slug']) ?>" role="tabpanel">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="small text-uppercase text-muted fw-bold mb-2"><i class="bi bi-gear me-1"></i>What EVORA Helps You Manage</h6>
                            <ul class="small mb-0 ps-3">
                                <?php foreach ($meta['manages'] as $item): ?>
                                    <li><?= e($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100 bg-light">
                            <h6 class="small text-uppercase text-muted fw-bold mb-2"><i class="bi bi-list-check me-1"></i>Prepare Before You Create This Event</h6>
                            <ul class="small mb-0 ps-3">
                                <?php foreach ($meta['checklist'] as $item): ?>
                                    <li><?= e($item) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="small text-uppercase text-muted fw-bold mb-2"><i class="bi bi-toolbox me-1"></i>Tools Available After Creation</h6>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($meta['tools'] as $tool): ?>
                                    <li class="mb-1"><i class="bi <?= e($tool['icon']) ?> text-primary me-1"></i><?= e($tool['label']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php if (!empty($meta['note'])): ?>
                    <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= e($meta['note']) ?></div>
                <?php endif; ?>
                <div class="mt-3">
                    <?php if ($c['slug'] === 'sports-tournament'): ?>
                        <a href="<?= url('organizer-sport-select') ?>" class="btn btn-evora-primary">
                            <i class="bi bi-arrow-right-circle me-1"></i> Proceed &mdash; Choose Your Sport
                        </a>
                    <?php else: ?>
                        <a href="<?= url('organizer-event-form', ['category_id' => $c['id']]) ?>" class="btn btn-evora-primary">
                            <i class="bi bi-arrow-right-circle me-1"></i> Proceed &mdash; Create a <?= e($c['name']) ?> Event
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var hash = window.location.hash;
    if (hash) {
        var trigger = document.querySelector('[data-bs-target="' + hash + '"]');
        if (trigger) new bootstrap.Tab(trigger).show();
    }
});
document.addEventListener('shown.bs.tab', function (e) {
    var target = e.target.getAttribute('data-bs-target');
    if (target) history.replaceState(null, '', target);
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
