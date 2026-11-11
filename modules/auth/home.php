<?php if (Auth::check()): redirect(Auth::role() . '-dashboard'); endif; ?>
<?php
// Public landing page: live upcoming events, categories, how it works, and the organizer pitch (v43).
$db = Database::connection();
$upcoming = $db->query(
    "SELECT e.id, e.max_participants, e.registration_deadline, " . EVENT_CARD_COLUMNS . ", e.name, e.event_date, e.event_time, e.venue, e.banner_path, e.fee, c.name AS category_name, o.organization_name, " . OrganizerBadgeService::sqlVerified('o') . " AS organizer_verified
     FROM events e JOIN event_categories c ON c.id = e.category_id JOIN organizers o ON o.id = e.organizer_id
     WHERE e.status IN ('open', 'ongoing') AND e.event_date >= CURDATE()
     ORDER BY e.event_date, e.event_time LIMIT 6"
)->fetchAll();
$categories = $db->query(
    "SELECT c.id, c.name, c.slug, (SELECT COUNT(*) FROM events e WHERE e.category_id = c.id AND e.status IN ('open', 'ongoing') AND e.event_date >= CURDATE()) AS open_count
     FROM event_categories c ORDER BY c.name"
)->fetchAll();
$categoryIcons = ['fun-run' => 'bi-stopwatch', 'sports-tournament' => 'bi-trophy', 'seminar-workshop' => 'bi-mic', 'competition' => 'bi-award',
    'community-event' => 'bi-people', 'other' => 'bi-grid'];
