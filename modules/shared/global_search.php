<?php
/**
 * Global search (Ctrl+K, v53): GET q → JSON groups of matches the signed-in user may open.
 * Read-only and scoped by role: admins search the platform; organizers their own events and
 * participants; staff their assigned events; participants open events and their own
 * registrations. Pages come from the sidebar on the client, not from here.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 80) {
    echo json_encode(['groups' => []]);
    exit;
}
$db = Database::connection();
$like = '%' . addcslashes($q, '%_\\') . '%';
$role = Auth::role();
$uid = (int) Auth::id();
$groups = [];
$day = fn (?string $d): string => $d ? date('M d, Y', strtotime($d)) : '';
$status = fn (string $s): string => ucfirst(str_replace('_', ' ', $s));
$run = function (string $sql, array $params) use ($db): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
};

if ($role === 'admin') {
    $rows = $run('SELECT e.id, e.name, e.event_date, e.status, o.organization_name FROM events e JOIN organizers o ON o.id = e.organizer_id
                  WHERE e.status <> "removed" AND (e.name LIKE :a OR e.venue LIKE :b OR o.organization_name LIKE :c) ORDER BY e.event_date DESC LIMIT 6',
        ['a' => $like, 'b' => $like, 'c' => $like]);
    $groups[] = ['label' => 'Events', 'items' => array_map(fn ($r) => ['title' => $r['name'], 'sub' => $day($r['event_date']) . ' · ' . $status($r['status']) . ' · ' . $r['organization_name'],
        'url' => url('event-view', ['id' => $r['id']]), 'icon' => 'bi-calendar-event'], $rows)];
    $rows = $run('SELECT o.id, o.organization_name, o.status, u.email FROM organizers o JOIN users u ON u.id = o.user_id
                  WHERE o.organization_name LIKE :a OR u.email LIKE :b OR u.full_name LIKE :c ORDER BY o.organization_name LIMIT 5',
        ['a' => $like, 'b' => $like, 'c' => $like]);
    $groups[] = ['label' => 'Organizers', 'items' => array_map(fn ($r) => ['title' => $r['organization_name'], 'sub' => $r['email'] . ' · ' . $status($r['status']),
        'url' => url('admin-organizer-review', ['id' => $r['id']]), 'icon' => 'bi-building'], $rows)];
    $rows = $run('SELECT u.full_name, u.email, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.full_name LIKE :a OR u.email LIKE :b ORDER BY u.full_name LIMIT 6', ['a' => $like, 'b' => $like]);
    $groups[] = ['label' => 'Users', 'items' => array_map(fn ($r) => ['title' => $r['full_name'], 'sub' => $r['email'] . ' · ' . AccountService::roleLabel($r['role']),
        'url' => url('admin-users', ['dt_search' => $r['email']]), 'icon' => 'bi-person'], $rows)];
} elseif ($role === 'organizer') {
    $org = $run('SELECT id FROM organizers WHERE user_id = :u', ['u' => $uid]);
    $oid = (int) ($org[0]['id'] ?? 0);
    $rows = $run('SELECT id, name, event_date, status FROM events WHERE organizer_id = :o AND status <> "removed" AND (name LIKE :a OR venue LIKE :b)
                  ORDER BY event_date DESC LIMIT 6', ['o' => $oid, 'a' => $like, 'b' => $like]);
    $groups[] = ['label' => 'My events', 'items' => array_map(fn ($r) => ['title' => $r['name'], 'sub' => $day($r['event_date']) . ' · ' . $status($r['status']),
        'url' => url('organizer-event-manage', ['id' => $r['id']]), 'icon' => 'bi-calendar-event'], $rows)];
    $rows = $run('SELECT u.full_name, u.email, e.id AS event_id, e.name AS event_name, r.status FROM registrations r
                  JOIN participants p ON p.id = r.participant_id JOIN users u ON u.id = p.user_id JOIN events e ON e.id = r.event_id
                  WHERE e.organizer_id = :o AND (u.full_name LIKE :a OR u.email LIKE :b) ORDER BY r.id DESC LIMIT 8',
        ['o' => $oid, 'a' => $like, 'b' => $like]);
    $groups[] = ['label' => 'Participants', 'items' => array_map(fn ($r) => ['title' => $r['full_name'], 'sub' => $r['event_name'] . ' · ' . $status($r['status']),
        'url' => url('organizer-registrations', ['event_id' => $r['event_id'], 'dt_search' => $r['email']]), 'icon' => 'bi-person'], $rows)];
} elseif ($role === 'staff') {
    $rows = $run('SELECT DISTINCT e.id, e.name, e.event_date, e.status FROM event_staff es JOIN events e ON e.id = es.event_id
                  WHERE es.user_id = :u AND e.status NOT IN ("removed", "archived") AND (e.name LIKE :a OR e.venue LIKE :b) ORDER BY e.event_date DESC LIMIT 6',
        ['u' => $uid, 'a' => $like, 'b' => $like]);
    $groups[] = ['label' => 'My assigned events', 'items' => array_map(fn ($r) => ['title' => $r['name'], 'sub' => $day($r['event_date']) . ' · ' . $status($r['status']),
        'url' => url('event-view', ['id' => $r['id']]), 'icon' => 'bi-calendar-event'], $rows)];
} elseif ($role === 'participant') {
    $rows = $run('SELECT e.id, e.name, e.event_date, e.venue FROM events e WHERE e.status IN ("open", "ongoing") AND e.event_date >= CURDATE()
                  AND (e.name LIKE :a OR e.venue LIKE :b) ORDER BY e.event_date LIMIT 6', ['a' => $like, 'b' => $like]);
    $groups[] = ['label' => 'Open events', 'items' => array_map(fn ($r) => ['title' => $r['name'], 'sub' => $day($r['event_date']) . ' · ' . $r['venue'],
        'url' => url('event-view', ['id' => $r['id']]), 'icon' => 'bi-calendar-event'], $rows)];
    $rows = $run('SELECT e.name, e.event_date, r.status FROM registrations r JOIN participants p ON p.id = r.participant_id JOIN events e ON e.id = r.event_id
                  WHERE p.user_id = :u AND e.name LIKE :a ORDER BY e.event_date DESC LIMIT 6', ['u' => $uid, 'a' => $like]);
    $groups[] = ['label' => 'My registrations', 'items' => array_map(fn ($r) => ['title' => $r['name'], 'sub' => $day($r['event_date']) . ' · ' . $status($r['status']),
        'url' => url('my-registrations', ['dt_search' => $r['name']]), 'icon' => 'bi-clipboard-check'], $rows)];
}

echo json_encode(['groups' => array_values(array_filter($groups, fn ($g) => $g['items']))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
