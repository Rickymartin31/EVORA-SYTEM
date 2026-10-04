<?php
// Join or leave an event's waitlist (POST + CSRF). v39
Auth::requireRole('participant');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('events');
}
Csrf::requireValid();
$db = Database::connection();
$eventId = (int) ($_POST['event_id'] ?? 0);
$pStmt = $db->prepare('SELECT id FROM participants WHERE user_id = :uid');
$pStmt->execute(['uid' => Auth::id()]);
$participantId = (int) ($pStmt->fetchColumn() ?: 0);
$event = $db->prepare('SELECT * FROM events WHERE id = :id');
$event->execute(['id' => $eventId]);
$event = $event->fetch();
try {
    if (!$event || !$participantId) {
        throw new DomainException('Event not found.');
    }
    if (($_POST['action'] ?? '') === 'leave') {
        WaitlistService::leave($db, $eventId, $participantId, (int) Auth::id());
        flash('success', 'You left the waitlist.');
    } else {
        $catId = (int) ($_POST['race_category_id'] ?? 0) ?: null;
        if ($catId) {
            $c = $db->prepare('SELECT 1 FROM race_categories WHERE id = :id AND event_id = :eid');
            $c->execute(['id' => $catId, 'eid' => $eventId]);
            if (!$c->fetchColumn()) {
                throw new DomainException('Please choose a valid race category.');
            }
        }
        WaitlistService::join($db, $event, $participantId, (int) Auth::id(), $catId);
        $entry = WaitlistService::entry($db, $eventId, $participantId);
        flash('success', 'You joined the waitlist (number ' . (int) $entry['position'] . ' in line). We will notify you if a slot opens — it is then held for you for ' . WaitlistService::OFFER_HOURS . ' hours.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', 'You are already on this waitlist.');
}
redirect('event-view', ['id' => $eventId]);
