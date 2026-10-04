<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$stmt = $db->prepare(
    "SELECT id, name, event_date, event_time, registration_deadline, status FROM events WHERE organizer_id = :oid AND status != 'removed'"
);
$stmt->execute(['oid' => $organizer['id'] ?? 0]);
$events = $stmt->fetchAll();

$calendarEvents = [];
foreach ($events as $ev) {
    $calendarEvents[] = [
        'title' => $ev['name'],
        'start' => $ev['event_date'],
        'color' => '#4f46e5',
        'url' => url('organizer-event-manage', ['id' => $ev['id']]),
    ];
    if (!empty($ev['registration_deadline'])) {
        $calendarEvents[] = [
            'title' => 'Deadline: ' . $ev['name'],
            'start' => $ev['registration_deadline'],
            'color' => '#dc2626',
            'url' => url('organizer-event-manage', ['id' => $ev['id']]),
        ];
    }
}

$pageDescription = 'Your event dates and registration deadlines.';
$pageTitle = 'My Calendar';
$activeRoute = 'organizer-calendar';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">My Event Calendar</h6>
        <div class="small text-muted"><span class="legend-swatch is-event" aria-hidden="true"></span> Event Date &nbsp; <span class="legend-swatch is-deadline" aria-hidden="true"></span> Registration Deadline</div>
    </div>
    <div id="calendar"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        height: 'auto',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
        events: <?= json_encode($calendarEvents) ?>,
    });
    calendar.render();
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
