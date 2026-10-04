<?php
Auth::requireRole('admin');
$db = Database::connection();

$stmt = $db->query(
    "SELECT e.id, e.name, e.event_date, e.registration_deadline, e.status, o.organization_name
     FROM events e JOIN organizers o ON o.id = e.organizer_id
     WHERE e.status != 'removed'"
);
$events = $stmt->fetchAll();

$calendarEvents = [];
foreach ($events as $ev) {
    $calendarEvents[] = [
        'title' => $ev['name'] . ' (' . $ev['organization_name'] . ')',
        'start' => $ev['event_date'],
        'color' => '#4f46e5',
        'url' => url('event-view', ['id' => $ev['id']]),
    ];
    if (!empty($ev['registration_deadline'])) {
        $calendarEvents[] = [
            'title' => 'Deadline: ' . $ev['name'],
            'start' => $ev['registration_deadline'],
            'color' => '#dc2626',
            'url' => url('event-view', ['id' => $ev['id']]),
        ];
    }
}

$pageDescription = 'Event dates and registration deadlines across the platform.';
$pageTitle = 'Platform Calendar';
$activeRoute = 'admin-calendar';
include EVORA_ROOT . '/modules/shared/partials/layout_top.php';
?>
<div class="card-evora p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-semibold mb-0">All Platform Events</h6>
        <div class="small text-muted"><span class="legend-swatch is-event" aria-hidden="true"></span> Event Date &nbsp; <span class="legend-swatch is-deadline" aria-hidden="true"></span> Registration Deadline</div>
    </div>
    <div id="calendar"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: 'dayGridMonth',
        height: 'auto',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
        events: <?= json_encode($calendarEvents) ?>,
    });
    calendar.render();
});
</script>
<?php include EVORA_ROOT . '/modules/shared/partials/layout_bottom.php'; ?>
