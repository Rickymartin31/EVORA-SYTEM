<?php
Auth::requireRole('organizer');
$db = Database::connection();
$userId = Auth::id();

$orgStmt = $db->prepare('SELECT id FROM organizers WHERE user_id = :uid');
$orgStmt->execute(['uid' => $userId]);
$organizer = $orgStmt->fetch();

$eventId = (int) ($_GET['event_id'] ?? 0);
$eventStmt = $db->prepare(
    'SELECT e.id, c.slug AS category_slug FROM events e
     JOIN event_categories c ON c.id = e.category_id
     WHERE e.id = :id AND e.organizer_id = :oid'
);
$eventStmt->execute(['id' => $eventId, 'oid' => $organizer['id'] ?? 0]);
$event = $eventStmt->fetch();

header('Content-Type: text/html; charset=utf-8');

if (!$event) {
    echo '<p style="font-family:sans-serif;color:#888;text-align:center;padding:40px;">Event not found.</p>';
    exit;
}

$defaultTitlesByCategory = [
    'fun-run' => ['Certificate of Participation', 'has successfully participated in'],
    'seminar-workshop' => ['Certificate of Participation', 'has successfully participated in'],
];
[$defaultTitle, $defaultVerb] = $defaultTitlesByCategory[$event['category_slug']] ?? ['Certificate of Participation', 'has successfully participated in'];

$settingsStmt = $db->prepare('SELECT certificate_title FROM event_certificate_settings WHERE event_id = :eid');
$settingsStmt->execute(['eid' => $eventId]);
$settings = $settingsStmt->fetch();
$title = (!empty($settings['certificate_title'])) ? $settings['certificate_title'] : $defaultTitle;

$html = CertificateService::previewHtml($db, $eventId, $title, $defaultVerb);
if ($html !== null) {
    // v72: the certificate is one fixed A4 page; scale it down to fit the preview frame.
    $fit = '<style>html,body{overflow:hidden;background:#fff}.page{transform-origin:0 0}</style>'
        . '<script>function fit(){var p=document.querySelector(".page");if(p){p.style.transform="scale("+Math.min(window.innerWidth/p.offsetWidth,window.innerHeight/p.offsetHeight)+")";}}'
        . 'window.addEventListener("load",fit);window.addEventListener("resize",fit);</script>';
    $html = str_replace('</body>', $fit . '</body>', $html);
}
echo $html ?? '<p style="font-family:sans-serif;color:#888;text-align:center;padding:40px;">Unable to build a preview.</p>';