$settings = systemSettings();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($settings['system_name'] ?? 'EVORA') ?> — Integrated Event Management</title>
    <meta name="description" content="Find fun runs, tournaments, seminars and more. Register and pay online, then check in with your QR code.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= assetUrl('css/theme.css') ?>" rel="stylesheet">
    <?= themeInit() ?>
    <?= pageHead($pageMeta ?? []) ?>
    <style>
        .home-hero { background: linear-gradient(135deg, var(--evora-sidebar-bg), var(--evora-primary)); }
        .home-hero h1, .home-hero .lead { color: #fff; }
        .home-hero .lead { opacity: .92; }
        .home-trust { color: rgba(255,255,255,.85); }
        .home-section-title { font-weight: 800; letter-spacing: -.01em; }
        .cat-tile { display: flex; align-items: center; gap: .75rem; padding: 1rem 1.1rem; border-radius: .9rem; background: var(--evora-surface);
            border: 1px solid rgba(79,70,229,.12); color: inherit; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
        .cat-tile:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(30,27,75,.08); color: inherit; }
        .cat-tile i { font-size: 1.5rem; color: var(--evora-primary); }
        .step-num { width: 2rem; height: 2rem; border-radius: 50%; background: var(--evora-primary); color: #fff; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .organizer-band { background: linear-gradient(135deg, #1e1b4b, #312e81); color: #fff; border-radius: 1.25rem; }
        .organizer-band h2 { color: #fff; }
        .organizer-band p { color: rgba(255,255,255,.85); }
    </style>
</head>
<body>
<nav class="navbar navbar-dark" style="background:var(--evora-sidebar-bg);">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= url('home') ?>">EVO<span style="color:var(--evora-accent)">RA</span></a>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm theme-toggle theme-toggle-nav" data-theme-toggle aria-label="Switch theme"></button>
            <a href="<?= url('events') ?>" class="btn btn-outline-light btn-sm">Browse Events</a>
            <a href="<?= url('login') ?>" class="btn btn-outline-light btn-sm">Login</a>
            <a href="<?= url('register-participant') ?>" class="btn btn-light btn-sm">Sign Up</a>
        </div>
    </div>
</nav>

<header class="home-hero text-center py-5">
    <div class="container py-4 py-lg-5">
        <h1 class="display-4 fw-bold">Run Your Events End-to-End</h1>
        <p class="lead col-lg-8 mx-auto mt-3">Find fun runs, tournaments, seminars and more. Register and pay online in minutes,
            get your QR e-ticket by email, and check in fast on event day.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
            <a href="<?= url('events') ?>" class="btn btn-light btn-lg"><i class="bi bi-search me-1" aria-hidden="true"></i> Explore Events</a>
            <a href="<?= url('register-organizer') ?>" class="btn btn-outline-light btn-lg">Become an Organizer</a>
        </div>
        <p class="small home-trust mt-4 mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i> Verified organizers · Secure PayPal payments · QR check-in</p>
    </div>
</header>

<main>
    <section class="py-5" aria-labelledby="upcomingHeading">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
                <div>
                    <h2 class="h3 home-section-title mb-1" id="upcomingHeading">Upcoming Events</h2>
                    <p class="text-muted mb-0">Open for registration now.</p>
                </div>
                <a href="<?= url('events') ?>" class="btn btn-outline-primary btn-sm">See all events <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if (!$upcoming): ?>
                <div class="card-evora p-4 text-center text-muted">
                    <i class="bi bi-calendar-event fs-2 d-block mb-2" aria-hidden="true"></i>
                    New events appear here as soon as organizers open registration. Check back soon — or <a href="<?= url('register-organizer') ?>">host your own event</a>.
                </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($upcoming as $ev): ?>
                    <div class="col-sm-6 col-lg-4">
                        <article class="card-evora h-100 d-flex flex-column">
                            <?php if ($ev['banner_path']): ?>
                                <img src="<?= uploadUrl($ev['banner_path']) ?>" alt="" class="w-100" style="height:160px;object-fit:cover;border-radius:.75rem .75rem 0 0;" loading="lazy">
                            <?php else: ?>
                                <div class="w-100 d-flex align-items-center justify-content-center text-white event-cover-placeholder"><i class="bi bi-calendar-event fs-1" aria-hidden="true"></i></div>
                            <?php endif; ?>
                            <div class="p-3 d-flex flex-column flex-grow-1">
                                <?= eventCardBadges($ev) ?>
                                <h3 class="h6 fw-semibold"><?= e($ev['name']) ?></h3>
                                <p class="small text-muted mb-1"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i><?= date('D, M d, Y', strtotime($ev['event_date'])) ?><?= $ev['event_time'] ? ' · ' . date('g:i A', strtotime($ev['event_time'])) : '' ?></p>
                                <p class="small text-muted mb-1"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e($ev['venue']) ?></p>
                                <p class="small text-muted mb-1"><i class="bi bi-building me-1" aria-hidden="true"></i><?= e($ev['organization_name']) ?><?= verifiedBadge((bool) $ev['organizer_verified']) ?></p>
                                <?= eventCardSlots($ev) ?: '<div class="mb-3"></div>' ?>
                                <a href="<?= url('event-view', ['id' => $ev['id']]) ?>" class="btn btn-evora-primary btn-sm w-100 mt-auto">View Details</a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pb-5" aria-labelledby="categoriesHeading">
        <div class="container">
            <h2 class="h3 home-section-title mb-4" id="categoriesHeading">Browse by Category</h2>
            <div class="row g-3">
                <?php foreach ($categories as $c): ?>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a class="cat-tile h-100" href="<?= url('events', ['category' => $c['id']]) ?>">
                            <i class="bi <?= $categoryIcons[$c['slug']] ?? 'bi-calendar-event' ?>" aria-hidden="true"></i>
                            <span><span class="fw-semibold d-block small"><?= e($c['name']) ?></span><span class="small text-muted"><?= (int) $c['open_count'] ?> open</span></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="py-5 bg-white border-top border-bottom" aria-labelledby="howHeading">
        <div class="container">
            <h2 class="h3 home-section-title text-center mb-5" id="howHeading">How It Works</h2>
            <div class="row g-5">
                <div class="col-lg-6">
                    <h3 class="h5 fw-bold mb-4"><i class="bi bi-person me-1 text-primary" aria-hidden="true"></i> For participants</h3>
                    <div class="d-flex gap-3 mb-4"><span class="step-num">1</span><div><div class="fw-semibold">Find an event</div><div class="small text-muted">Browse by category, search by name, venue or organizer, and save the ones you like.</div></div></div>
                    <div class="d-flex gap-3 mb-4"><span class="step-num">2</span><div><div class="fw-semibold">Register and pay securely</div><div class="small text-muted">Pay through PayPal straight to the organizer. Your receipt and QR e-ticket arrive by email.</div></div></div>
                    <div class="d-flex gap-3"><span class="step-num">3</span><div><div class="fw-semibold">Show your QR on event day</div><div class="small text-muted">Staff scan it to check you in — and you get reminders, results and certificates afterwards.</div></div></div>
                </div>
                <div class="col-lg-6">
                    <h3 class="h5 fw-bold mb-4"><i class="bi bi-building me-1 text-primary" aria-hidden="true"></i> For organizers</h3>
                    <div class="d-flex gap-3 mb-4"><span class="step-num">1</span><div><div class="fw-semibold">Apply and get verified</div><div class="small text-muted">An administrator reviews your organization so participants know you are legitimate.</div></div></div>
                    <div class="d-flex gap-3 mb-4"><span class="step-num">2</span><div><div class="fw-semibold">Create your event</div><div class="small text-muted">Fun runs with categories and bibs, tournaments with brackets, seminars with sessions and certificates.</div></div></div>
                    <div class="d-flex gap-3"><span class="step-num">3</span><div><div class="fw-semibold">Get paid directly</div><div class="small text-muted">Payments go straight to your own PayPal account — no payout requests, no waiting.</div></div></div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5" aria-labelledby="featuresHeading">
        <div class="container">
            <h2 class="visually-hidden" id="featuresHeading">Why EVORA</h2>
            <div class="row g-4 text-center">
                <div class="col-sm-6 col-lg-3">
                    <i class="bi bi-patch-check fs-1 text-primary" aria-hidden="true"></i>
                    <h3 class="h5 mt-3">Verified Organizers</h3>
                    <p class="text-muted small">Admin-reviewed organizer accounts keep the platform trustworthy.</p>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <i class="bi bi-qr-code fs-1 text-primary" aria-hidden="true"></i>
                    <h3 class="h5 mt-3">QR Check-in &amp; E-tickets</h3>
                    <p class="text-muted small">Signed QR codes and printable e-tickets drive attendance and item claiming on event day.</p>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <i class="bi bi-paypal fs-1 text-primary" aria-hidden="true"></i>
                    <h3 class="h5 mt-3">Direct PayPal Payments</h3>
                    <p class="text-muted small">Participants pay the organizer directly; EVORA verifies the payment and issues the QR automatically.</p>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <i class="bi bi-bar-chart fs-1 text-primary" aria-hidden="true"></i>
                    <h3 class="h5 mt-3">Reports &amp; Results</h3>
                    <p class="text-muted small">Attendance, payment and race-result reports, generated automatically.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="pb-5" aria-labelledby="organizerHeading">
        <div class="container">
            <div class="organizer-band p-4 p-lg-5 d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                <div>
                    <h2 class="h3 fw-bold mb-2" id="organizerHeading">Hosting an event?</h2>
                    <p class="mb-0">Registration, PayPal payments paid straight to you, QR check-in, waitlists and reports — all in one place.</p>
                </div>
                <a href="<?= url('register-organizer') ?>" class="btn btn-light btn-lg flex-shrink-0">Become an Organizer</a>
            </div>
        </div>
    </section>
</main>

<footer class="text-center py-4 text-muted small border-top">
    &copy; <?= date('Y') ?> EVORA — An Integrated Web Application for Event Management. Capstone Project.
    <div class="mt-1"><a href="<?= url('user-agreement') ?>" class="text-muted">User Agreement</a> · <a href="<?= url('privacy-policy') ?>" class="text-muted">Privacy Policy</a></div>
</footer>
</body>
</html>
